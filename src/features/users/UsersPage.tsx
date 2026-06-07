import { useMemo, useState } from 'react';
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query';
import { toast } from 'sonner';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { api } from '@/lib/api';
import { useDebounce } from '@/lib/useDebounce';
import ConfirmDialog from '@/components/ConfirmDialog';
import { downloadCsv } from '@/lib/csv';
import { formatDateTime } from '@/lib/locale';
import iconUsers from '@/assets/icons/icon-users.png';
import UserFormModal, { type UserFormSubmit } from './UserFormModal';
import {
  ROLE_BADGE_CLASSES,
  ROLE_OPTIONS,
  normaliseRoles,
  type AdminUser,
  type AppRole,
  type PaginatedUsers,
} from './types';

interface UsersQueryParams {
  page: number;
  search: string;
  role: AppRole | '';
  status: 'all' | 'active' | 'inactive';
}

async function fetchUsers(params: UsersQueryParams): Promise<PaginatedUsers> {
  const query: Record<string, string | number | boolean> = { page: params.page, per_page: 25 };
  if (params.search.trim()) query.search = params.search.trim();
  if (params.role) query.role = params.role;
  if (params.status !== 'all') query.is_active = params.status === 'active';
  const { data } = await api.get<PaginatedUsers>('/users', { params: query });
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

const UsersPage = () => {
  const { t } = useTranslation();
  const roleLabel = (r: AppRole) => t(`users.role.${r}`);
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [role, setRole] = useState<AppRole | ''>('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<AdminUser | null>(null);
  const [deleting, setDeleting] = useState<AdminUser | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const debouncedSearch = useDebounce(search, 300);

  const params: UsersQueryParams = useMemo(
    () => ({ page, search: debouncedSearch, role, status }),
    [page, debouncedSearch, role, status],
  );

  const usersQuery = useQuery({
    queryKey: ['admin-users', params],
    queryFn: () => fetchUsers(params),
    placeholderData: keepPreviousData,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin-users'] });

  const createMutation = useMutation({
    mutationFn: async (payload: UserFormSubmit) => {
      const { data } = await api.post('/users', payload);
      return data;
    },
    onSuccess: () => {
      toast.success(t('users.created'));
      setCreateOpen(false);
      setFormError(null);
      invalidate();
    },
    onError: (err) => setFormError(extractApiError(err, t('errors.createFailed'))),
  });

  const updateMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: string; payload: Partial<UserFormSubmit> }) => {
      const { data } = await api.put(`/users/${id}`, payload);
      return data;
    },
    onSuccess: () => {
      toast.success(t('users.updated'));
      setEditing(null);
      setFormError(null);
      invalidate();
    },
    onError: (err) => setFormError(extractApiError(err, t('errors.updateFailed'))),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: async (user: AdminUser) => {
      await api.put(`/users/${user.id}`, { is_active: !user.is_active });
    },
    onSuccess: (_d, user) => {
      toast.success(user.is_active ? t('users.deactivated') : t('users.activated'));
      invalidate();
    },
    onError: (err) => toast.error(extractApiError(err, t('errors.actionFailed'))),
  });

  const deleteMutation = useMutation({
    mutationFn: async (user: AdminUser) => {
      await api.delete(`/users/${user.id}`);
    },
    onSuccess: () => {
      toast.success(t('users.deleted'));
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(extractApiError(err, t('errors.deleteFailed'))),
  });

  const users = usersQuery.data?.data ?? [];
  const meta = usersQuery.data?.meta;

  const handleExport = () => {
    if (users.length === 0) {
      toast.info(t('common.noData'));
      return;
    }
    downloadCsv(
      `agri-sync-utilisateurs-${new Date().toISOString().slice(0, 10)}.csv`,
      users.map((u) => ({
        nom: u.name,
        email: u.email,
        roles: normaliseRoles(u.roles).map((r) => roleLabel(r)).join(' | '),
        statut: u.is_active ? t('common.active') : t('common.inactive'),
        langue: u.preferred_lang,
        derniere_connexion: formatDateTime(u.last_login_at),
        cree_le: formatDateTime(u.created_at),
      })),
      [
        { key: 'nom', label: t('users.name') },
        { key: 'email', label: t('users.email') },
        { key: 'roles', label: t('users.roles') },
        { key: 'statut', label: t('common.status') },
        { key: 'langue', label: t('common.language') },
        { key: 'derniere_connexion', label: t('users.lastLogin') },
        { key: 'cree_le', label: t('common.createdAt') },
      ],
    );
  };

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-center gap-3">
          <img src={iconUsers} alt="" className="h-9 w-9 shrink-0" />
          <div>
            <h1 className="display-md text-foreground">{t('users.title')}</h1>
            <p className="text-sm text-muted-foreground">{t('users.subtitle')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {meta && (
            <span className="text-xs text-muted-foreground">
              {t(meta.total > 1 ? 'users.countOther' : 'users.countOne', { count: meta.total })}
            </span>
          )}
          <button
            type="button"
            onClick={handleExport}
            className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            {t('common.exportCsv')}
          </button>
          <button
            type="button"
            onClick={() => {
              setFormError(null);
              setCreateOpen(true);
            }}
            className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90"
          >
            {t('users.new')}
          </button>
        </div>
      </header>

      <div className="flex flex-wrap gap-3 rounded-lg border border-border bg-muted/20 p-3">
        <input
          type="search"
          value={search}
          maxLength={120}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder={t('users.searchPlaceholder')}
          className="h-9 flex-1 min-w-[220px] rounded-md border border-border bg-background px-3 text-sm"
        />
        <select
          value={role}
          onChange={(e) => {
            setRole(e.target.value as AppRole | '');
            setPage(1);
          }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
        >
          <option value="">{t('users.allRoles')}</option>
          {ROLE_OPTIONS.map((r) => (
            <option key={r} value={r}>
              {roleLabel(r)}
            </option>
          ))}
        </select>
        <select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value as typeof status);
            setPage(1);
          }}
          className="h-9 rounded-md border border-border bg-background px-2 text-sm"
        >
          <option value="all">{t('common.allStatuses')}</option>
          <option value="active">{t('common.actives')}</option>
          <option value="inactive">{t('common.inactives')}</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">{t('users.name')}</th>
              <th className="px-4 py-3 font-medium">{t('users.email')}</th>
              <th className="px-4 py-3 font-medium">{t('users.roles')}</th>
              <th className="px-4 py-3 font-medium">{t('common.status')}</th>
              <th className="px-4 py-3 font-medium">{t('users.lastLogin')}</th>
              <th className="px-4 py-3 font-medium text-right">{t('common.actions')}</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {usersQuery.isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                  {t('common.loading')}
                </td>
              </tr>
            )}
            {usersQuery.isError && !usersQuery.isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-rose-400">
                  {t('users.loadFailed')}
                </td>
              </tr>
            )}
            {!usersQuery.isLoading && !usersQuery.isError && users.length === 0 && (
              <tr>
                <td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">
                  {t('users.none')}
                </td>
              </tr>
            )}
            {users.map((user) => {
              const userRoles = normaliseRoles(user.roles);
              const isToggling =
                toggleActiveMutation.isPending && toggleActiveMutation.variables?.id === user.id;
              return (
                <tr key={user.id} className="bg-background hover:bg-muted/20">
                  <td className="px-4 py-3 font-medium text-foreground">{user.name}</td>
                  <td className="px-4 py-3 text-muted-foreground">{user.email}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1.5">
                      {userRoles.length === 0 && (
                        <span className="text-xs text-muted-foreground">—</span>
                      )}
                      {userRoles.map((r) => (
                        <span
                          key={r}
                          className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${ROLE_BADGE_CLASSES[r]}`}
                        >
                          {roleLabel(r)}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <span
                      className={
                        user.is_active
                          ? 'inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-medium text-emerald-300 ring-1 ring-inset ring-emerald-500/30'
                          : 'inline-flex items-center gap-1.5 rounded-full bg-zinc-500/15 px-2 py-0.5 text-[11px] font-medium text-zinc-300 ring-1 ring-inset ring-zinc-500/30'
                      }
                    >
                      <span
                        className={`h-1.5 w-1.5 rounded-full ${
                          user.is_active ? 'bg-emerald-400' : 'bg-zinc-400'
                        }`}
                      />
                      {user.is_active ? t('common.active') : t('common.inactive')}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-xs text-muted-foreground">
                    {formatDateTime(user.last_login_at)}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1.5">
                      <button
                        type="button"
                        onClick={() => {
                          setFormError(null);
                          setEditing(user);
                        }}
                        className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                      >
                        {t('common.edit')}
                      </button>
                      <button
                        type="button"
                        disabled={isToggling}
                        onClick={() => toggleActiveMutation.mutate(user)}
                        className="rounded-md border border-border px-2.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
                      >
                        {user.is_active ? t('common.deactivate') : t('common.activate')}
                      </button>
                      <button
                        type="button"
                        onClick={() => setDeleting(user)}
                        className="rounded-md border border-rose-500/40 px-2.5 py-1 text-xs text-rose-300 hover:bg-rose-500/10"
                      >
                        {t('common.delete')}
                      </button>
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>
            {t('common.pageInfo', { current: meta.current_page, last: meta.last_page })}
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="rounded-md border border-border px-3 py-1 disabled:opacity-40"
            >
              {t('common.pagePrev')}
            </button>
            <button
              type="button"
              disabled={page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-md border border-border px-3 py-1 disabled:opacity-40"
            >
              {t('common.pageNext')}
            </button>
          </div>
        </div>
      )}

      <UserFormModal
        open={createOpen}
        mode="create"
        submitting={createMutation.isPending}
        serverError={formError}
        onClose={() => setCreateOpen(false)}
        onSubmit={(values) => createMutation.mutate(values)}
      />

      <UserFormModal
        open={!!editing}
        mode="edit"
        initial={editing}
        submitting={updateMutation.isPending}
        serverError={formError}
        onClose={() => setEditing(null)}
        onSubmit={(values) => {
          if (!editing) return;
          const payload: Partial<UserFormSubmit> = { ...values };
          if (!payload.password) delete payload.password;
          updateMutation.mutate({ id: editing.id, payload });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title={t('users.deleteTitle')}
        message={
          deleting
            ? t('users.deleteConfirm', { name: deleting.name })
            : ''
        }
        confirmLabel={t('common.delete')}
        variant="danger"
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting)}
        onClose={() => setDeleting(null)}
      />
    </section>
  );
};

export default UsersPage;
