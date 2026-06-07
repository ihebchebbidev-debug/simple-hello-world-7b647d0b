import { createRoot } from 'react-dom/client';
import App from './app/App';
import './styles/index.css';
import './styles/ionic-theme.css';
import { registerServiceWorker } from './lib/registerSW';

// Theme decision lives in ThemeProvider; default to dark before React mounts
// to avoid a flash of light styles.
try {
  const stored = localStorage.getItem('agri-sync.theme');
  if (stored !== 'light') document.documentElement.classList.add('dark');
} catch { document.documentElement.classList.add('dark'); }

// A focused <input type="number"> mutates its value on wheel/trackpad scroll,
// which silently corrupts quantities (field crew reported 15 → 14.8). Drop
// focus on wheel so the view scrolls instead of changing the value.
document.addEventListener(
  'wheel',
  () => {
    const el = document.activeElement;
    if (el instanceof HTMLInputElement && el.type === 'number') el.blur();
  },
  { passive: true },
);

createRoot(document.getElementById('root')!).render(<App />);

// Register the service worker after the app has mounted. No-op in dev,
// iframes, preview hosts, and native Capacitor shells.
void registerServiceWorker();
