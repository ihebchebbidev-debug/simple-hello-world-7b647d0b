export interface AdminPesticide {
  id: string;
  name: string;
  unit: string;
  chemical_composition: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface PaginatedPesticides {
  data: AdminPesticide[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const UNIT_OPTIONS = ['kg', 'g', 'L', 'mL', 't', 'kg/ha', 'L/ha'] as const;
