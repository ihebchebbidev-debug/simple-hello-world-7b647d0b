/**
 * Axios client for the Agri-Sync Laravel API.
 *
 * - Base URL from VITE_API_URL (defaults to the deployed Render instance).
 * - Bearer token persisted via the `native` storage layer (Capacitor Preferences
 *   on device, localStorage on web). Token is loaded synchronously from
 *   localStorage so the request interceptor stays sync — the auth bootstrap
 *   warms the cache from Preferences on app start.
 */
import axios, { type AxiosError } from 'axios';
import i18n from '@/i18n';

const DEFAULT_BASE_URL = 'https://hello-world-first-steps.onrender.com';

function normalise(raw: string | undefined): string {
  return (raw?.trim() || DEFAULT_BASE_URL).replace(/\/+$/, '').replace(/\/api$/, '');
}

export const BACKEND_URL = normalise(import.meta.env.VITE_API_URL as string | undefined);

export const TOKEN_STORAGE_KEY = 'agri-sync.auth.token';
export const USER_STORAGE_KEY = 'agri-sync.auth.user';

let memoryToken: string | null = null;
try {
  memoryToken = localStorage.getItem(TOKEN_STORAGE_KEY);
} catch {
  /* SSR / locked storage — ignore */
}

export function getAuthToken(): string | null {
  return memoryToken;
}

export function setAuthTokenSync(token: string | null): void {
  memoryToken = token;
  try {
    if (token) localStorage.setItem(TOKEN_STORAGE_KEY, token);
    else localStorage.removeItem(TOKEN_STORAGE_KEY);
  } catch {
    /* ignore */
  }
}

export const api = axios.create({
  baseURL: `${BACKEND_URL}/api`,
  headers: { Accept: 'application/json' },
  timeout: 30_000,
});

api.interceptors.request.use((config) => {
  const token = getAuthToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  // Ask Laravel to return localised error messages matching the UI language.
  const lang = (i18n.language || 'fr').slice(0, 2);
  config.headers['Accept-Language'] = lang;
  config.headers['X-Locale'] = lang;
  return config;
});

let onUnauthorized: (() => void) | null = null;
export function registerUnauthorizedHandler(fn: () => void): void {
  onUnauthorized = fn;
}

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    const url = String(error?.config?.url ?? '');
    if (error?.response?.status === 401 && !url.includes('/auth/login')) {
      setAuthTokenSync(null);
      onUnauthorized?.();
    }
    return Promise.reject(error);
  },
);

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details?: unknown,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Normalise any axios error into something the UI can render. */
export function toApiError(err: unknown, fallback = 'Erreur réseau'): ApiError {
  if (err instanceof ApiError) return err;
  if (axios.isAxiosError(err)) {
    const status = err.response?.status ?? 0;
    const data = err.response?.data as Record<string, unknown> | undefined;
    const errBag = (data?.error ?? data) as Record<string, unknown> | undefined;
    const message =
      (typeof errBag?.message === 'string' && errBag.message) ||
      (typeof data?.message === 'string' && (data.message as string)) ||
      err.message ||
      fallback;
    const code = typeof errBag?.code === 'string' ? errBag.code : status ? `http_${status}` : 'network_error';
    return new ApiError(status, code, message, errBag?.details ?? data?.errors);
  }
  return new ApiError(0, 'unknown', err instanceof Error ? err.message : fallback);
}
