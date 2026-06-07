import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import { api } from '@/lib/api';
import OperationDetailsModal from '@/features/dashboard/OperationDetailsModal';
import { formatDateFr } from '@/lib/format';

type OpType = 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';

const ENDPOINT: Record<OpType, string> = {
  irrigation: '/irrigation-operations',
  fertilization: '/fertilization-operations',
  phytosanitary: '/phytosanitary-operations',
  harvest: '/harvest-operations',
};

interface OpRow {
  id: string;
  operation_date: string;
  water_quantity?: number;
  quantity_applied?: number;
  quantity_harvested?: number;
  fertilizer?: { name?: string };
  pesticide?: { name?: string };
  target_pest?: string | null;
  remarks?: string | null;
}

interface Props {
  open: boolean;
  type: OpType | null;
  plotId: string | null;
  plotName?: string | null;
  dateFrom?: string;
  dateTo?: string;
  onClose: () => void;
}

const PlotOperationsHistoryModal = ({ open, type, plotId, plotName, dateFrom, dateTo, onClose }: Props) => {
  const { t } = useTranslation();
  const [selected, setSelected] = useState<{ type: OpType; id: string } | null>(null);

  const list = useQuery({
    queryKey: ['plot-op-history', type, plotId, dateFrom, dateTo],
    enabled: open && !!type && !!plotId,
    queryFn: async () => {
      const { data } = await api.get<{ data: OpRow[] }>(ENDPOINT[type!], {
        params: {
          plot_id: plotId,
          date_from: dateFrom,
          date_to: dateTo,
          per_page: 100,
          sort: '-operation_date',
        },
      });
      const payload = (data as unknown as { data?: OpRow[] }).data;
      return Array.isArray(payload) ? payload : [];
    },
  });

  if (!open || !type) return null;

  const valueOf = (r: OpRow) => {
    if (type === 'irrigation') return `${r.water_quantity ?? 0} m3`;
    if (type === 'fertilization') return `${r.quantity_applied ?? 0} (${r.fertilizer?.name ?? '—'})`;
    if (type === 'phytosanitary') return `${r.quantity_applied ?? 0} (${r.pesticide?.name ?? '—'})`;
    return `${r.quantity_harvested ?? 0} kg`;
  };

  const titleMap: Record<OpType, string> = {
    irrigation: t('dashboard.type.irrigation'),
    fertilization: t('dashboard.type.fertilization'),
    phytosanitary: t('dashboard.type.phytosanitary'),
    harvest: t('dashboard.type.harvest'),
  };

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title={`${titleMap[type]} — ${plotName ?? ''}`}
        description={t('reports.historyHint', 'Click a row to edit or delete the entry.')}
        size="lg"
      >
        {list.isLoading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}
        {list.isError && <p className="text-sm text-[hsl(var(--accent-danger))]">{t('reports.loadFailed')}</p>}
        {list.data && list.data.length === 0 && (
          <p className="text-sm text-muted-foreground py-6 text-center">{t('common.noData', 'No data')}</p>
        )}
        {list.data && list.data.length > 0 && (
          <div className="max-h-[60vh] overflow-y-auto -mx-1">
            <table className="w-full text-[12px]">
              <thead className="sticky top-0 bg-background">
                <tr className="text-left text-[10px] uppercase tracking-wider font-semibold text-muted-foreground">
                  <th className="px-2 py-2">{t('table.date', 'Date')}</th>
                  <th className="px-2 py-2">{t('common.value', 'Value')}</th>
                  {type === 'phytosanitary' && <th className="px-2 py-2">{t('table.pest', 'Target pest')}</th>}
                  <th className="px-2 py-2">{t('table.remarks', 'Remarks')}</th>
                </tr>
              </thead>
              <tbody>
                {list.data.map((r) => (
                  <tr
                    key={r.id}
                    onClick={() => setSelected({ type, id: r.id })}
                    className="border-t border-[hsl(var(--border)/0.5)] hover:bg-[hsl(var(--surface-bright)/0.4)] cursor-pointer transition-colors"
                  >
                    <td className="px-2 py-2 whitespace-nowrap font-medium text-foreground">{formatDateFr(r.operation_date)}</td>
                    <td className="px-2 py-2">{valueOf(r)}</td>
                    {type === 'phytosanitary' && <td className="px-2 py-2">{r.target_pest || '—'}</td>}
                    <td className="px-2 py-2 text-muted-foreground truncate max-w-[240px]">{r.remarks || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Modal>
      <OperationDetailsModal
        open={!!selected}
        type={selected?.type ?? null}
        id={selected?.id ?? null}
        onClose={() => {
          setSelected(null);
          list.refetch();
        }}
      />
    </>
  );
};

export default PlotOperationsHistoryModal;
