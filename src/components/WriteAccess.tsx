import type { ReactNode } from 'react';
import { useCurrentUser } from '@/hooks/useCurrentUser';

/**
 * Renders children for any authenticated role (admin / manager / technician).
 * Catalog writes (fertilizers, pesticides, water, labor) are intentionally open
 * to every role so multiple workstations can all add/edit entries — the backend
 * mirrors this with `role:technician,manager,admin` on those routes.
 */
const WriteAccess = ({ children, fallback = null }: { children: ReactNode; fallback?: ReactNode }) => {
  const { roles } = useCurrentUser();
  return roles.length > 0 ? <>{children}</> : <>{fallback}</>;
};

export default WriteAccess;
