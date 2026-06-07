import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import Skeleton from '@/components/Skeleton';
import OperationDetailsModal from './OperationDetailsModal';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';
import iconFertilization from '@/assets/icons/icon-fertilization.png';
import iconPhytosanitary from '@/assets/icons/icon-phytosanitary.png';
import iconHarvest from '@/assets/icons/icon-harvest.png';
import iconReports from '@/assets/icons/icon-reports.png';
import iconCosts from '@/assets/icons/icon-costs.png';

interface Stats {
  counts: {
    plots_active: number;
    fertilizers_active: number;
    pesticides_active: number;
    campaigns_active: number;
    pending_postings: number;
  };
  this_month: {
    period_start: string;
    water_quantity: number;
    fertilizer_quantity: number;
    treatments: number;
    harvest_quantity: number;
  };
}

interface ActivityItem {
  id: string;
  type: 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';
  plot_id: string | null;
  plot_name: string | null;
  operation_date: string;
  created_at: string;
}

const DashboardPage = () => {
  const { t, i18n } = useTranslation();
  const locale = i18n.language?.startsWith('en') ? 'en-US' : 'fr-FR';
  const [opModal, setOpModal] = useState<{ type: ActivityItem['type']; id: string } | null>(null);
  const plotsQuery = usePlotsForFilter();

  const statsQuery = useQuery({
    queryKey: ['admin-dashboard-stats'],
    queryFn: async () => (await api.get<{ data: Stats }>('/dashboard/stats')).data.data,
  });
  const activityQuery = useQuery({
    queryKey: ['admin-dashboard-activity'],
    queryFn: async () =>
      (await api.get<{ data: { items: ActivityItem[] } }>('/dashboard/recent-activity', {
        params: { limit: 24 },
      })).data.data.items,
  });

  const stats = statsQuery.data;
  const items = activityQuery.data ?? [];

  const [activityFilter, setActivityFilter] = useState<'all' | ActivityItem['type']>('all');
  const [activityDate, setActivityDate] = useState('');
  const [activityPlot, setActivityPlot] = useState<'all' | string>('all');
  const visibleActivity = useMemo(() => items.filter((a) => {
    if (activityFilter !== 'all' && a.type !== activityFilter) return false;
    if (activityDate && !a.operation_date.startsWith(activityDate)) return false;
    if (activityPlot !== 'all' && a.plot_id !== activityPlot) return false;
    return true;
  }), [activityFilter, activityDate, activityPlot, items]);

  const activityMeta: Record<ActivityItem['type'], { icon: string; color: string; label: string }> = {
    irrigation:    { icon: iconIrrigation,    color: 'hsl(var(--chart-blue))',   label: t('dashboard.type.irrigation') },
    fertilization: { icon: iconFertilization, color: 'hsl(var(--chart-green))',  label: t('dashboard.type.fertilization') },
    phytosanitary: { icon: iconPhytosanitary, color: 'hsl(var(--chart-orange))', label: t('dashboard.type.phytosanitary') },
    harvest:       { icon: iconHarvest,       color: 'hsl(var(--chart-red))',    label: t('dashboard.type.harvest') },
  };

  // Customer asked for the exact date — no "today / yesterday / Nd ago".
  const formatRelative = (iso: string) => {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: 'numeric' });
  };


  const KPI = ({ label, value, hint, accent, icon, to }: { label: string; value: string | number; hint?: string; accent?: string; icon?: string; to?: string }) => {
    const inner = (
      <div className={`stat-card ${to ? 'cursor-pointer hover:ring-1 hover:ring-[hsl(var(--primary)/0.5)] transition-all' : ''}`}>
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <div className="h-1 w-10 rounded-full mb-2" style={{ background: accent ?? 'hsl(var(--primary-glow))' }} />
            <p className="label-md">{label}</p>
            <p className="mt-2 text-2xl font-bold tracking-tight text-foreground">{value}</p>
            {hint && <p className="mt-1 text-[11px] text-muted-foreground">{hint}</p>}
          </div>
          {icon && (
            <div
              className="h-9 w-9 shrink-0 rounded-lg flex items-center justify-center"
              style={{ background: `${accent ?? 'hsl(var(--primary-glow))'} / 0.12`, backgroundColor: 'hsl(var(--surface-container-highest))' }}
            >
              <img src={icon} alt="" className="h-5 w-5 opacity-90" />
            </div>
          )}
        </div>
      </div>
    );
    return to ? <Link to={to} className="block">{inner}</Link> : inner;
  };

  const KPISkeleton = () => (
    <div className="stat-card">
      <Skeleton className="h-1 w-10 mb-2" />
      <Skeleton className="h-3 w-20" />
      <Skeleton className="mt-3 h-7 w-16" />
      <Skeleton className="mt-2 h-2.5 w-24" />
    </div>
  );

  const ActivitySkeleton = () => (
    <div className="flex items-center gap-3 rounded-lg px-2.5 py-2 bg-[hsl(var(--surface-container-lowest))]">
      <Skeleton className="h-8 w-8 shrink-0 rounded-md" />
      <div className="min-w-0 flex-1 space-y-1.5">
        <div className="flex items-baseline justify-between gap-2">
          <Skeleton className="h-3 w-32" />
          <Skeleton className="h-2.5 w-10" />
        </div>
        <Skeleton className="h-2.5 w-20" />
      </div>
    </div>
  );

  const ErrorCard = ({ message, onRetry }: { message: string; onRetry: () => void }) => (
    <div className="stat-card flex flex-col items-start gap-2 border border-[hsl(var(--accent-danger)/0.4)]">
      <p className="text-sm font-semibold" style={{ color: 'hsl(var(--accent-danger))' }}>{message}</p>
      <button
        type="button"
        onClick={onRetry}
        className="text-[11px] uppercase tracking-wider font-semibold text-primary hover:underline"
      >
        {t('common.retry', 'Retry')}
      </button>
    </div>
  );

  return (
    <div className="space-y-4 sm:space-y-5 animate-fade-in">
      <header>
        <h1 className="display-md text-foreground">{t('dashboard.title')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('dashboard.subtitle')}</p>
      </header>

      {statsQuery.isLoading && (
        <>
          <div className="grid gap-2.5 sm:gap-3 grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => <KPISkeleton key={`s1-${i}`} />)}
          </div>
          <div className="grid gap-2.5 sm:gap-3 grid-cols-2 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => <KPISkeleton key={`s2-${i}`} />)}
          </div>
        </>
      )}
      {statsQuery.isError && (
        <ErrorCard
          message={t('dashboard.statsLoadFailed', 'Failed to load stats')}
          onRetry={() => statsQuery.refetch()}
        />
      )}

      {stats && (
        <>
          <div className="grid gap-2.5 sm:gap-3 grid-cols-2 lg:grid-cols-4">
            <KPI to="/plots"           label={t('dashboard.plotsActive')}   value={stats.counts.plots_active}       hint={t('dashboard.hintConfigured')} accent="hsl(var(--chart-green))"  icon={iconReports} />
            <KPI to="/fertilizers"     label={t('dashboard.fertilizers')}   value={stats.counts.fertilizers_active} hint={t('dashboard.hintActive')}     accent="hsl(var(--chart-green))"  icon={iconFertilization} />
            <KPI to="/pesticides"      label={t('dashboard.pesticides')}    value={stats.counts.pesticides_active}  hint={t('dashboard.hintActive')}     accent="hsl(var(--chart-orange))" icon={iconPhytosanitary} />
            <KPI to="/campaigns"       label={t('dashboard.campaigns')}     value={stats.counts.campaigns_active}   hint={t('dashboard.hintOngoing')}    accent="hsl(var(--primary-glow))" icon={iconCosts} />
          </div>

          <div>
            <h2 className="text-[11px] uppercase tracking-wider font-semibold text-muted-foreground mb-2">
              {t('dashboard.reportShortcuts', 'Accès rapide aux rapports')}
            </h2>
            <div className="grid gap-2.5 sm:gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
              {[
                { to: '/reports/irrigation',      label: t('reports.tab.irrigation'),                  icon: iconIrrigation,    accent: 'hsl(var(--chart-blue))' },
                { to: '/reports/fertilization',   label: t('reports.tab.fertilization'),               icon: iconFertilization, accent: 'hsl(var(--chart-green))' },
                { to: '/reports/phytosanitary',   label: t('reports.tab.phytosanitary'),               icon: iconPhytosanitary, accent: 'hsl(var(--chart-orange))' },
                { to: '/reports/harvesting',      label: t('reports.tab.harvest'),                     icon: iconHarvest,       accent: 'hsl(var(--chart-red))' },
                { to: '/reports/production-cost', label: t('reports.tab.production-cost'),             icon: iconCosts,         accent: 'hsl(var(--primary-glow))' },
              ].map((r) => (
                <Link
                  key={r.to}
                  to={r.to}
                  className="stat-card group flex items-center gap-3 p-3 cursor-pointer hover:ring-1 hover:ring-[hsl(var(--primary)/0.5)] hover:-translate-y-0.5 transition-all h-full"
                >
                  <div
                    className="h-10 w-10 shrink-0 rounded-lg flex items-center justify-center"
                    style={{ background: 'hsl(var(--surface-container-highest))' }}
                  >
                    <img src={r.icon} alt="" className="h-6 w-6" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="h-1 w-8 rounded-full mb-1.5" style={{ background: r.accent }} />
                    <p className="text-[13px] font-semibold text-foreground truncate">{r.label}</p>
                    <p className="text-[10px] text-muted-foreground">{t('dashboard.openReport', 'Ouvrir le rapport')}</p>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </>
      )}

      {/* Recent activity table (Date | Parcelle | Type d'opération) */}
      <div className="stat-card">
        <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
          <h3 className="text-[13px] font-semibold text-foreground">{t('dashboard.recentActivity')}</h3>
          <div className="flex items-center gap-2 flex-wrap">
            <input
              type="date"
              value={activityDate}
              onChange={(e) => setActivityDate(e.target.value)}
              className="filter-select h-8 text-[11px]"
              aria-label={t('common.date', 'Date')}
            />
            <select
              value={activityPlot}
              onChange={(e) => setActivityPlot(e.target.value)}
              className="filter-select h-8 text-[11px]"
            >
              <option value="all">{t('reports.allPlots', 'All plots')}</option>
              {(plotsQuery.data ?? []).map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
            <select
              value={activityFilter}
              onChange={(e) => setActivityFilter(e.target.value as 'all' | ActivityItem['type'])}
              className="filter-select h-8 text-[11px]"
            >
              <option value="all">{t('reports.allActivities', 'All activities')}</option>
              <option value="irrigation">{t('dashboard.type.irrigation')}</option>
              <option value="fertilization">{t('dashboard.type.fertilization')}</option>
              <option value="phytosanitary">{t('dashboard.type.phytosanitary')}</option>
              <option value="harvest">{t('dashboard.type.harvest')}</option>
            </select>
            {(activityDate || activityPlot !== 'all' || activityFilter !== 'all') && (
              <button
                type="button"
                onClick={() => { setActivityDate(''); setActivityPlot('all'); setActivityFilter('all'); }}
                className="text-[10px] uppercase tracking-wider font-semibold text-muted-foreground hover:text-foreground"
              >
                {t('common.reset', 'Reset')}
              </button>
            )}
            <span className="text-[10px] uppercase tracking-wider font-semibold text-muted-foreground">
              {visibleActivity.length}
            </span>
          </div>
        </div>

        {activityQuery.isLoading && (
          <div className="space-y-1.5">
            {Array.from({ length: 6 }).map((_, i) => <ActivitySkeleton key={`act-s-${i}`} />)}
          </div>
        )}

        {!activityQuery.isLoading && activityQuery.isError && (
          <div className="py-8 flex flex-col items-center gap-2 text-center">
            <p className="text-sm font-medium" style={{ color: 'hsl(var(--accent-danger))' }}>
              {t('dashboard.activityLoadFailed', 'Failed to load recent activity')}
            </p>
            <button
              type="button"
              onClick={() => activityQuery.refetch()}
              className="text-[11px] uppercase tracking-wider font-semibold text-primary hover:underline"
            >
              {t('common.retry', 'Retry')}
            </button>
          </div>
        )}

        {!activityQuery.isLoading && !activityQuery.isError && visibleActivity.length === 0 && (
          <div className="py-10 flex flex-col items-center gap-2 text-center">
            <div className="h-12 w-12 rounded-full flex items-center justify-center bg-[hsl(var(--surface-container-highest))]">
              <img src={iconReports} alt="" className="h-6 w-6 opacity-50" />
            </div>
            <p className="text-sm font-medium text-foreground">
              {activityFilter === 'all' && !activityDate && activityPlot === 'all'
                ? t('dashboard.noActivity', 'No activity yet')
                : t('dashboard.noActivityFiltered', 'No activity matches this filter')}
            </p>
            <p className="text-[11px] text-muted-foreground max-w-xs">
              {t('dashboard.noActivityHint', 'Operations recorded by mobile users will appear here.')}
            </p>
          </div>
        )}

        {!activityQuery.isLoading && !activityQuery.isError && visibleActivity.length > 0 && (
          <div className="max-h-[460px] overflow-y-auto pr-1">
            <table className="w-full text-[12px]">
              <thead className="sticky top-0 bg-[hsl(var(--surface-container-lowest))] z-10">
                <tr className="text-left text-[10px] uppercase tracking-wider font-semibold text-muted-foreground">
                  <th className="px-2 py-2">{t('common.date', 'Date')}</th>
                  <th className="px-2 py-2">{t('common.plot', 'Parcelle')}</th>
                  <th className="px-2 py-2">{t('dashboard.operationType', "Type d'opération")}</th>
                </tr>
              </thead>
              <tbody>
                {visibleActivity.map((item) => {
                  const meta = activityMeta[item.type];
                  return (
                    <tr
                      key={`${item.type}-${item.id}`}
                      className="border-t border-[hsl(var(--border)/0.5)] hover:bg-[hsl(var(--surface-bright)/0.4)] cursor-pointer transition-colors"
                      onClick={() => setOpModal({ type: item.type, id: item.id })}
                    >
                      <td className="px-2 py-2 text-muted-foreground whitespace-nowrap">{formatRelative(item.operation_date)}</td>
                      <td className="px-2 py-2 font-medium text-foreground truncate max-w-[200px]">
                        {item.plot_name ?? t('dashboard.unknownPlot', 'Unknown plot')}
                      </td>
                      <td className="px-2 py-2">
                        <span className="inline-flex items-center gap-1.5">
                          <span className="h-2 w-2 rounded-full" style={{ background: meta.color }} />
                          <span style={{ color: meta.color }} className="font-semibold">{meta.label}</span>
                        </span>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <OperationDetailsModal
        open={!!opModal}
        type={opModal?.type ?? null}
        id={opModal?.id ?? null}
        onClose={() => setOpModal(null)}
      />
    </div>
  );
};

export default DashboardPage;
