import { useEffect, useState } from 'react';
import { authStore, type AuthUser } from '@/lib/auth';

export function useAuth() {
  const [user, setUser] = useState<AuthUser | null>(authStore.getUser());
  useEffect(() => authStore.subscribe(setUser), []);
  return {
    user,
    isAuthenticated: Boolean(user && authStore.getToken()),
    login: authStore.login,
    logout: authStore.logout,
    forgotPassword: authStore.forgotPassword,
  };
}
