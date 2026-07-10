import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
import { usePagination } from '@/hooks/usePagination';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import { useReportFilters } from '@/hooks/useReportFilters';
import { exportCSV } from '@/lib/export';

interface HarvestItem {
  id: string;
  date: string;
  num_workers: number;
  days_worked: number;
  quantity_harvested: number;
  daily_rate_at_entry: number;
}
interface PlotGroup { plot_id: string; plot_name: string; harvests: HarvestItem[] }
interface ApiData { plots: PlotGroup[] }

interface PlotRow {
  plot_id: string;
  plot_name: string;
  total_workers: number;
  total_quantity: number;
  total_cost: number;
}

const HarvestingReport = () => {
  const { t } = useTranslation();
  const filters = useReportFilters();
  const plotsQuery = usePlotsForFilter();
  const plots = plotsQuery.data ?? [];
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [cropFilter, setCropFilter] = useState('all');

  const cropTypes = useMemo(() => (
    Array.from(new Set(plots
      .map((plot) => plot.crop_type)
      .filter((type): type is string => Boolean(type))),
    ).sort()
  ), [plots]);

  const reportQuery = useQuery<ApiData>({
    queryKey: ['report-harvest', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: ApiData }>('/reports/harvest', { params: filters.apiParams });
      return (data as { data?: ApiData }).data ?? { plots: [] };
    },
  });

  const rows = useMemo<PlotRow[]>(() => {
    const groups = reportQuery.data?.plots ?? [];
    return groups.map((g) => ({
      plot_id: g.plot_id,
      plot_name: g.plot_name,
      total_workers: Math.round(
        g.harvests.reduce((s, h) => s + h.num_workers * h.days_worked, 0) * 100,
      ) / 100,
      total_quantity: Math.round(
        g.harvests.reduce((s, h) => s + Number(h.quantity_harvested || 0), 0) * 100,
      ) / 100,
      total_cost: g.harvests.reduce((s, h) => s + (h.num_workers * h.days_worked * (h.daily_rate_at_entry || 0)), 0),
    }));
  }, [reportQuery.data]);

  const rowsAfterCrop = useMemo(() => {
    if (cropFilter === 'all') return rows;
    const allowedPlots = new Set(plots.filter((p) => p.crop_type === cropFilter).map((p) => p.id));
    return rows.filter((row) => allowedPlots.has(row.plot_id));
  }, [cropFilter, plots, rows]);

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    const targetRows = rowsAfterCrop;
    if (!q) return targetRows;
    return targetRows.filter((r) =>
      `${r.plot_name} ${r.total_workers} ${r.total_quantity} ${r.total_quantity > 0 ? (r.total_cost / r.total_quantity).toFixed(3) : "0.000"}`.toLowerCase().includes(q),
    );
  }, [rowsAfterCrop, search]);

  const pagination = usePagination({
    rows: filteredRows,
    resetKey: `${search}|${filters.resetKey}`,
  });

  const handleExport = () => exportCSV(
    filteredRows.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot_name,
      "Main d'œuvre (homme/jour)": r.total_workers,
      'Quantité récoltée (kg)': r.total_quantity,
      'Coût du kg (dt)': r.total_quantity > 0 ? Number((r.total_cost / r.total_quantity).toFixed(3)) : 0,
    })),
    'harvest-report',
  );

  const cropFilterControl = (
    <select
      className="filter-select w-44 sm:w-48"
      value={cropFilter}
      onChange={(e) => setCropFilter(e.target.value)}
    >
      <option value="all">{t('reports.allCropTypes', 'All crops')}</option>
      {cropTypes.map((crop) => <option key={crop} value={crop}>{crop}</option>)}
    </select>
  );

  return (
    <div className="space-y-3 sm:space-y-4 animate-fade-in">
      <ReportToolbar filters={filters} onExport={handleExport} cropFilter={cropFilterControl} />

      <ReportTableCard
        title={t('reports.harvestLog', 'Harvest log')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={filteredRows.length}
        totalCount={rows.length}
        pagination={pagination}
        minWidth={420}
      >
        <table className="data-table min-w-[420px]">
          <thead>
            <tr>
              <th>{t('table.plot', 'Plot')}</th>
              <th>{t('table.workerDays', "Main d'œuvre (homme/jour)")}</th>
              <th>{t('table.quantityHarvested', 'Quantité récoltée (kg)')}</th>
              <th>{t('table.costPerKg', 'Coût du kg (dt)')}</th>
            </tr>
          </thead>
          <tbody>
            {pagination.pageRows.map((row) => (
              <tr
                key={row.plot_id}
                onClick={() => navigate(`/reports/history/harvest/${row.plot_id}`)}
                title={t('reports.clickHistory', 'Click to view harvest history')}
                className="cursor-pointer"
              >
                <td className="font-medium text-foreground">{row.plot_name}</td>
                <td>{row.total_workers}</td>
                <td className="font-semibold text-foreground">{row.total_quantity.toLocaleString()} kg</td>
                <td className="font-medium text-foreground">
                  {row.total_quantity > 0 ? (row.total_cost / row.total_quantity).toFixed(3) : "0.000"} dt
                </td>
              </tr>
            ))}
            <TableSkeletonRows
              colSpan={4}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && rows.length === 0)}
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

export default HarvestingReport;
