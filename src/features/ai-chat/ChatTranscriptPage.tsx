import { useEffect, useMemo, useState } from 'react';
import { BACKEND_URL } from '@/lib/api';

type TranscriptRow = {
  id: string;
  conversation_id: string | null;
  user_label: string | null;
  locale: string | null;
  streamed: boolean;
  status: 'ok' | 'error';
  error_code: string | null;
  question: string | null;
  answer: string | null;
  duration_ms: number | null;
  created_at: string | null;
};

/**
 * Hidden, unauthenticated inspection page (/chat): shows every question sent to
 * the assistant and the exact reply it produced, including failures.
 */
export default function ChatTranscriptPage() {
  const [rows, setRows] = useState<TranscriptRow[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<'' | 'ok' | 'error'>('');
  const [auto, setAuto] = useState(true);

  const url = useMemo(() => {
    const params = new URLSearchParams({ limit: '200' });
    if (status) params.set('status', status);
    if (search.trim()) params.set('q', search.trim());
    return `${BACKEND_URL}/api/ai/transcripts?${params.toString()}`;
  }, [status, search]);

  useEffect(() => {
    let cancelled = false;

    const load = async () => {
      try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`Request failed (${res.status})`);
        const json = (await res.json()) as { data?: { items?: TranscriptRow[]; total?: number } };
        if (cancelled) return;
        setRows(json.data?.items ?? []);
        setTotal(json.data?.total ?? 0);
        setError(null);
      } catch (e) {
        if (!cancelled) setError((e as Error).message);
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();
    if (!auto) return () => { cancelled = true; };
    const timer = window.setInterval(load, 10000);
    return () => { cancelled = true; window.clearInterval(timer); };
  }, [url, auto]);

  return (
    <div className="min-h-screen bg-neutral-950 text-neutral-100">
      <div className="mx-auto max-w-4xl px-4 py-8">
        <header className="mb-6">
          <h1 className="text-2xl font-semibold">AI transcripts</h1>
          <p className="text-sm text-neutral-400">
            Every question and the exact reply, errors included — {total} recorded.
          </p>
        </header>

        <div className="mb-6 flex flex-wrap items-center gap-3">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search question or answer…"
            className="flex-1 min-w-[200px] rounded-md border border-neutral-800 bg-neutral-900 px-3 py-2 text-sm outline-none focus:border-neutral-600"
          />
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as '' | 'ok' | 'error')}
            className="rounded-md border border-neutral-800 bg-neutral-900 px-3 py-2 text-sm"
          >
            <option value="">All</option>
            <option value="ok">Answered</option>
            <option value="error">Errors</option>
          </select>
          <label className="flex items-center gap-2 text-sm text-neutral-400">
            <input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} />
            Auto-refresh
          </label>
        </div>

        {loading && <p className="text-sm text-neutral-500">Loading…</p>}
        {error && <p className="text-sm text-amber-400">Could not load transcripts: {error}</p>}
        {!loading && !error && rows.length === 0 && (
          <p className="text-sm text-neutral-500">No exchanges recorded yet.</p>
        )}

        <ul className="space-y-4">
          {rows.map((row) => (
            <li key={row.id} className="rounded-lg border border-neutral-800 bg-neutral-900/60 p-4">
              <div className="mb-3 flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                <span>{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</span>
                <span>· {row.user_label ?? 'anonymous'}</span>
                {row.locale && <span>· {row.locale}</span>}
                {row.streamed && <span>· stream</span>}
                {row.duration_ms != null && <span>· {(row.duration_ms / 1000).toFixed(1)}s</span>}
                <span
                  className={
                    row.status === 'error'
                      ? 'rounded bg-red-500/15 px-2 py-0.5 text-red-300'
                      : 'rounded bg-emerald-500/15 px-2 py-0.5 text-emerald-300'
                  }
                >
                  {row.status === 'error' ? row.error_code ?? 'error' : 'ok'}
                </span>
              </div>

              <p className="mb-1 text-xs uppercase tracking-wide text-neutral-500">Question</p>
              <pre className="mb-4 whitespace-pre-wrap break-words font-sans text-sm text-neutral-100">
                {row.question ?? '—'}
              </pre>

              <p className="mb-1 text-xs uppercase tracking-wide text-neutral-500">Answer</p>
              <pre
                className={`whitespace-pre-wrap break-words font-sans text-sm ${
                  row.status === 'error' ? 'text-red-300' : 'text-neutral-200'
                }`}
              >
                {row.answer ?? '—'}
              </pre>

              {row.conversation_id && (
                <p className="mt-3 text-[11px] text-neutral-600">conv {row.conversation_id}</p>
              )}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
