import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import ConfirmDialog from '@/components/ConfirmDialog';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/locale';

/**
 * Reusable dated price-history modal for a single catalogue item
 * (fertilizer or pesticide). Lists prices ordered by effective date,
 * allows adding a new price + effective date, editing and deleting
 * existing rows. Backed by the `/prices` endpoint scoped by
 * `entity_type` + `entity_id`.
 */
export type PriceEntityType = 'fertilizer' | 'pesticide';

interface PriceRow {
  id: string;
  entity_type: PriceEntityType;
  entity_id: string;
  price_per_unit: string | number;
  unit: string | null;
  effective_from: string;
  effective_to: string | null;
}

interface PaginatedPrices {
  data: PriceRow[];
  meta?: { current_page: number; last_page: number; per_page: number; total: number };
}

interface Props {
  open: boolean;
  onClose: () => void;
  entityType: PriceEntityType;
  entityId: string | null;
  entityName: string;
  unit?: string | null;
}

const decimal4 = /^\d+(\.\d{1,4})?$/;

function extractApiError(err: unknown, fallback: string): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as
      | { error?: { message?: string; details?: Record<string, string[]> }; message?: string; errors?: Record<string, string[]> }
      | undefined;
    const details = data?.error?.details ?? data?.errors;
    if (details && typeof details === 'object') {
      const first = Object.values(details).flat()[0];
      if (typeof first === 'string') return first;
    }
    return data?.error?.message ?? data?.message ?? err.message ?? fallback;
  }
  return fallback;
}

function formatPrice(p: string | number): string {
  const n = typeof p === 'number' ? p : Number(p);
  if (!Number.isFinite(n)) return '—';
  return `${n.toFixed(3)} TND`;
}

const todayIso = () => new Date().toISOString().slice(0, 10);

