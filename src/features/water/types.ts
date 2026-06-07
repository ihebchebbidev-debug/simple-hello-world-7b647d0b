export interface WaterConfig {
  id: string;
  unit: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface PriceHistoryItem {
  id: string;
  entity_type: 'water' | 'fertilizer' | 'pesticide' | 'labor';
  entity_id: string | null;
  price_per_unit: string | number;
  unit: string | null;
  effective_from: string;
  effective_to: string | null;
  created_at: string;
  created_by: string | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
