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
import PesticideFormModal, { type PesticideFormSubmit } from './PesticideFormModal';
import EntityPriceHistoryModal from '@/components/EntityPriceHistoryModal';
import type { AdminPesticide, PaginatedPesticides } from './types';
import iconPhytosanitary from '@/assets/icons/icon-phytosanitary.png';

interface QueryParams { page: number; search: string; unit: string; status: 'all' | 'active' | 'inactive'; }

async function fetchPesticides(p: QueryParams): Promise<PaginatedPesticides> {
  const query: Record<string, string | number | boolean> = { page: p.page, per_page: 25 };
  if (p.search.trim()) query.search = p.search.trim();
  if (p.unit.trim()) query.unit = p.unit.trim();
  if (p.status !== 'all') query.is_active = p.status === 'active';
  const { data } = await api.get<PaginatedPesticides>('/pesticides', { params: query });
  return data;
}

function extractApiError(err: unknown, fallback: string): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as { error?: { message?: string; details?: Record<string, string[]> }; message?: string; errors?: Record<string, string[]> } | undefined;
    const details = data?.error?.details ?? data?.errors;
    if (details && typeof details === 'object') {
      const first = Object.values(details).flat()[0];
      if (typeof first === 'string') return first;
    }
    return data?.error?.message ?? data?.message ?? err.message ?? fallback;
  }
  return fallback;
}

