import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
import { usePagination } from '@/hooks/usePagination';
import { useReportFilters } from '@/hooks/useReportFilters';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import { exportCSV } from '@/lib/export';
import { formatMonthFr } from '@/lib/format';

interface MonthlyApi {
  plot_id: string;
  plot_name: string;
  year: number;
  month: number;
  n_total: number;
  p_total: number;
  k_total: number;
  n_per_ha: number | null;
  p_per_ha: number | null;
  k_per_ha: number | null;
  mg_per_ha: number | null;
  ca_per_ha: number | null;
  s_per_ha: number | null;
}
interface CumulApi {
  plot_id: string;
  plot_name: string;
  surface_area_ha: number;
  since: string | null;
  n_per_ha: number | null;
  p_per_ha: number | null;
  k_per_ha: number | null;
  mg_per_ha: number | null;
  ca_per_ha: number | null;
  s_per_ha: number | null;
}
interface ApiData { monthly: MonthlyApi[]; cumulative: CumulApi[]; compositionless: CompositionlessApiRow[] }

type Nutrient = 'n' | 'p' | 'k' | 'mg' | 'ca' | 's';

interface PlotMonthlyRow {
  plotId: string;
  plot: string;
  byMonth: Record<string, number>;
}

interface PlotCumulRow {
  plotId: string;
  plot: string;
  n: number;
  p: number;
  k: number;
  mg: number;
  ca: number;
  s: number;
}

interface CompositionlessApiRow {
  plot_id: string;
  plot_name: string;
  fertilizer_id: string;
  fertilizer_name: string;
  total_quantity: number;
}

interface CompositionlessRow {
  plotId: string;
  plot: string;
  byFertilizer: Record<string, number>;
}

const round1 = (n: number) => Math.round(n * 10) / 10;

