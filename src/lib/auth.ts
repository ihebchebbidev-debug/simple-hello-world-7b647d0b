import { api } from './api';
import { getAuthToken, setAuthToken } from './api';

export interface AuthUser {
  id: string;
  name: string;
  email: string;
  preferred_lang: 'fr' | 'en';
}

export interface LoginPayload {
  email: string;
  password: string;
}

export const auth = {
  async setupStatus(): Promise<{ needs_setup: boolean }> {
    const { data } = await api.get('/auth/setup-status');
    return data?.data ?? data;
  },
  async register(payload: { name: string; email: string; password: string; password_confirmation: string; preferred_lang?: 'fr' | 'en' }): Promise<AuthUser> {
    const { data } = await api.post('/auth/register', payload);
    const token = data?.data?.token ?? data?.token;
    if (token) setAuthToken(token);
    return data?.data?.user ?? data?.user;
  },
  async login(payload: LoginPayload): Promise<AuthUser> {
    const { data } = await api.post('/auth/login', payload);
    const token = data?.data?.token ?? data?.token;
    if (token) setAuthToken(token);
    return data?.data?.user ?? data?.user;
  },
  async me(): Promise<AuthUser> {
    const { data } = await api.get('/auth/me');
    return data?.data?.user ?? data?.user ?? data?.data ?? data;
  },
  async logout() {
    try {
      await api.post('/auth/logout');
    } finally {
      setAuthToken(null);
    }
  },
  async forgotPassword(email: string): Promise<void> {
    await api.post('/auth/forgot-password', { email });
  },
  async resetPassword(payload: { email: string; token: string; password: string; password_confirmation: string }): Promise<void> {
    await api.post('/auth/reset-password', payload);
  },
  token: () => getAuthToken(),
};
