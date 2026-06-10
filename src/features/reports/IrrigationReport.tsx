import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  Bar, BarChart, CartesianGrid, Legend, Line, LineChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
import { usePagination } from '@/hooks/usePagination';
import { useReportFilters } from '@/hooks/useReportFilters';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import { exportCSV } from '@/lib/export';
import { chartToDataUrl } from '@/lib/chartPrint';

const PALETTE = [
  'hsl(142, 60%, 42%)', 'hsl(217, 91%, 60%)', 'hsl(35, 92%, 50%)',
  'hsl(12, 60%, 65%)',  'hsl(280, 60%, 60%)', 'hsl(180, 60%, 45%)',
  'hsl(45, 90%, 55%)',  'hsl(330, 60%, 60%)', 'hsl(160, 50%, 45%)',
];

const tooltipStyle = {
  background: 'hsl(var(--card))',
  border: 'none',
  borderRadius: '8px',
  fontSize: '12px',
  color: 'hsl(var(--foreground))',
  padding: '8px 12px',
};

const MONTH_LABELS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

interface MonthlyApi {
  plot_id: string;
  plot_name: string;
  year: number;
  month: number;
  total_quantity: number;
  per_hectare: number | null;
}
interface CumulApi {
  plot_id: string;
  plot_name: string;
  surface_area_ha: number;
  total_quantity: number;
  per_hectare: number | null;
  since: string | null;
}
interface IrrigationReportData {
  monthly: MonthlyApi[];
  cumulative: CumulApi[];
}

