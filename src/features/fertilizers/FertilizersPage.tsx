import { useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useDebounce } from '@/lib/useDebounce';
import { formatDate } from '@/lib/locale';
import ConfirmDialog from '@/components/ConfirmDialog';
import AdminOnly from '@/components/AdminOnly';
import { downloadCsv } from '@/lib/csv';
import FertilizerFormModal, { type FertilizerFormSubmit } from './FertilizerFormModal';
import EntityPriceHistoryModal from '@/components/EntityPriceHistoryModal';
import type { AdminFertilizer, PaginatedFertilizers } from './types';
import iconFertilization from '@/assets/icons/icon-fertilization.png';

interface QueryParams { page: number; search: string; unit: string; status: 'all' | 'active' | 'inactive'; }

async function fetchFertilizers(p: QueryParams): Promise<PaginatedFertilizers> {
  const query: Record<string, string | number | boolean> = { page: p.page, per_page: 25 };
  if (p.search.trim()) query.search = p.search.trim();
  if (p.unit.trim()) query.unit = p.unit.trim();
  if (p.status !== 'all') query.is_active = p.status === 'active';
  const { data } = await api.get<PaginatedFertilizers>('/fertilizers', { params: query });
  return data;
}

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

function buildPageList(current: number, last: number): (number | '…')[] {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
  const pages: (number | '…')[] = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);
  if (start > 2) pages.push('…');
  for (let i = start; i <= end; i++) pages.push(i);
  if (end < last - 1) pages.push('…');
  pages.push(last);
  return pages;
}

