import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import iconExport from '@/assets/icons/icon-export.png';
import iconFilter from '@/assets/icons/icon-filter.png';
import CampaignFilter from '@/components/ui/CampaignFilter';
import DateFilter from '@/components/ui/DateFilter';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import type { ReportFiltersState } from '@/hooks/useReportFilters';

interface Props {
  filters: ReportFiltersState;
  showPlotFilter?: boolean;
  onExport?: () => void;
  /** Crop/culture filter slot — rendered between Campaign and Plot per customer spec. */
  cropFilter?: ReactNode;
  /** Optional right-aligned extras (e.g. chart mode toggle). */
  extras?: ReactNode;
}

/**
 * Filter order (left → right) per customer request:
 *   Campagne · Culture · Parcelle · Jour (date from → date to)
 */
const ReportToolbar = ({ filters, showPlotFilter = true, onExport, cropFilter, extras }: Props) => {
  const { t } = useTranslation();
  const plotsQuery = usePlotsForFilter();
  const plots = plotsQuery.data ?? [];

  return (
    <div className="report-toolbar no-print flex flex-wrap items-center gap-2">
      <img src={iconFilter} alt="" className="h-4 w-4 opacity-60" />

      {/* 1. Campagne */}
      <CampaignFilter value={filters.campaignId} onChange={filters.setCampaignId} />

      {/* 2. Culture (crop) */}
      {cropFilter}

      {/* 3. Parcelle */}
      {showPlotFilter && (
        <select
          className="filter-select w-44 sm:w-48"
          value={filters.plotId}
          onChange={(e) => filters.setPlotId(e.target.value)}
        >
          <option value="all">{t('reports.allPlots', 'All plots')}</option>
          {plots.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
      )}

      {/* 4. Jour (date range) */}
      <DateFilter value={filters.dateFrom} onChange={filters.setDateFrom} placeholder={t('reports.from', 'From')} />
      <DateFilter value={filters.dateTo} onChange={filters.setDateTo} placeholder={t('reports.to', 'To')} />

      <div className="ml-auto flex items-center gap-2">
        {extras}
        {onExport && (
          <button onClick={onExport} className="btn-primary-glass h-8 px-3 text-[12px]">
            <img src={iconExport} alt="" className="h-4 w-4" />
            {t('reports.export', 'Export')}
          </button>
        )}
        <button
          onClick={() => window.print()}
          className="btn-primary-glass h-8 px-3 text-[12px]"
          title={t('reports.printHint', 'Print or save as PDF')}
        >
          🖨 {t('reports.print', 'Print / PDF')}
        </button>
      </div>
    </div>
  );
};

export default ReportToolbar;
