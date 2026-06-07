/// <reference lib="webworker" />
/* eslint-disable no-restricted-globals */
/**
 * Agri-Sync service worker.
 *
 * - Precaches the Vite build manifest so the shell loads offline.
 * - NetworkFirst for HTML navigations so deploys are picked up (3s timeout
 *   then served from cache).
 * - StaleWhileRevalidate for hashed assets.
 * - For POST /api/postings: NetworkOnly with a Workbox BackgroundSyncPlugin
 *   that auto-replays failed network submissions when connectivity returns,
 *   even if the app tab is closed.
 * - Listens for the `agri-sync-outbox` Sync event and replays any items the
 *   app left in IndexedDB before being closed.
 *
 * The IndexedDB schema is kept in sync with `src/lib/db.ts`.
 */
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { registerRoute, setCatchHandler } from 'workbox-routing';
import { NetworkFirst, NetworkOnly, StaleWhileRevalidate } from 'workbox-strategies';
import { BackgroundSyncPlugin } from 'workbox-background-sync';
import { clientsClaim } from 'workbox-core';
import { openDB } from 'idb';

declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: Array<{ url: string; revision: string | null }>;
};

self.skipWaiting();
clientsClaim();
cleanupOutdatedCaches();
precacheAndRoute(self.__WB_MANIFEST || []);

const SYNC_TAG = 'agri-sync-outbox';
const DB_NAME = 'agri-sync';
const DB_VERSION = 2;
const STORE_OUTBOX = 'outbox';
const STORE_META = 'meta';
const STORE_DIAGNOSTICS = 'diagnostics';
const DIAG_MAX = 300;
const TOKEN_KEY = 'agri-sync.auth.token';
const LAST_SYNC_KEY = 'outbox.lastSyncAt';
const LAST_SYNC_ERROR_KEY = 'sync.lastError';
const LAST_SW_WAKE_KEY = 'sw.lastWakeAt';
const SW_WAKE_COUNT_KEY = 'sw.wakeCount';

async function diag(level: 'info' | 'warn' | 'error', message: string, meta?: Record<string, unknown>) {
  const tag = `[agri-sync:sw]`;
  // eslint-disable-next-line no-console
  (level === 'error' ? console.error : level === 'warn' ? console.warn : console.log)(tag, message, meta ?? '');
  try {
    const db = await getDB();
    const tx = db.transaction(STORE_DIAGNOSTICS, 'readwrite');
    await tx.store.add({ ts: new Date().toISOString(), level, source: 'sw', message, meta });
    const count = await tx.store.count();
    if (count > DIAG_MAX) {
      let cursor = await tx.store.openCursor();
      let removed = 0;
      const excess = count - DIAG_MAX;
      while (cursor && removed < excess) {
        await cursor.delete();
        removed += 1;
        cursor = await cursor.continue();
      }
    }
    await tx.done;
    if (level === 'error') {
      await db.put(STORE_META, {
        key: LAST_SYNC_ERROR_KEY,
        value: JSON.stringify({ ts: new Date().toISOString(), message, meta }),
      });
    }
  } catch { /* ignore */ }
}

/* ── HTML + assets ──────────────────────────────────────────────────────── */

registerRoute(
  ({ request }) => request.mode === 'navigate',
  new NetworkFirst({ cacheName: 'html', networkTimeoutSeconds: 3 }),
);

registerRoute(
  ({ request }) =>
    request.destination === 'style' ||
    request.destination === 'script' ||
    request.destination === 'worker' ||
    request.destination === 'font' ||
    request.destination === 'image',
  new StaleWhileRevalidate({ cacheName: 'assets' }),
);

/* ── Background Sync for POST /api/postings ─────────────────────────────── */

const postingsBgSync = new BackgroundSyncPlugin('agri-sync-postings', {
  maxRetentionTime: 24 * 60, // keep failed posts for 24h
});

registerRoute(
  ({ url, request }) => request.method === 'POST' && /\/api\/postings\/?$/.test(url.pathname),
  new NetworkOnly({ plugins: [postingsBgSync] }),
  'POST',
);

