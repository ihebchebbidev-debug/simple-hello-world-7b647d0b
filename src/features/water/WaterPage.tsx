import { useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { downloadCsv } from '@/lib/csv';
import { formatDate } from '@/lib/locale';
import ConfirmDialog from '@/components/ConfirmDialog';
import AdminOnly from '@/components/AdminOnly';
import WaterConfigFormModal, { type WaterConfigSubmit } from './WaterConfigFormModal';
import WaterPriceFormModal, { type WaterPriceSubmit } from './WaterPriceFormModal';
import type { PaginatedResponse, PriceHistoryItem, WaterConfig } from './types';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';

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

const WaterPage = () => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  const [unitModal, setUnitModal] = useState<{ mode: 'create' | 'edit'; data?: WaterConfig | null } | null>(null);
  const [deletingUnit, setDeletingUnit] = useState<WaterConfig | null>(null);
  const [unitFormError, setUnitFormError] = useState<string | null>(null);

  const [priceModal, setPriceModal] = useState<{ mode: 'create' | 'edit'; data?: PriceHistoryItem | null } | null>(null);
  const [deletingPrice, setDeletingPrice] = useState<PriceHistoryItem | null>(null);
  const [priceFormError, setPriceFormError] = useState<string | null>(null);

  // ── Water units ────────────────────────────────────────────────
  const unitsQuery = useQuery({
    queryKey: ['admin-water-config'],
    queryFn: async () => {
      const { data } = await api.get<PaginatedResponse<WaterConfig>>('/water-config', {
        params: { per_page: 100 },
      });
      return data;
    },
    placeholderData: keepPreviousData,
  });

  const invalidateUnits = () => queryClient.invalidateQueries({ queryKey: ['admin-water-config'] });

  const createUnit = useMutation({
    mutationFn: async (payload: WaterConfigSubmit) => (await api.post('/water-config', payload)).data,
    onSuccess: () => { toast.success(t('water.unitCreated')); setUnitModal(null); setUnitFormError(null); invalidateUnits(); },
    onError: (err) => setUnitFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateUnit = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<WaterConfigSubmit> }) =>
      (await api.put(`/water-config/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('water.unitUpdated')); setUnitModal(null); setUnitFormError(null); invalidateUnits(); },
    onError: (err) => setUnitFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleUnit = useMutation({
    mutationFn: async (item: WaterConfig) => {
      await api.put(`/water-config/${item.id}`, { is_active: !item.is_active });
    },
    onSuccess: (_d, item) => { toast.success(item.is_active ? t('water.unitDeactivated') : t('water.unitActivated')); invalidateUnits(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteUnit = useMutation({
    mutationFn: async (item: WaterConfig) => { await api.delete(`/water-config/${item.id}`); },
    onSuccess: () => { toast.success(t('water.unitDeactivated')); setDeletingUnit(null); invalidateUnits(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  // ── Water prices ───────────────────────────────────────────────
  const pricesQuery = useQuery({
    queryKey: ['admin-water-prices'],
    queryFn: async () => {
      const { data } = await api.get<PaginatedResponse<PriceHistoryItem>>('/prices', {
        params: { entity_type: 'water', per_page: 100 },
      });
      return data;
    },
    placeholderData: keepPreviousData,
  });

  const invalidatePrices = () => queryClient.invalidateQueries({ queryKey: ['admin-water-prices'] });

  const createPrice = useMutation({
    mutationFn: async (payload: WaterPriceSubmit) => (await api.post('/prices', payload)).data,
    onSuccess: () => { toast.success(t('water.priceCreated')); setPriceModal(null); setPriceFormError(null); invalidatePrices(); },
    onError: (err) => setPriceFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updatePrice = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<WaterPriceSubmit> }) =>
      (await api.put(`/prices/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('water.priceUpdated')); setPriceModal(null); setPriceFormError(null); invalidatePrices(); },
    onError: (err) => setPriceFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const deletePrice = useMutation({
    mutationFn: async (item: PriceHistoryItem) => { await api.delete(`/prices/${item.id}`); },
    onSuccess: () => { toast.success(t('water.priceDeleted')); setDeletingPrice(null); invalidatePrices(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const units = unitsQuery.data?.data ?? [];
  const prices = pricesQuery.data?.data ?? [];

  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);
  const currentPrice = useMemo(
    () => prices.find((p) => p.effective_from <= today && (!p.effective_to || p.effective_to >= today)),
    [prices, today],
  );
  const activeUnit = useMemo(() => units.find((u) => u.is_active)?.unit, [units]);

  const handleExport = () => {
    if (units.length === 0 && prices.length === 0) {
      toast.info(t('common.noData'));
      return;
    }
    const date = new Date().toISOString().slice(0, 10);
    if (units.length > 0) {
      downloadCsv(
        `agri-sync-eau-unites-${date}.csv`,
        units.map((u) => ({
          libelle: u.unit,
          statut: u.is_active ? t('common.active') : t('common.inactive'),
          cree_le: formatDate(u.created_at),
        })),
        [
          { key: 'libelle', label: t('water.label') },
          { key: 'statut',  label: t('common.status')  },
          { key: 'cree_le', label: t('common.createdAt') },
        ],
      );
    }
    if (prices.length > 0) {
      downloadCsv(
        `agri-sync-eau-tarifs-${date}.csv`,
        prices.map((p) => ({
          prix: p.price_per_unit,
          unite: p.unit ?? '',
          du: p.effective_from,
          au: p.effective_to ?? '',
        })),
        [
          { key: 'prix',  label: t('water.pricePerUnit') },
          { key: 'unite', label: t('water.unit') },
          { key: 'du',    label: t('water.effectiveFrom') },
          { key: 'au',    label: t('water.effectiveTo') },
        ],
      );
    }
  };

  return (
    <section className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconIrrigation} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('water.title')}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{t('water.subtitle')}</p>
          </div>
        </div>
        <button
          type="button"
          onClick={handleExport}
          className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          {t('common.exportCsv')}
        </button>
      </header>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="rounded-lg border border-border bg-muted/20 p-4">
          <p className="text-xs uppercase text-muted-foreground">{t('water.activeUnit')}</p>
          <p className="mt-1 text-2xl font-semibold text-foreground">{activeUnit ?? '—'}</p>
        </div>
        <div className="rounded-lg border border-border bg-muted/20 p-4">
          <p className="text-xs uppercase text-muted-foreground">{t('water.currentPrice')}</p>
          <p className="mt-1 text-2xl font-semibold text-foreground">
            {currentPrice ? formatPrice(currentPrice.price_per_unit) : '—'}
            {currentPrice?.unit ? <span className="ml-1 text-sm text-muted-foreground">/ {currentPrice.unit}</span> : null}
          </p>
        </div>
      </div>

      {/* Units section */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">{t('water.unitsTitle')}</h2>
          <AdminOnly>
            <button
              type="button"
              onClick={() => { setUnitFormError(null); setUnitModal({ mode: 'create' }); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90"
            >
              {t('water.newUnit')}
            </button>
          </AdminOnly>
        </div>
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">{t('water.label')}</th>
                <th className="px-4 py-3 font-medium">{t('common.status')}</th>
                <th className="px-4 py-3 font-medium">{t('common.createdAt')}</th>
                <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {unitsQuery.isLoading && (<tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
              {unitsQuery.isError && !unitsQuery.isLoading && (<tr><td colSpan={4} className="px-4 py-10 text-center text-rose-400">{t('water.unitsLoadFailed')}</td></tr>)}
              {!unitsQuery.isLoading && !unitsQuery.isError && units.length === 0 && (<tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">{t('water.noUnits')}</td></tr>)}
              {units.map((u) => (
                <tr key={u.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{u.unit}</td>
                  <td className="px-4 py-3">
                    <span className={u.is_active
                      ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                      : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'}>
                      <span className={`h-1.5 w-1.5 rounded-full ${u.is_active ? 'bg-emerald-400' : 'bg-zinc-400'}`} />
                      {u.is_active ? t('common.activeF') : t('common.inactiveF')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{formatDate(u.created_at)}</td>
                  <td className="px-4 py-3 text-right">
                    <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                      <div className="flex justify-end gap-1.5">
                        <button type="button"
                          onClick={() => { setUnitFormError(null); setUnitModal({ mode: 'edit', data: u }); }}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                          {t('common.edit')}
                        </button>
                        <button type="button"
                          disabled={toggleUnit.isPending && toggleUnit.variables?.id === u.id}
                          onClick={() => toggleUnit.mutate(u)}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
                          {u.is_active ? t('common.deactivate') : t('common.activate')}
                        </button>
                        <button type="button"
                          onClick={() => setDeletingUnit(u)}
                          className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">
                          {t('common.delete')}
                        </button>
                      </div>
                    </AdminOnly>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Prices section */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">{t('water.pricesTitle')}</h2>
          <AdminOnly>
            <button
              type="button"
              onClick={() => { setPriceFormError(null); setPriceModal({ mode: 'create' }); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90"
            >
              {t('water.newPrice')}
            </button>
          </AdminOnly>
        </div>
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">{t('water.pricePerUnit')}</th>
                <th className="px-4 py-3 font-medium">{t('water.unit')}</th>
                <th className="px-4 py-3 font-medium">{t('water.effectiveFrom')}</th>
                <th className="px-4 py-3 font-medium">{t('water.effectiveTo')}</th>
                <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {pricesQuery.isLoading && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
              {pricesQuery.isError && !pricesQuery.isLoading && (<tr><td colSpan={5} className="px-4 py-10 text-center text-rose-400">{t('water.pricesLoadFailed')}</td></tr>)}
              {!pricesQuery.isLoading && !pricesQuery.isError && prices.length === 0 && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('water.noPrices')}</td></tr>)}
              {prices.map((p) => {
                const isCurrent = currentPrice?.id === p.id;
                return (
                  <tr key={p.id} className="bg-background hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">
                      {formatPrice(p.price_per_unit)}
                      {isCurrent && (
                        <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-medium text-primary">
                          {t('common.current')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{p.unit ?? '—'}</td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(p.effective_from)}</td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(p.effective_to)}</td>
                    <td className="px-4 py-3 text-right">
                      <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                        <div className="flex justify-end gap-1.5">
                          <button type="button"
                            onClick={() => { setPriceFormError(null); setPriceModal({ mode: 'edit', data: p }); }}
                            className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                            {t('common.edit')}
                          </button>
                          <button type="button"
                            onClick={() => setDeletingPrice(p)}
                            className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">
                            {t('common.delete')}
                          </button>
                        </div>
                      </AdminOnly>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <WaterConfigFormModal
        open={!!unitModal}
        mode={unitModal?.mode ?? 'create'}
        initial={unitModal?.data ?? null}
        submitting={createUnit.isPending || updateUnit.isPending}
        serverError={unitFormError}
        onClose={() => setUnitModal(null)}
        onSubmit={(values) => {
          if (unitModal?.mode === 'edit' && unitModal.data) {
            updateUnit.mutate({ id: unitModal.data.id, payload: values });
          } else {
            createUnit.mutate(values);
          }
        }}
      />

      <WaterPriceFormModal
        open={!!priceModal}
        mode={priceModal?.mode ?? 'create'}
        initial={priceModal?.data ?? null}
        defaultUnit={activeUnit}
        submitting={createPrice.isPending || updatePrice.isPending}
        serverError={priceFormError}
        onClose={() => setPriceModal(null)}
        onSubmit={(values) => {
          if (priceModal?.mode === 'edit' && priceModal.data) {
            updatePrice.mutate({ id: priceModal.data.id, payload: values });
          } else {
            createPrice.mutate(values);
          }
        }}
      />

      <ConfirmDialog
        open={!!deletingUnit}
        title={t('water.deleteUnitTitle')}
        message={deletingUnit ? t('water.deleteUnitMsg', { unit: deletingUnit.unit }) : ''}
        confirmLabel={t('common.deactivate')}
        variant="danger"
        loading={deleteUnit.isPending}
        onConfirm={() => deletingUnit && deleteUnit.mutate(deletingUnit)}
        onClose={() => setDeletingUnit(null)}
      />

      <ConfirmDialog
        open={!!deletingPrice}
        title={t('water.deletePriceTitle')}
        message={deletingPrice ? t('water.deletePriceMsg', { price: formatPrice(deletingPrice.price_per_unit) }) : ''}
        confirmLabel={t('common.delete')}
        variant="danger"
        loading={deletePrice.isPending}
        onConfirm={() => deletingPrice && deletePrice.mutate(deletingPrice)}
        onClose={() => setDeletingPrice(null)}
      />
    </section>
  );
};

export default WaterPage;
