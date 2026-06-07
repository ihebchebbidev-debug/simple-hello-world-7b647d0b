import { useCallback, useEffect, useMemo, useState } from 'react';
import { fetchRecent, getCached } from '@/lib/recentEntries';
import type { RecentEntryRecord, RecentOperationType } from '@/lib/db';
import { useOfflineQueue } from './useOfflineQueue';

export interface RecentEntryView extends RecentEntryRecord {
  /** Status for UI badges: synced (server) / pending / failed. */
  status: 'synced' | 'pending' | 'failed';
}

/**
 * Recent entries for a single operation type, merged from three sources:
 *  - server cache (IndexedDB, refreshed when online)
 *  - outbox items (pending / failed local writes)
 * Outbox entries shadow server entries by date; the merged list is sorted
 * by operation_date desc and capped to 10.
 */
export function useRecentEntries(type: RecentOperationType) {
  const [cached, setCached] = useState<RecentEntryRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { items: outboxItems, online } = useOfflineQueue();

  const reload = useCallback(async () => {
    const rows = await getCached(type);
    setCached(rows);
  }, [type]);

  const refresh = useCallback(async () => {
    if (!online) { await reload(); return; }
    setRefreshing(true); setError(null);
    try {
      const rows = await fetchRecent(type);
      setCached(rows);
    } catch (e) {
      setError((e as Error).message);
      await reload();
    } finally {
      setRefreshing(false);
    }
  }, [type, online, reload]);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      await reload();
      if (cancelled) return;
      setLoading(false);
      if (online) void refresh();
    })();
    return () => { cancelled = true; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type]);

  // Merge: outbox (pending/failed for this type) + server cache.
  const merged: RecentEntryView[] = useMemo(() => {
    const localForType = outboxItems
      .filter((it) => it.type === type)
      .map<RecentEntryView>((it) => ({
        key: `${type}:outbox:${it.id}`,
        type,
        serverId: null,
        operationDate: String(
          (it.payload as Record<string, unknown>).operation_date ?? it.createdAt.slice(0, 10),
        ).slice(0, 10),
        source: 'outbox',
        payload: it.payload as Record<string, unknown>,
        updatedAt: it.createdAt,
        status: it.status === 'failed' ? 'failed' : 'pending',
      }));

    const serverViews: RecentEntryView[] = cached
      .filter((r) => r.source === 'server')
      .map((r) => ({ ...r, status: 'synced' }));

    return [...localForType, ...serverViews]
      .sort((a, b) =>
        a.operationDate === b.operationDate
          ? (a.updatedAt < b.updatedAt ? 1 : -1)
          : (a.operationDate < b.operationDate ? 1 : -1),
      )
      .slice(0, 10);
  }, [cached, outboxItems, type]);

  return { entries: merged, loading, refreshing, error, refresh, online };
}
