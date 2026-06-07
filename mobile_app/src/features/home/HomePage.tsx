import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { IonContent, IonPage, IonRefresher, IonRefresherContent, type RefresherEventDetail } from '@ionic/react';
import { Settings, RefreshCw } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { useReferenceData } from '@/hooks/useReferenceData';
import NetworkBadge from '@/components/NetworkBadge';
import Skeleton from '@/components/Skeleton';
import logoIcon from '@/assets/logo-icon.png';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';
import iconFertilization from '@/assets/icons/icon-fertilization.png';
import iconPhytosanitary from '@/assets/icons/icon-phytosanitary.png';
import iconHarvest from '@/assets/icons/icon-harvest.png';
import iconSync from '@/assets/icons/icon-sync.png';

const HomePage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { pending, online, initialLoaded } = useOfflineQueue();
  const { refetch, isLoading } = useReferenceData();
  const pendingCount = pending.length;

  const onPullRefresh = async (e: CustomEvent<RefresherEventDetail>) => {
    try { await refetch(); } finally { e.detail.complete(); }
  };

  const tiles = [
    { to: '/irrigation', icon: iconIrrigation, label: t('home.irrigation'), tint: 'hsl(var(--chart-blue))' },
    { to: '/fertilization', icon: iconFertilization, label: t('home.fertilization'), tint: 'hsl(var(--chart-green))' },
    { to: '/phytosanitary', icon: iconPhytosanitary, label: t('home.phytosanitary'), tint: 'hsl(var(--chart-orange))' },
    { to: '/harvest', icon: iconHarvest, label: t('home.harvest'), tint: 'hsl(var(--chart-red))' },
  ];

  return (
    <IonPage>
      <IonContent fullscreen>
        <IonRefresher slot="fixed" onIonRefresh={onPullRefresh}>
          <IonRefresherContent />
        </IonRefresher>
        <div className="flex flex-col min-h-screen px-5 pt-6 pb-24">
          <div className="flex items-center justify-between mb-6">
            <div className="flex items-center gap-3">
              <img src={logoIcon} alt="" className="h-10 w-10 rounded-xl" />
              <div>
                <h1 className="text-base font-bold text-foreground">Agri-Sync</h1>
                <p className="text-[11px] text-muted-foreground">
                  {user ? t('home.welcome', { name: user.name }) : t('app.tagline')}
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <NetworkBadge online={online} />
              <button onClick={() => navigate('/settings')} className="btn-ghost h-9 w-9 p-0 flex items-center justify-center">
                <Settings className="h-5 w-5" />
              </button>
            </div>
          </div>

          {!initialLoaded ? (
            <div className="w-full flex items-center gap-3 p-4 rounded-xl mb-6 bg-surface-high">
              <Skeleton className="h-8 w-8 rounded-lg" />
              <div className="flex-1 space-y-2">
                <Skeleton className="h-4 w-2/3" />
                <Skeleton className="h-3 w-1/3" />
              </div>
            </div>
          ) : (
            <button
              onClick={() => navigate('/sync')}
              className="w-full flex items-center gap-3 p-4 rounded-xl mb-6 transition-colors"
              style={{ background: pendingCount > 0 ? 'hsl(var(--accent-warning) / 0.12)' : 'hsl(var(--primary) / 0.12)' }}
            >
              <img src={iconSync} alt="" className="h-8 w-8" />
              <div className="flex-1 text-left">
                <p className="text-sm font-semibold text-foreground">
                  {pendingCount > 0 ? t('home.pendingSome', { count: pendingCount }) : t('home.pendingNone')}
                </p>
                <p className="text-[11px] text-muted-foreground">{t('home.syncOpen')}</p>
              </div>
            </button>
          )}

          <div className="grid grid-cols-2 gap-3">
            {tiles.map((tile) => (
              <button
                key={tile.to}
                onClick={() => navigate(tile.to)}
                className="card-tile aspect-square flex flex-col items-center justify-center gap-3 p-4 hover:bg-[hsl(var(--surface-bright))]"
              >
                <div className="h-12 w-12 rounded-xl flex items-center justify-center" style={{ background: `${tile.tint}20` }}>
                  <img src={tile.icon} alt="" className="h-7 w-7" />
                </div>
                <span className="text-sm font-medium text-foreground">{tile.label}</span>
              </button>
            ))}
          </div>

          <button
            onClick={() => refetch()} disabled={isLoading || !online}
            className="mt-6 mx-auto text-xs text-muted-foreground flex items-center gap-2 disabled:opacity-50"
          >
            <RefreshCw className={`h-3.5 w-3.5 ${isLoading ? 'animate-spin' : ''}`} />
            {t('home.refreshRefs')}
          </button>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default HomePage;
