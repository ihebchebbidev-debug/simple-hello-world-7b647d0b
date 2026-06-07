import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import { fr } from './fr';
import { en } from './en';

const LANG_KEY = 'agri-sync.lang';

function detectLang(): 'fr' | 'en' {
  try {
    const stored = localStorage.getItem(LANG_KEY);
    if (stored === 'fr' || stored === 'en') return stored;
  } catch { /* ignore */ }
  const env = (import.meta.env.VITE_DEFAULT_LOCALE as string | undefined) ?? '';
  if (env === 'fr' || env === 'en') return env;
  return 'fr';
}

void i18n.use(initReactI18next).init({
  resources: { fr: { translation: fr }, en: { translation: en } },
  lng: detectLang(),
  fallbackLng: 'fr',
  interpolation: { escapeValue: false },
  returnNull: false,
});

export function setLanguage(lang: 'fr' | 'en') {
  void i18n.changeLanguage(lang);
  try { localStorage.setItem(LANG_KEY, lang); } catch { /* ignore */ }
}

export default i18n;
