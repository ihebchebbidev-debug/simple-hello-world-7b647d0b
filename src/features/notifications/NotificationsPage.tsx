import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/locale';
import iconBell from '@/assets/icons/icon-bell.png';

interface AdminNotification {
  id: string;
  type: string;
  title: string;
  body: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}
interface Paginated<T> { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

const NotificationsPage = () => {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);

  const listQuery = useQuery({
    queryKey: ['admin-notifications', page, unreadOnly],
    queryFn: async () => (await api.get<Paginated<AdminNotification>>('/notifications', { params: { page, per_page: 25, unread_only: unreadOnly || undefined } })).data,
  });
  const countQuery = useQuery({
    queryKey: ['admin-notifications-count'],
    queryFn: async () => (await api.get<{ data: { unread_count: number } }>('/notifications/unread-count')).data.data.unread_count,
  });
  const invalidate = () => { qc.invalidateQueries({ queryKey: ['admin-notifications'] }); qc.invalidateQueries({ queryKey: ['admin-notifications-count'] }); };

  const markRead = useMutation({
    mutationFn: async (id: string) => (await api.post(`/notifications/${id}/read`)).data,
    onSuccess: invalidate,
  });
  const markAll = useMutation({
    mutationFn: async () => (await api.post('/notifications/mark-all-read')).data,
    onSuccess: () => { toast.success(t('notifications.allRead')); invalidate(); },
  });
  const deleteOne = useMutation({
    mutationFn: async (id: string) => (await api.delete(`/notifications/${id}`)).data,
    onSuccess: () => { toast.success(t('notifications.deleted')); invalidate(); },
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconBell} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('notifications.title')}</h1>
            <p className="text-sm text-muted-foreground">
              {t('notifications.subtitle')} · {t('notifications.unreadCount', { count: countQuery.data ?? 0 })}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2 text-xs text-muted-foreground">
            <input type="checkbox" checked={unreadOnly} onChange={(e) => { setUnreadOnly(e.target.checked); setPage(1); }} />
            {t('notifications.unreadOnly')}
          </label>
          <button onClick={() => markAll.mutate()} disabled={markAll.isPending || !countQuery.data}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50">
            {t('notifications.markAllRead')}
          </button>
        </div>
      </header>

      <div className="space-y-2">
        {listQuery.isLoading && <p className="text-center text-sm text-muted-foreground py-8">{t('common.loading')}</p>}
        {!listQuery.isLoading && items.length === 0 && <p className="text-center text-sm text-muted-foreground py-8">{t('notifications.none')}</p>}
        {items.map((n) => (
          <div key={n.id} className={`flex items-start justify-between gap-3 rounded-lg border p-3 ${n.is_read ? 'border-border bg-background' : 'border-primary/40 bg-primary/5'}`}>
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                {!n.is_read && <span className="h-2 w-2 rounded-full bg-primary shrink-0" />}
                <h3 className="text-sm font-medium text-foreground truncate">{n.title}</h3>
                <span className="text-[10px] uppercase text-muted-foreground">{n.type}</span>
              </div>
              {n.body && <p className="text-xs text-muted-foreground mt-1">{n.body}</p>}
              <p className="text-[11px] text-muted-foreground mt-1">{formatDate(n.created_at)}</p>
            </div>
            <div className="flex flex-col gap-1.5 shrink-0">
              {!n.is_read && (
                <button onClick={() => markRead.mutate(n.id)} className="rounded-md border border-border px-2 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground">
                  {t('notifications.markRead')}
                </button>
              )}
              <button onClick={() => deleteOne.mutate(n.id)} className="rounded-md border border-rose-500/40 px-2 py-0.5 text-[11px] text-rose-300 hover:bg-rose-500/10">
                {t('common.delete')}
              </button>
            </div>
          </div>
        ))}
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

export default NotificationsPage;
