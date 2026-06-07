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
import PlotFormModal, { type PlotFormSubmit } from './PlotFormModal';
import type { AdminPlot, PaginatedPlots } from './types';
import iconPlots from '@/assets/icons/icon-plots.png';

interface PlotsQueryParams {
  page: number; search: string; crop: string; status: 'all' | 'active' | 'inactive';
}

async function fetchPlots(params: PlotsQueryParams): Promise<PaginatedPlots> {
  const query: Record<string, string | number | boolean> = { page: params.page, per_page: 25 };
  if (params.search.trim()) query.search = params.search.trim();
  if (params.crop.trim()) query.crop_type = params.crop.trim();
  if (params.status !== 'all') query.is_active = params.status === 'active';
  const { data } = await api.get<PaginatedPlots>('/plots', { params: query });
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

const PlotsPage = () => {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [crop, setCrop] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<AdminPlot | null>(null);
  const [deleting, setDeleting] = useState<AdminPlot | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);

  const debouncedSearch = useDebounce(search, 300);
  const debouncedCrop = useDebounce(crop, 300);

  const params: PlotsQueryParams = useMemo(
    () => ({ page, search: debouncedSearch, crop: debouncedCrop, status }),
    [page, debouncedSearch, debouncedCrop, status],
  );

  const plotsQuery = useQuery({
    queryKey: ['admin-plots', params],
    queryFn: () => fetchPlots(params),
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin-plots'] });

  const createMutation = useMutation({
    mutationFn: async (payload: PlotFormSubmit) => (await api.post('/plots', payload)).data,
    onSuccess: () => { toast.success(t('plots.created')); setCreateOpen(false); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<PlotFormSubmit> }) =>
      (await api.put(`/plots/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('plots.updated')); setEditing(null); setFormError(null); invalidate(); },
    onError: (err) => setFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: async (plot: AdminPlot) => { await api.put(`/plots/${plot.id}`, { is_active: !plot.is_active }); },
    onSuccess: (_d, plot) => { toast.success(plot.is_active ? t('plots.deactivated') : t('plots.activated')); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteMutation = useMutation({
    mutationFn: async (plot: AdminPlot) => { await api.delete(`/plots/${plot.id}`); },
    onSuccess: () => { toast.success(t('plots.deleted')); setDeleting(null); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const plots = plotsQuery.data?.data ?? [];
  const meta = plotsQuery.data?.meta;

  const countLabel = (n: number) => t(n > 1 ? 'plots.countOther' : 'plots.countOne', { count: n });

  const handleExport = async () => {
    if (exporting) return;
    setExporting(true);
    try {
      const all: AdminPlot[] = [];
      let currentPage = 1;
      let lastPage = 1;
      const PER_PAGE = 100;
      do {
        const query: Record<string, string | number | boolean> = { page: currentPage, per_page: PER_PAGE };
        if (search.trim()) query.search = search.trim();
        if (crop.trim()) query.crop_type = crop.trim();
        if (status !== 'all') query.is_active = status === 'active';
        const { data } = await api.get<PaginatedPlots>('/plots', { params: query });
        all.push(...(data.data ?? []));
        lastPage = data.meta?.last_page ?? 1;
        currentPage += 1;
      } while (currentPage <= lastPage);

      if (all.length === 0) { toast.info(t('common.noData')); return; }
      downloadCsv(
        `agri-sync-plots-${new Date().toISOString().slice(0, 10)}.csv`,
        all.map((p) => ({
          name: p.name,
          surface: p.surface_area_ha,
          crop: p.crop_type ?? '',
          variety: p.variety ?? '',
          status: p.is_active ? t('common.active') : t('common.inactive'),
          created: formatDate(p.created_at),
        })),
        [
          { key: 'name',    label: t('plots.csv.name') },
          { key: 'surface', label: t('plots.csv.surface') },
          { key: 'crop',    label: t('plots.csv.crop') },
          { key: 'variety', label: t('plots.csv.variety') },
          { key: 'status',  label: t('plots.csv.status') },
          { key: 'created', label: t('plots.csv.created') },
        ],
      );
      toast.success(countLabel(all.length));
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
          <img src={iconPlots} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('plots.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('plots.subtitle')}</p>
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
              {t('plots.new')}
            </button>
          </AdminOnly>
        </div>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input type="search" value={search} maxLength={120}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder={t('plots.searchPlaceholder')}
          className="h-9 flex-1 min-w-[220px] rounded-md border border-border bg-background px-3 text-sm" />
        <input type="text" value={crop} maxLength={100}
          onChange={(e) => { setCrop(e.target.value); setPage(1); }}
          placeholder={t('plots.cropFilter')}
          className="h-9 w-40 rounded-md border border-border bg-background px-3 text-sm" />
        <select value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1); }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm">
          <option value="all">{t('common.allStatuses')}</option>
          <option value="active">{t('common.activesF')}</option>
          <option value="inactive">{t('common.inactivesF')}</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('plots.name')}</th>
              <th className="px-4 py-3 font-medium">{t('plots.surface')}</th>
              <th className="px-4 py-3 font-medium">{t('plots.crop')}</th>
              <th className="px-4 py-3 font-medium">{t('plots.variety')}</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {plotsQuery.isLoading && (<tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
            {plotsQuery.isError && !plotsQuery.isLoading && (<tr><td colSpan={6} className="px-4 py-10 text-center text-rose-400">{t('plots.loadFailed')}</td></tr>)}
            {!plotsQuery.isLoading && !plotsQuery.isError && plots.length === 0 && (<tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">{t('plots.none')}</td></tr>)}

            {plots.map((plot) => {
              const isToggling = toggleActiveMutation.isPending && toggleActiveMutation.variables?.id === plot.id;
              return (
                <tr key={plot.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{plot.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{plot.surface_area_ha} ha</td>
                  <td className="px-4 py-3 text-muted-foreground">{plot.crop_type ?? '—'}</td>
                  <td className="px-4 py-3 text-muted-foreground">{plot.variety ?? '—'}</td>
                  <td className="px-4 py-3">
                    <span className={plot.is_active
                      ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                      : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'}>
                      <span className={`h-1.5 w-1.5 rounded-full ${plot.is_active ? 'bg-emerald-400' : 'bg-zinc-400'}`} />
                      {plot.is_active ? t('common.activeF') : t('common.inactiveF')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                      <div className="flex justify-end gap-1.5">
                        <button type="button" onClick={() => { setFormError(null); setEditing(plot); }}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
                          {t('common.edit')}
                        </button>
                        <button type="button" disabled={isToggling} onClick={() => toggleActiveMutation.mutate(plot)}
                          className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
                          {plot.is_active ? t('common.deactivate') : t('common.activate')}
                        </button>
                        <button type="button" onClick={() => setDeleting(plot)}
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

      {meta && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
          <span>{t('common.pageInfo', { current: meta.current_page, last: meta.last_page })} — {countLabel(meta.total)}</span>
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

      <PlotFormModal open={createOpen} mode="create" submitting={createMutation.isPending} serverError={formError}
        onClose={() => setCreateOpen(false)} onSubmit={(values) => createMutation.mutate(values)} />

      <PlotFormModal open={!!editing} mode="edit" initial={editing} submitting={updateMutation.isPending} serverError={formError}
        onClose={() => setEditing(null)}
        onSubmit={(values) => { if (!editing) return; updateMutation.mutate({ id: editing.id, payload: values }); }} />

      <ConfirmDialog open={!!deleting}
        title={t('plots.deleteTitle')}
        message={deleting ? t('plots.deleteConfirm', { name: deleting.name }) : ''}
        confirmLabel={t('common.delete')} variant="danger" loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting)}
        onClose={() => setDeleting(null)} />
    </section>
  );
};

export default PlotsPage;