const IrrigationReport = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const filters = useReportFilters();
  const plotsQuery = usePlotsForFilter();
  const [cropFilter, setCropFilter] = useState('all');
  const [chartMode, setChartMode] = useState<'bar' | 'line'>('line');
  const [search, setSearch] = useState('');

  const openHistory = (plotId: string, plotName: string) => {
    const qs = new URLSearchParams({ plot_name: plotName });
    if (filters.apiParams.date_from) qs.set('date_from', String(filters.apiParams.date_from));
    if (filters.apiParams.date_to) qs.set('date_to', String(filters.apiParams.date_to));
    navigate(`/reports/history/irrigation/${plotId}?${qs.toString()}`);
  };

  const reportQuery = useQuery<IrrigationReportData>({
    queryKey: ['report-irrigation', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: IrrigationReportData }>('/reports/irrigation', { params: filters.apiParams });
      return (data as { data?: IrrigationReportData }).data ?? { monthly: [], cumulative: [] };
    },
  });

  const monthly = reportQuery.data?.monthly ?? [];
  const cumulative = reportQuery.data?.cumulative ?? [];
  const plots = plotsQuery.data ?? [];

  const cropTypes = useMemo(
    () => Array.from(new Set(plots.map((p) => p.crop_type).filter(Boolean) as string[])).sort(),
    [plots],
  );

  const filteredPlotIds = useMemo(() => {
    if (cropFilter === 'all') return null;
    return new Set(plots.filter((p) => p.crop_type === cropFilter).map((p) => p.id));
  }, [plots, cropFilter]);

  // Build chart series: per-plot per-month per_hectare value
  const chartPlotsList = useMemo(() => {
    const ids = new Set<string>();
    monthly.forEach((m) => {
      if (filteredPlotIds && !filteredPlotIds.has(m.plot_id)) return;
      ids.add(m.plot_id);
    });
    const plotsBy: Record<string, string> = {};
    monthly.forEach((m) => { plotsBy[m.plot_id] = m.plot_name; });
    return Array.from(ids).slice(0, 5).map((id) => ({ id, name: plotsBy[id] ?? id }));
  }, [monthly, filteredPlotIds]);

  const chartData = useMemo(() => {
    const buckets = new Map<string, Record<string, number | string>>();
    monthly.forEach((m) => {
      if (filteredPlotIds && !filteredPlotIds.has(m.plot_id)) return;
      const key = `${m.year}-${String(m.month).padStart(2, '0')}`;
      const label = `${MONTH_LABELS[m.month - 1]} ${String(m.year).slice(2)}`;
      const row = buckets.get(key) ?? { month: label };
      const plotName = chartPlotsList.find((p) => p.id === m.plot_id)?.name;
      if (plotName) row[plotName] = m.per_hectare ?? m.total_quantity;
      buckets.set(key, row);
    });
    return Array.from(buckets.entries()).sort(([a], [b]) => a.localeCompare(b)).map(([, v]) => v);
  }, [monthly, filteredPlotIds, chartPlotsList]);

  const totalByPlot = useMemo(() => cumulative
    .filter((c) => !filteredPlotIds || filteredPlotIds.has(c.plot_id))
    .map((c) => ({
      plotId: c.plot_id,
      plot: c.plot_name,
      total: Math.round(c.total_quantity),
      perHa: c.per_hectare !== null ? Math.round(c.per_hectare * 10) / 10 : 0,
    })), [cumulative, filteredPlotIds]);

  const searchedRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return totalByPlot;
    return totalByPlot.filter((r) =>
      `${r.plot} ${r.total} ${r.perHa}`.toLowerCase().includes(q),
    );
  }, [totalByPlot, search]);

  const pagination = usePagination({
    rows: searchedRows,
    resetKey: `${search}|${filters.resetKey}|${cropFilter}`,
  });

  const handleExport = () => exportCSV(
    totalByPlot.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot,
      'm3/parcelle': r.total,
      'm3/ha': r.perHa,
    })),
    'irrigation-report',
  );

  const ChartComponent = chartMode === 'bar' ? BarChart : LineChart;

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

  const chartToggle = (
    <div className="flex rounded-md overflow-hidden bg-[hsl(var(--surface-container-highest))]">
      {(['bar', 'line'] as const).map((mode) => (
        <button
          key={mode}
          onClick={() => setChartMode(mode)}
          className={`px-3 h-9 text-[11px] font-medium transition-colors ${
            chartMode === mode
              ? 'bg-[hsl(var(--primary)/0.2)] text-[hsl(var(--primary-glow))]'
              : 'text-muted-foreground'
          }`}
        >
          {mode === 'bar' ? t('reports.chartBar', 'Bar') : t('reports.chartLine', 'Line')}
        </button>
      ))}
    </div>
  );

  return (
    <div className="space-y-3 sm:space-y-4 animate-fade-in">
      <div className="stat-card !p-3 sm:!p-4">
        <ReportToolbar
          filters={filters}
          showPlotFilter
          onExport={handleExport}
          cropFilter={cropFilterControl}
          extras={chartToggle}
        />
      </div>


      <div className="stat-card">
        <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
          <h3 className="text-[13px] font-semibold text-foreground" style={{ textTransform: 'none' }}>m3/ha</h3>
          <p className="text-[11px] text-muted-foreground">
            {t('reports.plotsCount', { count: chartPlotsList.length, defaultValue: '{{count}} plot(s)' })} · {cropFilter === 'all' ? t('reports.allCropTypes', 'All crops') : cropFilter}
          </p>
        </div>
        <div style={{ width: '100%', height: 320 }}>
          {!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && !reportQuery.data) ? (
            <div className="flex items-center justify-center h-full">
              <div className="w-full max-w-md space-y-3 px-6">
                <div className="h-3 w-32 rounded bg-[hsl(var(--surface-bright))] animate-pulse" />
                <div className="h-48 w-full rounded bg-[hsl(var(--surface-bright))] animate-pulse opacity-70" />
              </div>
            </div>
          ) : reportQuery.isError && !reportQuery.isFetching ? (
            <div className="flex items-center justify-center h-full gap-3 px-4 text-sm">
              <span className="text-[hsl(var(--accent-danger))]">{t('reports.loadFailed')}</span>
              <button type="button" onClick={() => reportQuery.refetch()} className="btn-secondary text-xs">
                {t('common.retry', 'Réessayer')}
              </button>
            </div>
          ) : chartData.length === 0 ? (
            <p className="px-4 py-10 text-center text-sm text-muted-foreground">{t('reports.noData')}</p>
          ) : (
            <ResponsiveContainer>
              <ChartComponent data={chartData} margin={{ top: 5, right: 10, left: -10, bottom: 5 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
                <XAxis dataKey="month" tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
                <Tooltip contentStyle={tooltipStyle} cursor={chartMode === 'bar' ? { fill: 'hsl(var(--surface-container-lowest))' } : undefined} />
                <Legend wrapperStyle={{ fontSize: '11px', paddingTop: '8px' }} />
                {chartPlotsList.map((plot, i) => {
                  const color = PALETTE[i % PALETTE.length];
                  return chartMode === 'bar'
                    ? <Bar key={plot.name} dataKey={plot.name} fill={color} radius={[4, 4, 0, 0]} maxBarSize={20} />
                    : <Line key={plot.name} type="monotone" dataKey={plot.name} stroke={color} strokeWidth={2.2} dot={{ r: 3 }} activeDot={{ r: 5 }} />;
                })}
              </ChartComponent>
            </ResponsiveContainer>
          )}
        </div>
      </div>

      <ReportTableCard
        title={t('reports.totalWater', 'Total water per plot')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={searchedRows.length}
        totalCount={totalByPlot.length}
        pagination={pagination}
        minWidth={360}
      >
        <table className="data-table min-w-[360px]">
          <thead>
            <tr>
              <th>{t('table.plot', 'Plot')}</th>
              <th style={{ textTransform: 'none' }}>m3/parcelle</th>
              <th style={{ textTransform: 'none' }}>m3/ha</th>

            </tr>
          </thead>
          <tbody>
            {pagination.pageRows.map((row) => (
              <tr
                key={row.plotId}
                onDoubleClick={() => openHistory(row.plotId, row.plot)}
                title={t('reports.dblClickHistory', 'Double-click to view operations history')}
                className="cursor-pointer"
              >

                <td className="font-medium text-foreground">{row.plot}</td>
                <td className="font-semibold text-foreground">{row.total.toLocaleString()}</td>
                <td>{row.perHa}</td>
              </tr>
            ))}
            <TableSkeletonRows
              colSpan={3}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && totalByPlot.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && pagination.pageRows.length === 0}
              onRetry={() => reportQuery.refetch()}
            />
          </tbody>
        </table>
      </ReportTableCard>

    </div>
  );
};

export default IrrigationReport;
