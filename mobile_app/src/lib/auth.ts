/**
 * Auth state for the mobile PWA.
 *
 * Mirrors the admin auth contract: POST /api/auth/login → { data: { token, user } }
 * Token is held in memory (api.ts), persisted to localStorage and mirrored to
 * Capacitor Preferences so a relaunched native app reads it back synchronously.
 */
import { api, getAuthToken, setAuthTokenSync, toApiError, USER_STORAGE_KEY } from './api';
import { prefGet, prefSet, prefRemove } from './native';
import { dbMetaSet } from './db';

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  preferred_lang: 'fr' | 'en';
  is_active?: boolean;
}

let memoryUser: AuthUser | null = null;
try {
  const raw = localStorage.getItem(USER_STORAGE_KEY);
  memoryUser = raw ? (JSON.parse(raw) as AuthUser) : null;
} catch { /* ignore */ }

const listeners = new Set<(u: AuthUser | null) => void>();
function emit() { for (const fn of listeners) fn(memoryUser); }

function persistUser(u: AuthUser | null) {
  memoryUser = u;
  try {
    if (u) localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(u));
    else localStorage.removeItem(USER_STORAGE_KEY);
  } catch { /* ignore */ }
  void (u ? prefSet(USER_STORAGE_KEY, JSON.stringify(u)) : prefRemove(USER_STORAGE_KEY));
  emit();
}

function persistToken(token: string | null) {
  setAuthTokenSync(token);
  void (token ? prefSet('agri-sync.auth.token', token) : prefRemove('agri-sync.auth.token'));
  // Mirror to IndexedDB so the service worker can read it when replaying the
  // outbox during a Background Sync wake-up (the SW has no access to localStorage).
  void dbMetaSet('agri-sync.auth.token', token ?? '').catch(() => {});
}

export const authStore = {
  getUser: (): AuthUser | null => memoryUser,
  getToken: (): string | null => getAuthToken(),
  isAuthenticated: (): boolean => Boolean(getAuthToken() && memoryUser),
  subscribe(fn: (u: AuthUser | null) => void): () => void {
    listeners.add(fn);
    fn(memoryUser);
    return () => listeners.delete(fn);
  },

  /** Hydrate token + user from Capacitor Preferences on cold start (native). */
  async bootstrap(): Promise<void> {
    const [tok, usr] = await Promise.all([prefGet('agri-sync.auth.token'), prefGet(USER_STORAGE_KEY)]);
    if (tok && !getAuthToken()) setAuthTokenSync(tok);
    if (usr && !memoryUser) {
      try { memoryUser = JSON.parse(usr) as AuthUser; emit(); } catch { /* ignore */ }
    }
    // Always mirror the current token into IDB so the SW has the latest one.
    void dbMetaSet('agri-sync.auth.token', getAuthToken() ?? '').catch(() => {});
  },

  async login(email: string, password: string): Promise<AuthUser> {
    try {
      const { data } = await api.post('/auth/login', { email, password });
      const token = (data?.data?.token ?? data?.token) as string | undefined;
      const user = (data?.data?.user ?? data?.user) as AuthUser | undefined;
      if (!token || !user) throw new Error('Réponse de connexion invalide');
      persistToken(token);
      persistUser(user);
      return user;
    } catch (err) {
      throw toApiError(err, 'Connexion impossible');
    }
  },

  async me(): Promise<AuthUser | null> {
    if (!getAuthToken()) return null;
    try {
      const { data } = await api.get('/auth/me');
      const user = (data?.data?.user ?? data?.data ?? data?.user) as AuthUser | undefined;
      if (user) persistUser(user);
      return user ?? null;
    } catch {
      return memoryUser;
    }
  },

  async logout(): Promise<void> {
    try { await api.post('/auth/logout'); } catch { /* offline / token gone */ }
    persistToken(null);
    persistUser(null);
  },

  async forgotPassword(email: string): Promise<void> {
    try {
      await api.post('/auth/forgot-password', { email });
    } catch (err) {
      throw toApiError(err, 'Envoi impossible');
    }
  },
};