const FertilizationReport = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const filters = useReportFilters({ defaultActiveCampaign: true });
  const plotsQuery = usePlotsForFilter();
  const [cropFilter, setCropFilter] = useState('all');
  const [searchCumul, setSearchCumul] = useState('');

  const openHistory = (plotId: string, plotName: string, params?: { nutrient?: 'n' | 'p' | 'k' | 'mg' | 'ca' | 's'; fertilizerId?: string }) => {
    const qs = new URLSearchParams({ plot_name: plotName });
    if (filters.apiParams.date_from) qs.set('date_from', String(filters.apiParams.date_from));
    if (filters.apiParams.date_to) qs.set('date_to', String(filters.apiParams.date_to));
    if (params?.nutrient) qs.set('nutrient', params.nutrient);
    if (params?.fertilizerId) qs.set('fertilizer_id', params.fertilizerId);
    navigate(`/reports/history/fertilization/${plotId}?${qs.toString()}`);
  };

  const reportQuery = useQuery<ApiData>({
    queryKey: ['report-fertilization', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: ApiData }>('/reports/fertilization', { params: filters.apiParams });
      return (data as { data?: ApiData }).data ?? { monthly: [], cumulative: [] };
    },
  });

  const monthly = reportQuery.data?.monthly ?? [];
  const cumulative = reportQuery.data?.cumulative ?? [];
  const compositionless = reportQuery.data?.compositionless ?? [];
  const plots = plotsQuery.data ?? [];

  const cropTypes = useMemo(
    () => Array.from(new Set(plots.map((p) => p.crop_type).filter(Boolean) as string[])).sort(),
    [plots],
  );

  const cropPlotIds = useMemo(() => {
    if (cropFilter === 'all') return null;
    return new Set(plots.filter((p) => p.crop_type === cropFilter).map((p) => p.id));
  }, [cropFilter, plots]);

  const visibleMonthly = useMemo(
    () => (cropPlotIds ? monthly.filter((m) => cropPlotIds.has(m.plot_id)) : monthly),
    [monthly, cropPlotIds],
  );
  const visibleCumul = useMemo(
    () => (cropPlotIds ? cumulative.filter((c) => cropPlotIds.has(c.plot_id)) : cumulative),
    [cumulative, cropPlotIds],
  );

  // Distinct sorted YYYY-MM keys
  const months = useMemo(() => {
    const set = new Set<string>();
    visibleMonthly.forEach((m) => set.add(`${m.year}-${String(m.month).padStart(2, '0')}`));
    return [...set].sort();
  }, [visibleMonthly]);

  // Distinct plots with at least one monthly row
  const monthlyPlots = useMemo(() => {
    const map = new Map<string, string>();
    visibleMonthly.forEach((m) => map.set(m.plot_id, m.plot_name));
    return Array.from(map.entries()).map(([plotId, plot]) => ({ plotId, plot }));
  }, [visibleMonthly]);

  const buildPivot = (nutrient: Nutrient): PlotMonthlyRow[] => {
    const key: keyof MonthlyApi = nutrient === 'n'
      ? 'n_per_ha'
      : nutrient === 'p'
        ? 'p_per_ha'
        : nutrient === 'k'
          ? 'k_per_ha'
          : nutrient === 'mg'
            ? 'mg_per_ha'
            : nutrient === 'ca'
              ? 'ca_per_ha'
              : 's_per_ha';
    return monthlyPlots.map(({ plotId, plot }) => {
      const byMonth: Record<string, number> = {};
      months.forEach((mm) => { byMonth[mm] = 0; });
      visibleMonthly
        .filter((m) => m.plot_id === plotId)
        .forEach((m) => {
          const k = `${m.year}-${String(m.month).padStart(2, '0')}`;
          byMonth[k] = round1((byMonth[k] ?? 0) + (m[key] ?? 0));
        });
      return { plotId, plot, byMonth };
    });
  };

  const azoteRows = useMemo(() => buildPivot('n'), [visibleMonthly, monthlyPlots, months]);
  const phosphoreRows = useMemo(() => buildPivot('p'), [visibleMonthly, monthlyPlots, months]);
  const potassiumRows = useMemo(() => buildPivot('k'), [visibleMonthly, monthlyPlots, months]);
  const magnesiumRows = useMemo(() => buildPivot('mg'), [visibleMonthly, monthlyPlots, months]);
  const calciumRows = useMemo(() => buildPivot('ca'), [visibleMonthly, monthlyPlots, months]);
  const sulfurRows = useMemo(() => buildPivot('s'), [visibleMonthly, monthlyPlots, months]);

  const cumulRows = useMemo<PlotCumulRow[]>(() =>
    visibleCumul.map((c) => ({
      plotId: c.plot_id,
      plot: c.plot_name,
      n: round1(c.n_per_ha ?? 0),
      p: round1(c.p_per_ha ?? 0),
      k: round1(c.k_per_ha ?? 0),
      mg: round1(c.mg_per_ha ?? 0),
      ca: round1(c.ca_per_ha ?? 0),
      s: round1(c.s_per_ha ?? 0),
    })),
    [visibleCumul],
  );

  const filteredCumul = useMemo(() => {
    const q = searchCumul.trim().toLowerCase();
    return q ? cumulRows.filter((r) => r.plot.toLowerCase().includes(q)) : cumulRows;
  }, [cumulRows, searchCumul]);

  const cumulPg = usePagination({
    rows: filteredCumul,
    resetKey: `${searchCumul}|${filters.resetKey}|${cropFilter}`,
  });

  const compositionlessFertilizers = useMemo(() => {
    const map = new Map<string, string>();
    compositionless.forEach((row) => map.set(row.fertilizer_id, row.fertilizer_name));
    return Array.from(map.entries()).map(([fertilizerId, fertilizer]) => ({ fertilizerId, fertilizer }));
  }, [compositionless]);

  const compositionlessRows = useMemo<CompositionlessRow[]>(() => {
    const rowsMap = new Map<string, CompositionlessRow>();
    compositionless.forEach((row) => {
      if (!rowsMap.has(row.plot_id)) {
        rowsMap.set(row.plot_id, { plotId: row.plot_id, plot: row.plot_name, byFertilizer: {} });
      }
      const plotRow = rowsMap.get(row.plot_id)!;
      plotRow.byFertilizer[row.fertilizer_id] = round1((plotRow.byFertilizer[row.fertilizer_id] ?? 0) + row.total_quantity);
    });
    return Array.from(rowsMap.values());
  }, [compositionless]);

  const handleExport = () => exportCSV(
    cumulRows.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot,
      'N (unité/ha)': r.n,
      'P (unité/ha)': r.p,
      'K (unité/ha)': r.k,
      'Mg (unité/ha)': r.mg,
      'Ca (unité/ha)': r.ca,
      'S (unité/ha)': r.s,
    })),
    'fertilization-report',
  );

  const cropFilterControl = (
    <select
      className="filter-select w-44 sm:w-48"
      value={cropFilter}
      onChange={(e) => setCropFilter(e.target.value)}
    >
      <option value="all">{t('reports.allCropTypes', 'All crops')}</option>
      {cropTypes.map((c) => <option key={c} value={c}>{c}</option>)}
    </select>
  );

  const renderPivot = (title: string, rows: PlotMonthlyRow[], nutrient: Nutrient) => (
    <div className="stat-card">
      <h3 className="text-[13px] font-semibold text-foreground mb-3 uppercase tracking-wider">
        {title}{' '}
        <span className="text-[11px] normal-case text-muted-foreground font-normal">(unité/ha)</span>
      </h3>
      <div className="overflow-x-auto -mx-1">
        <table className="data-table min-w-[420px]">
          <thead>
            <tr>
              <th>{t('table.plot', 'Plot')}</th>
              {months.map((m) => <th key={m}>{formatMonthFr(m)}</th>)}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row.plotId}
                onDoubleClick={() => openHistory(row.plotId, row.plot, { nutrient })}
                title={t('reports.dblClickHistory', 'Double-click to view operations history')}
                className="cursor-pointer"
              >
                <td className="font-medium text-foreground">{row.plot}</td>
                {months.map((m) => <td key={m}>{row.byMonth[m] || '—'}</td>)}
              </tr>
            ))}

            <TableSkeletonRows
              colSpan={Math.max(2, months.length + 1)}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && rows.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && (rows.length === 0 || months.length === 0)}
              onRetry={() => reportQuery.refetch()}
            />
          </tbody>
        </table>
      </div>
    </div>
  );

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="stat-card !p-3 sm:!p-4">
        <ReportToolbar filters={filters} onExport={handleExport} cropFilter={cropFilterControl} />
      </div>


      {renderPivot(t('reports.nitrogen', 'NITROGEN'), azoteRows, 'n')}
      {renderPivot(t('reports.phosphorus', 'PHOSPHORUS'), phosphoreRows, 'p')}
      {renderPivot(t('reports.potassium', 'POTASSIUM'), potassiumRows, 'k')}
      {renderPivot(t('reports.magnesium', 'MAGNESIUM'), magnesiumRows, 'mg')}
      {renderPivot(t('reports.calcium', 'CALCIUM'), calciumRows, 'ca')}
      {renderPivot(t('reports.sulfur', 'SULFUR'), sulfurRows, 's')}

      <ReportTableCard
        title={t('reports.cumulativeElements', 'Eléments cumulés par hectare')}
        subtitle={t('reports.cumulativeNPKSub', 'Σ since campaign start')}
        search={searchCumul}
        onSearchChange={setSearchCumul}
        filteredCount={filteredCumul.length}
        totalCount={cumulRows.length}
        pagination={cumulPg}
      >
        <table className="data-table mt-2">
          <thead>
            <tr>
              <th>{t('table.plot', 'Plot')}</th>
              <th>N (unité/ha)</th>
              <th>P (unité/ha)</th>
              <th>K (unité/ha)</th>
              <th>Mg (unité/ha)</th>
              <th>Ca (unité/ha)</th>
              <th>S (unité/ha)</th>
            </tr>
          </thead>
          <tbody>
            {cumulPg.pageRows.map((row) => (
              <tr
                key={row.plotId}
                onDoubleClick={() => openHistory(row.plotId, row.plot)}
                title={t('reports.dblClickHistory', 'Double-click to view operations history')}
                className="cursor-pointer"
              >
                <td className="font-medium text-foreground">{row.plot}</td>
                <td className="font-semibold text-foreground">{row.n}</td>
                <td className="font-semibold text-foreground">{row.p}</td>
                <td className="font-semibold text-foreground">{row.k}</td>
                <td className="font-semibold text-foreground">{row.mg}</td>
                <td className="font-semibold text-foreground">{row.ca}</td>
                <td className="font-semibold text-foreground">{row.s}</td>
              </tr>
            ))}

            <TableSkeletonRows
              colSpan={7}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && cumulRows.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && cumulPg.pageRows.length === 0}
              onRetry={() => reportQuery.refetch()}
            />
          </tbody>
        </table>
      </ReportTableCard>

      <ReportTableCard
        title={t('reports.compositionlessFertilizers', 'Fertilizers without composition')}
        subtitle={t('reports.compositionlessFertilizersSub', 'Total quantity by plot and fertilizer, history drill-down')}
      >
        <div className="overflow-x-auto">
          <table className="data-table mt-2 min-w-[640px]">
            <thead>
              <tr>
                <th>{t('table.plot', 'Plot')}</th>
                {compositionlessFertilizers.map((fertilizer) => (
                  <th key={fertilizer.fertilizerId}>{fertilizer.fertilizer}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {compositionlessRows.map((row) => (
                <tr key={row.plotId}>
                  <td className="font-medium text-foreground">{row.plot}</td>
                  {compositionlessFertilizers.map((fertilizer) => (
                    <td
                      key={fertilizer.fertilizerId}
                      onDoubleClick={() => openHistory(row.plotId, row.plot, { fertilizerId: fertilizer.fertilizerId })}
                      title={t('reports.dblClickHistory', 'Double-click to view operations history')}
                      className="cursor-pointer"
                    >
                      {row.byFertilizer[fertilizer.fertilizerId] ?? '—'}
                    </td>
                  ))}
                </tr>
              ))}
              {compositionlessRows.length === 0 && (
                <tr>
                  <td colSpan={Math.max(1, compositionlessFertilizers.length + 1)} className="py-4 text-center text-muted-foreground">
                    {t('reports.noCompositionlessFertilizers', 'No fertilizers without composition found for the selected filters.')}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </ReportTableCard>

    </div>
  );
};

export default FertilizationReport;
