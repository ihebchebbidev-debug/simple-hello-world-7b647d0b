/**
 * Dedicated page that lists every operation entry for a single plot.
 *
 * Reached by clicking a row in any per-plot report (irrigation, fertilization,
 * phytosanitary, harvest). Replaces the older modal so reports with many
 * entries (20+) get a full-width, paginated view and a real back button.
 */
import { useMemo, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { ArrowLeft } from 'lucide-react';
import { api } from '@/lib/api';
import { formatDateFr } from '@/lib/format';
import { usePagination } from '@/hooks/usePagination';
import ReportTableCard from '@/components/reports/ReportTableCard';
import OperationDetailsModal from '@/features/dashboard/OperationDetailsModal';

type OpType = 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';

const ENDPOINT: Record<OpType, string> = {
  irrigation: '/irrigation-operations',
  fertilization: '/fertilization-operations',
  phytosanitary: '/phytosanitary-operations',
  harvest: '/harvest-operations',
};

const PARENT_ROUTE: Record<OpType, string> = {
  irrigation: '/reports/irrigation',
  fertilization: '/reports/fertilization',
  phytosanitary: '/reports/phytosanitary',
  harvest: '/reports/harvesting',
};

interface OpRow {
  id: string;
  operation_date: string;
  plot?: { name?: string };
  water_quantity?: number;
  quantity_applied?: number;
  quantity_harvested?: number;
  fertilizer?: { name?: string };
  pesticide?: { name?: string };
  target_pest?: string | null;
  remarks?: string | null;
}

const PlotOperationsHistoryPage = () => {
  const { t } = useTranslation();
  const { type, plotId } = useParams<{ type: OpType; plotId: string }>();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<{ id: string } | null>(null);

  const dateFrom = params.get('date_from') ?? undefined;
  const dateTo = params.get('date_to') ?? undefined;
  const plotName = params.get('plot_name') ?? '';
  // v4: when drilling down from the N/P/K fertilization pivot we restrict
  // the listed entries to ops whose fertilizer contains that nutrient.
  const nutrient = params.get('nutrient') ?? undefined;

  const validType = (type ?? '') as OpType;
  const isValid = !!ENDPOINT[validType] && !!plotId;

  const list = useQuery({
    queryKey: ['plot-history-page', validType, plotId, dateFrom, dateTo, nutrient],
    enabled: isValid,
    queryFn: async () => {
      const { data } = await api.get<{ data: OpRow[] }>(ENDPOINT[validType], {
        params: {
          plot_id: plotId,
          date_from: dateFrom,
          date_to: dateTo,
          ...(validType === 'fertilization' && nutrient ? { nutrient } : {}),
          per_page: 100,
          sort: '-operation_date',
        },
      });
      const payload = (data as unknown as { data?: OpRow[] }).data;
      return Array.isArray(payload) ? payload : [];
    },
  });

  const rows = list.data ?? [];

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter((r) =>
      [
        r.operation_date,
        r.fertilizer?.name,
        r.pesticide?.name,
        r.target_pest,
        r.remarks,
      ].some((v) => (v ?? '').toString().toLowerCase().includes(q)),
    );
  }, [rows, search]);

  const pg = usePagination({ rows: filtered, resetKey: `${search}|${validType}|${plotId}` });

  const titleMap: Record<OpType, string> = {
    irrigation: t('dashboard.type.irrigation'),
    fertilization: t('dashboard.type.fertilization'),
    phytosanitary: t('dashboard.type.phytosanitary'),
    harvest: t('dashboard.type.harvest'),
  };

  const valueOf = (r: OpRow) => {
    if (validType === 'irrigation') return `${r.water_quantity ?? 0} m3`;
    if (validType === 'fertilization') return `${r.quantity_applied ?? 0} (${r.fertilizer?.name ?? '—'})`;
    if (validType === 'phytosanitary') return `${r.quantity_applied ?? 0} (${r.pesticide?.name ?? '—'})`;
    return `${r.quantity_harvested ?? 0} kg`;
  };

  if (!isValid) {
    return (
      <div className="p-6 text-sm text-[hsl(var(--accent-danger))]">
        {t('common.invalidRoute', 'Invalid route')}
      </div>
    );
  }

  return (
    <div className="space-y-4 animate-fade-in">
      <div className="flex items-center gap-3 flex-wrap">
        <button
          type="button"
          onClick={() => navigate(PARENT_ROUTE[validType])}
          className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          {t('common.back', 'Back')}
        </button>
        <div>
          <h1 className="text-base sm:text-lg font-semibold text-foreground">
            {titleMap[validType]} — {plotName || t('table.plot', 'Plot')}
          </h1>
          <p className="text-[11px] text-muted-foreground">
            {t('reports.historyHint', 'Click a row to edit or delete the entry.')}
          </p>
        </div>
      </div>

      {list.isError && (
        <div className="stat-card text-center text-sm text-[hsl(var(--accent-danger))]">
          {t('reports.loadFailed')}
        </div>
      )}

      <ReportTableCard
        title={t('reports.entries', 'Entries')}
        search={search}
        onSearchChange={setSearch}
        filteredCount={filtered.length}
        totalCount={rows.length}
        pagination={pg}
      >
        <table className="data-table mt-2">
          <thead>
            <tr>
              <th>{t('table.date', 'Date')}</th>
              <th>{t('common.value', 'Value')}</th>
              {validType === 'phytosanitary' && <th>{t('table.pest', 'Target pest')}</th>}
              <th>{t('table.remarks', 'Remarks')}</th>
            </tr>
          </thead>
          <tbody>
            {pg.pageRows.length === 0 ? (
              <tr>
                <td colSpan={validType === 'phytosanitary' ? 4 : 3} className="text-center py-8">
                  {list.isLoading ? t('common.loading') : t('common.noData', 'No data')}
                </td>
              </tr>
            ) : pg.pageRows.map((r) => (
              <tr
                key={r.id}
                onClick={() => setSelected({ id: r.id })}
                title={t('reports.clickEdit', 'Click to edit')}
                className="cursor-pointer"
              >
                <td className="font-medium text-foreground whitespace-nowrap">{formatDateFr(r.operation_date)}</td>
                <td>{valueOf(r)}</td>
                {validType === 'phytosanitary' && <td>{r.target_pest || '—'}</td>}
                <td className="text-muted-foreground truncate max-w-[260px]">{r.remarks || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ReportTableCard>

      <OperationDetailsModal
        open={!!selected}
        type={selected ? validType : null}
        id={selected?.id ?? null}
        onClose={() => {
          setSelected(null);
          list.refetch();
        }}
      />
    </div>
  );
};

export default PlotOperationsHistoryPage;
