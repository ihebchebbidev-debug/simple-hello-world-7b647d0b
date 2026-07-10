import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, LabelList } from 'recharts';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';

const COLORS = {
  irrigation: '#4da6ff',
  fertilization: '#43b596',
  phytosanitary: '#ffb84d',
  harvest: '#f07167'
};

const renderCustomTotalLabel = (props: any) => {
  const { x, y, width, value } = props;
  if (!value) return null;
  return (
    <text x={x + width / 2} y={y - 10} fill="hsl(var(--foreground))" textAnchor="middle" fontSize={12} fontWeight="bold">
      {`${Math.round(value).toLocaleString()} TND`}
    </text>
  );
};
import { usePagination } from '@/hooks/usePagination';
import { useReportFilters } from '@/hooks/useReportFilters';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import { exportCSV } from '@/lib/export';

interface ApiPlotCost {
  plot_id: string;
  plot_name: string;
  surface_area_ha: number;
  irrigation_cost: number;
  fertilization_cost: number;
  phytosanitary_cost: number;
  harvest_cost: number;
  total_cost: number;
  cost_per_ha: number | null;
}
interface ApiData { plots: ApiPlotCost[]; grand_total: number }

interface CostRow {
  plot: string;
  surface: number;
  irrigation: number;
  fertilization: number;
  phytosanitary: number;
  harvest: number;
  total: number;
}

const round2 = (n: number) => Math.round(n * 100) / 100;
const perHa = (cost: number, surface: number) => (surface > 0 ? round2(cost / surface) : 0);

