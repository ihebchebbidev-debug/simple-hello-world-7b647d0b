import { useEffect, useState, useCallback } from 'react';
import {
  flushOutbox, removeFromOutbox, retryItem, retryFailed,
  subscribeOutbox, subscribeLastSync,
  type OutboxItem, type RetryReport,
} from '@/lib/offlineQueue';
import { getNetworkStatus, onNetworkChange } from '@/lib/native';

export function useOfflineQueue() {
  const [items, setItems] = useState<OutboxItem[]>([]);
  const [online, setOnline] = useState<boolean>(typeof navigator !== 'undefined' ? navigator.onLine : true);
  const [flushing, setFlushing] = useState(false);
  const [lastSyncAt, setLastSyncAt] = useState<string | null>(null);
  const [initialLoaded, setInitialLoaded] = useState(false);

  useEffect(() => {
    let netHandle: { remove: () => void } | null = null;
    let cancelled = false;
    void getNetworkStatus().then((s) => setOnline(s.connected));
    // Single source of truth: subscribeOutbox emits the IndexedDB snapshot as
    // its first value. Flip `initialLoaded` only then, so skeletons stay
    // visible until the real data lands and disappear automatically afterwards.
    const unsub = subscribeOutbox((next) => {
      if (cancelled) return;
      setItems(next);
      setInitialLoaded(true);
    });
    const unsubLast = subscribeLastSync(setLastSyncAt);
    void onNetworkChange((connected) => setOnline(connected)).then((h) => { netHandle = h; });
    return () => { cancelled = true; unsub(); unsubLast(); netHandle?.remove(); };
  }, []);

  const flushNow = useCallback(async () => {
    setFlushing(true);
    try { return await flushOutbox(); } finally { setFlushing(false); }
  }, []);

  const retryAllFailed = useCallback(async (): Promise<RetryReport> => {
    setFlushing(true);
    try { return await retryFailed(); } finally { setFlushing(false); }
  }, []);

  const retryOne = useCallback(async (id: string): Promise<RetryReport> => {
    setFlushing(true);
    try { return await retryFailed([id]); } finally { setFlushing(false); }
  }, []);

  return {
    items,
    pending: items.filter((i) => i.status === 'pending'),
    syncing: items.filter((i) => i.status === 'syncing'),
    failed: items.filter((i) => i.status === 'failed'),
    online,
    flushing,
    lastSyncAt,
    initialLoaded,
    flushNow,
    remove: useCallback((id: string) => removeFromOutbox(id), []),
    retry: useCallback((id: string) => retryItem(id), []),
    retryOne,
    retryAllFailed,
  };
}
