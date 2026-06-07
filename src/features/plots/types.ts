export interface AdminPlot {
  id: string;
  name: string;
  surface_area_ha: number;
  crop_type: string | null;
  variety: string | null;
  season_start_date: string | null; // YYYY-MM-DD
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface PaginatedPlots {
  data: AdminPlot[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
