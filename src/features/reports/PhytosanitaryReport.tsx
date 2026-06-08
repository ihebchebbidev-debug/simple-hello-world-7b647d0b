import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import iconSearch from '@/assets/icons/icon-search.png';
import { api } from '@/lib/api';
import ReportTableCard from '@/components/reports/ReportTableCard';
import ReportToolbar from '@/components/reports/ReportToolbar';
import TableSkeletonRows from '@/components/reports/TableSkeletonRows';
import { usePagination } from '@/hooks/usePagination';
import { useReportFilters } from '@/hooks/useReportFilters';
import { usePlotsForFilter } from '@/hooks/usePlotsForFilter';
import { exportCSV } from '@/lib/export';
import { formatDateFr } from '@/lib/format';
import OperationDetailsModal from '@/features/dashboard/OperationDetailsModal';

interface Treatment {
  id: string;
  date: string;
  pesticide_id: string;
  pesticide_name: string;
  pesticide_unit: string;
  chemical_composition: string;
  quantity_applied: number;
  pesticide_per_ha: number | null;
  water_volume_l: number | null;
  volume_per_ha: number | null;
  target_pest: string | null;
  remarks: string | null;
  price_at_entry: number;
}
interface PlotGroup { plot_id: string; plot_name: string; surface_area_ha: number; treatments: Treatment[] }
interface ApiData { plots: PlotGroup[] }

interface FlatRow {
  id: string;
  plot_id: string;
  plot_name: string;
  operation_date: string;
  pesticide_name: string;
  chemical_composition: string;
  quantity_applied: number;
  target_pest: string | null;
  remarks: string | null;
  surface_ha: number;
  pesticide_per_ha: number;
  volume_per_ha: number | null;
}

const ColFilter = ({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder?: string }) => (
  <div className="relative">
    <img src={iconSearch} alt="" className="absolute left-1.5 top-1/2 -translate-y-1/2 h-3 w-3 opacity-40" />
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="h-6 w-full rounded pl-5 pr-1 text-[10px] font-normal normal-case tracking-normal bg-[hsl(var(--surface-container-highest))] focus:outline-none focus:ring-1 focus:ring-ring"
    />
  </div>
);

