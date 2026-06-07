/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string;
  readonly VITE_DEFAULT_LOCALE: 'fr' | 'en';
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
