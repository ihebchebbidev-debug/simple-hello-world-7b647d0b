/**
 * /developer — token-guarded ticket inbox.
 *
 * No sign-up, no app auth. The developer pastes the shared token once
 * (`DEVELOPER_ACCESS_TOKEN` on the Render service); it's kept in
 * localStorage and sent on every request as `X-Developer-Token`.
 */

import { useEffect, useMemo, useState } from 'react';
import axios, { type AxiosInstance } from 'axios';
import { BACKEND_URL } from '@/lib/api';
import { Bug, Lightbulb, MessageSquare, LogOut, RefreshCw, Search } from 'lucide-react';

type FeedbackType = 'bug' | 'feature' | 'other';
type Severity = 'low' | 'medium' | 'high' | 'critical';
type Status = 'open' | 'in_progress' | 'resolved' | 'closed';

interface Ticket {
  id: string;
  user: { id: string; name: string; email: string } | null;
  type: FeedbackType;
  severity: Severity;
  status: Status;
  title: string;
  description: string;
  page_url: string | null;
  user_agent: string | null;
  app_version: string | null;
  metadata: Record<string, unknown> | null;
  admin_notes: string | null;
  resolved_at: string | null;
  created_at: string;
  updated_at: string;
}

const TOKEN_KEY = 'agri-sync.developer.token';

const makeClient = (token: string): AxiosInstance =>
  axios.create({
    baseURL: `${BACKEND_URL}/api`,
    headers: { Accept: 'application/json', 'X-Developer-Token': token },
  });

const typeIcon = (t: FeedbackType) =>
  t === 'bug' ? <Bug className="h-3.5 w-3.5" />
    : t === 'feature' ? <Lightbulb className="h-3.5 w-3.5" />
    : <MessageSquare className="h-3.5 w-3.5" />;

const severityClass = (s: Severity) =>
  s === 'critical' ? 'bg-red-500/15 text-red-400 border-red-500/30'
    : s === 'high' ? 'bg-orange-500/15 text-orange-400 border-orange-500/30'
    : s === 'medium' ? 'bg-amber-500/15 text-amber-400 border-amber-500/30'
    : 'bg-slate-500/15 text-slate-300 border-slate-500/30';

const statusClass = (s: Status) =>
  s === 'resolved' ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
    : s === 'in_progress' ? 'bg-sky-500/15 text-sky-400 border-sky-500/30'
    : s === 'closed' ? 'bg-zinc-500/15 text-zinc-400 border-zinc-500/30'
    : 'bg-rose-500/15 text-rose-400 border-rose-500/30';

