import axios from 'axios';

const DEFAULT_BACKEND_URL = 'https://hello-world-first-steps.onrender.com';
export const TOKEN_KEY = 'agri-sync.auth.token';
const LEGACY_TOKEN_KEY = 'agrysync.token';

function normaliseBackendUrl(value?: string): string {
  return (value?.trim() || DEFAULT_BACKEND_URL).replace(/\/+$/, '').replace(/\/api$/, '');
}

export const BACKEND_URL = normaliseBackendUrl(import.meta.env.VITE_API_URL);

export function getAuthToken(): string | null {
  return localStorage.getItem(TOKEN_KEY) ?? localStorage.getItem(LEGACY_TOKEN_KEY);
}

export function setAuthToken(token: string | null): void {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(LEGACY_TOKEN_KEY, token);
    return;
  }

  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(LEGACY_TOKEN_KEY);
}

export const api = axios.create({
  baseURL: `${BACKEND_URL}/api`,
  headers: { Accept: 'application/json' },
  paramsSerializer: { indexes: false },
});

api.interceptors.request.use((config) => {
  const token = getAuthToken();
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const url = String(error?.config?.url ?? '');
    const isAuthCall = url.includes('/auth/login');
    const isDeveloperCall = url.includes('/developer'); // /developer page has its own token
    if (error?.response?.status === 401 && !isAuthCall && !isDeveloperCall) {
      setAuthToken(null);
      if (window.location.pathname !== '/login') window.location.assign('/login');
    }
    return Promise.reject(error);
  },
);
