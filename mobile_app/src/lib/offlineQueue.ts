/**
 * Offline-first outbox.
 *
 * - Persists submitted operations to IndexedDB (`outbox` store, see lib/db.ts).
 *   The service worker can read the same store to background-sync queued
 *   items even when the app tab is closed.
 * - Tries to POST immediately; on failure, marks pending with exponential
 *   backoff and replays automatically when the network comes back online.
 * - Also registers a Background Sync tag so the browser will wake the SW
 *   and re-flush the queue when connectivity returns even if the app is closed.
 * - Mobile UI submits one "outbox item" per form, but fertilization and
 *   phytosanitary forms can contain multiple line items. We explode those
 *   into N API requests with deterministic per-line client_ids so a retry
 *   is idempotent on the server side.
 */
import axios from 'axios';
import { api, toApiError, ApiError } from './api';
import { getNetworkStatus, onNetworkChange, platform, prefGet, prefRemove } from './native';
import {
  dbOutboxAll, dbOutboxPut, dbOutboxPutMany, dbOutboxDelete, dbOutboxClear,
  dbMetaGet, dbMetaSet, type OutboxRecord,
} from './db';
import { logDiag } from './diagnostics';

export type OutboxOperationType = OutboxRecord['type'];
export type OutboxStatus = OutboxRecord['status'];
export type OutboxItem<TPayload = Record<string, unknown>> = Omit<OutboxRecord, 'payload'> & {
  payload: TPayload;
};

const LEGACY_STORAGE_KEY = 'agri-sync.outbox.v1';
const LEGACY_LAST_SYNC_KEY = 'agri-sync.outbox.lastSyncAt';
const LAST_SYNC_KEY = 'outbox.lastSyncAt';
const SYNC_TAG = 'agri-sync-outbox';
const MAX_RETRIES = 5;
const RETRY_BASE_MS = 2_000;
const RETRY_CAP_MS = 60_000;

/* ── Persistence ────────────────────────────────────────────────────────── */

let cached: OutboxRecord[] | null = null;
let migrated = false;

async function migrateFromLegacy(): Promise<void> {
  if (migrated) return;
  migrated = true;
  try {
    const raw = await prefGet(LEGACY_STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as OutboxRecord[];
      if (Array.isArray(parsed) && parsed.length > 0) {
        await dbOutboxPutMany(parsed.map((it) => ({
          ...it,
          status: it.status === 'syncing' ? 'pending' : it.status,
          nextAttemptAt: it.status === 'syncing' ? 0 : it.nextAttemptAt,
        })));
      }
      await prefRemove(LEGACY_STORAGE_KEY);
    }
    const legacyLast = await prefGet(LEGACY_LAST_SYNC_KEY);
    if (legacyLast) {
      const existing = await dbMetaGet(LAST_SYNC_KEY);
      if (!existing) await dbMetaSet(LAST_SYNC_KEY, legacyLast);
      await prefRemove(LEGACY_LAST_SYNC_KEY);
    }
  } catch { /* legacy storage unreachable — ignore */ }
}

async function readAll(): Promise<OutboxRecord[]> {
  if (cached) return cached;
  await migrateFromLegacy();
  const items = await dbOutboxAll();
  // Recover from a crash mid-flush: items left in 'syncing' must be replayed.
  let dirty = false;
  for (const it of items) {
    if (it.status === 'syncing') {
      it.status = 'pending';
      it.nextAttemptAt = 0;
      dirty = true;
    }
  }
  if (dirty) await dbOutboxPutMany(items);
  cached = items;
  return cached;
}

async function persistAll(items: OutboxRecord[]): Promise<void> {
  cached = items;
  await dbOutboxPutMany(items);
  emit(items);
}

async function persistOne(item: OutboxRecord): Promise<void> {
  await dbOutboxPut(item);
  if (cached) {
    const idx = cached.findIndex((i) => i.id === item.id);
    if (idx >= 0) cached[idx] = item; else cached.push(item);
    emit(cached);
  }
}

async function removeOne(id: string): Promise<void> {
  await dbOutboxDelete(id);
  if (cached) {
    cached = cached.filter((i) => i.id !== id);
    emit(cached);
  }
}

