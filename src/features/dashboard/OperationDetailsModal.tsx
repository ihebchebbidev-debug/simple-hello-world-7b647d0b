import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import Modal from '@/components/Modal';
import { api } from '@/lib/api';

/**
 * Click-through editor for an operation row on the dashboard.
 * Loads the operation by id+type, lets an admin tweak the most
 * commonly-edited fields, then PUT / DELETE on the matching endpoint.
 * The 4 op types share the same plot_id / operation_date / campaign_id
 * shape; only the quantity / extra fields differ — handled inline.
 */

type OpType = 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';

const ENDPOINT: Record<OpType, string> = {
  irrigation: '/irrigation-operations',
  fertilization: '/fertilization-operations',
  phytosanitary: '/phytosanitary-operations',
  harvest: '/harvest-operations',
};

interface Props {
  open: boolean;
  type: OpType | null;
  id: string | null;
  onClose: () => void;
}

interface AnyOp {
  id: string;
  plot_id: string;
  plot?: { name?: string };
  campaign_id?: string | null;
  operation_date: string;
  // shared / variant fields
  water_quantity?: number;
  quantity_applied?: number;
  quantity_harvested?: number;
  num_workers?: number;
  days_worked?: number;
  water_volume_l?: number | null;
  target_pest?: string | null;
  remarks?: string | null;
  fertilizer?: { name?: string };
  pesticide?: { name?: string };
}