// Fallback for legacy per-operation endpoints (mobile may fall back to them).
const opEndpointBgSync = new BackgroundSyncPlugin('agri-sync-op-endpoints', {
  maxRetentionTime: 24 * 60,
});
registerRoute(
  ({ url, request }) =>
    request.method === 'POST' &&
    /\/api\/(irrigation|fertilization|phytosanitary|harvest)-operations\/?$/.test(url.pathname),
  new NetworkOnly({ plugins: [opEndpointBgSync] }),
  'POST',
);

/* ── IndexedDB outbox replay (app-managed queue) ───────────────────────── */

type OutboxRecord = {
  id: string;
  type: 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';
  payload: Record<string, unknown>;
  createdAt: string;
  attempts: number;
  lastError?: string;
  status: 'pending' | 'syncing' | 'failed';
  nextAttemptAt?: number;
};

function getDB() {
  return openDB(DB_NAME, DB_VERSION, {
    upgrade(db, oldVersion) {
      if (!db.objectStoreNames.contains(STORE_OUTBOX)) {
        const s = db.createObjectStore(STORE_OUTBOX, { keyPath: 'id' });
        s.createIndex('by-status', 'status');
        s.createIndex('by-nextAttemptAt', 'nextAttemptAt');
      }
      if (!db.objectStoreNames.contains('reference')) db.createObjectStore('reference', { keyPath: 'key' });
      if (!db.objectStoreNames.contains(STORE_META)) db.createObjectStore(STORE_META, { keyPath: 'key' });
      if (oldVersion < 2 && !db.objectStoreNames.contains(STORE_DIAGNOSTICS)) {
        const s = db.createObjectStore(STORE_DIAGNOSTICS, { keyPath: 'id', autoIncrement: true });
        s.createIndex('by-ts', 'ts');
      }
    },
  });
}

async function getToken(): Promise<string | null> {
  try {
    const db = await getDB();
    const rec = await db.get(STORE_META, TOKEN_KEY);
    if (rec?.value) return rec.value as string;
  } catch { /* fall through */ }
  return null;
}

interface Posting {
  client_id: string;
  operation_type: OutboxRecord['type'];
  payload: Record<string, unknown>;
  submitted_at: string;
}

function buildPostings(item: OutboxRecord): Posting[] {
  const p = item.payload;
  const date = (p.operation_date ?? p.date) as string;
  const plot_id = p.plot_id as string;
  const submitted_at = item.createdAt;
  switch (item.type) {
    case 'irrigation':
      return [{
        client_id: item.id, operation_type: 'irrigation',
        payload: { plot_id, operation_date: date, water_quantity: Number(p.water_quantity ?? 0) },
        submitted_at,
      }];
    case 'harvest':
      return [{
        client_id: item.id, operation_type: 'harvest',
        payload: {
          plot_id, operation_date: date,
          num_workers: Number(p.num_workers ?? 0),
          days_worked: Number(p.days_worked ?? 1),
          quantity_harvested: Number(p.quantity_harvested ?? 0),
        },
        submitted_at,
      }];
    case 'fertilization': {
      const rows = (p.items as Array<Record<string, unknown>>) ?? [];
      return rows.map((it, idx) => ({
        client_id: `${item.id}:${idx}`, operation_type: 'fertilization' as const,
        payload: {
          plot_id, operation_date: date,
          fertilizer_id: it.fertilizer_id as string,
          quantity_applied: Number(it.quantity_applied ?? it.quantity ?? 0),
        },
        submitted_at,
      }));
    }
    case 'phytosanitary': {
      const rows = (p.items as Array<Record<string, unknown>>) ?? [];
      const target_pest = (p.target_pest ?? null) as string | null;
      const remarks = (p.remarks ?? null) as string | null;
      return rows.map((it, idx) => ({
        client_id: `${item.id}:${idx}`, operation_type: 'phytosanitary' as const,
        payload: {
          plot_id, operation_date: date,
          pesticide_id: it.pesticide_id as string,
          quantity_applied: Number(it.quantity_applied ?? it.quantity ?? 0),
          target_pest, remarks,
        },
        submitted_at,
      }));
    }
  }
}

