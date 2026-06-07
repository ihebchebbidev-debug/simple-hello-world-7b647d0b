/**
 * IndexedDB layer shared by the outbox, the reference cache, and the
 * service worker. Wraps `idb` with a typed schema and best-effort migration
 * from the legacy Capacitor Preferences / localStorage records.
 *
 * Stores:
 *  - outbox     : queued operation submissions  (keyPath 'id')
 *  - reference  : single 'bundle' record         (keyPath 'key')
 *  - meta       : misc kv (lastSyncAt, …)         (keyPath 'key')
 *
 * The DB is also opened from the service worker (`src/sw.ts`) — the schema
 * MUST stay in sync with it.
 */
import { openDB, type IDBPDatabase, type DBSchema } from 'idb';

export const DB_NAME = 'agri-sync';
export const DB_VERSION = 3;

export const STORE_OUTBOX = 'outbox' as const;
export const STORE_REFERENCE = 'reference' as const;
export const STORE_META = 'meta' as const;
export const STORE_DIAGNOSTICS = 'diagnostics' as const;
export const STORE_RECENT = 'recent' as const;

export const DIAG_MAX_ENTRIES = 300;
export const RECENT_MAX_PER_TYPE = 20;

export interface OutboxRecord {
  id: string;
  type: 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';
  payload: Record<string, unknown>;
  createdAt: string;
  attempts: number;
  lastError?: string;
  status: 'pending' | 'syncing' | 'failed';
  nextAttemptAt?: number;
}

export interface ReferenceRecord {
  key: 'bundle';
  value: unknown;
  updatedAt: string;
}

export interface MetaRecord {
  key: string;
  value: string;
}

export type DiagLevel = 'info' | 'warn' | 'error';
export type DiagSource = 'app' | 'sw' | 'queue' | 'sync';

export interface DiagnosticRecord {
  id: number;            // autoincrement
  ts: string;            // ISO timestamp
  level: DiagLevel;
  source: DiagSource;
  message: string;
  meta?: Record<string, unknown>;
}

export type RecentOperationType = OutboxRecord['type'];

export interface RecentEntryRecord {
  /** `${type}:${id}` — server id when synced, outbox id when local-only. */
  key: string;
  type: RecentOperationType;
  /** ISO date of the operation (operation_date). Sort key. */
  operationDate: string;
  /** Server id when present; null for local-only outbox entries. */
  serverId: string | null;
  /** Source of truth: cached server payload vs. our outbox payload. */
  source: 'server' | 'outbox';
  /** Arbitrary payload kept for rendering (plot_id, quantities, etc.). */
  payload: Record<string, unknown>;
  /** When this row was last updated locally. */
  updatedAt: string;
}

interface AgriDB extends DBSchema {
  outbox: {
    key: string;
    value: OutboxRecord;
    indexes: { 'by-status': string; 'by-nextAttemptAt': number };
  };
  reference: { key: string; value: ReferenceRecord };
  meta: { key: string; value: MetaRecord };
  diagnostics: {
    key: number;
    value: DiagnosticRecord;
    indexes: { 'by-ts': string };
  };
  recent: {
    key: string;
    value: RecentEntryRecord;
    indexes: { 'by-type': string; 'by-type-date': [string, string] };
  };
}

let dbPromise: Promise<IDBPDatabase<AgriDB>> | null = null;

export function getDB(): Promise<IDBPDatabase<AgriDB>> {
  if (!dbPromise) {
    dbPromise = openDB<AgriDB>(DB_NAME, DB_VERSION, {
      upgrade(db, oldVersion) {
        if (!db.objectStoreNames.contains(STORE_OUTBOX)) {
          const s = db.createObjectStore(STORE_OUTBOX, { keyPath: 'id' });
          s.createIndex('by-status', 'status');
          s.createIndex('by-nextAttemptAt', 'nextAttemptAt');
        }
        if (!db.objectStoreNames.contains(STORE_REFERENCE)) {
          db.createObjectStore(STORE_REFERENCE, { keyPath: 'key' });
        }
        if (!db.objectStoreNames.contains(STORE_META)) {
          db.createObjectStore(STORE_META, { keyPath: 'key' });
        }
        if (oldVersion < 2 && !db.objectStoreNames.contains(STORE_DIAGNOSTICS)) {
          const s = db.createObjectStore(STORE_DIAGNOSTICS, { keyPath: 'id', autoIncrement: true });
          s.createIndex('by-ts', 'ts');
        }
        if (oldVersion < 3 && !db.objectStoreNames.contains(STORE_RECENT)) {
          const s = db.createObjectStore(STORE_RECENT, { keyPath: 'key' });
          s.createIndex('by-type', 'type');
          s.createIndex('by-type-date', ['type', 'operationDate']);
        }
      },
    });
  }
  return dbPromise;
}

/* ── Outbox helpers ─────────────────────────────────────────────────────── */

export async function dbOutboxAll(): Promise<OutboxRecord[]> {
  const db = await getDB();
  return db.getAll(STORE_OUTBOX);
}

export async function dbOutboxPut(item: OutboxRecord): Promise<void> {
  const db = await getDB();
  await db.put(STORE_OUTBOX, item);
}

export async function dbOutboxPutMany(items: OutboxRecord[]): Promise<void> {
  const db = await getDB();
  const tx = db.transaction(STORE_OUTBOX, 'readwrite');
  await Promise.all(items.map((i) => tx.store.put(i)));
  await tx.done;
}

