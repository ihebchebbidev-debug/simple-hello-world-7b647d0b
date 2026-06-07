import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface PlotLite {
  id: string;
  name: string;
  surface_area_ha: number;
  crop_type?: string | null;
  variety?: string | null;
  season_start_date?: string | null;
  is_active?: boolean;
}

export function usePlotsForFilter() {
  return useQuery({
    queryKey: ['report-filter-plots'],
    queryFn: async () => {
      const { data } = await api.get<{ data: PlotLite[] }>('/plots', {
        params: { per_page: 100 },
      });
      const payload = (data as unknown as { data?: PlotLite[] }).data;
      return Array.isArray(payload) ? payload : [];
    },
    staleTime: 5 * 60 * 1000,
  });
}
