import { useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useDebounce } from '@/lib/useDebounce';
import ConfirmDialog from '@/components/ConfirmDialog';
import AdminOnly from '@/components/AdminOnly';
import Modal from '@/components/Modal';
import iconPhyto from '@/assets/icons/icon-phytosanitary.png';

interface AdminPest {
  id: string;
  name: string;
  scientific_name: string | null;
  category: string | null;
  description: string | null;
  is_active: boolean;
}
interface Paginated<T> { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

function extractApiError(err: unknown, fb: string): string {
  if (axios.isAxiosError(err)) {
    const d = err.response?.data as { error?: { message?: string; details?: Record<string, string[]> }; message?: string } | undefined;
    const details = d?.error?.details;
    if (details) { const f = Object.values(details).flat()[0]; if (typeof f === 'string') return f; }
    return d?.error?.message ?? d?.message ?? err.message ?? fb;
  }
  return fb;
}

const PestsPage = () => {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<AdminPest | null>(null);
  const [deleting, setDeleting] = useState<AdminPest | null>(null);
  const dSearch = useDebounce(search, 300);

  const params = useMemo(() => {
    const p: Record<string, string | number | boolean> = { page, per_page: 25 };
    if (dSearch.trim()) p.search = dSearch.trim();
    if (status !== 'all') p.is_active = status === 'active';
    return p;
  }, [page, dSearch, status]);

  const listQuery = useQuery({
    queryKey: ['admin-pests', params],
    queryFn: async () => (await api.get<Paginated<AdminPest>>('/pests', { params })).data,
    placeholderData: keepPreviousData,
  });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['admin-pests'] });

  const createMutation = useMutation({
    mutationFn: async (payload: Partial<AdminPest>) => (await api.post('/pests', payload)).data,
    onSuccess: () => { toast.success(t('pests.created')); setCreateOpen(false); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.createFailed'))),
  });
  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<AdminPest> }) => (await api.put(`/pests/${id}`, payload)).data,
    onSuccess: () => { toast.success(t('pests.updated')); setEditing(null); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.updateFailed'))),
  });
  const deleteMutation = useMutation({
    mutationFn: async (item: AdminPest) => { await api.delete(`/pests/${item.id}`); },
    onSuccess: () => { toast.success(t('pests.deleted')); setDeleting(null); invalidate(); },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconPhyto} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('pests.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('pests.subtitle')}</p>
          </div>
        </div>
        <AdminOnly><button onClick={() => setCreateOpen(true)} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90">{t('pests.new')}</button></AdminOnly>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder={t('common.search')} className="h-9 flex-1 min-w-[220px] rounded-md border border-border bg-background px-3 text-sm" />
        <select value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1); }} className="h-9 rounded-md border border-border bg-background px-2 text-sm">
          <option value="all">{t('common.allStatuses')}</option>
          <option value="active">{t('common.actives')}</option>
          <option value="inactive">{t('common.inactives')}</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('pests.name')}</th>
              <th className="px-4 py-3 font-medium">{t('pests.scientificName')}</th>
              <th className="px-4 py-3 font-medium">{t('pests.category')}</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {listQuery.isLoading && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
            {!listQuery.isLoading && items.length === 0 && (<tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">{t('pests.none')}</td></tr>)}
            {items.map((p) => (
              <tr key={p.id} className="bg-background hover:bg-muted/20">
                <td className="px-4 py-3 font-medium text-foreground">{p.name}</td>
                <td className="px-4 py-3 text-muted-foreground italic">{p.scientific_name ?? '—'}</td>
                <td className="px-4 py-3 text-muted-foreground">{p.category ?? '—'}</td>
                <td className="px-4 py-3">
                  <span className={p.is_active
                    ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                    : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'}>
                    <span className={`h-1.5 w-1.5 rounded-full ${p.is_active ? 'bg-emerald-400' : 'bg-zinc-400'}`} />
                    {p.is_active ? t('common.active') : t('common.inactive')}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  <AdminOnly fallback={<span className="text-xs text-muted-foreground">—</span>}>
                    <div className="flex justify-end gap-1.5">
                      <button onClick={() => setEditing(p)} className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">{t('common.edit')}</button>
                      <button onClick={() => setDeleting(p)} className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10">{t('common.delete')}</button>
                    </div>
                  </AdminOnly>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>{t('common.pageInfo', { current: meta.current_page, last: meta.last_page })}</span>
          <div className="flex gap-1.5">
            <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pagePrev')}</button>
            <button disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pageNext')}</button>
          </div>
        </div>
      )}

      <PestFormModal open={createOpen} mode="create" onClose={() => setCreateOpen(false)} submitting={createMutation.isPending} onSubmit={(v) => createMutation.mutate(v)} />
      <PestFormModal open={!!editing} mode="edit" initial={editing} onClose={() => setEditing(null)} submitting={updateMutation.isPending}
        onSubmit={(v) => editing && updateMutation.mutate({ id: editing.id, payload: v })} />
      <ConfirmDialog open={!!deleting} title={t('pests.deleteTitle')}
        message={deleting ? t('pests.deleteConfirm', { name: deleting.name }) : ''}
        confirmLabel={t('common.delete')} variant="danger" loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting)} onClose={() => setDeleting(null)} />
    </section>
  );
};

interface FormProps { open: boolean; mode: 'create' | 'edit'; initial?: AdminPest | null; submitting?: boolean; onClose: () => void; onSubmit: (v: Partial<AdminPest>) => void; }
const PestFormModal = ({ open, mode, initial, submitting, onClose, onSubmit }: FormProps) => {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [sci, setSci] = useState('');
  const [cat, setCat] = useState('');
  const [desc, setDesc] = useState('');
  const [active, setActive] = useState(true);
  useMemo(() => {
    if (open) {
      setName(initial?.name ?? ''); setSci(initial?.scientific_name ?? '');
      setCat(initial?.category ?? ''); setDesc(initial?.description ?? '');
      setActive(initial?.is_active ?? true);
    }
  }, [open, initial]);
  return (
    <Modal open={open} onClose={onClose} title={mode === 'create' ? t('pests.formCreateTitle') : t('pests.formEditTitle')}>
      <form onSubmit={(e) => { e.preventDefault(); if (submitting) return; onSubmit({ name, scientific_name: sci || null, category: cat || null, description: desc || null, is_active: active }); }} className="space-y-3 p-5">
        <div><label className="text-xs font-medium text-muted-foreground">{t('pests.name')}</label>
          <input required value={name} onChange={(e) => setName(e.target.value)} className="mt-1 w-full h-9 rounded-md border border-border bg-background px-3 text-sm" /></div>
        <div><label className="text-xs font-medium text-muted-foreground">{t('pests.scientificName')}</label>
          <input value={sci} onChange={(e) => setSci(e.target.value)} className="mt-1 w-full h-9 rounded-md border border-border bg-background px-3 text-sm" /></div>
        <div><label className="text-xs font-medium text-muted-foreground">{t('pests.category')}</label>
          <input value={cat} onChange={(e) => setCat(e.target.value)} className="mt-1 w-full h-9 rounded-md border border-border bg-background px-3 text-sm" /></div>
        <div><label className="text-xs font-medium text-muted-foreground">{t('pests.description')}</label>
          <textarea value={desc} onChange={(e) => setDesc(e.target.value)} rows={3} className="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" /></div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} />{t('common.active')}</label>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs">{t('common.cancel')}</button>
          <button type="submit" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">{submitting ? t('common.saving') : t('common.save')}</button>
        </div>
      </form>
    </Modal>
  );
};

export default PestsPage;