async function postPosting(posting: Posting, token: string | null): Promise<Response> {
  return fetch('/api/postings', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify(posting),
  });
}

async function notifyClients(): Promise<void> {
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  for (const c of clients) c.postMessage({ type: 'agri-sync:outbox-changed' });
}

async function replayOutboxFromDB(reason: string): Promise<void> {
  const db = await getDB();
  const items = (await db.getAll(STORE_OUTBOX)) as OutboxRecord[];
  if (items.length === 0) { void diag('info', 'sw.replay empty', { reason }); return; }
  void diag('info', 'sw.replay start', { reason, count: items.length });
  const token = await getToken();
  const now = Date.now();
  let touched = false;
  let sentAny = false;
  let sent = 0;
  let failed = 0;

  for (const item of items) {
    if (item.status === 'failed') continue;
    if (item.status === 'pending' && (item.nextAttemptAt ?? 0) > now) continue;
    const postings = buildPostings(item);
    let allOk = true;
    let lastError: string | undefined;
    for (const posting of postings) {
      try {
        const res = await postPosting(posting, token);
        if (!res.ok && res.status !== 409) {
          allOk = false;
          lastError = `HTTP ${res.status}`;
          break;
        }
      } catch (e) {
        allOk = false;
        lastError = (e as Error)?.message ?? 'network';
        break;
      }
    }
    if (allOk) {
      await db.delete(STORE_OUTBOX, item.id);
      sentAny = true;
      touched = true;
      sent += 1;
    } else {
      item.attempts = (item.attempts ?? 0) + 1;
      item.lastError = lastError;
      item.status = item.attempts >= 5 ? 'failed' : 'pending';
      const delay = Math.min(60_000, 2_000 * 2 ** Math.max(0, item.attempts - 1));
      item.nextAttemptAt = Date.now() + delay;
      await db.put(STORE_OUTBOX, item);
      touched = true;
      failed += 1;
      void diag(item.status === 'failed' ? 'error' : 'warn',
        item.status === 'failed' ? 'sw.item failed' : 'sw.item retry',
        { id: item.id, type: item.type, attempts: item.attempts, error: lastError });
    }
  }

  if (sentAny) {
    await db.put(STORE_META, { key: LAST_SYNC_KEY, value: new Date().toISOString() });
  }
  if (touched) await notifyClients();
  void diag('info', 'sw.replay done', { reason, sent, failed });
}

async function recordWake(reason: string): Promise<void> {
  try {
    const db = await getDB();
    const prev = await db.get(STORE_META, SW_WAKE_COUNT_KEY);
    const next = (prev?.value ? Number(prev.value) || 0 : 0) + 1;
    await db.put(STORE_META, { key: SW_WAKE_COUNT_KEY, value: String(next) });
    await db.put(STORE_META, { key: LAST_SW_WAKE_KEY, value: new Date().toISOString() });
    void diag('info', 'sw.wake', { reason, count: next });
  } catch { /* ignore */ }
}

self.addEventListener('sync', (event: Event) => {
  const evt = event as Event & { tag: string; waitUntil: (p: Promise<unknown>) => void };
  if (evt.tag === SYNC_TAG) {
    evt.waitUntil((async () => {
      await recordWake('sync-event');
      await replayOutboxFromDB('sync-event').catch((e) => diag('error', 'sw.replay crash', { error: String(e) }));
    })());
  }
});

self.addEventListener('message', (event) => {
  const data = event.data as { type?: string } | undefined;
  if (data?.type === 'agri-sync:flush-now') {
    event.waitUntil?.(replayOutboxFromDB('message').catch((e) => diag('error', 'sw.replay crash', { error: String(e) })));
  }
  if (data?.type === 'SKIP_WAITING') self.skipWaiting();
});

setCatchHandler(async () => Response.error());
