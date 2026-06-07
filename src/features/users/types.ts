export type AppRole = 'admin' | 'manager' | 'technician';

export interface UserRoleAssignment {
  role: AppRole;
}

export interface AdminUser {
  id: string;
  name: string;
  email: string;
  roles: AppRole[] | UserRoleAssignment[];
  preferred_lang: 'fr' | 'en';
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface PaginatedUsers {
  data: AdminUser[];
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export const ROLE_OPTIONS: AppRole[] = ['admin', 'manager', 'technician'];

export const ROLE_LABELS: Record<AppRole, string> = {
  admin: 'Administrateur',
  manager: 'Gestionnaire',
  technician: 'Technicien',
};

export const ROLE_BADGE_CLASSES: Record<AppRole, string> = {
  admin: 'bg-rose-500/15 text-rose-300 ring-1 ring-inset ring-rose-500/30',
  manager: 'bg-amber-500/15 text-amber-300 ring-1 ring-inset ring-amber-500/30',
  technician: 'bg-sky-500/15 text-sky-300 ring-1 ring-inset ring-sky-500/30',
};

export function normaliseRoles(roles: AdminUser['roles']): AppRole[] {
  if (!Array.isArray(roles)) return [];
  return roles
    .map((r) => (typeof r === 'string' ? r : r?.role))
    .filter(
      (r): r is AppRole => r === 'admin' || r === 'manager' || r === 'technician',
    );
}
