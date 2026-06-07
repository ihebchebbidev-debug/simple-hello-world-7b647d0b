import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage, IonRefresher, IonRefresherContent, type RefresherEventDetail } from '@ionic/react';
import { Globe, Moon, Sun, LogOut, RefreshCw, Check, AlertCircle, Activity, ChevronRight } from 'lucide-react';
import PageHeader from '@/components/PageHeader';
import Skeleton from '@/components/Skeleton';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';
import { useReferenceData } from '@/hooks/useReferenceData';
import { setLanguage } from '@/i18n';
import { BACKEND_URL } from '@/lib/api';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { refreshReferences } from '@/lib/referenceCache';

const SettingsPage = () => {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const { data: refs, refetch } = useReferenceData();
  const { online, initialLoaded } = useOfflineQueue();
  const [loggingOut, setLoggingOut] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [refreshFeedback, setRefreshFeedback] = useState<{ kind: 'ok' | 'fail'; msg: string } | null>(null);

  useEffect(() => {
    if (!refreshFeedback) return;
    const h = setTimeout(() => setRefreshFeedback(null), 3500);
    return () => clearTimeout(h);
  }, [refreshFeedback]);

  const onRefreshRefs = async () => {
    if (refreshing) return;
    if (!online) {
      setRefreshFeedback({ kind: 'fail', msg: t('settings.referenceRefreshOffline') });
      return;
    }
    setRefreshing(true);
    try {
      await refreshReferences();
      await refetch();
      setRefreshFeedback({ kind: 'ok', msg: t('settings.referenceRefreshed') });
    } catch (err) {
      const msg = err instanceof Error && err.message ? err.message : t('settings.referenceRefreshFailed');
      setRefreshFeedback({ kind: 'fail', msg });
    } finally {
      setRefreshing(false);
    }
  };

  const onLogout = async () => {
    setLoggingOut(true);
    try { await logout(); } finally { navigate('/login', { replace: true }); }
  };

  const switchLang = (lang: 'fr' | 'en') => setLanguage(lang);

  const lastFetched = refs.fetchedAt && new Date(refs.fetchedAt).getTime() > 0
    ? new Date(refs.fetchedAt).toLocaleString(i18n.language, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
    : null;

  const onPullRefresh = async (e: CustomEvent<RefresherEventDetail>) => {
    try { if (online) { await onRefreshRefs(); } } finally { e.detail.complete(); }
  };

  return (
    <IonPage>
      <IonContent fullscreen>
        <IonRefresher slot="fixed" onIonRefresh={onPullRefresh}>
          <IonRefresherContent />
        </IonRefresher>
        <div className="flex flex-col min-h-screen pb-24">
          <PageHeader title={t('settings.title')} showBack={false} />
          <div className="px-5 space-y-6">
            {!initialLoaded ? (
              <>
                {/* Account skeleton */}
                <div className="rounded-xl p-4 bg-surface-high space-y-2">
                  <Skeleton className="h-3.5 w-20" />
                  <Skeleton className="h-5 w-40" />
                  <Skeleton className="h-3.5 w-48" />
                </div>

                {/* Language skeleton */}
                <div className="space-y-2">
                  <Skeleton className="h-3.5 w-28" />
                  <div className="grid grid-cols-2 gap-2">
                    <Skeleton className="h-11 w-full rounded-xl" />
                    <Skeleton className="h-11 w-full rounded-xl" />
                  </div>
                </div>

                {/* Theme skeleton */}
                <div className="space-y-2">
                  <Skeleton className="h-3.5 w-16" />
                  <Skeleton className="h-11 w-full rounded-xl" />
                </div>

                {/* Reference data skeleton */}
                <div className="space-y-2">
                  <Skeleton className="h-3.5 w-36" />
                  <div className="rounded-xl p-4 bg-surface-high space-y-3">
                    <Skeleton className="h-3 w-3/4" />
                    <Skeleton className="h-10 w-full rounded-xl" />
                  </div>
                </div>

                {/* Backend URL skeleton */}
                <div className="space-y-2">
                  <Skeleton className="h-3.5 w-24" />
                  <Skeleton className="h-3 w-full" />
                </div>

                {/* Diagnostics skeleton */}
                <Skeleton className="h-12 w-full rounded-xl" />

                {/* Logout skeleton */}
                <Skeleton className="h-12 w-full rounded-xl" />
              </>
            ) : (
              <>
                {user && (
                  <div className="rounded-xl p-4 bg-surface-high">
                    <p className="label-md mb-1">{t('settings.account')}</p>
                    <p className="text-sm font-medium text-foreground">{user.name}</p>
                    <p className="text-xs text-muted-foreground">{user.email}</p>
                  </div>
                )}

                <div>
                  <p className="label-md mb-2 flex items-center gap-2"><Globe className="h-3.5 w-3.5" />{t('settings.language')}</p>
                  <div className="grid grid-cols-2 gap-2">
                    {(['fr', 'en'] as const).map((lng) => (
                      <button key={lng} onClick={() => switchLang(lng)}
                        className={`h-11 rounded-xl text-sm font-medium border ${
                          i18n.language.startsWith(lng) ? 'bg-primary text-primary-foreground border-primary' : 'border-border bg-surface-high text-foreground'
                        }`}>
                        {lng.toUpperCase()}
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <p className="label-md mb-2">{t('settings.theme')}</p>
                  <button onClick={toggleTheme} className="h-11 px-4 rounded-xl bg-surface-high border border-border w-full flex items-center justify-between">
                    <span className="text-sm text-foreground">{theme === 'dark' ? t('settings.dark') : t('settings.light')}</span>
                    {theme === 'dark' ? <Moon className="h-4 w-4" /> : <Sun className="h-4 w-4" />}
                  </button>
                </div>

                <div>
                  <p className="label-md mb-2">{t('settings.referenceData')}</p>
                  <div className="rounded-xl p-4 bg-surface-high space-y-3">
                    <p className="text-xs text-muted-foreground">
                      {lastFetched ? t('settings.referenceLastFetched', { when: lastFetched }) : t('settings.referenceNever')}
                    </p>
                    <button onClick={onRefreshRefs} disabled={refreshing}
                      className="btn-ghost h-10 w-full border border-border flex items-center justify-center gap-2 disabled:opacity-50">
                      <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
                      {refreshing ? t('settings.referenceRefreshing') : t('settings.referenceRefresh')}
                    </button>
                    {refreshFeedback && (
                      <p className={`text-[11px] flex items-center gap-1 ${
                        refreshFeedback.kind === 'ok'
                          ? 'text-[hsl(var(--primary-glow))]'
                          : 'text-[hsl(var(--accent-danger))]'
                      }`}>
                        {refreshFeedback.kind === 'ok'
                          ? <Check className="h-3 w-3" />
                          : <AlertCircle className="h-3 w-3" />}
                        {refreshFeedback.msg}
                      </p>
                    )}
                  </div>
                </div>

                <div>
                  <p className="label-md mb-2">{t('settings.backend')}</p>
                  <p className="text-xs text-muted-foreground break-all">{BACKEND_URL}</p>
                </div>

                <button onClick={() => navigate('/diagnostics')}
                  className="h-12 w-full rounded-xl bg-surface-high border border-border flex items-center justify-between px-4">
                  <span className="flex items-center gap-2 text-sm text-foreground">
                    <Activity className="h-4 w-4" /> {t('diagnostics.title')}
                  </span>
                  <ChevronRight className="h-4 w-4 text-muted-foreground" />
                </button>

                <button onClick={onLogout} disabled={loggingOut}
                  className="h-12 w-full rounded-xl bg-[hsl(var(--accent-danger)/0.12)] text-[hsl(var(--accent-danger))] font-medium flex items-center justify-center gap-2">
                  <LogOut className="h-4 w-4" />
                  {loggingOut ? t('settings.loggingOut') : t('settings.logout')}
                </button>
              </>
            )}
          </div>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default SettingsPage;