const OperationDetailsModal = ({ open, type, id, onClose }: Props) => {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [form, setForm] = useState<Record<string, string | number>>({});

  const baseUrl = type ? ENDPOINT[type] : '';

  const detail = useQuery({
    queryKey: ['op-detail', type, id],
    enabled: open && !!type && !!id,
    queryFn: async () => {
      const { data } = await api.get<{ data: AnyOp }>(`${baseUrl}/${id}`);
      return (data as { data?: AnyOp }).data ?? (data as unknown as AnyOp);
    },
  });

  useEffect(() => {
    if (!detail.data) return;
    const d = detail.data;
    setForm({
      operation_date: d.operation_date,
      water_quantity: d.water_quantity ?? '',
      quantity_applied: d.quantity_applied ?? '',
      quantity_harvested: d.quantity_harvested ?? '',
      num_workers: d.num_workers ?? '',
      days_worked: d.days_worked ?? '',
      water_volume_l: d.water_volume_l ?? '',
      target_pest: d.target_pest ?? '',
      remarks: d.remarks ?? '',
    });
  }, [detail.data]);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['admin-dashboard-activity'] });
    qc.invalidateQueries({ queryKey: ['admin-dashboard-stats'] });
    qc.invalidateQueries({ queryKey: ['report-irrigation'] });
    qc.invalidateQueries({ queryKey: ['report-fertilization'] });
    qc.invalidateQueries({ queryKey: ['report-phyto'] });
    qc.invalidateQueries({ queryKey: ['report-harvest'] });
  };

  const save = useMutation({
    mutationFn: async () => {
      // Send only fields that belong to this op type to avoid validation noise.
      const payload: Record<string, unknown> = { operation_date: form.operation_date };
      if (type === 'irrigation') payload.water_quantity = Number(form.water_quantity);
      if (type === 'fertilization') payload.quantity_applied = Number(form.quantity_applied);
      if (type === 'phytosanitary') {
        payload.quantity_applied = Number(form.quantity_applied);
        payload.water_volume_l = form.water_volume_l === '' || form.water_volume_l == null
          ? null
          : Number(form.water_volume_l);
        payload.target_pest = form.target_pest || null;
        payload.remarks = form.remarks || null;
      }
      if (type === 'harvest') {
        payload.quantity_harvested = Number(form.quantity_harvested);
        payload.num_workers = Number(form.num_workers);
        payload.days_worked = Number(form.days_worked);
      }
      await api.put(`${baseUrl}/${id}`, payload);
    },
    onSuccess: () => {
      toast.success(t('common.saved', 'Saved'));
      invalidate();
      onClose();
    },
    onError: (err: unknown) => {
      const r = (err as { response?: { data?: { error?: { message?: string }, message?: string } } })?.response?.data;
      toast.error(r?.error?.message ?? r?.message ?? t('common.saveFailed', 'Save failed'));
    },
  });

  const del = useMutation({
    mutationFn: async () => { await api.delete(`${baseUrl}/${id}`); },
    onSuccess: () => {
      toast.success(t('common.deleted', 'Deleted'));
      invalidate();
      onClose();
    },
    onError: (err: unknown) => {
      const r = (err as { response?: { data?: { error?: { message?: string }, message?: string }, status?: number } })?.response;
      const msg = r?.data?.error?.message ?? r?.data?.message;
      toast.error(msg ?? t('common.deleteFailed', 'Delete failed'));
    },
  });

  if (!open || !type) return null;

  const titleMap: Record<OpType, string> = {
    irrigation: t('dashboard.type.irrigation'),
    fertilization: t('dashboard.type.fertilization'),
    phytosanitary: t('dashboard.type.phytosanitary'),
    harvest: t('dashboard.type.harvest'),
  };
  const d = detail.data;
  const inputCls = 'w-full h-9 rounded px-2 text-sm bg-[hsl(var(--surface-container-highest))] focus:outline-none focus:ring-1 focus:ring-ring';

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`${titleMap[type]} — ${d?.plot?.name ?? ''}`}
      description={t('dashboard.opEditHint', 'Edit or delete this entry. Changes are immediate.')}
      footer={(
        <>
          <button
            type="button"
            onClick={() => {
              if (confirm(t('common.confirmDelete', 'Delete this entry?'))) del.mutate();
            }}
            disabled={del.isPending}
            className="h-9 px-3 rounded text-[12px] font-semibold text-[hsl(var(--accent-danger-foreground))] bg-[hsl(var(--accent-danger))] hover:opacity-90 disabled:opacity-50"
          >
            {del.isPending ? '…' : t('common.delete', 'Delete')}
          </button>
          <button
            type="button"
            onClick={onClose}
            className="h-9 px-3 rounded text-[12px] font-semibold text-muted-foreground hover:text-foreground"
          >
            {t('common.cancel', 'Cancel')}
          </button>
          <button
            type="button"
            onClick={() => save.mutate()}
            disabled={save.isPending || detail.isLoading}
            className="h-9 px-4 rounded text-[12px] font-semibold bg-primary text-primary-foreground hover:opacity-90 disabled:opacity-50"
          >
            {save.isPending ? '…' : t('common.save', 'Save')}
          </button>
        </>
      )}
    >
      {detail.isLoading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}
      {detail.isError && <p className="text-sm text-[hsl(var(--accent-danger))]">{t('reports.loadFailed')}</p>}

      {d && (
        <div className="space-y-3">
          <div>
            <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('common.date', 'Date')}</label>
            <input
              type="date"
              className={inputCls}
              value={String(form.operation_date ?? '').slice(0, 10)}
              onChange={(e) => setForm((p) => ({ ...p, operation_date: e.target.value }))}
            />
          </div>

          {type === 'irrigation' && (
            <div>
              <label className="text-[11px] uppercase tracking-wider text-muted-foreground">m3</label>
              <input
                type="number" step="0.01" className={inputCls}
                value={form.water_quantity}
                onChange={(e) => setForm((p) => ({ ...p, water_quantity: e.target.value }))}
              />
            </div>
          )}

          {type === 'fertilization' && (
            <>
              <p className="text-[11px] text-muted-foreground">{d.fertilizer?.name}</p>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('common.quantity', 'Quantity')}</label>
                <input
                  type="number" step="0.001" className={inputCls}
                  value={form.quantity_applied}
                  onChange={(e) => setForm((p) => ({ ...p, quantity_applied: e.target.value }))}
                />
              </div>
            </>
          )}

          {type === 'phytosanitary' && (
            <>
              <p className="text-[11px] text-muted-foreground">{d.pesticide?.name}</p>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('common.quantity', 'Quantity')}</label>
                <input
                  type="number" step="0.001" className={inputCls}
                  value={form.quantity_applied}
                  onChange={(e) => setForm((p) => ({ ...p, quantity_applied: e.target.value }))}
                />
              </div>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('table.sprayVolume', 'Volume de bouillie (L)')}</label>
                <input
                  type="number" step="0.001" min="0" className={inputCls}
                  value={String(form.water_volume_l ?? '')}
                  onChange={(e) => setForm((p) => ({ ...p, water_volume_l: e.target.value }))}
                />
              </div>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('table.pest', 'Target pest')}</label>
                <input
                  type="text" className={inputCls}
                  value={String(form.target_pest ?? '')}
                  onChange={(e) => setForm((p) => ({ ...p, target_pest: e.target.value }))}
                />
              </div>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">{t('table.remarks', 'Remarks')}</label>
                <textarea
                  rows={2}
                  className={`${inputCls} h-auto py-2`}
                  value={String(form.remarks ?? '')}
                  onChange={(e) => setForm((p) => ({ ...p, remarks: e.target.value }))}
                />
              </div>
            </>
          )}

          {type === 'harvest' && (
            <>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">
                  {t('table.quantityHarvested', 'Quantité récoltée (kg)')}
                </label>
                <input
                  type="number" step="0.01" min="0" className={inputCls}
                  value={form.quantity_harvested}
                  onChange={(e) => setForm((p) => ({ ...p, quantity_harvested: e.target.value }))}
                />
              </div>
              <div>
                <label className="text-[11px] uppercase tracking-wider text-muted-foreground">
                  {t('table.workerDays', "Main d'œuvre (homme/jour)")}
                </label>
                <input
                  type="number" min="1" step="1" className={inputCls}
                  value={form.num_workers}
                  onChange={(e) => setForm((p) => ({ ...p, num_workers: e.target.value, days_worked: 1 }))}
                />
              </div>
            </>
          )}
        </div>
      )}
    </Modal>
  );
};

export default OperationDetailsModal;
