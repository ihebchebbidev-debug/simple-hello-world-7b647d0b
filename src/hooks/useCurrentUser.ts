import { useQuery } from '@tanstack/react-query';
import { auth, type AuthUser } from '@/lib/auth';
import { normaliseRoles } from '@/features/users/types';
import type { AppRole } from '@/features/users/types';

/**
 * Cached current-user query. Drives role-based UI gating across the admin app.
 * Backend already enforces `role:admin` middleware on writes — this hook lets
 * the UI hide controls that would otherwise return 403 for managers/technicians.
 */
export function useCurrentUser() {
  const enabled = !!auth.token();
  const query = useQuery<AuthUser & { roles?: unknown; role?: unknown }>({
    queryKey: ['auth', 'me'],
    queryFn: () => auth.me() as Promise<AuthUser & { roles?: unknown; role?: unknown }>,
    enabled,
    staleTime: 5 * 60_000,
    gcTime: 30 * 60_000,
    retry: 0,
  });

  const u = query.data as (AuthUser & { roles?: unknown; role?: unknown }) | undefined;
  const roles: AppRole[] = (() => {
    if (!u) return [];
    const r = (u as { roles?: unknown }).roles;
    if (Array.isArray(r)) return normaliseRoles(r as never);
    const single = (u as { role?: unknown }).role;
    if (single === 'admin' || single === 'manager' || single === 'technician') return [single];
    return [];
  })();

  return {
    user: u,
    roles,
    isAdmin: roles.includes('admin'),
    isManager: roles.includes('manager'),
    isTechnician: roles.includes('technician'),
    isLoading: query.isLoading,
  };
}

export const useIsAdmin = (): boolean => useCurrentUser().isAdmin;
