import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { formatDate } from '@/lib/locale';

interface SystemLog {
  id: string;
  event: string;
  message: string;
  context: Record<string, unknown> | null;
  created_at: string;
}
interface Paginated<T> { data: T[]; meta?: { current_page: number; last_page: number; total: number } }

const LogsPage = () => {
  const { t } = useTranslation();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');

  const listQuery = useQuery({
    queryKey: ['system-logs', page, search],
    queryFn: async () => (await api.get<Paginated<SystemLog>>('/logs', {
      params: { page, per_page: 25, search: search || undefined },
    })).data,
    placeholderData: keepPreviousData,
  });

  const items = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <section className="space-y-5">
      <header>
        <h1 className="display-md text-foreground">{t('logs.title', 'System logs')}</h1>
        <p className="text-sm text-muted-foreground">{t('logs.subtitle', 'Audit trail of system events.')}</p>
      </header>

      <div className="flex gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder={t('common.search', 'Search')}
          className="h-9 flex-1 rounded-md border border-border bg-background px-2 text-sm"
        />
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('logs.event', 'Event')}</th>
              <th className="px-4 py-3 font-medium">{t('logs.message', 'Message')}</th>
              <th className="px-4 py-3 font-medium">{t('logs.date', 'Date')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {listQuery.isLoading && (<tr><td colSpan={3} className="px-4 py-10 text-center text-muted-foreground">{t('common.loading', 'Loading...')}</td></tr>)}
            {!listQuery.isLoading && items.length === 0 && (<tr><td colSpan={3} className="px-4 py-10 text-center text-muted-foreground">{t('logs.none', 'No logs')}</td></tr>)}
            {items.map((l) => (
              <tr key={l.id} className="bg-background hover:bg-muted/20">
                <td className="px-4 py-3 font-medium text-foreground">{l.event}</td>
                <td className="px-4 py-3 text-muted-foreground">{l.message}</td>
                <td className="px-4 py-3 text-muted-foreground">{formatDate(l.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>{t('common.pageInfo', { current: meta.current_page, last: meta.last_page })}</span>
          <div className="flex gap-1.5">
            <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pagePrev', 'Prev')}</button>
            <button disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-border px-3 py-1 disabled:opacity-40">{t('common.pageNext', 'Next')}</button>
          </div>
        </div>
      )}
    </section>
  );
};

export default LogsPage;