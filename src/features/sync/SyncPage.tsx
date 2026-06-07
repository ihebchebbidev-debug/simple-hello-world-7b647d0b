import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/locale';
import iconSync from '@/assets/icons/icon-sync.png';

interface AdminPosting {
  id: string;
  client_id: string;
  operation_type: string;
  status: 'queued' | 'processing' | 'completed' | 'failed' | string;
  error_message: string | null;
  retry_count: number;
  submitted_at: string;
  synced_at: string | null;
}
interface Paginated<T> { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

const STATUS_COLORS: Record<string, string> = {
  queued: 'bg-amber-500/15 text-amber-300 ring-amber-500/30',
  processing: 'bg-blue-500/15 text-blue-300 ring-blue-500/30',
  completed: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
  failed: 'bg-rose-500/15 text-rose-300 ring-rose-500/30',
};

const SyncPage = () => {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');
  const [opType, setOpType] = useState('');

  const listQuery = useQuery({
    queryKey: ['admin-postings', page, statusFilter, opType],
    queryFn: async () => (await api.get<Paginated<AdminPosting>>('/postings', {
      params: { page, per_page: 25, status: statusFilter || undefined, operation_type: opType || undefined },
    })).data,
    placeholderData: keepPreviousData,
    refetchInterval: 15000,
  });

  const retry = useMutation({
    mutationFn: async (id: string) => (await api.post(`/postings/${id}/retry`)).data,
    onSuccess: () => { toast.success(t('sync.retried')); qc.invalidateQueries({ queryKey: ['admin-postings'] }); },
    onError: () => toast.error(t('errors.actionFailed')),
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconSync} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('sync.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('sync.subtitle')}</p>
          </div>
        </div>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <select value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm">
          <option value="">{t('sync.allStatuses')}</option>
          <option value="queued">{t('sync.queued')}</option>
          <option value="processing">{t('sync.processing')}</option>
          <option value="completed">{t('sync.completed')}</option>
          <option value="failed">{t('sync.failed')}</option>
        </select>
        <select value={opType} onChange={(e) => { setOpType(e.target.value); setPage(1); }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm">
          <option value="">{t('sync.allTypes')}</option>
          <option value="irrigation">{t('dashboard.type.irrigation')}</option>
          <option value="fertilization">{t('dashboard.type.fertilization')}</option>
          <option value="phytosanitary">{t('dashboard.type.phytosanitary')}</option>
          <option value="harvest">{t('dashboard.type.harvest')}</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('sync.type')}</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium">{t('sync.submitted')}</th>
              <th className="px-4 py-3 font-medium">{t('sync.synced')}</th>
              <th className="px-4 py-3 font-medium">{t('sync.retries')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {listQuery.isLoading && (<tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading')}</td></tr>)}
            {!listQuery.isLoading && items.length === 0 && (<tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">{t('sync.none')}</td></tr>)}
            {items.map((p) => (
              <tr key={p.id} className="bg-background hover:bg-muted/20">
                <td className="px-4 py-3 font-medium text-foreground">{p.operation_type}</td>
                <td className="px-4 py-3">
                  <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${STATUS_COLORS[p.status] ?? 'bg-zinc-500/15 text-zinc-300 ring-zinc-500/30'}`}>
                    {t(`sync.${p.status}`, p.status)}
                  </span>
                  {p.error_message && <p className="text-[10px] text-rose-300 mt-1 truncate max-w-xs">{p.error_message}</p>}
                </td>
                <td className="px-4 py-3 text-muted-foreground">{formatDate(p.submitted_at)}</td>
                <td className="px-4 py-3 text-muted-foreground">{p.synced_at ? formatDate(p.synced_at) : '—'}</td>
                <td className="px-4 py-3 text-muted-foreground">{p.retry_count}</td>
                <td className="px-4 py-3 text-right">
                  {p.status === 'failed' && (
                    <button disabled={retry.isPending} onClick={() => retry.mutate(p.id)} className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
                      {t('sync.retry')}
                    </button>
                  )}
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
    </section>
  );
};

export default SyncPage;
