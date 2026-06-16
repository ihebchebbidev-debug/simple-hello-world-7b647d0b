import { type QueryClient, dehydrate, hydrate } from '@tanstack/react-query';

declare const __BUILD_ID__: string;

/**
 * Lightweight localStorage persistence for the React Query cache.
 *
 * Goal: a reload paints INSTANTLY from the last cache instead of refetching
 * from scratch — WITHOUT ever showing stale data. The restored cache is only a
 * fast placeholder: the global config has `staleTime: 30s` + `refetchOnMount`,
 * so every restored query revalidates the moment its component mounts, and
 * mutations invalidate their keys. So deletes disappear and new rows appear as
 * soon as the (immediate) background refetch lands — your own changes are
 * instant, others' show within the refetch.
 *
 * Uses dehydrate/hydrate from the core package — no extra dependency.
 */

const KEY = 'flehty.rq-cache.v1';
const MAX_AGE = 1000 * 60 * 60 * 24; // hard cap: a snapshot older than 24h is dropped
const BUSTER = typeof __BUILD_ID__ !== 'undefined' ? __BUILD_ID__ : 'dev';

interface Persisted {
  buster: string;
  savedAt: number;
  state: ReturnType<typeof dehydrate>;
}

/** Hydrate the cache from localStorage (call once, right after creating the client). */
export function restoreQueryCache(qc: QueryClient): void {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return;
    const p = JSON.parse(raw) as Persisted;
    // Discard on a new deploy (shapes may have changed) or if it's too old.
    if (p.buster !== BUSTER || Date.now() - p.savedAt > MAX_AGE) {
      localStorage.removeItem(KEY);
      return;
    }
    hydrate(qc, p.state);
  } catch {
    try { localStorage.removeItem(KEY); } catch { /* ignore */ }
  }
}

/** Persist successful query data to localStorage (debounced). Returns an unsubscribe fn. */
export function startQueryPersist(qc: QueryClient): () => void {
  let timer: ReturnType<typeof setTimeout> | undefined;

  const save = () => {
    try {
      const state = dehydrate(qc, {
        shouldDehydrateQuery: (q) => {
          if (q.state.status !== 'success') return false;
          const key = q.queryKey[0];
          // Skip the large, very-volatile activity feed (up to 1000 rows) — it
          // refetches quickly and would bloat localStorage.
          if (key === 'admin-dashboard-activity') return false;
          return true;
        },
      });
      localStorage.setItem(KEY, JSON.stringify({ buster: BUSTER, savedAt: Date.now(), state } satisfies Persisted));
    } catch {
      /* quota exceeded / non-serialisable — skip this write */
    }
  };

  const unsub = qc.getQueryCache().subscribe(() => {
    clearTimeout(timer);
    timer = setTimeout(save, 1500);
  });

  return () => { clearTimeout(timer); unsub(); };
}
