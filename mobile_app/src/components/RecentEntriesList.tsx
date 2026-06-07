/**
 * Recent entries list shown at the bottom of every operation form page.
 *
 * Shows the last 10 entries for the current operation type, merged from:
 *   - the local outbox (pending / failed writes) — always available offline
 *   - the cached server snapshot (refreshed when online)
 *
 * Per-row badge indicates synced / pending / failed so the technician can
 * tell at a glance which entries already reached the server.
 */
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { RefreshCw, Check, Clock, AlertCircle, WifiOff } from 'lucide-react';
import { useRecentEntries, type RecentEntryView } from '@/hooks/useRecentEntries';
import { useReferenceData } from '@/hooks/useReferenceData';
import type { RecentOperationType } from '@/lib/db';

interface Props { type: RecentOperationType }

const RecentEntriesList = ({ type }: Props) => {
  const { t, i18n } = useTranslation();
  const { entries, loading, refreshing, refresh, online } = useRecentEntries(type);
  const { data: refs } = useReferenceData();
  const [plotFilter, setPlotFilter] = useState<string>('all');


  const plotName = (id: unknown): string => {
    const p = refs.plots.find((pl) => pl.id === String(id));
    return p ? p.name : (id ? String(id) : '—');
  };

  const fmtDate = (iso: string) => {
    if (!iso) return '';
    try {
      return new Date(iso).toLocaleDateString(i18n.language, { day: '2-digit', month: 'short', year: '2-digit' });
    } catch { return iso; }
  };

  const summary = (e: RecentEntryView): string => {
    const p = e.payload as Record<string, unknown>;
    switch (type) {
      case 'irrigation': {
        const q = p.water_quantity ?? p.quantity;
        return q != null ? `${q} ${t('recent.unitWater')}` : '';
      }
      case 'fertilization': {
        const items = Array.isArray(p.items) ? p.items : [];
        if (items.length) return t('recent.itemsCount', { count: items.length });
        const q = p.quantity_applied ?? p.quantity;
        return q != null ? `${q}` : '';
      }
      case 'phytosanitary': {
        const items = Array.isArray(p.items) ? p.items : [];
        const target = p.target_pest ? ` · ${p.target_pest}` : '';
        if (items.length) return `${t('recent.itemsCount', { count: items.length })}${target}`;
        return String(p.target_pest ?? '');
      }
      case 'harvest': {
        const q = p.quantity_harvested ?? p.quantity;
        const w = p.num_workers ?? p.workers;
        const parts: string[] = [];
        if (q != null) parts.push(`${q} kg`);
        if (w != null) parts.push(`${w} ${t('recent.workers')}`);
        return parts.join(' · ');
      }
    }
  };

  // Plots present in current entries (for the filter dropdown)
  const plotOptions = useMemo(() => {
    const map = new Map<string, string>();
    entries.forEach((e) => {
      const pid = (e.payload as Record<string, unknown>).plot_id;
      if (pid == null) return;
      const id = String(pid);
      if (!map.has(id)) map.set(id, plotName(id));
    });
    return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entries, refs.plots]);

  const visibleEntries = useMemo(() => {
    if (plotFilter === 'all') return entries;
    return entries.filter((e) => String((e.payload as Record<string, unknown>).plot_id ?? '') === plotFilter);
  }, [entries, plotFilter]);

  return (
    <section className="mt-2" aria-label={t('recent.title')}>
      <div className="flex items-center justify-between mb-2 gap-2">
        <h3 className="text-sm font-semibold text-foreground">{t('recent.title')}</h3>
        <div className="flex items-center gap-2">
          {plotOptions.length > 1 && (
            <select
              value={plotFilter}
              onChange={(e) => setPlotFilter(e.target.value)}
              className="cl-input h-8 rounded-lg text-xs px-2 max-w-[140px]"
              aria-label={t('recent.filterByPlot', 'Filtrer par parcelle')}
            >
              <option value="all">{t('recent.allPlots', 'Toutes parcelles')}</option>
              {plotOptions.map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
          )}
          <button
            type="button"
            onClick={() => void refresh()}
            disabled={refreshing || !online}
            className="btn-ghost h-8 px-2 flex items-center gap-1 text-xs"
            aria-label={t('recent.refresh')}
          >
            <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />
            {online ? t('recent.refresh') : t('common.offline')}
          </button>
        </div>
      </div>


      {loading ? (
        <ul className="space-y-2">
          {[0, 1, 2].map((i) => (
            <li key={i} className="h-12 rounded-xl bg-surface-high/60 animate-pulse" />
          ))}
        </ul>
      ) : visibleEntries.length === 0 ? (
        <p className="text-xs text-muted-foreground px-1 py-3">
          {online ? t('recent.empty') : (
            <span className="inline-flex items-center gap-1.5">
              <WifiOff className="h-3.5 w-3.5" /> {t('recent.emptyOffline')}
            </span>
          )}
        </p>
      ) : (
        <ul className="space-y-1.5">
          {visibleEntries.map((e) => {

            const StatusIcon = e.status === 'synced' ? Check : e.status === 'failed' ? AlertCircle : Clock;
            const statusClass =
              e.status === 'synced'  ? 'text-[hsl(var(--accent-success,142_71%_45%))]' :
              e.status === 'failed'  ? 'text-[hsl(var(--accent-danger))]' :
                                       'text-[hsl(var(--accent-warning,38_92%_50%))]';
            const statusLabel =
              e.status === 'synced'  ? t('recent.synced')  :
              e.status === 'failed'  ? t('recent.failed')  :
                                       t('recent.pending');
            return (
              <li
                key={e.key}
                className="flex items-center gap-3 rounded-xl bg-surface-high/80 border border-border/60 px-3 py-2"
              >
                <StatusIcon className={`h-4 w-4 shrink-0 ${statusClass}`} aria-label={statusLabel} />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 text-sm text-foreground truncate">
                    <span className="font-medium truncate">{plotName((e.payload as Record<string, unknown>).plot_id)}</span>
                    <span className="text-muted-foreground text-xs shrink-0">{fmtDate(e.operationDate)}</span>
                  </div>
                  <p className="text-xs text-muted-foreground truncate">{summary(e) || '—'}</p>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
};

export default RecentEntriesList;