export async function dbOutboxDelete(id: string): Promise<void> {
  const db = await getDB();
  await db.delete(STORE_OUTBOX, id);
}

export async function dbOutboxClear(): Promise<void> {
  const db = await getDB();
  await db.clear(STORE_OUTBOX);
}

/* ── Reference helpers ──────────────────────────────────────────────────── */

export async function dbReferenceGet<T = unknown>(): Promise<T | null> {
  const db = await getDB();
  const rec = await db.get(STORE_REFERENCE, 'bundle');
  return (rec?.value as T) ?? null;
}

export async function dbReferenceSet(value: unknown): Promise<void> {
  const db = await getDB();
  await db.put(STORE_REFERENCE, { key: 'bundle', value, updatedAt: new Date().toISOString() });
}

export async function dbReferenceClear(): Promise<void> {
  const db = await getDB();
  await db.delete(STORE_REFERENCE, 'bundle');
}

/* ── Meta helpers ───────────────────────────────────────────────────────── */

export async function dbMetaGet(key: string): Promise<string | null> {
  const db = await getDB();
  const rec = await db.get(STORE_META, key);
  return rec?.value ?? null;
}

export async function dbMetaSet(key: string, value: string): Promise<void> {
  const db = await getDB();
  await db.put(STORE_META, { key, value });
}

/* ── Diagnostics helpers ────────────────────────────────────────────────── */

export const META_LAST_SYNC_ERROR = 'sync.lastError';
export const META_LAST_SYNC_AT = 'outbox.lastSyncAt';
export const META_LAST_SW_WAKE = 'sw.lastWakeAt';
export const META_SW_WAKE_COUNT = 'sw.wakeCount';

export async function dbDiagAdd(entry: Omit<DiagnosticRecord, 'id'>): Promise<void> {
  const db = await getDB();
  const tx = db.transaction(STORE_DIAGNOSTICS, 'readwrite');
  await tx.store.add(entry as DiagnosticRecord);
  // Cap: drop oldest if over DIAG_MAX_ENTRIES
  const count = await tx.store.count();
  if (count > DIAG_MAX_ENTRIES) {
    const excess = count - DIAG_MAX_ENTRIES;
    let cursor = await tx.store.openCursor();
    let removed = 0;
    while (cursor && removed < excess) {
      await cursor.delete();
      removed += 1;
      cursor = await cursor.continue();
    }
  }
  await tx.done;
}

export async function dbDiagAll(): Promise<DiagnosticRecord[]> {
  const db = await getDB();
  const all = await db.getAll(STORE_DIAGNOSTICS);
  return all.sort((a, b) => (a.ts < b.ts ? 1 : -1));
}

export async function dbDiagClear(): Promise<void> {
  const db = await getDB();
  await db.clear(STORE_DIAGNOSTICS);
}

/* ── Recent-entries helpers ─────────────────────────────────────────────── */

export async function dbRecentByType(type: RecentOperationType): Promise<RecentEntryRecord[]> {
  const db = await getDB();
  const rows = await db.getAllFromIndex(STORE_RECENT, 'by-type', type);
  return (rows as RecentEntryRecord[]).sort((a: RecentEntryRecord, b: RecentEntryRecord) =>
    a.operationDate < b.operationDate ? 1 : -1,
  );
}

export async function dbRecentPut(entry: RecentEntryRecord): Promise<void> {
  const db = await getDB();
  await db.put(STORE_RECENT, entry);
}

export async function dbRecentPutMany(entries: RecentEntryRecord[]): Promise<void> {
  if (!entries.length) return;
  const db = await getDB();
  const tx = db.transaction(STORE_RECENT, 'readwrite');
  await Promise.all(entries.map((e) => tx.store.put(e)));
  await tx.done;
}

export async function dbRecentDelete(key: string): Promise<void> {
  const db = await getDB();
  await db.delete(STORE_RECENT, key);
}

/** Trim a single type's rows down to RECENT_MAX_PER_TYPE most recent. */
export async function dbRecentTrim(type: RecentOperationType): Promise<void> {
  const rows = await dbRecentByType(type);
  if (rows.length <= RECENT_MAX_PER_TYPE) return;
  const db = await getDB();
  const tx = db.transaction(STORE_RECENT, 'readwrite');
  await Promise.all(rows.slice(RECENT_MAX_PER_TYPE).map((r) => tx.store.delete(r.key)));
  await tx.done;
}

/**
 * Replace the server-sourced rows for a given type with `entries`, keeping
 * any outbox-sourced (local-only) rows untouched. Used after a successful
 * fetch so deletions on the server propagate to the cache.
 */
export async function dbRecentReplaceServer(
  type: RecentOperationType,
  entries: RecentEntryRecord[],
): Promise<void> {
  const db = await getDB();
  const existing = await db.getAllFromIndex(STORE_RECENT, 'by-type', type);
  const tx = db.transaction(STORE_RECENT, 'readwrite');
  await Promise.all(
    (existing as RecentEntryRecord[])
      .filter((r: RecentEntryRecord) => r.source === 'server')
      .map((r: RecentEntryRecord) => tx.store.delete(r.key)),
  );
  await Promise.all(entries.map((e) => tx.store.put(e)));
  await tx.done;
  await dbRecentTrim(type);
}