/* ── Subscriptions ──────────────────────────────────────────────────────── */

type Listener = (items: OutboxItem[]) => void;
const listeners = new Set<Listener>();
function emit(items: OutboxRecord[]) { for (const fn of listeners) fn(items as OutboxItem[]); }

export function subscribeOutbox(fn: Listener): () => void {
  listeners.add(fn);
  void readAll().then((i) => fn(i as OutboxItem[]));
  return () => { listeners.delete(fn); };
}

/* ── Background Sync registration ───────────────────────────────────────── */

async function registerBackgroundSync(): Promise<void> {
  if (typeof navigator === 'undefined') return;
  if (!('serviceWorker' in navigator) || !('SyncManager' in window)) return;
  try {
    const reg = await navigator.serviceWorker.ready;
    // SyncManager is not on the standard ServiceWorkerRegistration type yet.
    await (reg as ServiceWorkerRegistration & {
      sync: { register: (tag: string) => Promise<void> };
    }).sync.register(SYNC_TAG);
  } catch { /* sync API unavailable — fall back to in-app retries */ }
}

/* ── Public API ─────────────────────────────────────────────────────────── */

export async function enqueue<T extends Record<string, unknown>>(
  type: OutboxOperationType,
  payload: T,
): Promise<OutboxItem<T>> {
  const items = await readAll();
  const item: OutboxRecord = {
    id: crypto.randomUUID(),
    type,
    payload,
    createdAt: new Date().toISOString(),
    attempts: 0,
    status: 'pending',
  };
  items.push(item);
  await persistAll(items);
  void logDiag('info', 'queue', 'enqueue', { id: item.id, type });
  void registerBackgroundSync();
  void flushOutbox().catch(() => {});
  return item as OutboxItem<T>;
}

export async function getOutbox(): Promise<OutboxItem[]> { return (await readAll()) as OutboxItem[]; }

export async function removeFromOutbox(id: string): Promise<void> {
  await removeOne(id);
}

export async function clearOutbox(): Promise<void> {
  await dbOutboxClear();
  cached = [];
  emit(cached);
}

export async function retryItem(id: string): Promise<void> {
  const items = await readAll();
  const target = items.find((i) => i.id === id);
  if (!target) return;
  target.status = 'pending';
  target.nextAttemptAt = Date.now();
  target.attempts = 0;
  await persistOne(target);
  void registerBackgroundSync();
  void flushOutbox().catch(() => {});
}

/* ── Per-item retry with feedback ───────────────────────────────────────── */

export interface RetryReport {
  total: number;
  succeeded: string[];
  failed: { id: string; error: string }[];
  skipped: number;
}

export async function retryFailed(ids?: string[]): Promise<RetryReport> {
  const all = await readAll();
  const targets = all.filter((i) => i.status === 'failed' && (!ids || ids.includes(i.id)));
  const report: RetryReport = { total: targets.length, succeeded: [], failed: [], skipped: 0 };
  if (targets.length === 0) return report;

  const net = await getNetworkStatus();
  if (!net.connected) { report.skipped = targets.length; return report; }

  for (const it of targets) {
    it.status = 'pending';
    it.attempts = 0;
    it.nextAttemptAt = 0;
    it.lastError = undefined;
  }
  await persistAll(all);

  const targetIds = new Set(targets.map((t) => t.id));
  await flushOutbox();

  const after = await readAll();
  for (const id of targetIds) {
    const still = after.find((i) => i.id === id);
    if (!still) report.succeeded.push(id);
    else if (still.status === 'failed') report.failed.push({ id, error: still.lastError ?? 'Unknown error' });
    else if (still.status === 'pending') report.failed.push({ id, error: still.lastError ?? 'Will retry' });
  }
  return report;
}

/* ── Last successful sync timestamp ─────────────────────────────────────── */

let lastSyncCache: string | null = null;
const lastSyncListeners = new Set<(iso: string | null) => void>();

export async function getLastSyncAt(): Promise<string | null> {
  if (lastSyncCache) return lastSyncCache;
  await migrateFromLegacy();
  lastSyncCache = await dbMetaGet(LAST_SYNC_KEY);
  return lastSyncCache;
}

