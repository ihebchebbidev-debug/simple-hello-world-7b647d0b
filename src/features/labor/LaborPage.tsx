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
import LaborConfigFormModal, { type LaborConfigSubmit } from './LaborConfigFormModal';
import LaborRateFormModal, { type LaborRateSubmit } from './LaborRateFormModal';
import type { LaborConfig, PaginatedResponse, PriceHistoryItem } from './types';
import iconCosts from '@/assets/icons/icon-costs.png';

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

function formatRate(p: string | number): string {
  const n = typeof p === 'number' ? p : Number(p);
  if (!Number.isFinite(n)) return '—';
  return `${n.toFixed(3)} TND`;
}

const LaborPage = () => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();

  const [configModal, setConfigModal] = useState<{ mode: 'create' | 'edit'; data?: LaborConfig | null } | null>(null);
  const [deletingConfig, setDeletingConfig] = useState<LaborConfig | null>(null);
  const [configFormError, setConfigFormError] = useState<string | null>(null);

  const [rateModal, setRateModal] = useState<{ mode: 'create' | 'edit'; data?: PriceHistoryItem | null } | null>(null);
  const [deletingRate, setDeletingRate] = useState<PriceHistoryItem | null>(null);
  const [rateFormError, setRateFormError] = useState<string | null>(null);

  // ── Labor configs ───────────────────────────────────────────────
  const configsQuery = useQuery({
    queryKey: ['admin-labor-config'],
    queryFn: async () => {
      const { data } = await api.get<PaginatedResponse<LaborConfig>>('/labor-config', {
        params: { per_page: 100 },
      });
      return data;
    },
    placeholderData: keepPreviousData,
  });

  const invalidateConfigs = () => queryClient.invalidateQueries({ queryKey: ['admin-labor-config'] });

  const createConfig = useMutation({
    mutationFn: async (payload: LaborConfigSubmit) => (await api.post('/labor-config', payload)).data,
    onSuccess: () => { toast.success(t('labor.configCreated')); setConfigModal(null); setConfigFormError(null); invalidateConfigs(); },
    onError: (err) => setConfigFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateConfig = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<LaborConfigSubmit> }) =>
      (await api.put(`/labor-config/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('labor.configUpdated')); setConfigModal(null); setConfigFormError(null); invalidateConfigs(); },
    onError: (err) => setConfigFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleConfig = useMutation({
    mutationFn: async (item: LaborConfig) => {
      await api.put(`/labor-config/${item.id}`, { is_active: !item.is_active });
    },
    onSuccess: (_d, item) => { toast.success(item.is_active ? t('labor.configDeactivated') : t('labor.configActivated')); invalidateConfigs(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteConfig = useMutation({
    mutationFn: async (item: LaborConfig) => { await api.delete(`/labor-config/${item.id}`); },
    onSuccess: () => { toast.success(t('labor.configDeactivated')); setDeletingConfig(null); invalidateConfigs(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  // ── Labor rates (price history) ─────────────────────────────────
  const ratesQuery = useQuery({
    queryKey: ['admin-labor-rates'],
    queryFn: async () => {
      const { data } = await api.get<PaginatedResponse<PriceHistoryItem>>('/prices', {
        params: { entity_type: 'labor', per_page: 100 },
      });
      return data;
    },
    placeholderData: keepPreviousData,
  });

  const invalidateRates = () => queryClient.invalidateQueries({ queryKey: ['admin-labor-rates'] });

  const createRate = useMutation({
    mutationFn: async (payload: LaborRateSubmit) => (await api.post('/prices', payload)).data,
    onSuccess: () => { toast.success(t('labor.rateCreated')); setRateModal(null); setRateFormError(null); invalidateRates(); },
    onError: (err) => setRateFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateRate = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<LaborRateSubmit> }) =>
      (await api.put(`/prices/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('labor.rateUpdated')); setRateModal(null); setRateFormError(null); invalidateRates(); },
    onError: (err) => setRateFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const deleteRate = useMutation({
    mutationFn: async (item: PriceHistoryItem) => { await api.delete(`/prices/${item.id}`); },
    onSuccess: () => { toast.success(t('labor.rateDeleted')); setDeletingRate(null); invalidateRates(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const configs = configsQuery.data?.data ?? [];
  const rates = ratesQuery.data?.data ?? [];

  const today = useMemo(() => new Date().toISOString().slice(0, 10), []);
  const currentRate = useMemo(
    () => rates.find((p) => p.effective_from <= today && (!p.effective_to || p.effective_to >= today)),
    [rates, today],
  );
  const hasActiveConfig = useMemo(() => configs.some((c) => c.is_active), [configs]);

  const handleExport = () => {
    if (configs.length === 0 && rates.length === 0) {
      toast.info(t('common.noData'));
      return;
    }
    const date = new Date().toISOString().slice(0, 10);
    if (rates.length > 0) {
      downloadCsv(
        `agri-sync-main-oeuvre-tarifs-${date}.csv`,
        rates.map((p) => ({
          tarif: p.price_per_unit,
          du: p.effective_from,
          au: p.effective_to ?? '',
        })),
        [
          { key: 'tarif', label: t('labor.rate') },
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
          <img src={iconCosts} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('labor.title')}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{t('labor.subtitle')}</p>
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
          <p className="text-xs uppercase text-muted-foreground">{t('labor.activeConfig')}</p>
          <p className="mt-1 text-2xl font-semibold text-foreground">{hasActiveConfig ? t('common.yes') : '—'}</p>
        </div>
        <div className="rounded-lg border border-border bg-muted/20 p-4">
          <p className="text-xs uppercase text-muted-foreground">{t('labor.currentRate')}</p>
          <p className="mt-1 text-2xl font-semibold text-foreground">
            {currentRate ? formatRate(currentRate.price_per_unit) : '—'}
            {currentRate ? <span className="ml-1 text-sm text-muted-foreground">{t('labor.perDay')}</span> : null}
          </p>
        </div>
      </div>

      {/* Config section */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">{t('labor.configsTitle')}</h2>
          <AdminOnly>
            <button
              type="button"
              onClick={() => { setConfigFormError(null); setConfigModal({ mode: 'create' }); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90"
            >
              {t('labor.newConfig')}
            </button>
          </AdminOnly>
        </div>
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">{t('common.status')}</th>
                <th className="px-4 py-3 font-medium">{t('common.createdAt')}</th>
                <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {configsQuery.isLoading && (<tr><td colSpan={3} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
              {configsQuery.isError && !configsQuery.isLoading && (<tr><td colSpan={3} className="px-4 py-10 text-center text-rose-400">{t('labor.configsLoadFailed')}</td></tr>)}
              {!configsQuery.isLoading && !configsQuery.isError && configs.length === 0 && (<tr><td colSpan={3} className="px-4 py-10 text-center text-muted-foreground">{t('labor.noConfigs')}</td></tr>)}
              {configs.map((c) => (
                <tr key={c.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3">
                    <span className={c.is_active
                      ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                      : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'}>
                      <span className={`h-1.5 w-1.5 rounded-full ${c.is_active ? 'bg-emerald-400' : 'bg-zinc-400'}`} />
                      {c.is_active ? t('common.activeF') : t('common.inactiveF')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{formatDate(c.created_at)}</td>
                  <td className="px-4 py-3 text-right">
                    <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                      <div className="flex justify-end gap-1.5">
                        <button type="button"
                          onClick={() => { setConfigFormError(null); setConfigModal({ mode: 'edit', data: c }); }}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                          {t('common.edit')}
                        </button>
                        <button type="button"
                          disabled={toggleConfig.isPending && toggleConfig.variables?.id === c.id}
                          onClick={() => toggleConfig.mutate(c)}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
                          {c.is_active ? t('common.deactivate') : t('common.activate')}
                        </button>
                        <button type="button"
                          onClick={() => setDeletingConfig(c)}
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

      {/* Rates section */}
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-semibold text-foreground">{t('labor.ratesTitle')}</h2>
          <AdminOnly>
            <button
              type="button"
              onClick={() => { setRateFormError(null); setRateModal({ mode: 'create' }); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90"
            >
              {t('labor.newRate')}
            </button>
          </AdminOnly>
        </div>
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full min-w-[760px] text-left text-sm">
            <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">{t('labor.rate')}</th>
                <th className="px-4 py-3 font-medium">{t('water.effectiveFrom')}</th>
                <th className="px-4 py-3 font-medium">{t('water.effectiveTo')}</th>
                <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {ratesQuery.isLoading && (<tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
              {ratesQuery.isError && !ratesQuery.isLoading && (<tr><td colSpan={4} className="px-4 py-10 text-center text-rose-400">{t('labor.ratesLoadFailed')}</td></tr>)}
              {!ratesQuery.isLoading && !ratesQuery.isError && rates.length === 0 && (<tr><td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">{t('labor.noRates')}</td></tr>)}
              {rates.map((p) => {
                const isCurrent = currentRate?.id === p.id;
                return (
                  <tr key={p.id} className="bg-background hover:bg-muted/20">
                    <td className="px-4 py-3 font-medium text-foreground">
                      {formatRate(p.price_per_unit)}
                      <span className="ml-1 text-xs text-muted-foreground">{t('labor.perDay')}</span>
                      {isCurrent && (
                        <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-medium text-primary">
                          {t('common.current')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(p.effective_from)}</td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(p.effective_to)}</td>
                    <td className="px-4 py-3 text-right">
                      <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                        <div className="flex justify-end gap-1.5">
                          <button type="button"
                            onClick={() => { setRateFormError(null); setRateModal({ mode: 'edit', data: p }); }}
                            className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                            {t('common.edit')}
                          </button>
                          <button type="button"
                            onClick={() => setDeletingRate(p)}
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

      <LaborConfigFormModal
        open={!!configModal}
        mode={configModal?.mode ?? 'create'}
        initial={configModal?.data ?? null}
        submitting={createConfig.isPending || updateConfig.isPending}
        serverError={configFormError}
        onClose={() => setConfigModal(null)}
        onSubmit={(values) => {
          if (configModal?.mode === 'edit' && configModal.data) {
            updateConfig.mutate({ id: configModal.data.id, payload: values });
          } else {
            createConfig.mutate(values);
          }
        }}
      />

      <LaborRateFormModal
        open={!!rateModal}
        mode={rateModal?.mode ?? 'create'}
        initial={rateModal?.data ?? null}
        submitting={createRate.isPending || updateRate.isPending}
        serverError={rateFormError}
        onClose={() => setRateModal(null)}
        onSubmit={(values) => {
          if (rateModal?.mode === 'edit' && rateModal.data) {
            updateRate.mutate({ id: rateModal.data.id, payload: values });
          } else {
            createRate.mutate(values);
          }
        }}
      />

      <ConfirmDialog
        open={!!deletingConfig}
        title={t('labor.deleteConfigTitle')}
        message={t('labor.deleteConfigMsg')}
        confirmLabel={t('common.deactivate')}
        variant="danger"
        loading={deleteConfig.isPending}
        onConfirm={() => deletingConfig && deleteConfig.mutate(deletingConfig)}
        onClose={() => setDeletingConfig(null)}
      />

      <ConfirmDialog
        open={!!deletingRate}
        title={t('labor.deleteRateTitle')}
        message={t('labor.deleteRateMsg')}
        confirmLabel={t('common.delete')}
        variant="danger"
        loading={deleteRate.isPending}
        onConfirm={() => deletingRate && deleteRate.mutate(deletingRate)}
        onClose={() => setDeletingRate(null)}
      />
    </section>
  );
};

export default LaborPage;