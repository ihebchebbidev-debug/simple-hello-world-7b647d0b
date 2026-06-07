import { useEffect, useState } from 'react';
import { BACKEND_URL } from '@/lib/api';
import logoIcon from '@/assets/logo-icon.png';

interface Props {
  children: React.ReactNode;
}

type Status = 'checking' | 'ready' | 'error';

const HEALTH_URL = `${BACKEND_URL}/api/health`;

export default function BackendWakeup({ children }: Props) {
  const [status, setStatus] = useState<Status>('checking');
  const [attempts, setAttempts] = useState(0);
  const [elapsed, setElapsed] = useState(0);

  useEffect(() => {
    if (status !== 'checking') return;
    const startedAt = Date.now();
    let cancelled = false;
    let timer: ReturnType<typeof setInterval>;

    const ping = async (): Promise<void> => {
      try {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), 8000);
        const res = await fetch(HEALTH_URL, { signal: ctrl.signal, cache: 'no-store' });
        clearTimeout(t);
        if (!cancelled && res.ok) {
          setStatus('ready');
          return;
        }
      } catch {
        // ignore — keep retrying
      }
      if (cancelled) return;
      setAttempts((a) => a + 1);
      setTimeout(ping, 3000);
    };

    timer = setInterval(() => setElapsed(Math.floor((Date.now() - startedAt) / 1000)), 1000);
    ping();

    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [status]);

  if (status === 'ready') return <>{children}</>;

  return (
    <div className="min-h-screen flex items-center justify-center bg-background px-6">
      <div
        aria-hidden
        className="pointer-events-none fixed inset-0 opacity-30"
        style={{
          background:
            'radial-gradient(circle at 50% 35%, hsl(142 72% 29% / 0.4), transparent 60%)',
        }}
      />
      <div className="relative w-full max-w-md bg-card rounded-xl p-8 text-center animate-fade-in shadow-xl">
        <img src={logoIcon} alt="Agri-Sync" className="h-16 w-16 mx-auto mb-4" />
        <h1 className="display-md text-foreground mb-2">Agri-Sync</h1>
        <p className="text-sm text-muted-foreground mb-6">
          Réveil du serveur backend en cours…
        </p>

        <div className="flex justify-center mb-6">
          <div className="h-10 w-10 rounded-full border-4 border-primary/20 border-t-primary animate-spin" />
        </div>

        <div className="text-xs text-muted-foreground space-y-1">
          <p>Tentative #{attempts + 1} · {elapsed}s écoulées</p>
          <p className="text-[11px] opacity-70 break-all">{HEALTH_URL}</p>
        </div>

        <div className="mt-6 p-3 rounded-lg bg-yellow-500/10 border border-yellow-500/30 text-left">
          <p className="text-xs text-yellow-600 dark:text-yellow-400 font-medium mb-1">
            ⚠️ Mode développement
          </p>
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            Le backend est hébergé sur un plan gratuit (Render). Il s'endort après
            quelques minutes d'inactivité et peut prendre <strong>30 à 60 secondes</strong> pour
            redémarrer. Cet écran disparaîtra automatiquement dès que le serveur
            sera disponible. <em>Ce comportement n'existera pas en production.</em>
          </p>
        </div>
      </div>
    </div>
  );
}