const DeveloperPage = () => {
  const [token, setToken] = useState<string>(() => localStorage.getItem(TOKEN_KEY) ?? '');
  const [draftToken, setDraftToken] = useState('');
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [loading, setLoading] = useState(false);
  const [authError, setAuthError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [filterStatus, setFilterStatus] = useState<'' | Status>('');
  const [filterType, setFilterType] = useState<'' | FeedbackType>('');
  const [selected, setSelected] = useState<Ticket | null>(null);

  const client = useMemo(() => (token ? makeClient(token) : null), [token]);

  const load = async () => {
    if (!client) return;
    setLoading(true);
    setAuthError(null);
    try {
      const params: Record<string, string> = { per_page: '100' };
      if (filterStatus) params.status = filterStatus;
      if (filterType) params.type = filterType;
      if (search.trim()) params.search = search.trim();
      const { data } = await client.get('/developer/feedback', { params });
      setTickets(data?.data ?? []);
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } })?.response?.status;
      if (status === 401) {
        setAuthError('Invalid developer token.');
        localStorage.removeItem(TOKEN_KEY);
        setToken('');
      } else if (status === 503) {
        setAuthError('DEVELOPER_ACCESS_TOKEN is not configured on the server.');
      } else {
        setAuthError('Could not load tickets.');
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { if (token) void load(); /* eslint-disable-next-line */ }, [token]);

  const updateTicket = async (id: string, patch: Partial<Pick<Ticket, 'status' | 'admin_notes' | 'severity'>>) => {
    if (!client) return;
    try {
      const { data } = await client.patch(`/developer/feedback/${id}`, patch);
      const updated: Ticket = data?.data;
      setTickets((prev) => prev.map((t) => (t.id === id ? updated : t)));
      if (selected?.id === id) setSelected(updated);
    } catch {
      setAuthError('Update failed.');
    }
  };

  const deleteTicket = async (id: string) => {
    if (!client) return;
    if (!confirm('Delete this ticket permanently?')) return;
    try {
      await client.delete(`/developer/feedback/${id}`);
      setTickets((prev) => prev.filter((t) => t.id !== id));
      if (selected?.id === id) setSelected(null);
    } catch {
      setAuthError('Delete failed.');
    }
  };

  // ─── Gate ────────────────────────────────────────────────────────────────
  if (!token) {
    return (
      <div className="min-h-screen bg-background text-foreground flex items-center justify-center p-6">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (!draftToken.trim()) return;
            localStorage.setItem(TOKEN_KEY, draftToken.trim());
            setToken(draftToken.trim());
          }}
          className="w-full max-w-sm space-y-4 rounded-xl border border-border bg-[hsl(var(--surface-bright))] p-6 shadow-xl"
        >
          <div>
            <h1 className="text-lg font-bold">Developer console</h1>
            <p className="mt-1 text-xs text-muted-foreground">
              Paste the <code className="rounded bg-black/30 px-1">DEVELOPER_ACCESS_TOKEN</code> configured on the API server.
            </p>
          </div>
          <input
            type="password"
            autoFocus
            value={draftToken}
            onChange={(e) => setDraftToken(e.target.value)}
            placeholder="Developer token"
            className="cl-input h-10 w-full text-sm"
          />
          {authError && <p className="text-xs text-red-400">{authError}</p>}
          <button
            type="submit"
            className="w-full rounded-md bg-[hsl(var(--primary))] px-3 py-2 text-sm font-semibold text-[hsl(var(--primary-foreground))] hover:opacity-90"
          >
            Unlock
          </button>
        </form>
      </div>
    );
  }

  // ─── Dashboard ───────────────────────────────────────────────────────────
  return (
    <div className="min-h-screen bg-background text-foreground">
      <header className="sticky top-0 z-10 border-b border-border bg-[hsl(var(--surface-bright))]/80 backdrop-blur">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
          <div>
            <h1 className="text-base font-bold">Developer · ticket inbox</h1>
            <p className="text-[11px] text-muted-foreground">
              {tickets.length} ticket{tickets.length === 1 ? '' : 's'} · {BACKEND_URL}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <button
              onClick={load}
              className="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs hover:bg-black/20"
              disabled={loading}
            >
              <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} /> Reload
            </button>
            <button
              onClick={() => { localStorage.removeItem(TOKEN_KEY); setToken(''); setDraftToken(''); }}
              className="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs hover:bg-black/20"
            >
              <LogOut className="h-3.5 w-3.5" /> Lock
            </button>
          </div>
        </div>

        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-2 px-4 pb-3">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void load(); }}
              placeholder="Search title / description…"
              className="cl-input h-8 w-full pl-8 text-xs"
            />
          </div>
          <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value as '' | Status)} className="cl-input h-8 text-xs">
            <option value="">All statuses</option>
            <option value="open">Open</option>
            <option value="in_progress">In progress</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
          </select>
          <select value={filterType} onChange={(e) => setFilterType(e.target.value as '' | FeedbackType)} className="cl-input h-8 text-xs">
            <option value="">All types</option>
            <option value="bug">Bug</option>
            <option value="feature">Feature</option>
            <option value="other">Other</option>
          </select>
          <button onClick={load} className="rounded-md bg-[hsl(var(--primary))] px-3 py-1 text-xs font-semibold text-[hsl(var(--primary-foreground))] hover:opacity-90">
            Apply
          </button>
        </div>
      </header>

      {authError && <div className="mx-auto max-w-7xl px-4 pt-3 text-xs text-red-400">{authError}</div>}

      <main className="mx-auto grid max-w-7xl gap-4 px-4 py-4 lg:grid-cols-[1fr_420px]">
        {/* List */}
        <div className="space-y-2">
          {tickets.length === 0 && !loading && (
            <div className="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
              No tickets yet.
            </div>
          )}
          {tickets.map((t) => (
            <button
              key={t.id}
              onClick={() => setSelected(t)}
              className={`block w-full rounded-lg border p-3 text-left transition ${
                selected?.id === t.id ? 'border-[hsl(var(--primary))] bg-[hsl(var(--surface-bright))]' : 'border-border hover:bg-[hsl(var(--surface-bright))]/60'
              }`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="inline-flex items-center gap-1 rounded border border-border px-1.5 py-0.5 text-[10px] uppercase">
                      {typeIcon(t.type)} {t.type}
                    </span>
                    <span className={`rounded border px-1.5 py-0.5 text-[10px] uppercase ${severityClass(t.severity)}`}>{t.severity}</span>
                    <span className={`rounded border px-1.5 py-0.5 text-[10px] uppercase ${statusClass(t.status)}`}>{t.status.replace('_', ' ')}</span>
                  </div>
                  <p className="mt-1.5 truncate text-sm font-semibold">{t.title}</p>
                  <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{t.description}</p>
                  <p className="mt-1 text-[11px] text-muted-foreground">
                    {t.user?.email
                      ?? (t.metadata?.reporter_email as string | undefined)
                      ?? 'anonymous'} · {new Date(t.created_at).toLocaleString()}
                  </p>
                </div>
              </div>
            </button>
          ))}
        </div>

        {/* Detail panel */}
        <aside className="lg:sticky lg:top-[140px] lg:self-start">
          {!selected ? (
            <div className="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
              Select a ticket to view details.
            </div>
          ) : (
            <div className="space-y-3 rounded-lg border border-border bg-[hsl(var(--surface-bright))] p-4">
              <div>
                <h2 className="text-sm font-bold">{selected.title}</h2>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                  #{selected.id.slice(0, 8)} · {new Date(selected.created_at).toLocaleString()}
                </p>
              </div>

              <div className="grid grid-cols-2 gap-2 text-xs">
                <label className="space-y-1">
                  <span className="text-muted-foreground">Status</span>
                  <select
                    value={selected.status}
                    onChange={(e) => updateTicket(selected.id, { status: e.target.value as Status })}
                    className="cl-input h-8 w-full text-xs"
                  >
                    <option value="open">Open</option>
                    <option value="in_progress">In progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                  </select>
                </label>
                <label className="space-y-1">
                  <span className="text-muted-foreground">Severity</span>
                  <select
                    value={selected.severity}
                    onChange={(e) => updateTicket(selected.id, { severity: e.target.value as Severity })}
                    className="cl-input h-8 w-full text-xs"
                  >
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                  </select>
                </label>
              </div>

              <div className="space-y-1 text-xs">
                <span className="text-muted-foreground">Description</span>
                <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded border border-border bg-black/20 p-2 text-[12px]">{selected.description}</pre>
              </div>

              <dl className="grid grid-cols-1 gap-1.5 text-[11px] text-muted-foreground">
                <div><dt className="inline font-semibold text-foreground/80">Reporter: </dt>
                  <dd className="inline">{selected.user?.email ?? (selected.metadata?.reporter_email as string | undefined) ?? 'anonymous'}</dd></div>
                {(selected.metadata?.reporter_name as string | undefined) && (
                  <div><dt className="inline font-semibold text-foreground/80">Name: </dt>
                    <dd className="inline">{selected.metadata?.reporter_name as string}</dd></div>
                )}
                {selected.page_url && (
                  <div><dt className="inline font-semibold text-foreground/80">Page: </dt>
                    <dd className="inline break-all"><a href={selected.page_url} target="_blank" rel="noreferrer" className="underline">{selected.page_url}</a></dd></div>
                )}
                {selected.app_version && (
                  <div><dt className="inline font-semibold text-foreground/80">App: </dt>
                    <dd className="inline">{selected.app_version}</dd></div>
                )}
                {selected.user_agent && (
                  <div><dt className="inline font-semibold text-foreground/80">UA: </dt>
                    <dd className="inline break-all">{selected.user_agent}</dd></div>
                )}
              </dl>

              <label className="block space-y-1 text-xs">
                <span className="text-muted-foreground">Admin notes</span>
                <textarea
                  defaultValue={selected.admin_notes ?? ''}
                  onBlur={(e) => {
                    const v = e.target.value;
                    if (v !== (selected.admin_notes ?? '')) updateTicket(selected.id, { admin_notes: v });
                  }}
                  className="cl-input min-h-[80px] w-full resize-y px-2 py-1.5 text-xs"
                  placeholder="Notes saved on blur…"
                />
              </label>

              <div className="flex justify-end pt-1">
                <button
                  onClick={() => deleteTicket(selected.id)}
                  className="rounded-md border border-red-500/40 px-3 py-1.5 text-xs font-semibold text-red-400 hover:bg-red-500/10"
                >
                  Delete
                </button>
              </div>
            </div>
          )}
        </aside>
      </main>
    </div>
  );
};

export default DeveloperPage;
