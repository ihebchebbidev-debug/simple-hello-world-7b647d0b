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

// 18 well-spaced hues so plots stay distinguishable on farms with many plots.
const PALETTE = [
  'hsl(142, 60%, 42%)', 'hsl(217, 91%, 60%)', 'hsl(35, 92%, 50%)',
  'hsl(12, 60%, 65%)',  'hsl(280, 60%, 60%)', 'hsl(180, 60%, 45%)',
  'hsl(45, 90%, 55%)',  'hsl(330, 60%, 60%)', 'hsl(160, 50%, 45%)',
  'hsl(255, 70%, 65%)', 'hsl(95, 55%, 45%)',  'hsl(200, 80%, 50%)',
  'hsl(20, 85%, 55%)',  'hsl(300, 55%, 55%)', 'hsl(120, 45%, 50%)',
  'hsl(60, 70%, 45%)',  'hsl(350, 75%, 62%)', 'hsl(230, 60%, 58%)',
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

interface ChartPlot { id: string; name: string }
type ChartRow = Record<string, number | string>;

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

const NUTRIENT_KEY: Record<Nutrient, keyof MonthlyApi> = {
  n: 'n_per_ha',
  p: 'p_per_ha',
  k: 'k_per_ha',
  mg: 'mg_per_ha',
  ca: 'ca_per_ha',
  s: 's_per_ha',
};

interface NutrientChartCardProps {
  title: string;
  data: ChartRow[];
  plots: ChartPlot[];
  mode: 'bar' | 'line';
  isLoading: boolean;
  isError: boolean;
  onRetry: () => void;
}

// One chart per nutrient. Each card keeps its own print-only PNG/SVG snapshot in
// sync because the live Recharts ResponsiveContainer doesn't paint into the print
// canvas (it exports as a grey box).
const NutrientChartCard = ({ title, data, plots, mode, isLoading, isError, onRetry }: NutrientChartCardProps) => {
  const { t } = useTranslation();
  const chartRef = useRef<HTMLDivElement>(null);
  const printImgRef = useRef<HTMLImageElement>(null);

  const refreshChartSnapshot = () => {
    const src = chartToDataUrl(chartRef.current);
    if (src && printImgRef.current) printImgRef.current.src = src;
  };

  useEffect(() => {
    const id = window.setTimeout(refreshChartSnapshot, 350);
    return () => window.clearTimeout(id);
  }, [data, mode, plots]);

  useEffect(() => {
    window.addEventListener('beforeprint', refreshChartSnapshot);
    return () => window.removeEventListener('beforeprint', refreshChartSnapshot);
  }, []);

  const ChartComponent = mode === 'bar' ? BarChart : LineChart;

  return (
    <div className="stat-card">
      <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 className="text-[13px] font-semibold text-foreground uppercase tracking-wider">
          {title}{' '}
          <span className="text-[11px] normal-case text-muted-foreground font-normal tracking-normal">(unité/ha)</span>
        </h3>
        <p className="text-[11px] text-muted-foreground">
          {t('reports.plotsCount', { count: plots.length, defaultValue: '{{count}} plot(s)' })}
        </p>
      </div>
      <div ref={chartRef} className="no-print" style={{ width: '100%', height: 300 }}>
        {isLoading ? (
          <div className="flex items-center justify-center h-full">
            <div className="w-full max-w-md space-y-3 px-6">
              <div className="h-3 w-32 rounded bg-[hsl(var(--surface-bright))] animate-pulse" />
              <div className="h-44 w-full rounded bg-[hsl(var(--surface-bright))] animate-pulse opacity-70" />
            </div>
          </div>
        ) : isError ? (
          <div className="flex items-center justify-center h-full gap-3 px-4 text-sm">
            <span className="text-[hsl(var(--accent-danger))]">{t('reports.loadFailed')}</span>
            <button type="button" onClick={onRetry} className="btn-secondary text-xs">
              {t('common.retry', 'Réessayer')}
            </button>
          </div>
        ) : data.length === 0 || plots.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">{t('reports.noData')}</p>
        ) : (
          <ResponsiveContainer>
            <ChartComponent data={data} margin={{ top: 5, right: 10, left: -10, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
              <XAxis dataKey="month" tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} axisLine={false} tickLine={false} />
              <Tooltip contentStyle={tooltipStyle} cursor={mode === 'bar' ? { fill: 'hsl(var(--surface-container-lowest))' } : undefined} />
              <Legend wrapperStyle={{ fontSize: '11px', paddingTop: '8px' }} />
              {plots.map((plot, i) => {
                const color = PALETTE[i % PALETTE.length];
                return mode === 'bar'
                  ? <Bar key={plot.name} dataKey={plot.name} fill={color} radius={[4, 4, 0, 0]} maxBarSize={20} isAnimationActive={false} />
                  : <Line key={plot.name} type="monotone" dataKey={plot.name} stroke={color} strokeWidth={2.2} dot={{ r: 3 }} activeDot={{ r: 5 }} connectNulls isAnimationActive={false} />;
              })}
            </ChartComponent>
          </ResponsiveContainer>
        )}
      </div>
      {/* Print/PDF fallback — a flattened snapshot of the chart above. */}
      <img ref={printImgRef} alt="" className="print-only" style={{ width: '100%', height: 'auto' }} />
      {plots.length > 0 && (
        <div className="print-only" style={{ marginTop: '8px' }}>
          {plots.map((plot, i) => (
            <span
              key={plot.id}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', marginRight: '14px', fontSize: '11px', color: '#111' }}
            >
              <span style={{ width: 10, height: 10, borderRadius: 2, background: PALETTE[i % PALETTE.length], display: 'inline-block' }} />
              {plot.name}
            </span>
          ))}
        </div>
      )}
    </div>
  );
};

const FertilizationReport = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const filters = useReportFilters({ defaultActiveCampaign: true });
  const plotsQuery = usePlotsForFilter();
  const [cropFilter, setCropFilter] = useState('all');
  const [chartMode, setChartMode] = useState<'bar' | 'line'>('line');
  const [searchCumul, setSearchCumul] = useState('');

  const openHistory = (plotId: string, plotName: string, params?: { nutrient?: Nutrient; fertilizerId?: string }) => {
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
    queryFn: async (): Promise<ApiData> => {
      const { data } = await api.get<{ data: ApiData }>('/reports/fertilization', { params: filters.apiParams });
      return data.data ?? { monthly: [], cumulative: [], compositionless: [] };
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
    () => (cropPlotIds ? monthly.filter((m: MonthlyApi) => cropPlotIds.has(m.plot_id)) : monthly),
    [monthly, cropPlotIds],
  );
  const visibleCumul = useMemo(
    () => (cropPlotIds ? cumulative.filter((c: CumulApi) => cropPlotIds.has(c.plot_id)) : cumulative),
    [cumulative, cropPlotIds],
  );

  // Distinct sorted YYYY-MM keys
  const months = useMemo(() => {
    const set = new Set<string>();
    visibleMonthly.forEach((m: MonthlyApi) => set.add(`${m.year}-${String(m.month).padStart(2, '0')}`));
    return [...set].sort();
  }, [visibleMonthly]);

  // Distinct plots with at least one monthly row — these become the chart series.
  const chartPlots = useMemo<ChartPlot[]>(() => {
    const map = new Map<string, string>();
    visibleMonthly.forEach((m: MonthlyApi) => map.set(m.plot_id, m.plot_name));
    return Array.from(map.entries()).map(([id, name]) => ({ id, name }));
  }, [visibleMonthly]);

  // Pre-aggregate every nutrient per plot per month in a single pass, then shape
  // each nutrient into Recharts rows (one row per month, one key per plot).
  const chartDataByNutrient = useMemo(() => {
    const acc = new Map<string, Record<string, number>>(); // `${nutrient}|${plotId}|${ym}` bucket
    const bucket = (nutrient: Nutrient): Record<string, number> => {
      const existing = acc.get(nutrient);
      if (existing) return existing;
      const fresh: Record<string, number> = {};
      acc.set(nutrient, fresh);
      return fresh;
    };
    (Object.keys(NUTRIENT_KEY) as Nutrient[]).forEach((nutrient) => {
      const key = NUTRIENT_KEY[nutrient];
      const store = bucket(nutrient);
      visibleMonthly.forEach((m) => {
        const ym = `${m.year}-${String(m.month).padStart(2, '0')}`;
        const cell = `${m.plot_id}|${ym}`;
        store[cell] = (store[cell] ?? 0) + ((m[key] as number | null) ?? 0);
      });
    });

    const result = {} as Record<Nutrient, ChartRow[]>;
    (Object.keys(NUTRIENT_KEY) as Nutrient[]).forEach((nutrient) => {
      const store = acc.get(nutrient) ?? {};
      result[nutrient] = months.map((ym) => {
        const [year, mo] = ym.split('-');
        const row: ChartRow = { month: `${MONTH_LABELS[Number(mo) - 1]} ${year.slice(2)}` };
        chartPlots.forEach(({ id, name }) => {
          const value = store[`${id}|${ym}`];
          if (value) row[name] = round1(value);
        });
        return row;
      });
    });
    return result;
  }, [visibleMonthly, months, chartPlots]);

  const cumulRows = useMemo<PlotCumulRow[]>(() =>
    visibleCumul.map((c: CumulApi) => ({
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
    compositionless.forEach((row: CompositionlessApiRow) => map.set(row.fertilizer_id, row.fertilizer_name));
    return Array.from(map.entries()).map(([fertilizerId, fertilizer]) => ({ fertilizerId, fertilizer }));
  }, [compositionless]);

  const compositionlessRows = useMemo<CompositionlessRow[]>(() => {
    const rowsMap = new Map<string, CompositionlessRow>();
    compositionless.forEach((row: CompositionlessApiRow) => {
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

  const chartLoading = !filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && !reportQuery.data);
  const chartError = reportQuery.isError && !reportQuery.isFetching;

  const NUTRIENTS: { nutrient: Nutrient; title: string }[] = [
    { nutrient: 'n', title: t('reports.nitrogen', 'NITROGEN') },
    { nutrient: 'p', title: t('reports.phosphorus', 'PHOSPHORUS') },
    { nutrient: 'k', title: t('reports.potassium', 'POTASSIUM') },
    { nutrient: 'mg', title: t('reports.magnesium', 'MAGNESIUM') },
    { nutrient: 'ca', title: t('reports.calcium', 'CALCIUM') },
    { nutrient: 's', title: t('reports.sulfur', 'SULFUR') },
  ];

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="stat-card !p-3 sm:!p-4">
        <ReportToolbar filters={filters} onExport={handleExport} cropFilter={cropFilterControl} extras={chartToggle} />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        {NUTRIENTS.map(({ nutrient, title }) => (
          <NutrientChartCard
            key={nutrient}
            title={title}
            data={chartDataByNutrient[nutrient]}
            plots={chartPlots}
            mode={chartMode}
            isLoading={chartLoading}
            isError={chartError}
            onRetry={() => reportQuery.refetch()}
          />
        ))}
      </div>

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
