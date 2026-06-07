import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage, IonRefresher, IonRefresherContent, type RefresherEventDetail } from '@ionic/react';
import { RefreshCw, Trash2, RotateCw, Check, AlertCircle } from 'lucide-react';
import PageHeader from '@/components/PageHeader';
import NetworkBadge from '@/components/NetworkBadge';
import Skeleton from '@/components/Skeleton';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import iconSync from '@/assets/icons/icon-sync.png';
import type { OutboxItem, OutboxOperationType } from '@/lib/offlineQueue';

const SyncPage = () => {
  const { t, i18n } = useTranslation();
  const {
    items, pending, syncing, failed, online, flushing, lastSyncAt, initialLoaded,
    flushNow, remove, retryOne, retryAllFailed,
  } = useOfflineQueue();

  // Per-item transient feedback (id -> 'ok' | 'fail'), auto-clears.
  const [feedback, setFeedback] = useState<Record<string, 'ok' | 'fail'>>({});
  const [banner, setBanner] = useState<string | null>(null);

  useEffect(() => {
    if (!banner) return;
    const h = setTimeout(() => setBanner(null), 3500);
    return () => clearTimeout(h);
  }, [banner]);

  const typeLabel: Record<OutboxOperationType, string> = {
    irrigation: t('home.irrigation'),
    fertilization: t('home.fertilization'),
    phytosanitary: t('home.phytosanitary'),
    harvest: t('home.harvest'),
  };

  const fmt = (iso: string) =>
    new Date(iso).toLocaleString(i18n.language, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

  const badgeClass = (s: OutboxItem['status']) =>
    s === 'failed' ? 'badge-failed' : s === 'syncing' ? 'badge-synced' : 'badge-pending';
  const badgeLabel = (s: OutboxItem['status']) =>
    s === 'failed' ? t('sync.failed') : s === 'syncing' ? '…' : t('sync.pending');

  const flashFeedback = (id: string, kind: 'ok' | 'fail') => {
    setFeedback((m) => ({ ...m, [id]: kind }));
    setTimeout(() => setFeedback((m) => { const { [id]: _, ...rest } = m; return rest; }), 2500);
  };

  const onRetryOne = async (id: string) => {
    const report = await retryOne(id);
    if (report.skipped > 0) { setBanner(t('sync.retryReportSkip')); return; }
    if (report.succeeded.includes(id)) flashFeedback(id, 'ok');
    else flashFeedback(id, 'fail');
  };

  const onRetryAll = async () => {
    const report = await retryAllFailed();
    if (report.skipped > 0) { setBanner(t('sync.retryReportSkip')); return; }
    report.succeeded.forEach((id) => flashFeedback(id, 'ok'));
    report.failed.forEach(({ id }) => flashFeedback(id, 'fail'));
    const okMsg = report.succeeded.length ? t('sync.retryReportOk', { count: report.succeeded.length }) : '';
    const failMsg = report.failed.length ? t('sync.retryReportFail', { count: report.failed.length }) : '';
    setBanner([okMsg, failMsg].filter(Boolean).join(' '));
  };

  const onPullRefresh = async (e: CustomEvent<RefresherEventDetail>) => {
    try { if (online) await flushNow(); } finally { e.detail.complete(); }
  };

  return (
    <IonPage>
      <IonContent fullscreen>
        <IonRefresher slot="fixed" onIonRefresh={onPullRefresh}>
          <IonRefresherContent />
        </IonRefresher>
        <div className="flex flex-col min-h-screen pb-24">
          <PageHeader
            title={t('sync.title')}
            icon={<img src={iconSync} alt="" className="h-5 w-5" />}
            right={<NetworkBadge online={online} />}
            showBack={false}
          />

          <div className="px-5 space-y-6 flex-1">
            <div className="grid grid-cols-3 gap-3">
              {!initialLoaded ? (
                <>
                  <div className="rounded-xl p-4 text-center bg-surface-high">
                    <Skeleton className="h-7 w-10 mx-auto" />
                    <Skeleton className="h-3 w-14 mx-auto mt-2" />
                  </div>
                  <div className="rounded-xl p-4 text-center bg-surface-high">
                    <Skeleton className="h-7 w-10 mx-auto" />
                    <Skeleton className="h-3 w-14 mx-auto mt-2" />
                  </div>
                  <div className="rounded-xl p-4 text-center bg-surface-high">
                    <Skeleton className="h-7 w-10 mx-auto" />
                    <Skeleton className="h-3 w-14 mx-auto mt-2" />
                  </div>
                </>
              ) : (
                <>
                  <div className="rounded-xl p-4 text-center" style={{ background: 'hsl(var(--accent-warning) / 0.12)' }}>
                    <p className="text-2xl font-bold text-[hsl(var(--accent-warning))]">{pending.length + syncing.length}</p>
                    <p className="text-[10px] font-medium text-muted-foreground mt-1">{t('sync.pending')}</p>
                  </div>
                  <div className="rounded-xl p-4 text-center" style={{ background: 'hsl(var(--primary) / 0.12)' }}>
                    <p className="text-2xl font-bold text-[hsl(var(--primary-glow))]">{items.length}</p>
                    <p className="text-[10px] font-medium text-muted-foreground mt-1">{t('sync.queue')}</p>
                  </div>
                  <div className="rounded-xl p-4 text-center" style={{ background: 'hsl(var(--accent-danger) / 0.12)' }}>
                    <p className="text-2xl font-bold text-[hsl(var(--accent-danger))]">{failed.length}</p>
                    <p className="text-[10px] font-medium text-muted-foreground mt-1">{t('sync.errors')}</p>
                  </div>
                </>
              )}
            </div>

            <div className="flex items-center justify-between text-xs px-1">
              <span className="text-muted-foreground">{t('sync.lastSync')}</span>
              <span className="text-foreground font-medium">
                {lastSyncAt ? fmt(lastSyncAt) : t('sync.lastSyncNever')}
              </span>
            </div>

            <button
              onClick={flushNow}
              disabled={flushing || !online || (pending.length + syncing.length) === 0}
              className="btn-primary-glass w-full h-12 text-base flex items-center justify-center gap-2"
            >
              <RefreshCw className={`h-5 w-5 ${flushing ? 'animate-spin' : ''}`} />
              {flushing ? t('sync.syncing') : t('sync.syncNow')}
            </button>

            {failed.length > 0 && (
              <button
                onClick={onRetryAll}
                disabled={flushing || !online}
                className="w-full h-11 rounded-xl text-sm font-medium border border-[hsl(var(--accent-danger)/0.4)] text-[hsl(var(--accent-danger))] flex items-center justify-center gap-2 disabled:opacity-50"
              >
                <RotateCw className={`h-4 w-4 ${flushing ? 'animate-spin' : ''}`} />
                {t('sync.retryAllFailed')} ({failed.length})
              </button>
            )}

            {banner && (
              <div className="rounded-xl p-3 text-xs bg-surface-high text-foreground text-center">{banner}</div>
            )}

            {!online && <p className="text-xs text-center text-muted-foreground">{t('sync.offlineHint')}</p>}

            {!initialLoaded ? (
              <div>
                <Skeleton className="h-4 w-24 mb-3" />
                <div className="space-y-2">
                  {[0, 1, 2, 3, 4].map((i) => (
                    <div key={i} className="flex items-center justify-between p-3.5 rounded-xl gap-3 bg-surface-high">
                      <div className="min-w-0 flex-1 space-y-2">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-3 w-1/3" />
                      </div>
                      <div className="flex items-center gap-2">
                        <Skeleton className="h-5 w-14 rounded-full" />
                        <Skeleton className="h-8 w-8 rounded-lg" />
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ) : items.length === 0 ? (
              <div className="rounded-xl p-6 text-center bg-surface-high">
                <p className="text-sm text-muted-foreground">{t('sync.empty')}</p>
              </div>
            ) : (
              <div>
                <p className="label-md mb-3">{t('sync.queue')}</p>
                <div className="space-y-2">
                  {items.map((item) => (
                    <div key={item.id} className="flex items-center justify-between p-3.5 rounded-xl gap-3 bg-surface-high">
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium text-foreground">{typeLabel[item.type]}</p>
                        <p className="text-[11px] text-muted-foreground">{fmt(item.createdAt)}</p>
                        {item.lastError && (
                          <p className="text-[11px] text-[hsl(var(--accent-danger))] truncate">
                            {item.lastError} · {t('sync.attempts', { count: item.attempts })}
                          </p>
                        )}
                        {feedback[item.id] === 'ok' && (
                          <p className="text-[11px] text-[hsl(var(--primary-glow))] flex items-center gap-1 mt-0.5">
                            <Check className="h-3 w-3" /> {t('sync.itemSyncedOk')}
                          </p>
                        )}
                        {feedback[item.id] === 'fail' && (
                          <p className="text-[11px] text-[hsl(var(--accent-danger))] flex items-center gap-1 mt-0.5">
                            <AlertCircle className="h-3 w-3" /> {t('sync.itemSyncFail')}
                          </p>
                        )}
                      </div>
                      <span className={badgeClass(item.status)}>{badgeLabel(item.status)}</span>
                      {item.status === 'failed' && (
                        <button onClick={() => onRetryOne(item.id)} disabled={flushing || !online}
                          className="p-1.5 rounded-lg hover:bg-surface-bright text-muted-foreground disabled:opacity-50" aria-label={t('sync.retry')}>
                          <RotateCw className="h-4 w-4" />
                        </button>
                      )}
                      <button onClick={() => remove(item.id)} className="p-1.5 rounded-lg hover:bg-surface-bright text-muted-foreground" aria-label={t('sync.delete')}>
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default SyncPage;