const PesticidesPage = () => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [unit, setUnit] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<AdminPesticide | null>(null);
  const [deleting, setDeleting] = useState<AdminPesticide | null>(null);
  const [pricesFor, setPricesFor] = useState<AdminPesticide | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  const debouncedSearch = useDebounce(search, 300);
  const debouncedUnit = useDebounce(unit, 300);

  const params: QueryParams = useMemo(
    () => ({ page, search: debouncedSearch, unit: debouncedUnit, status }),
    [page, debouncedSearch, debouncedUnit, status],
  );

  const listQuery = useQuery({
    queryKey: ['admin-pesticides', params],
    queryFn: () => fetchPesticides(params),
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin-pesticides'] });

  const createMutation = useMutation({
    mutationFn: async (payload: PesticideFormSubmit) => {
      const { initial_price, ...rest } = payload;
      const res = await api.post<{ data: { id: string } }>('/pesticides', rest);
      const created = res.data?.data;
      if (initial_price && initial_price > 0 && created?.id) {
        try {
          await api.post('/prices', {
            entity_type: 'pesticide', entity_id: created.id,
            price_per_unit: initial_price, unit: rest.unit,
            effective_from: new Date().toISOString().slice(0, 10), effective_to: null,
          });
        } catch { /* non-fatal */ }
      }
      return res.data;
    },
    onSuccess: () => { toast.success(t('pesticides.created')); setCreateOpen(false); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<PesticideFormSubmit> }) =>
      (await api.put(`/pesticides/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('pesticides.updated')); setEditing(null); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: async (item: AdminPesticide) => { await api.put(`/pesticides/${item.id}`, { is_active: !item.is_active }); },
    onSuccess: (_d, item) => { toast.success(item.is_active ? t('pesticides.deactivated') : t('pesticides.activated')); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteMutation = useMutation({
    mutationFn: async (item: AdminPesticide) => { await api.delete(`/pesticides/${item.id}`); },
    onSuccess: () => { toast.success(t('pesticides.deleted')); setDeleting(null); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const countLabel = (n: number) => t(n > 1 ? 'pesticides.countOther' : 'pesticides.countOne', { count: n });

  const handleExport = async () => {
    if (exporting) return;
    setExporting(true);
    try {
      const all: AdminPesticide[] = [];
      let cp = 1, lp = 1;
      do {
        const q: Record<string, string | number | boolean> = { page: cp, per_page: 100 };
        if (search.trim()) q.search = search.trim();
        if (unit.trim()) q.unit = unit.trim();
        if (status !== 'all') q.is_active = status === 'active';
        const { data } = await api.get<PaginatedPesticides>('/pesticides', { params: q });
        all.push(...(data.data ?? []));
        lp = data.meta?.last_page ?? 1;
        cp += 1;
      } while (cp <= lp);
      if (all.length === 0) { toast.info(t('common.noData')); return; }
      downloadCsv(`agri-sync-pesticides-${new Date().toISOString().slice(0, 10)}.csv`,
        all.map((f) => ({
          name: f.name, unit: f.unit, composition: f.chemical_composition ?? '',
          status: f.is_active ? t('common.active') : t('common.inactive'),
          created: formatDate(f.created_at),
        })),
        [
          { key: 'name',        label: t('plots.name') },
          { key: 'unit',        label: t('fertilizers.unit') },
          { key: 'composition', label: t('pesticides.compositionLabel') },
          { key: 'status',      label: t('common.status') },
          { key: 'created',     label: t('common.createdAt') },
        ],
      );
      toast.success(countLabel(all.length));
    } catch (err) { toast.error(extractApiError(err, t('common.exportFailed'))); }
    finally { setExporting(false); }
  };

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconPhytosanitary} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('pesticides.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('pesticides.subtitle')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {meta && <span className="text-xs text-muted-foreground">{countLabel(meta.total)}</span>}
          <button type="button" onClick={handleExport} disabled={exporting}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
            {exporting ? t('common.exporting') : t('common.exportCsv')}
          </button>
          <AdminOnly>
            <button type="button" onClick={() => { setFormError(null); setCreateOpen(true); }}
              className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90">
              {t('pesticides.new')}
            </button>
          </AdminOnly>
        </div>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input type="search" value={search} maxLength={120}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder={t('pesticides.searchPlaceholder')}
          className="h-9 flex-1 min-w-[220px] rounded-md border border-border bg-background px-3 text-sm" />
        <input type="text" value={unit} maxLength={20}
          onChange={(e) => { setUnit(e.target.value); setPage(1); }}
          placeholder={t('pesticides.unitFilter')}
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
              <th className="px-4 py-3 font-medium">{t('pesticides.composition')}</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {listQuery.isLoading && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
            {listQuery.isError && !listQuery.isLoading && (<tr><td colSpan={5} className="px-4 py-10 text-center text-rose-400">{t('pesticides.loadFailed')}</td></tr>)}
            {!listQuery.isLoading && !listQuery.isError && items.length === 0 && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('pesticides.none')}</td></tr>)}
            {items.map((item) => {
              const isToggling = toggleActiveMutation.isPending && toggleActiveMutation.variables?.id === item.id;
              return (
                <tr key={item.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{item.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{item.unit}</td>
                  <td className="px-4 py-3 text-muted-foreground max-w-[320px] truncate" title={item.chemical_composition ?? ''}>{item.chemical_composition ?? '—'}</td>
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
                      <button type="button" onClick={() => setPricesFor(item)} className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">{t('prices.button', 'Prices')}</button>
                      <AdminOnly>
                        <button type="button" onClick={() => { setFormError(null); setEditing(item); }} className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">{t('common.edit')}</button>
                        <button type="button" disabled={isToggling} onClick={() => toggleActiveMutation.mutate(item)} className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">{item.is_active ? t('common.deactivate') : t('common.activate')}</button>
                        <button type="button" onClick={() => setDeleting(item)} className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">{t('common.delete')}</button>
                      </AdminOnly>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <PesticideFormModal open={createOpen} mode="create" submitting={createMutation.isPending} serverError={formError}
        onClose={() => setCreateOpen(false)} onSubmit={(values) => createMutation.mutate(values)} />
      <PesticideFormModal open={!!editing} mode="edit" initial={editing} submitting={updateMutation.isPending} serverError={formError}
        onClose={() => setEditing(null)}
        onSubmit={(values) => { if (!editing) return; updateMutation.mutate({ id: editing.id, payload: values }); }} />
      <ConfirmDialog open={!!deleting} title={t('pesticides.deleteTitle')}
        message={deleting ? t('pesticides.deleteConfirm', { name: deleting.name }) : ''}
        confirmLabel={t('common.delete')} variant="danger" loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting)}
        onClose={() => setDeleting(null)} />

      <EntityPriceHistoryModal
        open={!!pricesFor}
        onClose={() => setPricesFor(null)}
        entityType="pesticide"
        entityId={pricesFor?.id ?? null}
        entityName={pricesFor?.name ?? ''}
        unit={pricesFor?.unit ?? null}
      />
    </section>
  );
};

export default PesticidesPage;