const PhytosanitaryReport = () => {
  const { t } = useTranslation();
  const filters = useReportFilters();
  const plotsQuery = usePlotsForFilter();
  const [search, setSearch] = useState('');
  const [cropFilter, setCropFilter] = useState('all');
  const [colFilters, setColFilters] = useState({
    date: '', plot: '', product: '', composition: '', pest: '', remarks: '',
  });
  const [editId, setEditId] = useState<string | null>(null);
  const setCol = (key: keyof typeof colFilters) => (v: string) =>
    setColFilters((prev) => ({ ...prev, [key]: v }));

  const cropTypes = useMemo(
    () => Array.from(new Set((plotsQuery.data ?? []).map((p) => p.crop_type).filter(Boolean) as string[])).sort(),
    [plotsQuery.data],
  );

  const reportQuery = useQuery<ApiData>({
    queryKey: ['report-phyto', filters.apiParams],
    enabled: filters.filtersReady,
    queryFn: async () => {
      const { data } = await api.get<{ data: ApiData }>('/reports/phytosanitary', { params: filters.apiParams });
      return (data as { data?: ApiData }).data ?? { plots: [] };
    },
  });

  const enriched = useMemo<FlatRow[]>(() => {
    const groups = reportQuery.data?.plots ?? [];
    const plots = plotsQuery.data ?? [];
    const out: FlatRow[] = [];
    groups.forEach((g) => {
      // Prefer surface from the report payload (authoritative for the join),
      // fall back to the plots lookup, and finally 0 to avoid divide-by-zero.
      const surface =
        g.surface_area_ha
        || plots.find((p) => p.id === g.plot_id)?.surface_area_ha
        || 0;
      g.treatments.forEach((tx) => {
        const perHa = tx.pesticide_per_ha
          ?? (surface > 0 ? Math.round((tx.quantity_applied / surface) * 1000) / 1000 : 0);
        const volPerHa = tx.volume_per_ha
          ?? (tx.water_volume_l != null && surface > 0
            ? Math.round((tx.water_volume_l / surface) * 1000) / 1000
            : null);
        out.push({
          id: tx.id,
          plot_id: g.plot_id,
          plot_name: g.plot_name,
          operation_date: tx.date,
          pesticide_name: tx.pesticide_name,
          chemical_composition: tx.chemical_composition ?? '',
          quantity_applied: tx.quantity_applied,
          target_pest: tx.target_pest,
          remarks: tx.remarks,
          surface_ha: surface,
          pesticide_per_ha: perHa,
          volume_per_ha: volPerHa,
        });
      });
    });
    // Newest operations first (client feedback).
    out.sort((a, b) => (b.operation_date > a.operation_date ? 1 : b.operation_date < a.operation_date ? -1 : 0));
    return out;
  }, [reportQuery.data, plotsQuery.data]);

  const cropPlotIds = useMemo(() => {
    if (cropFilter === 'all') return null;
    return new Set((plotsQuery.data ?? []).filter((p) => p.crop_type === cropFilter).map((p) => p.id));
  }, [cropFilter, plotsQuery.data]);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    const f = colFilters;
    return enriched.filter((op) => {
      if (cropPlotIds && !cropPlotIds.has(op.plot_id)) return false;
      if (f.date && !op.operation_date.includes(f.date)) return false;
      if (f.plot && !op.plot_name.toLowerCase().includes(f.plot.toLowerCase())) return false;
      if (f.product && !op.pesticide_name.toLowerCase().includes(f.product.toLowerCase())) return false;
      if (f.composition && !op.chemical_composition.toLowerCase().includes(f.composition.toLowerCase())) return false;
      if (f.pest && !(op.target_pest ?? '').toLowerCase().includes(f.pest.toLowerCase())) return false;
      if (f.remarks && !(op.remarks ?? '').toLowerCase().includes(f.remarks.toLowerCase())) return false;
      if (!q) return true;
      const hay = `${op.operation_date} ${op.plot_name} ${op.pesticide_name} ${op.chemical_composition} ${op.target_pest ?? ''} ${op.remarks ?? ''} ${op.pesticide_per_ha} ${op.volume_per_ha ?? ''}`;
      return hay.toLowerCase().includes(q);
    });
  }, [enriched, colFilters, search, cropPlotIds]);

  const pagination = usePagination({
    rows: filtered,
    resetKey: `${search}|${filters.resetKey}|${cropFilter}|${Object.values(colFilters).join('|')}`,
  });

  const handleExport = () => exportCSV(
    filtered.map((r) => ({
      [t('table.date', 'Date')]: formatDateFr(r.operation_date),
      [t('table.plot', 'Plot')]: r.plot_name,
      [t('table.product', 'Product')]: r.pesticide_name,
      [t('table.composition', 'Composition')]: r.chemical_composition,
      [t('table.pesticidePerHa', 'Pesticide /ha')]: `${r.pesticide_per_ha} / ha`,
      [t('table.volumePerHa', 'Volume /ha')]: r.volume_per_ha != null ? `${r.volume_per_ha} / ha` : '',
      [t('table.pest', 'Target pest')]: r.target_pest ?? '',
      [t('table.remarks', 'Remarks')]: r.remarks ?? '',
    })),
    'phytosanitary-report',
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

  return (
    <div className="space-y-3 sm:space-y-4 animate-fade-in">
      <ReportToolbar filters={filters} onExport={handleExport} cropFilter={cropFilterControl} />

      <ReportTableCard
        title={t('reports.treatmentLog', 'Treatment log')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={filtered.length}
        totalCount={enriched.length}
        pagination={pagination}
        minWidth={1120}
      >
        <table className="data-table min-w-[1120px]">
          <thead>
            <tr>
              <th><div className="flex flex-col gap-1">{t('table.date', 'Date')}<ColFilter value={colFilters.date} onChange={setCol('date')} placeholder="yyyy-mm-dd" /></div></th>
              <th><div className="flex flex-col gap-1">{t('table.plot', 'Plot')}<ColFilter value={colFilters.plot} onChange={setCol('plot')} /></div></th>
              <th><div className="flex flex-col gap-1">{t('table.product', 'Product')}<ColFilter value={colFilters.product} onChange={setCol('product')} /></div></th>
              <th><div className="flex flex-col gap-1">{t('table.composition', 'Composition')}<ColFilter value={colFilters.composition} onChange={setCol('composition')} /></div></th>
              <th>{t('table.pesticidePerHa', 'Pesticide /ha')}</th>
              <th>{t('table.volumePerHa', 'Volume /ha')}</th>
              <th><div className="flex flex-col gap-1">{t('table.pest', 'Target pest')}<ColFilter value={colFilters.pest} onChange={setCol('pest')} /></div></th>
              <th><div className="flex flex-col gap-1">{t('table.remarks', 'Remarks')}<ColFilter value={colFilters.remarks} onChange={setCol('remarks')} /></div></th>
            </tr>
          </thead>
          <tbody>
            {pagination.pageRows.map((row) => (
              <tr
                key={row.id}
                onDoubleClick={() => setEditId(row.id)}
                title={t('reports.dblClickEdit', 'Double-click to edit')}
                className="cursor-pointer"
              >

                <td className="font-medium text-foreground whitespace-nowrap">{formatDateFr(row.operation_date)}</td>
                <td>{row.plot_name}</td>
                <td className="text-foreground">{row.pesticide_name}</td>
                <td className="text-[11px]">{row.chemical_composition}</td>
                <td className="font-semibold text-foreground whitespace-nowrap">{row.pesticide_per_ha}</td>
                <td className="whitespace-nowrap">{row.volume_per_ha != null ? row.volume_per_ha : '—'}</td>
                <td>{row.target_pest || '—'}</td>
                <td className="text-[11px]">{row.remarks || '—'}</td>
              </tr>
            ))}
            <TableSkeletonRows
              colSpan={8}
              isLoading={!filters.filtersReady || reportQuery.isLoading || (reportQuery.isFetching && enriched.length === 0)}
              isError={reportQuery.isError && !reportQuery.isFetching}
              isEmpty={filters.filtersReady && !reportQuery.isLoading && !reportQuery.isError && pagination.pageRows.length === 0}
              onRetry={() => reportQuery.refetch()}
            />
          </tbody>
        </table>
      </ReportTableCard>

      <OperationDetailsModal
        open={!!editId}
        type="phytosanitary"
        id={editId}
        onClose={() => setEditId(null)}
      />
    </div>
  );
};

export default PhytosanitaryReport;