const EntityPriceHistoryModal = ({ open, onClose, entityType, entityId, entityName, unit }: Props) => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  const [price, setPrice] = useState('');
  const [from, setFrom] = useState(todayIso());
  const [editingId, setEditingId] = useState<string | null>(null);
  const [deletingRow, setDeletingRow] = useState<PriceRow | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const queryKey = ['admin-entity-prices', entityType, entityId];

  const pricesQuery = useQuery<PaginatedPrices>({
    queryKey,
    enabled: open && !!entityId,
    queryFn: async () => {
      const { data } = await api.get<PaginatedPrices>('/prices', {
        params: { entity_type: entityType, entity_id: entityId, per_page: 100 },
      });
      return data;
    },
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey });

  const createMutation = useMutation({
    mutationFn: async () => {
      return (await api.post('/prices', {
        entity_type: entityType,
        entity_id: entityId,
        price_per_unit: Number(price),
        unit: unit ?? null,
        effective_from: from,
        effective_to: null,
      })).data;
    },
    onSuccess: () => {
      toast.success(t('prices.added', 'Price added'));
      setPrice('');
      setFrom(todayIso());
      setFormError(null);
      invalidate();
    },
    onError: (err) => setFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: { price_per_unit: number; effective_from: string } }) =>
      (await api.put(`/prices/${id}`, payload)).data,
    onSuccess: () => {
      toast.success(t('prices.updated', 'Price updated'));
      setEditingId(null);
      setFormError(null);
      invalidate();
    },
    onError: (err) => setFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const deleteMutation = useMutation({
    mutationFn: async (row: PriceRow) => { await api.delete(`/prices/${row.id}`); },
    onSuccess: () => {
      toast.success(t('prices.deleted', 'Price deleted'));
      setDeletingRow(null);
      invalidate();
    },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  useEffect(() => {
    if (!open) {
      setPrice('');
      setFrom(todayIso());
      setEditingId(null);
      setDeletingRow(null);
      setFormError(null);
    }
  }, [open]);

  const rows = pricesQuery.data?.data ?? [];
  const today = useMemo(() => todayIso(), []);
  const currentRow = useMemo(
    () => rows.find((r) => r.effective_from <= today && (!r.effective_to || r.effective_to >= today)),
    [rows, today],
  );

  const handleAdd = (e: React.FormEvent) => {
    e.preventDefault();
    if (!decimal4.test(price)) {
      setFormError(t('validation.max4dec', 'Up to 4 decimals'));
      return;
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(from)) {
      setFormError(t('validation.dateFormat', 'Invalid date format'));
      return;
    }
    setFormError(null);
    createMutation.mutate();
  };

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title={t('prices.historyTitle', { name: entityName, defaultValue: 'Price history — {{name}}' })}
        description={t('prices.historyDesc', 'Dated effective prices. Operations record a snapshot at submission time.')}
        size="lg"
        footer={
          <button
            type="button"
            onClick={onClose}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            {t('common.close', 'Close')}
          </button>
        }
      >
        <div className="space-y-4">
          {/* Current price summary */}
          <div className="rounded-md border border-border bg-muted/20 p-3 text-xs">
            <span className="text-muted-foreground">{t('prices.current', 'Current price:')} </span>
            <span className="font-semibold text-foreground">
              {currentRow ? formatPrice(currentRow.price_per_unit) : '—'}
              {unit ? <span className="ml-1 text-muted-foreground">/ {unit}</span> : null}
            </span>
          </div>

          {/* Add new dated price */}
          <form onSubmit={handleAdd} className="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 items-end rounded-md border border-border bg-muted/10 p-3">
            <label className="block space-y-1">
              <span className="text-xs font-medium text-muted-foreground">
                {t('prices.newPriceLabel', 'New price (TND)')}{unit ? ` / ${unit}` : ''}
              </span>
              <input
                type="number" step="0.001" min="0" required
                value={price}
                onChange={(e) => setPrice(e.target.value)}
                className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
                placeholder="0.000"
              />
            </label>
            <label className="block space-y-1">
              <span className="text-xs font-medium text-muted-foreground">{t('prices.effectiveFrom', 'Effective from')}</span>
              <input
                type="date" required
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
              />
            </label>
            <button
              type="submit"
              disabled={createMutation.isPending || !entityId}
              className="h-9 rounded-md bg-primary px-4 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
            >
              {createMutation.isPending ? t('common.saving', 'Saving…') : t('prices.add', 'Add')}
            </button>
          </form>

          {formError && (
            <p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">{formError}</p>
          )}

          {/* History list */}
          <div className="overflow-x-auto rounded-lg border border-border">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 font-medium">{t('water.pricePerUnit', 'Price / unit')}</th>
                  <th className="px-3 py-2 font-medium">{t('prices.effectiveFrom', 'Effective from')}</th>
                  <th className="px-3 py-2 font-medium">{t('water.effectiveTo', 'Effective to')}</th>
                  <th className="px-3 py-2 font-medium text-right">{t('common.actions', 'Actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {pricesQuery.isLoading && (
                  <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">{t('common.loading', 'Loading…')}</td></tr>
                )}
                {pricesQuery.isError && !pricesQuery.isLoading && (
                  <tr><td colSpan={4} className="px-3 py-6 text-center text-rose-400">{t('prices.loadFailed', 'Could not load prices.')}</td></tr>
                )}
                {!pricesQuery.isLoading && !pricesQuery.isError && rows.length === 0 && (
                  <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">{t('prices.none', 'No price recorded yet.')}</td></tr>
                )}
                {rows.map((row) => {
                  const isEditing = editingId === row.id;
                  return (
                    <PriceEditableRow
                      key={row.id}
                      row={row}
                      isEditing={isEditing}
                      isCurrent={currentRow?.id === row.id}
                      submitting={updateMutation.isPending}
                      onEdit={() => setEditingId(row.id)}
                      onCancel={() => setEditingId(null)}
                      onSave={(payload) => updateMutation.mutate({ id: row.id, payload })}
                      onDelete={() => setDeletingRow(row)}
                    />
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deletingRow}
        title={t('prices.deleteTitle', 'Delete price')}
        message={deletingRow ? t('prices.deleteMsg', { price: formatPrice(deletingRow.price_per_unit), defaultValue: 'The price of {{price}} will be deleted. Continue?' }) : ''}
        confirmLabel={t('common.delete', 'Delete')}
        variant="danger"
        loading={deleteMutation.isPending}
        onConfirm={() => deletingRow && deleteMutation.mutate(deletingRow)}
        onClose={() => setDeletingRow(null)}
      />
    </>
  );
};

/* ---------------------------------------------------------------- */
/*  Inline-editable row                                              */
/* ---------------------------------------------------------------- */

interface RowProps {
  row: PriceRow;
  isEditing: boolean;
  isCurrent: boolean;
  submitting: boolean;
  onEdit: () => void;
  onCancel: () => void;
  onSave: (payload: { price_per_unit: number; effective_from: string }) => void;
  onDelete: () => void;
}

const PriceEditableRow = ({ row, isEditing, isCurrent, submitting, onEdit, onCancel, onSave, onDelete }: RowProps) => {
  const { t } = useTranslation();
  const [price, setPrice] = useState(String(row.price_per_unit));
  const [from, setFrom] = useState(row.effective_from);

  useEffect(() => {
    if (isEditing) {
      setPrice(String(row.price_per_unit));
      setFrom(row.effective_from);
    }
  }, [isEditing, row]);

  if (!isEditing) {
    return (
      <tr className="bg-background hover:bg-muted/20">
        <td className="px-3 py-2 font-medium text-foreground">
          {formatPrice(row.price_per_unit)}
          {isCurrent && (
            <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-medium text-primary">
              {t('common.current', 'Current')}
            </span>
          )}
        </td>
        <td className="px-3 py-2 text-muted-foreground">{formatDate(row.effective_from)}</td>
        <td className="px-3 py-2 text-muted-foreground">{formatDate(row.effective_to)}</td>
        <td className="px-3 py-2 text-right">
          <div className="flex justify-end gap-1.5">
            <button type="button" onClick={onEdit}
              className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
              {t('common.edit', 'Edit')}
            </button>
            <button type="button" onClick={onDelete}
              className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">
              {t('common.delete', 'Delete')}
            </button>
          </div>
        </td>
      </tr>
    );
  }

  return (
    <tr className="bg-muted/10">
      <td className="px-3 py-2">
        <input
          type="number" step="0.001" min="0" required value={price}
          onChange={(e) => setPrice(e.target.value)}
          className="h-8 w-28 rounded-md border border-border bg-background px-2 text-sm" />
      </td>
      <td className="px-3 py-2">
        <input
          type="date" required value={from}
          onChange={(e) => setFrom(e.target.value)}
          className="h-8 w-36 rounded-md border border-border bg-background px-2 text-sm" />
      </td>
      <td className="px-3 py-2 text-muted-foreground">{formatDate(row.effective_to)}</td>
      <td className="px-3 py-2 text-right">
        <div className="flex justify-end gap-1.5">
          <button type="button" onClick={onCancel}
            className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel', 'Cancel')}
          </button>
          <button type="button" disabled={submitting}
            onClick={() => onSave({ price_per_unit: Number(price), effective_from: from })}
            className="rounded-md bg-primary px-2.5 py-1 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving', 'Saving…') : t('common.save', 'Save')}
          </button>
        </div>
      </td>
    </tr>
  );
};

export default EntityPriceHistoryModal;
