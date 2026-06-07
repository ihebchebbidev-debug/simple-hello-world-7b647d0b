import { useQuery } from '@tanstack/react-query';
import { loadReferences, type ReferenceBundle } from '@/lib/referenceCache';

const EMPTY: ReferenceBundle = { plots: [], fertilizers: [], pesticides: [], pests: [], fetchedAt: new Date(0).toISOString() };

export function useReferenceData() {
  const q = useQuery({
    queryKey: ['references'],
    queryFn: loadReferences,
    staleTime: 5 * 60_000,
    retry: 1,
  });
  return { data: q.data ?? EMPTY, isLoading: q.isLoading, refetch: q.refetch, error: q.error };
}
