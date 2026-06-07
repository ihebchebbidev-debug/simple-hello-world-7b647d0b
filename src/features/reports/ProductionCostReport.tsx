import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
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
  irrigation: number;
  fertilization: number;
  phytosanitary: number;
  harvest: number;
  total: number;
}

const round2 = (n: number) => Math.round(n * 100) / 100;

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
      .map((r) => ({
        plot: r.plot_name,
        irrigation: round2(r.irrigation_cost),
        fertilization: round2(r.fertilization_cost),
        phytosanitary: round2(r.phytosanitary_cost),
        harvest: round2(r.harvest_cost),
        total: round2(r.total_cost),
      }));
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

  const sumOf = (key: keyof Omit<CostRow, 'plot'>) =>
    round2(costs.reduce((s, r) => s + r[key], 0));

  const handleExport = () => exportCSV(
    costs.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot,
      [t('table.irrigationCost', 'Irrigation')]: `${r.irrigation} TND`,
      [t('table.fertilizationCost', 'Fertilization')]: `${r.fertilization} TND`,
      [t('table.phytosanitaryCost', 'Phytosanitary')]: `${r.phytosanitary} TND`,
      [t('table.harvestCost', 'Harvest')]: `${r.harvest} TND`,
      [t('table.totalCost', 'Total')]: `${r.total} TND`,
    })),
    'production-costs',
  );

  return (
    <div className="space-y-4 animate-fade-in">
      <ReportToolbar
        filters={filters}
        onExport={handleExport}
        extras={(
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


      <div className="stat-card-glass">
        <div className="flex items-center justify-between">
          <div>
            <p className="label-md">{t('table.totalCost', 'Total cost')}</p>
            <p className="display-md text-foreground mt-1">
              {sumOf('total').toLocaleString()}{' '}
              <span className="text-sm font-normal text-muted-foreground">TND</span>
            </p>
          </div>
          <p className="text-[13px] text-muted-foreground">
            {t('reports.plotsCount', { count: costs.length, defaultValue: '{{count}} plot(s)' })}
          </p>
        </div>
      </div>

      <ReportTableCard
        title={t('reports.costBreakdown', 'Cost breakdown')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={filteredCosts.length}
        totalCount={costs.length}
        pagination={pagination}
        minWidth={760}
      >
        <table className="data-table min-w-[760px]">
          <thead>
            <tr>
              <th>{t('table.plot', 'Plot')}</th>
              <th>{t('table.irrigationCost', 'Irrigation')}</th>
              <th>{t('table.fertilizationCost', 'Fertilization')}</th>
              <th>{t('table.phytosanitaryCost', 'Phytosanitary')}</th>
              <th>{t('table.harvestCost', 'Harvest')}</th>
              <th>{t('table.totalCost', 'Total')}</th>
            </tr>
          </thead>
          <tbody>
            {pagination.pageRows.map((row) => (
              <tr key={row.plot}>
                <td className="font-medium text-foreground">{row.plot}</td>
                <td>{row.irrigation.toLocaleString()} TND</td>
                <td>{row.fertilization.toLocaleString()} TND</td>
                <td>{row.phytosanitary.toLocaleString()} TND</td>
                <td>{row.harvest.toLocaleString()} TND</td>
                <td className="font-bold text-foreground">{row.total.toLocaleString()} TND</td>
              </tr>
            ))}
            <TableSkeletonRows
              colSpan={6}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && costs.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && costs.length === 0}
              onRetry={() => reportQuery.refetch()}
            />
            {costs.length > 0 && (
              <tr>
                <td className="font-bold text-foreground">{t('common.total', 'Total')}</td>
                <td className="font-semibold">{sumOf('irrigation').toLocaleString()} TND</td>
                <td className="font-semibold">{sumOf('fertilization').toLocaleString()} TND</td>
                <td className="font-semibold">{sumOf('phytosanitary').toLocaleString()} TND</td>
                <td className="font-semibold">{sumOf('harvest').toLocaleString()} TND</td>
                <td className="font-bold" style={{ color: 'hsl(var(--primary-glow))' }}>
                  {sumOf('total').toLocaleString()} TND
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </ReportTableCard>
    </div>
  );
};

export default ProductionCostReport;
