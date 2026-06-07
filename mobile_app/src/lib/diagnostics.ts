/**
 * Lightweight diagnostics logger for the mobile app.
 * - Persists structured entries to IndexedDB (capped, see DIAG_MAX_ENTRIES).
 * - Mirrors entries to console with an `[agri-sync:<source>]` prefix so they
 *   show up in Chrome DevTools / Safari Web Inspector while debugging.
 * - The service worker writes its own entries directly via `dbDiagAdd` to
 *   avoid pulling app-only imports — schema stays in sync via db.ts.
 */
import {
  dbDiagAdd, dbDiagAll, dbDiagClear, dbMetaGet, dbMetaSet,
  META_LAST_SYNC_ERROR, META_LAST_SYNC_AT, META_LAST_SW_WAKE, META_SW_WAKE_COUNT,
  type DiagLevel, type DiagSource, type DiagnosticRecord,
} from './db';

export type { DiagLevel, DiagSource, DiagnosticRecord };

type Listener = () => void;
const listeners = new Set<Listener>();
function emit() { for (const fn of listeners) fn(); }

export function subscribeDiagnostics(fn: Listener): () => void {
  listeners.add(fn);
  return () => { listeners.delete(fn); };
}

export async function logDiag(
  level: DiagLevel,
  source: DiagSource,
  message: string,
  meta?: Record<string, unknown>,
): Promise<void> {
  const entry = { ts: new Date().toISOString(), level, source, message, meta };
  const tag = `[agri-sync:${source}]`;
  // eslint-disable-next-line no-console
  const fn = level === 'error' ? console.error : level === 'warn' ? console.warn : console.log;
  fn(tag, message, meta ?? '');
  try {
    await dbDiagAdd(entry);
    if (level === 'error') {
      await dbMetaSet(META_LAST_SYNC_ERROR, JSON.stringify({ ts: entry.ts, message, meta }));
    }
    emit();
  } catch { /* IDB unavailable — console-only is acceptable */ }
}

export async function getDiagnostics(): Promise<DiagnosticRecord[]> { return dbDiagAll(); }
export async function clearDiagnostics(): Promise<void> { await dbDiagClear(); emit(); }

export interface SyncSnapshot {
  lastSyncAt: string | null;
  lastError: { ts: string; message: string; meta?: unknown } | null;
  lastSwWakeAt: string | null;
  swWakeCount: number;
}

export async function getSyncSnapshot(): Promise<SyncSnapshot> {
  const [lastSyncAt, lastErrRaw, lastSwWakeAt, wakeCountRaw] = await Promise.all([
    dbMetaGet(META_LAST_SYNC_AT),
    dbMetaGet(META_LAST_SYNC_ERROR),
    dbMetaGet(META_LAST_SW_WAKE),
    dbMetaGet(META_SW_WAKE_COUNT),
  ]);
  let lastError: SyncSnapshot['lastError'] = null;
  if (lastErrRaw) {
    try { lastError = JSON.parse(lastErrRaw); } catch { lastError = { ts: '', message: lastErrRaw }; }
  }
  return {
    lastSyncAt,
    lastError,
    lastSwWakeAt,
    swWakeCount: wakeCountRaw ? Number(wakeCountRaw) || 0 : 0,
  };
}

export async function clearLastSyncError(): Promise<void> {
  await dbMetaSet(META_LAST_SYNC_ERROR, '');
  emit();
}
