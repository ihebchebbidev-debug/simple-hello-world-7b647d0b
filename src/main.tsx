import { createRoot } from 'react-dom/client';
import App from './app/App';
import './i18n';
import './styles/index.css';
import { initVersionCheck } from './lib/versionCheck';

document.documentElement.classList.add('dark');

// A focused <input type="number"> mutates its value on mouse-wheel scroll,
// which silently corrupts quantities (e.g. scrolling over a 15 turns it into
// 14.8). Drop focus on wheel so the page scrolls instead of changing the value.
document.addEventListener(
  'wheel',
  () => {
    const el = document.activeElement;
    if (el instanceof HTMLInputElement && el.type === 'number') el.blur();
  },
  { passive: true },
);

createRoot(document.getElementById('root')!).render(<App />);

// Poll /version.json so a new Vercel deployment triggers a one-shot reload
// and the user always sees the latest bundle.
void initVersionCheck();
