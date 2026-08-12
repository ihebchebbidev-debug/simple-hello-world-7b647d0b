export interface AdminFertilizer {
  id: string;
  name: string;
  unit: string;
  n_percent: number;
  p_percent: number;
  k_percent: number;
  mg_percent: number;
  ca_percent: number;
  s_percent: number;
  /** kg/L — only set for liquids; null when not recorded. */
  density_kg_per_l: number | null;
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