const FertilizersPage = () => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [unit, setUnit] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<AdminFertilizer | null>(null);
  const [deleting, setDeleting] = useState<AdminFertilizer | null>(null);
  const [pricesFor, setPricesFor] = useState<AdminFertilizer | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  const debouncedSearch = useDebounce(search, 300);
  const debouncedUnit = useDebounce(unit, 300);

  const params: QueryParams = useMemo(
    () => ({ page, search: debouncedSearch, unit: debouncedUnit, status }),
    [page, debouncedSearch, debouncedUnit, status],
  );

  const listQuery = useQuery({
    queryKey: ['admin-fertilizers', params],
    queryFn: () => fetchFertilizers(params),
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin-fertilizers'] });

  const createMutation = useMutation({
    mutationFn: async (payload: FertilizerFormSubmit) => {
      const { initial_price, ...rest } = payload;
      const res = await api.post<{ data: { id: string } }>('/fertilizers', rest);
      const created = res.data?.data;
      if (initial_price && initial_price > 0 && created?.id) {
        try {
          await api.post('/prices', {
            entity_type: 'fertilizer', entity_id: created.id,
            price_per_unit: initial_price, unit: rest.unit,
            effective_from: new Date().toISOString().slice(0, 10), effective_to: null,
          });
        } catch { /* non-fatal — admin can add via Prices later */ }
      }
      return res.data;
    },
    onSuccess: () => { toast.success(t('fertilizers.created')); setCreateOpen(false); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<FertilizerFormSubmit> }) =>
      (await api.put(`/fertilizers/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('fertilizers.updated')); setEditing(null); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: async (item: AdminFertilizer) => { await api.put(`/fertilizers/${item.id}`, { is_active: !item.is_active }); },
    onSuccess: (_d, item) => { toast.success(item.is_active ? t('fertilizers.deactivated') : t('fertilizers.activated')); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteMutation = useMutation({
    mutationFn: async (item: AdminFertilizer) => { await api.delete(`/fertilizers/${item.id}`); },
    onSuccess: () => { toast.success(t('fertilizers.deleted')); setDeleting(null); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const handleExport = async () => {
    if (exporting) return;
    setExporting(true);
    try {
      const all: AdminFertilizer[] = [];
      let currentPage = 1;
      let lastPage = 1;
      const PER_PAGE = 100;
      do {
        const query: Record<string, string | number | boolean> = { page: currentPage, per_page: PER_PAGE };
        if (search.trim()) query.search = search.trim();
        if (unit.trim()) query.unit = unit.trim();
        if (status !== 'all') query.is_active = status === 'active';
        const { data } = await api.get<PaginatedFertilizers>('/fertilizers', { params: query });
        all.push(...(data.data ?? []));
        lastPage = data.meta?.last_page ?? 1;
        currentPage += 1;
      } while (currentPage <= lastPage);

      if (all.length === 0) { toast.info(t('common.noData')); return; }
      downloadCsv(
        `agri-sync-fertilizers-${new Date().toISOString().slice(0, 10)}.csv`,
        all.map((f) => ({
          name: f.name, unit: f.unit, n: f.n_percent, p: f.p_percent, k: f.k_percent,
          status: f.is_active ? t('common.active') : t('common.inactive'),
          created: formatDate(f.created_at),
        })),
        [
          { key: 'name',    label: t('plots.name') },
          { key: 'unit',    label: t('fertilizers.unit') },
          { key: 'n',       label: 'N (%)' },
          { key: 'p',       label: 'P (%)' },
          { key: 'k',       label: 'K (%)' },
          { key: 'status',  label: t('common.status') },
          { key: 'created', label: t('common.createdAt') },
        ],
      );
      toast.success(t('fertilizers.count', { count: all.length }));
    } catch (err) {
      toast.error(extractApiError(err, t('common.exportFailed')));
    } finally {
      setExporting(false);
    }
  };

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconFertilization} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('fertilizers.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('fertilizers.subtitle')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {meta && <span className="text-xs text-muted-foreground">{t('fertilizers.count', { count: meta.total })}</span>}
          <button type="button" onClick={handleExport} disabled={exporting}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
            {exporting ? t('common.exporting') : t('common.exportCsv')}
          </button>
          <AdminOnly>
            <button type="button" onClick={() => { setFormError(null); setCreateOpen(true); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90">
              {t('fertilizers.new')}
            </button>
          </AdminOnly>
        </div>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input type="search" value={search} maxLength={120}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder={t('fertilizers.searchPlaceholder')}
          className="h-9 flex-1 min-w-[220px] rounded-md border border-border bg-background px-3 text-sm" />
        <input type="text" value={unit} maxLength={20}
          onChange={(e) => { setUnit(e.target.value); setPage(1); }}
          placeholder={t('fertilizers.unitFilter')}
          className="h-9 w-44 rounded-md border border-border bg-background px-3 text-sm" />
        <select value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1); }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm">
          <option value="all">{t('common.allStatuses')}</option>
          <option value="active">{t('common.actives')}</option>
          <option value="inactive">{t('common.inactives')}</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('plots.name')}</th>
              <th className="px-4 py-3 font-medium">{t('fertilizers.unit')}</th>
              <th className="px-4 py-3 font-medium">N&nbsp;%</th>
              <th className="px-4 py-3 font-medium">P&nbsp;%</th>
              <th className="px-4 py-3 font-medium">K&nbsp;%</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {listQuery.isLoading && (<tr><td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
            {listQuery.isError && !listQuery.isLoading && (<tr><td colSpan={7} className="px-4 py-10 text-center text-rose-400">{t('fertilizers.loadFailed')}</td></tr>)}
            {!listQuery.isLoading && !listQuery.isError && items.length === 0 && (<tr><td colSpan={7} className="px-4 py-10 text-center text-muted-foreground">{t('fertilizers.none')}</td></tr>)}
            {items.map((item) => {
              const isToggling = toggleActiveMutation.isPending && toggleActiveMutation.variables?.id === item.id;
              return (
                <tr key={item.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{item.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{item.unit}</td>
                  <td className="px-4 py-3 text-muted-foreground">{Number(item.n_percent).toFixed(2)}</td>
                  <td className="px-4 py-3 text-muted-foreground">{Number(item.p_percent).toFixed(2)}</td>
                  <td className="px-4 py-3 text-muted-foreground">{Number(item.k_percent).toFixed(2)}</td>
                  <td className="px-4 py-3">
                    <span className={item.is_active
                      ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                      : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'}>
                      <span className={`h-1.5 w-1.5 rounded-full ${item.is_active ? 'bg-emerald-400' : 'bg-zinc-400'}`} />
                      {item.is_active ? t('common.active') : t('common.inactive')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1.5">
                      <button type="button" onClick={() => setPricesFor(item)}
                        className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                        {t('prices.button', 'Prices')}
                      </button>
                      <AdminOnly>
                        <button type="button" onClick={() => { setFormError(null); setEditing(item); }}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                          {t('common.edit')}
                        </button>
                        <button type="button" disabled={isToggling} onClick={() => toggleActiveMutation.mutate(item)}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
                          {item.is_active ? t('common.deactivate') : t('common.activate')}
                        </button>
                        <button type="button" onClick={() => setDeleting(item)}
                          className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">
                          {t('common.delete')}
                        </button>
                      </AdminOnly>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
          <span>{t('common.pageInfo', { current: meta.current_page, last: meta.last_page })} — {t('fertilizers.count', { count: meta.total })}</span>
          <div className="flex flex-wrap items-center gap-1.5">
            <button type="button" disabled={page <= 1} onClick={() => setPage(1)} className="rounded-md border border-border px-2 py-1 disabled:opacity-40">«</button>
            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pagePrev')}</button>
            {buildPageList(meta.current_page, meta.last_page).map((it, idx) =>
              it === '…' ? (<span key={`gap-${idx}`} className="px-2">…</span>) : (
                <button key={it} type="button" onClick={() => setPage(it as number)}
                  className={it === meta.current_page
                    ? 'rounded-md bg-primary px-3 py-1 font-medium text-primary-foreground'
                    : 'rounded-md border border-border px-3 py-1 hover:bg-muted hover:text-foreground'}>
                  {it}
                </button>
              ),
            )}
            <button type="button" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pageNext')}</button>
            <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(meta.last_page)} className="rounded-md border border-border px-2 py-1 disabled:opacity-40">»</button>
          </div>
        </div>
      )}

      <FertilizerFormModal open={createOpen} mode="create" submitting={createMutation.isPending} serverError={formError}
        onClose={() => setCreateOpen(false)} onSubmit={(values) => createMutation.mutate(values)} />

      <FertilizerFormModal open={!!editing} mode="edit" initial={editing} submitting={updateMutation.isPending} serverError={formError}
        onClose={() => setEditing(null)}
        onSubmit={(values) => { if (!editing) return; updateMutation.mutate({ id: editing.id, payload: values }); }} />

      <ConfirmDialog open={!!deleting}
        title={t('fertilizers.deleteTitle')}
        message={deleting ? t('fertilizers.deleteConfirm', { name: deleting.name }) : ''}
        confirmLabel={t('common.delete')} variant="danger" loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting)}
        onClose={() => setDeleting(null)} />

      <EntityPriceHistoryModal
        open={!!pricesFor}
        onClose={() => setPricesFor(null)}
        entityType="fertilizer"
        entityId={pricesFor?.id ?? null}
        entityName={pricesFor?.name ?? ''}
        unit={pricesFor?.unit ?? null}
      />
    </section>
  );
};

export default FertilizersPage;
