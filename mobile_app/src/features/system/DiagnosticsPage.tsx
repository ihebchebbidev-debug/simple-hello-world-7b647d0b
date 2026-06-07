import { useEffect, useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage, IonRefresher, IonRefresherContent, type RefresherEventDetail } from '@ionic/react';
import { Trash2, Copy, AlertCircle, Activity, Server, Smartphone, Clock } from 'lucide-react';
import PageHeader from '@/components/PageHeader';
import Skeleton from '@/components/Skeleton';
import {
  getDiagnostics, getSyncSnapshot, clearDiagnostics, clearLastSyncError,
  subscribeDiagnostics, type DiagnosticRecord, type SyncSnapshot,
} from '@/lib/diagnostics';

const sourceIcon = (s: DiagnosticRecord['source']) => {
  if (s === 'sw') return <Server className="h-3 w-3" />;
  if (s === 'queue' || s === 'sync') return <Activity className="h-3 w-3" />;
  return <Smartphone className="h-3 w-3" />;
};

const levelClass = (l: DiagnosticRecord['level']) =>
  l === 'error' ? 'text-[hsl(var(--accent-danger))]'
    : l === 'warn' ? 'text-[hsl(var(--accent-warning))]'
    : 'text-muted-foreground';

const DiagnosticsPage = () => {
  const { t, i18n } = useTranslation();
  const [entries, setEntries] = useState<DiagnosticRecord[]>([]);
  const [snap, setSnap] = useState<SyncSnapshot>({ lastSyncAt: null, lastError: null, lastSwWakeAt: null, swWakeCount: 0 });
  const [toast, setToast] = useState<string | null>(null);
  const [loaded, setLoaded] = useState(false);

  const load = useCallback(async () => {
    const [e, s] = await Promise.all([getDiagnostics(), getSyncSnapshot()]);
    setEntries(e);
    setSnap(s);
    setLoaded(true);
  }, []);

  useEffect(() => { void load(); const u = subscribeDiagnostics(() => void load()); return u; }, [load]);

  useEffect(() => {
    if (!toast) return;
    const h = setTimeout(() => setToast(null), 2500);
    return () => clearTimeout(h);
  }, [toast]);

  const fmt = (iso: string | null) =>
    !iso ? t('diagnostics.never')
      : new Date(iso).toLocaleString(i18n.language, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });

  const onPullRefresh = async (e: CustomEvent<RefresherEventDetail>) => {
    try { await load(); } finally { e.detail.complete(); }
  };

  const onCopy = async () => {
    const payload = JSON.stringify({ snapshot: snap, entries }, null, 2);
    try {
      await navigator.clipboard.writeText(payload);
      setToast(t('diagnostics.copied'));
    } catch {
      setToast(t('diagnostics.copyFailed'));
    }
  };

  const onClear = async () => {
    await clearDiagnostics();
    await clearLastSyncError();
    await load();
    setToast(t('diagnostics.cleared'));
  };

  return (
    <IonPage>
      <IonContent fullscreen>
        <IonRefresher slot="fixed" onIonRefresh={onPullRefresh}><IonRefresherContent /></IonRefresher>
        <div className="flex flex-col min-h-screen pb-24">
          <PageHeader title={t('diagnostics.title')} icon={<Activity className="h-5 w-5" />} />

          <div className="px-5 space-y-5 flex-1">
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl p-3 bg-surface-high">
                <p className="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1">
                  <Clock className="h-3 w-3" /> {t('diagnostics.lastSync')}
                </p>
                <p className="text-xs font-medium text-foreground mt-1">{fmt(snap.lastSyncAt)}</p>
              </div>
              <div className="rounded-xl p-3 bg-surface-high">
                <p className="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1">
                  <Server className="h-3 w-3" /> {t('diagnostics.swWake')}
                </p>
                <p className="text-xs font-medium text-foreground mt-1">
                  {snap.swWakeCount} · {fmt(snap.lastSwWakeAt)}
                </p>
              </div>
            </div>

            <div className="rounded-xl p-3 bg-surface-high">
              <p className="text-[10px] uppercase tracking-wide text-muted-foreground flex items-center gap-1">
                <AlertCircle className="h-3 w-3" /> {t('diagnostics.lastError')}
              </p>
              {snap.lastError && snap.lastError.message ? (
                <>
                  <p className="text-xs font-medium text-[hsl(var(--accent-danger))] mt-1 break-words">{snap.lastError.message}</p>
                  <p className="text-[10px] text-muted-foreground mt-0.5">{fmt(snap.lastError.ts)}</p>
                </>
              ) : (
                <p className="text-xs text-muted-foreground mt-1">{t('diagnostics.noError')}</p>
              )}
            </div>

            <div className="flex gap-2">
              <button onClick={onCopy}
                className="flex-1 h-10 rounded-xl text-xs font-medium bg-surface-high border border-border flex items-center justify-center gap-2">
                <Copy className="h-3.5 w-3.5" /> {t('diagnostics.copy')}
              </button>
              <button onClick={onClear}
                className="flex-1 h-10 rounded-xl text-xs font-medium border border-[hsl(var(--accent-danger)/0.4)] text-[hsl(var(--accent-danger))] flex items-center justify-center gap-2">
                <Trash2 className="h-3.5 w-3.5" /> {t('diagnostics.clear')}
              </button>
            </div>

            {toast && (
              <div className="rounded-xl p-2.5 text-xs bg-surface-high text-foreground text-center">{toast}</div>
            )}

            <div>
              <p className="label-md mb-2">{t('diagnostics.logs', { count: entries.length })}</p>
              {!loaded ? (
                <div className="space-y-1.5">
                  {[0, 1, 2, 3].map((i) => (
                    <div key={i} className="rounded-lg p-2.5 bg-surface-high space-y-2">
                      <div className="flex items-center justify-between gap-2">
                        <Skeleton className="h-3 w-24" />
                        <Skeleton className="h-3 w-16" />
                      </div>
                      <Skeleton className="h-3 w-4/5" />
                    </div>
                  ))}
                </div>
              ) : entries.length === 0 ? (
                <div className="rounded-xl p-6 text-center bg-surface-high">
                  <p className="text-sm text-muted-foreground">{t('diagnostics.empty')}</p>
                </div>
              ) : (
                <div className="space-y-1.5">
                  {entries.map((e) => (
                    <div key={e.id} className="rounded-lg p-2.5 bg-surface-high text-[11px] font-mono">
                      <div className="flex items-center justify-between gap-2">
                        <span className={`flex items-center gap-1 ${levelClass(e.level)}`}>
                          {sourceIcon(e.source)} {e.source}:{e.level}
                        </span>
                        <span className="text-muted-foreground text-[10px]">
                          {new Date(e.ts).toLocaleTimeString(i18n.language, { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
                        </span>
                      </div>
                      <p className="text-foreground mt-1 break-words">{e.message}</p>
                      {e.meta && Object.keys(e.meta).length > 0 && (
                        <p className="text-muted-foreground mt-1 break-all">{JSON.stringify(e.meta)}</p>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default DiagnosticsPage;
