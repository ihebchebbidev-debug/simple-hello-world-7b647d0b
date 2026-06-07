import type { PaginatedResponse, PriceHistoryItem } from '../water/types';

export interface LaborConfig {
  id: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export type { PaginatedResponse, PriceHistoryItem };