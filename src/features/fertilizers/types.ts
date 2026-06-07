export interface AdminFertilizer {
  id: string;
  name: string;
  unit: string;
  n_percent: number;
  p_percent: number;
  k_percent: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface PaginatedFertilizers {
  data: AdminFertilizer[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const UNIT_OPTIONS = ['kg', 'g', 'L', 'mL', 't', 'kg/ha', 'L/ha'] as const;
