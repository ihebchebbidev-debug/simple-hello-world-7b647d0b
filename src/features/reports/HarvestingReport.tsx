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
}

const HarvestingReport = () => {
  const { t } = useTranslation();
  const filters = useReportFilters();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');

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
    }));
  }, [reportQuery.data]);

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) =>
      `${r.plot_name} ${r.total_workers} ${r.total_quantity}`.toLowerCase().includes(q),
    );
  }, [rows, search]);

  const pagination = usePagination({
    rows: filteredRows,
    resetKey: `${search}|${filters.resetKey}`,
  });

  const handleExport = () => exportCSV(
    filteredRows.map((r) => ({
      [t('table.plot', 'Plot')]: r.plot_name,
      "Main d'œuvre (homme/jour)": r.total_workers,
      'Quantité récoltée (kg)': r.total_quantity,
    })),
    'harvest-report',
  );

  return (
    <div className="space-y-3 sm:space-y-4 animate-fade-in">
      <ReportToolbar filters={filters} onExport={handleExport} />

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
              </tr>
            ))}
            <TableSkeletonRows
              colSpan={3}
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