async function markSyncedNow(): Promise<void> {
  lastSyncCache = new Date().toISOString();
  await dbMetaSet(LAST_SYNC_KEY, lastSyncCache);
  for (const fn of lastSyncListeners) fn(lastSyncCache);
}

export function subscribeLastSync(fn: (iso: string | null) => void): () => void {
  lastSyncListeners.add(fn);
  void getLastSyncAt().then(fn);
  return () => { lastSyncListeners.delete(fn); };
}

/* ── Payload → API postings ─────────────────────────────────────────────── */

interface Posting {
  client_id: string;
  operation_type: OutboxOperationType;
  payload: Record<string, unknown>;
  submitted_at: string;
  device_info?: Record<string, unknown>;
}

function buildPostings(item: OutboxRecord): Posting[] {
  const p = item.payload;
  const date = (p.operation_date ?? p.date) as string;
  const plot_id = p.plot_id as string;
  const submitted_at = item.createdAt;
  const device_info = { platform, ua: typeof navigator !== 'undefined' ? navigator.userAgent : '' };

  switch (item.type) {
    case 'irrigation':
      return [{
        client_id: item.id,
        operation_type: 'irrigation',
        payload: { plot_id, operation_date: date, water_quantity: Number(p.water_quantity ?? p.water_quantity_m3 ?? 0) },
        submitted_at, device_info,
      }];
    case 'harvest':
      return [{
        client_id: item.id,
        operation_type: 'harvest',
        payload: {
          plot_id, operation_date: date,
          num_workers: Number(p.num_workers ?? 0),
          days_worked: Number(p.days_worked ?? 1),
          quantity_harvested: Number(p.quantity_harvested ?? p.quantity_kg ?? 0),
        },
        submitted_at, device_info,
      }];
    case 'fertilization': {
      const rows = (p.items as Array<Record<string, unknown>>) ?? [];
      return rows.map((it, idx) => ({
        client_id: `${item.id}:${idx}`,
        operation_type: 'fertilization' as const,
        payload: {
          plot_id, operation_date: date,
          fertilizer_id: it.fertilizer_id as string,
          quantity_applied: Number(it.quantity_applied ?? it.quantity ?? 0),
        },
        submitted_at, device_info,
      }));
    }
    case 'phytosanitary': {
      const rows = (p.items as Array<Record<string, unknown>>) ?? [];
      const remarks = (p.remarks ?? null) as string | null;
      const waterVolume = Number(p.water_total_l ?? p.water_volume_l ?? 0);
      return rows.map((it, idx) => ({
        client_id: `${item.id}:${idx}`,
        operation_type: 'phytosanitary' as const,
        payload: {
          plot_id, operation_date: date,
          pesticide_id: it.pesticide_id as string,
          quantity_applied: Number(it.quantity_applied ?? it.quantity ?? 0),
          water_volume_l: Number(it.water_volume_l ?? waterVolume),
          // Per-pesticide bioagresseur, falling back to a treatment-wide one.
          target_pest: (it.target_pest ?? p.target_pest ?? null) as string | null,
          remarks,
        },
        submitted_at, device_info,
      }));
    }
  }
}

/* ── Sender ─────────────────────────────────────────────────────────────── */

async function sendOne(posting: Posting): Promise<void> {
  try {
    await api.post('/postings', posting);
    return;
  } catch (err) {
    if (axios.isAxiosError(err)) {
      const status = err.response?.status;
      if (status === 404 || status === 405) {
        await api.post(`/${posting.operation_type}-operations`, posting.payload);
        return;
      }
    }
    throw err;
  }
}

async function sendItem(item: OutboxRecord): Promise<void> {
  const postings = buildPostings(item);
  if (postings.length === 0) return;
  for (const posting of postings) await sendOne(posting);
}

function isUnrecoverable(err: unknown): boolean {
  const api = toApiError(err);
  return api instanceof ApiError && api.status >= 400 && api.status < 500 && api.status !== 408 && api.status !== 429;
}

/* ── Flush loop ─────────────────────────────────────────────────────────── */

let flushing = false;

