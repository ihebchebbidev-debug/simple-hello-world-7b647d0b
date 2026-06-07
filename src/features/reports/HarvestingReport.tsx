import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
import { usePagination } from '@/hooks/usePagination';
import { useReportFilters } from '@/hooks/useReportFilters';
import { exportCSV } from '@/lib/export';
import { formatDateFr } from '@/lib/format';
import OperationDetailsModal from '@/features/dashboard/OperationDetailsModal';

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

interface FlatRow {
  id: string;
  plot_id: string;
  plot_name: string;
  operation_date: string;
  num_workers: number;
  days_worked: number;
  quantity_harvested: number;
}

const HarvestingReport = () => {
  const { t } = useTranslation();
  const filters = useReportFilters();
  const [search, setSearch] = useState('');
  const [editId, setEditId] = useState<string | null>(null);

  const reportQuery = useQuery<ApiData>({
    queryKey: ['report-harvest', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: ApiData }>('/reports/harvest', { params: filters.apiParams });
      return (data as { data?: ApiData }).data ?? { plots: [] };
    },
  });

  const flat = useMemo<FlatRow[]>(() => {
    const groups = reportQuery.data?.plots ?? [];
    const out: FlatRow[] = [];
    groups.forEach((g) => g.harvests.forEach((h) => out.push({
      id: h.id,
      plot_id: g.plot_id,
      plot_name: g.plot_name,
      operation_date: h.date,
      num_workers: h.num_workers,
      days_worked: h.days_worked,
      quantity_harvested: h.quantity_harvested,
    })));
    return out;
  }, [reportQuery.data]);

  const filteredOps = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return flat;
    return flat.filter((op) =>
      `${op.operation_date} ${op.plot_name} ${op.num_workers} ${op.quantity_harvested}`
        .toLowerCase().includes(q),
    );
  }, [flat, search]);

  const pagination = usePagination({
    rows: filteredOps,
    resetKey: `${search}|${filters.resetKey}`,
  });

  const handleExport = () => exportCSV(
    filteredOps.map((r) => ({
      [t('table.date', 'Date')]: formatDateFr(r.operation_date),
      [t('table.plot', 'Plot')]: r.plot_name,
      "Main d'œuvre (homme/jour)": Math.round(r.num_workers * r.days_worked * 100) / 100,
      'Quantité récoltée (kg)': r.quantity_harvested,
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
        filteredCount={filteredOps.length}
        totalCount={flat.length}
        pagination={pagination}
        minWidth={420}
      >
        <table className="data-table min-w-[420px]">
          <thead>
            <tr>
              <th>{t('table.date', 'Date')}</th>
              <th>{t('table.plot', 'Plot')}</th>
              <th>{t('table.workerDays', "Main d'œuvre (homme/jour)")}</th>
              <th>{t('table.quantityHarvested', 'Quantité récoltée (kg)')}</th>
            </tr>
          </thead>
          <tbody>
            {pagination.pageRows.map((row) => {
              const workerDays = Math.round(row.num_workers * row.days_worked * 100) / 100;
              return (
                <tr
                  key={row.id}
                  onDoubleClick={() => setEditId(row.id)}
                  title={t('reports.dblClickEdit', 'Double-click to edit')}
                  className="cursor-pointer"
                >

                  <td className="font-medium text-foreground whitespace-nowrap">{formatDateFr(row.operation_date)}</td>
                  <td>{row.plot_name}</td>
                  <td>{workerDays}</td>
                  <td className="font-semibold text-foreground">{row.quantity_harvested.toLocaleString()} kg</td>
                </tr>
              );
            })}
            <TableSkeletonRows
              colSpan={4}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && flat.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && pagination.pageRows.length === 0}
              onRetry={() => reportQuery.refetch()}
            />
          </tbody>
        </table>
      </ReportTableCard>


      <OperationDetailsModal
        open={!!editId}
        type="harvest"
        id={editId}
        onClose={() => setEditId(null)}
      />
    </div>
  );
};

export default HarvestingReport;
