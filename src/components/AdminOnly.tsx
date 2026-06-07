import type { ReactNode } from 'react';
import { useIsAdmin } from '@/hooks/useCurrentUser';

/**
 * Renders children only for users with the `admin` role.
 * Backend already enforces `role:admin` middleware — this component prevents
 * managers/technicians from seeing buttons that would 403.
 */
const AdminOnly = ({ children, fallback = null }: { children: ReactNode; fallback?: ReactNode }) => {
  return useIsAdmin() ? <>{children}</> : <>{fallback}</>;
};

export default AdminOnly;
