import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { fr } from './fr';
import { en } from './en';

export const SUPPORTED_LANGS = ['fr', 'en'] as const;
export type AdminLang = (typeof SUPPORTED_LANGS)[number];

const STORAGE_KEY = 'agrysync.lang';
const fallback = (import.meta.env.VITE_DEFAULT_LOCALE ?? 'fr') as AdminLang;
const stored = (typeof localStorage !== 'undefined'
  ? localStorage.getItem(STORAGE_KEY)
  : null) as AdminLang | null;

if (!i18n.isInitialized) {
  void i18n.use(initReactI18next).init({
    resources: {
      fr: { translation: fr },
      en: { translation: en },
    },
    lng: stored ?? fallback,
    fallbackLng: 'fr',
    interpolation: { escapeValue: false },
    returnNull: false,
  });
}

export function setAdminLang(lang: AdminLang): void {
  void i18n.changeLanguage(lang);
  try {
    localStorage.setItem(STORAGE_KEY, lang);
    document.documentElement.setAttribute('lang', lang);
  } catch {
    // ignore (SSR / private mode)
  }
}

if (typeof document !== 'undefined') {
  document.documentElement.setAttribute('lang', i18n.language);
}

export default i18n;
