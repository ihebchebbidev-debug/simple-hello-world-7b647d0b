/**
 * Recent-entries cache for mobile operation pages.
 *
 * Goal: every operation form (irrigation, fertilization, phytosanitary,
 * harvest) shows the technician a short history of recent entries — even
 * when offline. The cache:
 *
 *   1. Fetches the latest N records from the API when online and persists
 *      them to IndexedDB (`recent` store, source = 'server').
 *   2. Falls back to the cached rows when offline / fetch fails.
 *   3. Mirrors local-only outbox entries (pending or failed) so the user
 *      sees their just-saved row immediately, before any sync round-trip.
 */
import { api, toApiError } from './api';
import {
  dbRecentByType, dbRecentPut, dbRecentReplaceServer,
  RECENT_MAX_PER_TYPE,
  type RecentEntryRecord, type RecentOperationType,
} from './db';
import type { OutboxRecord } from './db';

const ENDPOINTS: Record<RecentOperationType, string> = {
  irrigation:    '/irrigation-operations',
  fertilization: '/fertilization-operations',
  phytosanitary: '/phytosanitary-operations',
  harvest:       '/harvest-operations',
};

interface ServerRow {
  id: string | number;
  operation_date?: string;
  date?: string;
  [k: string]: unknown;
}

function unwrap<T>(data: unknown): T[] {
  if (Array.isArray(data)) return data as T[];
  const obj = data as Record<string, unknown> | undefined;
  if (Array.isArray(obj?.data)) return obj!.data as T[];
  if (Array.isArray((obj?.data as Record<string, unknown>)?.data)) {
    return (obj!.data as Record<string, unknown>).data as T[];
  }
  return [];
}

function pickDate(row: ServerRow): string {
  const d = row.operation_date ?? row.date ?? '';
  return String(d).slice(0, 10);
}

/**
 * Fetch the latest entries for one operation type and refresh the cache.
 * Throws on network failure — callers should fall back to `getCached`.
 */
export async function fetchRecent(type: RecentOperationType): Promise<RecentEntryRecord[]> {
  try {
    const { data } = await api.get(ENDPOINTS[type], {
      params: {
        per_page: RECENT_MAX_PER_TYPE,
        sort: '-operation_date',
        order: 'desc',
      },
    });
    const rows = unwrap<ServerRow>(data);
    const now = new Date().toISOString();
    const mapped: RecentEntryRecord[] = rows.map((r) => ({
      key: `${type}:server:${r.id}`,
      type,
      serverId: String(r.id),
      operationDate: pickDate(r) || now.slice(0, 10),
      source: 'server',
      payload: r as Record<string, unknown>,
      updatedAt: now,
    }));
    await dbRecentReplaceServer(type, mapped);
    return mapped;
  } catch (err) {
    throw toApiError(err, 'Unable to fetch recent entries');
  }
}

export async function getCached(type: RecentOperationType): Promise<RecentEntryRecord[]> {
  return dbRecentByType(type);
}

/** Record a freshly-enqueued outbox item so the form's history shows it instantly. */
export async function recordOutboxEntry(item: OutboxRecord): Promise<void> {
  const payload = item.payload as Record<string, unknown>;
  const opDate = String(payload.operation_date ?? new Date().toISOString().slice(0, 10)).slice(0, 10);
  await dbRecentPut({
    key: `${item.type}:outbox:${item.id}`,
    type: item.type,
    serverId: null,
    operationDate: opDate,
    source: 'outbox',
    payload,
    updatedAt: new Date().toISOString(),
  });
}