export async function flushOutbox(): Promise<{ sent: number; failed: number; remaining: number }> {
  if (flushing) return { sent: 0, failed: 0, remaining: 0 };
  flushing = true;
  try {
    const net = await getNetworkStatus();
    if (!net.connected) {
      void logDiag('info', 'queue', 'flush.skipped offline');
      return { sent: 0, failed: 0, remaining: (await readAll()).length };
    }

    let items = await readAll();
    let sent = 0;
    let failed = 0;
    const now = Date.now();

    const queue = items.filter((i) => i.status === 'pending' && (i.nextAttemptAt ?? 0) <= now);
    if (queue.length > 0) void logDiag('info', 'queue', 'flush.start', { count: queue.length });

    for (const item of queue) {
      item.status = 'syncing';
      await persistOne(item);
      try {
        await sendItem(item);
        await removeOne(item.id);
        items = items.filter((i) => i.id !== item.id);
        sent += 1;
        void logDiag('info', 'queue', 'item.sent', { id: item.id, type: item.type });
      } catch (err) {
        item.attempts += 1;
        const apiErr = toApiError(err);
        item.lastError = apiErr.message;
        const dead = isUnrecoverable(err) || item.attempts >= MAX_RETRIES;
        item.status = dead ? 'failed' : 'pending';
        if (!dead) {
          const delay = Math.min(RETRY_CAP_MS, RETRY_BASE_MS * 2 ** (item.attempts - 1));
          item.nextAttemptAt = Date.now() + delay;
        }
        failed += 1;
        await persistOne(item);
        void logDiag(dead ? 'error' : 'warn', 'queue', dead ? 'item.failed' : 'item.retry', {
          id: item.id, type: item.type, attempts: item.attempts, error: apiErr.message,
        });
      }
    }
    if (sent > 0) await markSyncedNow();
    if (queue.length > 0) void logDiag('info', 'queue', 'flush.done', { sent, failed });
    return { sent, failed, remaining: (await readAll()).length };
  } finally {
    flushing = false;
  }
}

/* ── Auto-flush wiring ──────────────────────────────────────────────────── */

let started = false;
let netUnsub: { remove: () => void } | null = null;
let timer: ReturnType<typeof setInterval> | null = null;
let swMsgHandler: ((e: MessageEvent) => void) | null = null;

export function startOfflineQueue(): () => void {
  if (started) return () => { /* noop */ };
  started = true;
  void flushOutbox().catch(() => {});
  void onNetworkChange((connected) => {
    void logDiag('info', 'app', connected ? 'network.online' : 'network.offline');
    if (connected) {
      void registerBackgroundSync();
      void flushOutbox().catch(() => {});
    }
  }).then((h) => { netUnsub = h; });
  timer = setInterval(() => { void flushOutbox().catch(() => {}); }, 30_000);
  // Background → foreground replay (Capacitor App + web visibility).
  let appUnsub: { remove: () => void } | null = null;
  if (platform !== 'web') {
    void import('@capacitor/app')
      .then(({ App }) => App.addListener('appStateChange', (s) => {
        if (s.isActive) void flushOutbox().catch(() => {});
      }))
      .then((h) => { appUnsub = { remove: () => { void h.remove(); } }; })
      .catch(() => { /* plugin missing */ });
  }
  const onVis = () => { if (document.visibilityState === 'visible') void flushOutbox().catch(() => {}); };
  if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVis);
  // The service worker may have flushed items while we were closed — refresh
  // our in-memory cache so the UI reflects the truth on the next read.
  if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
    swMsgHandler = (e: MessageEvent) => {
      const data = e.data as { type?: string } | undefined;
      if (data?.type === 'agri-sync:outbox-changed') {
        void logDiag('info', 'sw', 'sw.notify outbox-changed');
        cached = null;
        void readAll().then(emit);
      }
    };
    navigator.serviceWorker.addEventListener('message', swMsgHandler);
  }
  return () => {
    started = false;
    netUnsub?.remove();
    appUnsub?.remove();
    if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVis);
    if (timer) clearInterval(timer);
    if (swMsgHandler && typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
      navigator.serviceWorker.removeEventListener('message', swMsgHandler);
    }
  };
}