const ProductionCostReport = () => {
  const { t } = useTranslation();
  const filters = useReportFilters();
  const plotsQuery = usePlotsForFilter();
  const [search, setSearch] = useState('');
  const [cropFilter, setCropFilter] = useState('all');

  const cropTypes = useMemo(
    () => Array.from(new Set((plotsQuery.data ?? []).map((p) => p.crop_type).filter(Boolean) as string[])).sort(),
    [plotsQuery.data],
  );

  const reportQuery = useQuery<ApiData>({
    queryKey: ['report-cost', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: ApiData }>('/reports/production-cost', { params: filters.apiParams });
      return (data as { data?: ApiData }).data ?? { plots: [], grand_total: 0 };
    },
  });

  const cropPlotIds = useMemo(() => {
    if (cropFilter === 'all') return null;
    return new Set((plotsQuery.data ?? []).filter((p) => p.crop_type === cropFilter).map((p) => p.id));
  }, [cropFilter, plotsQuery.data]);

  const costs = useMemo<CostRow[]>(() => {
    const rows = reportQuery.data?.plots ?? [];
    return rows
      .filter((r) => !cropPlotIds || cropPlotIds.has(r.plot_id))
      .map((r) => {
        const surface = Number(r.surface_area_ha) || 0;
        return {
          plot: r.plot_name,
          surface,
          irrigation: perHa(r.irrigation_cost, surface),
          fertilization: perHa(r.fertilization_cost, surface),
          phytosanitary: perHa(r.phytosanitary_cost, surface),
          harvest: perHa(r.harvest_cost, surface),
          total: perHa(r.total_cost, surface),
        };
      });
  }, [reportQuery.data, cropPlotIds]);

  const filteredCosts = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return costs;
    return costs.filter((r) =>
      `${r.plot} ${r.irrigation} ${r.fertilization} ${r.phytosanitary} ${r.harvest} ${r.total}`
        .toLowerCase().includes(q),
    );
  }, [costs, search]);

  const pagination = usePagination({
    rows: filteredCosts,
    resetKey: `${search}|${filters.resetKey}`,
  });

  const handleExport = () => exportCSV(
    costs.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot,
      [t('table.irrigationCost', 'Irrigation') + ' /ha']: `${r.irrigation} TND/ha`,
      [t('table.fertilizationCost', 'Fertilization') + ' /ha']: `${r.fertilization} TND/ha`,
      [t('table.phytosanitaryCost', 'Phytosanitary') + ' /ha']: `${r.phytosanitary} TND/ha`,
      [t('table.harvestCost', 'Harvest') + ' /ha']: `${r.harvest} TND/ha`,
      [t('table.totalCost', 'Total') + ' /ha']: `${r.total} TND/ha`,
    })),
    'production-costs',
  );

  return (
    <div className="space-y-4 animate-fade-in">
      <ReportToolbar
        filters={filters}
        onExport={handleExport}
        cropFilter={(
          <select
            className="filter-select w-44 sm:w-48"
            value={cropFilter}
            onChange={(e) => setCropFilter(e.target.value)}
          >
            <option value="all">{t('reports.allCropTypes', 'All crops')}</option>
            {cropTypes.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        )}
      />

      <ReportTableCard
        title={t('reports.costBreakdown', 'Cost breakdown per hectare')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={filteredCosts.length}
        totalCount={costs.length}
        pagination={pagination}
        minWidth={760}
      >
        {(!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && costs.length === 0)) ? (
          <div className="h-[500px] flex items-center justify-center text-muted-foreground min-w-[760px]">
            {t('common.loading', 'Loading...')}
          </div>
        ) : reportQuery.isError ? (
          <div className="h-[500px] flex flex-col items-center justify-center text-destructive min-w-[760px] gap-2">
            <p>{t('common.error', 'Error loading data')}</p>
            <button onClick={() => reportQuery.refetch()} className="btn-primary text-sm px-3 py-1">
              {t('common.retry', 'Retry')}
            </button>
          </div>
        ) : costs.length === 0 ? (
          <div className="h-[500px] flex items-center justify-center text-muted-foreground min-w-[760px]">
            {t('common.noData', 'No data available')}
          </div>
        ) : (
          <div className="min-w-[760px] h-[500px] pt-4">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={pagination.pageRows}
                margin={{ top: 30, right: 30, left: 20, bottom: 60 }}
              >
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="hsl(var(--border))" />
                <XAxis 
                  dataKey="plot" 
                  tick={{ fill: 'hsl(var(--foreground))' }}
                  tickMargin={10}
                  angle={-45}
                  textAnchor="end"
                />
                <YAxis 
                  tickFormatter={(val) => `${val}`}
                  tick={{ fill: 'hsl(var(--foreground))' }}
                  label={{ value: t('reports.costPerHa', 'Coût (TND/ha)'), angle: -90, position: 'insideLeft', style: { fill: 'hsl(var(--foreground))' } }}
                />
                <Tooltip 
                  formatter={(value: number) => `${Math.round(value).toLocaleString()} TND`}
                  cursor={{ fill: 'hsl(var(--muted))', opacity: 0.4 }}
                  contentStyle={{ borderRadius: '8px', border: '1px solid hsl(var(--border))', backgroundColor: 'hsl(var(--card))', color: 'hsl(var(--foreground))' }}
                />
                <Legend wrapperStyle={{ paddingTop: '20px' }} />
                <Bar dataKey="irrigation" name={t('table.irrigationCost', 'Irrigation') + ' /ha'} stackId="a" fill={COLORS.irrigation}>
                  <LabelList dataKey="irrigation" position="inside" fill="#fff" fontSize={11} formatter={(val: number) => val > 0 ? Math.round(val) : ''} />
                </Bar>
                <Bar dataKey="fertilization" name={t('table.fertilizationCost', 'Fertilisation') + ' /ha'} stackId="a" fill={COLORS.fertilization}>
                  <LabelList dataKey="fertilization" position="inside" fill="#fff" fontSize={11} formatter={(val: number) => val > 0 ? Math.round(val) : ''} />
                </Bar>
                <Bar dataKey="phytosanitary" name={t('table.phytosanitaryCost', 'Phytosanitaire') + ' /ha'} stackId="a" fill={COLORS.phytosanitary}>
                  <LabelList dataKey="phytosanitary" position="inside" fill="#fff" fontSize={11} formatter={(val: number) => val > 0 ? Math.round(val) : ''} />
                </Bar>
                <Bar dataKey="harvest" name={t('table.harvestCost', 'Récolte') + ' /ha'} stackId="a" fill={COLORS.harvest}>
                  <LabelList dataKey="harvest" position="inside" fill="#fff" fontSize={11} formatter={(val: number) => val > 0 ? Math.round(val) : ''} />
                  <LabelList dataKey="total" position="top" content={renderCustomTotalLabel} />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </ReportTableCard>
    </div>
  );
};

export default ProductionCostReport;
