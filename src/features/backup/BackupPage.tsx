import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQueries, useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import * as XLSX from 'xlsx';
import {
  Users, MapPin, CalendarRange, Leaf, Biohazard, Bug,
  Droplets, HardHat, Download, CheckCircle2, AlertCircle,
  Loader2, FileSpreadsheet, HardDriveDownload, RefreshCw,
  Wheat, Camera, RotateCcw, Trash2, ChevronDown, ChevronRight,
  TriangleAlert, Clock,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { api } from '@/lib/api';
import { toast } from 'sonner';

// ── Types ────────────────────────────────────────────────────────────────────

interface CatDef {
  key: string;
  endpoint: string;
  /** Backend max: 100 for reference data, 1000 for operations */
  perPage: number;
  sheetName: string;
  labelKey: string;
  descKey: string;
  Icon: LucideIcon;
  iconColor: string;
  iconBg: string;
}

interface Snapshot {
  id: string;
  label: string;
  status: 'ready' | 'restoring' | 'restore_failed';
  size_bytes: number;
  metadata: { counts: Record<string, number>; total_records: number } | null;
  notes: string | null;
  created_by: { id: string; name: string } | null;
  created_at: string;
  updated_at: string;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractRows(apiData: unknown): Record<string, unknown>[] {
  if (!apiData) return [];
  const d = apiData as Record<string, unknown>;
  if (Array.isArray(d.data)) return d.data as Record<string, unknown>[];
  if (d.data && typeof d.data === 'object') return [d.data as Record<string, unknown>];
  if (Array.isArray(apiData)) return apiData as Record<string, unknown>[];
  return [];
}

async function fetchAllPages(endpoint: string, perPage: number): Promise<Record<string, unknown>[]> {
  const all: Record<string, unknown>[] = [];
  let page = 1;
  for (;;) {
    const { data } = await api.get(endpoint, { params: { page, per_page: perPage } });
    const rows = extractRows(data);
    all.push(...rows);
    const meta = (data as Record<string, unknown>)?.meta as { last_page?: number } | undefined;
    if (!meta?.last_page || page >= meta.last_page || rows.length === 0) break;
    page++;
  }
  return all;
}

function flattenRow(row: Record<string, unknown>): Record<string, unknown> {
  const flat: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(row)) {
    if (v === null || v === undefined) {
      flat[k] = '';
    } else if (Array.isArray(v)) {
      flat[k] = v
        .map((i) =>
          typeof i === 'object' && i !== null
            ? (i as { role?: string }).role ?? JSON.stringify(i)
            : String(i),
        )
        .join(', ');
    } else if (typeof v === 'object') {
      flat[k] = JSON.stringify(v);
    } else {
      flat[k] = v;
    }
  }
  return flat;
}

function buildAndDownload(
  sheets: { name: string; rows: Record<string, unknown>[] }[],
  filename: string,
) {
  const wb = XLSX.utils.book_new();
  for (const { name, rows } of sheets) {
    if (rows.length === 0) {
      const ws = XLSX.utils.aoa_to_sheet([['Aucune donnée']]);
      XLSX.utils.book_append_sheet(wb, ws, name.slice(0, 31));
      continue;
    }
    const flat = rows.map(flattenRow);
    const ws = XLSX.utils.json_to_sheet(flat);
    const keys = Object.keys(flat[0] ?? {});
    ws['!cols'] = keys.map((k) => ({
      wch: Math.max(k.length, ...flat.slice(0, 200).map((r) => String(r[k] ?? '').length)),
    }));
    XLSX.utils.book_append_sheet(wb, ws, name.slice(0, 31));
  }
  XLSX.writeFile(wb, filename);
}

const today = () => new Date().toISOString().slice(0, 10);

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function groupByMonth(snapshots: Snapshot[]): [string, Snapshot[]][] {
  const map = new Map<string, Snapshot[]>();
  for (const s of snapshots) {
    const key = s.created_at.slice(0, 7); // "2026-06"
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(s);
  }
  return Array.from(map.entries());
}

function monthLabel(key: string): string {
  const [y, m] = key.split('-');
  return new Date(Number(y), Number(m) - 1, 1).toLocaleString(undefined, {
    month: 'long', year: 'numeric',
  });
}

// ── Snapshot API calls ────────────────────────────────────────────────────────

async function fetchSnapshots(): Promise<Snapshot[]> {
  const { data } = await api.get('/backup-snapshots');
  const d = (data as { data?: unknown }).data;
  return Array.isArray(d) ? d : [];
}

async function createSnapshot(label?: string): Promise<Snapshot> {
  const { data } = await api.post('/backup-snapshots', { label });
  return (data as { data: Snapshot }).data;
}

async function deleteSnapshot(id: string): Promise<void> {
  await api.delete(`/backup-snapshots/${id}`);
}

async function restoreSnapshot(id: string): Promise<void> {
  await api.post(`/backup-snapshots/${id}/restore`);
}

// ── Category definitions ─────────────────────────────────────────────────────

const CATEGORIES: CatDef[] = [
  // ── Reference / configuration data ──
  {
    key: 'users',
    endpoint: '/users',
    perPage: 100,
    sheetName: 'Utilisateurs',
    labelKey: 'backup.cat.users',
    descKey: 'backup.catDesc.users',
    Icon: Users,
    iconColor: 'text-violet-400',
    iconBg: 'bg-violet-500/10',
  },
  {
    key: 'plots',
    endpoint: '/plots',
    perPage: 100,
    sheetName: 'Parcelles',
    labelKey: 'backup.cat.plots',
    descKey: 'backup.catDesc.plots',
    Icon: MapPin,
    iconColor: 'text-emerald-400',
    iconBg: 'bg-emerald-500/10',
  },
  {
    key: 'campaigns',
    endpoint: '/campaigns',
    perPage: 100,
    sheetName: 'Campagnes',
    labelKey: 'backup.cat.campaigns',
    descKey: 'backup.catDesc.campaigns',
    Icon: CalendarRange,
    iconColor: 'text-amber-400',
    iconBg: 'bg-amber-500/10',
  },
  {
    key: 'fertilizers',
    endpoint: '/fertilizers',
    perPage: 100,
    sheetName: 'Engrais',
    labelKey: 'backup.cat.fertilizers',
    descKey: 'backup.catDesc.fertilizers',
    Icon: Leaf,
    iconColor: 'text-lime-400',
    iconBg: 'bg-lime-500/10',
  },
  {
    key: 'pesticides',
    endpoint: '/pesticides',
    perPage: 100,
    sheetName: 'Produits de traitement',
    labelKey: 'backup.cat.pesticides',
    descKey: 'backup.catDesc.pesticides',
    Icon: Biohazard,
    iconColor: 'text-rose-400',
    iconBg: 'bg-rose-500/10',
  },
  {
    key: 'pests',
    endpoint: '/pests',
    perPage: 100,
    sheetName: 'Bioagresseurs',
    labelKey: 'backup.cat.pests',
    descKey: 'backup.catDesc.pests',
    Icon: Bug,
    iconColor: 'text-orange-400',
    iconBg: 'bg-orange-500/10',
  },
  {
    key: 'waterConfig',
    endpoint: '/water-config',
    perPage: 100,
    sheetName: 'Config. Eau',
    labelKey: 'backup.cat.waterConfig',
    descKey: 'backup.catDesc.waterConfig',
    Icon: Droplets,
    iconColor: 'text-sky-400',
    iconBg: 'bg-sky-500/10',
  },
  {
    key: 'laborConfig',
    endpoint: '/labor-config',
    perPage: 100,
    sheetName: 'Config. Main-d\'œuvre',
    labelKey: 'backup.cat.laborConfig',
    descKey: 'backup.catDesc.laborConfig',
    Icon: HardHat,
    iconColor: 'text-orange-400',
    iconBg: 'bg-orange-500/10',
  },
  // ── Field operations ──
  {
    key: 'irrigation',
    endpoint: '/irrigation-operations',
    perPage: 1000,
    sheetName: 'Irrigation',
    labelKey: 'backup.cat.irrigation',
    descKey: 'backup.catDesc.irrigation',
    Icon: Droplets,
    iconColor: 'text-sky-400',
    iconBg: 'bg-sky-500/10',
  },
  {
    key: 'fertilization',
    endpoint: '/fertilization-operations',
    perPage: 1000,
    sheetName: 'Fertilisation',
    labelKey: 'backup.cat.fertilization',
    descKey: 'backup.catDesc.fertilization',
    Icon: Leaf,
    iconColor: 'text-lime-400',
    iconBg: 'bg-lime-500/10',
  },
  {
    key: 'phytosanitary',
    endpoint: '/phytosanitary-operations',
    perPage: 1000,
    sheetName: 'Phytosanitaire',
    labelKey: 'backup.cat.phytosanitary',
    descKey: 'backup.catDesc.phytosanitary',
    Icon: Biohazard,
    iconColor: 'text-rose-400',
    iconBg: 'bg-rose-500/10',
  },
  {
    key: 'harvest',
    endpoint: '/harvest-operations',
    perPage: 1000,
    sheetName: 'Récolte',
    labelKey: 'backup.cat.harvest',
    descKey: 'backup.catDesc.harvest',
    Icon: Wheat,
    iconColor: 'text-yellow-400',
    iconBg: 'bg-yellow-500/10',
  },
];

// ── Page component ────────────────────────────────────────────────────────────

const BackupPage = () => {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [exportingAll, setExportingAll] = useState(false);
  const [lastExport, setLastExport] = useState<string | null>(
    () => localStorage.getItem('flehty.lastBackup'),
  );
  const [restoreTarget, setRestoreTarget] = useState<Snapshot | null>(null);
  const [createLabel, setCreateLabel] = useState('');
  const [showCreateInput, setShowCreateInput] = useState(false);

  // ── Snapshot queries & mutations ──────────────────────────────────────────

  const snapshotsQuery = useQuery({
    queryKey: ['backup-snapshots'],
    queryFn: fetchSnapshots,
    staleTime: 30_000,
  });

  const createMutation = useMutation({
    mutationFn: (label?: string) => createSnapshot(label || undefined),
    onSuccess: (snap) => {
      qc.invalidateQueries({ queryKey: ['backup-snapshots'] });
      toast.success(t('backup.snap.created', { label: snap.label }));
      setCreateLabel('');
      setShowCreateInput(false);
    },
    onError: () => toast.error(t('backup.snap.createError')),
  });

  const deleteMutation = useMutation({
    mutationFn: deleteSnapshot,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['backup-snapshots'] });
      toast.success(t('backup.snap.deleted'));
    },
    onError: () => toast.error(t('backup.snap.deleteError')),
  });

  const restoreMutation = useMutation({
    mutationFn: restoreSnapshot,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['backup-snapshots'] });
      // Invalidate all other queries — data has changed
      qc.invalidateQueries();
      toast.success(t('backup.snap.restoreSuccess'));
      setRestoreTarget(null);
    },
    onError: () => {
      qc.invalidateQueries({ queryKey: ['backup-snapshots'] });
      toast.error(t('backup.snap.restoreError'));
      setRestoreTarget(null);
    },
  });

  // ── Excel export queries ──────────────────────────────────────────────────

  const results = useQueries({
    queries: CATEGORIES.map((cat) => ({
      queryKey: ['backup', cat.key],
      queryFn: () => fetchAllPages(cat.endpoint, cat.perPage),
      retry: 1,
      staleTime: 5 * 60 * 1000,
    })),
  });

  const handleRefresh = useCallback(() => {
    results.forEach((r) => r.refetch());
  }, [results]);

  const handleExportSingle = useCallback(
    (cat: CatDef, rows: Record<string, unknown>[]) => {
      if (!rows.length) { toast.info(t('common.noData')); return; }
      buildAndDownload([{ name: cat.sheetName, rows }], `flehty-${cat.key}-${today()}.xlsx`);
      toast.success(t('backup.exportedSingle', { name: cat.sheetName }));
    },
    [t],
  );

  const handleExportAll = useCallback(async () => {
    setExportingAll(true);
    try {
      const sheets: { name: string; rows: Record<string, unknown>[] }[] = [];
      for (let i = 0; i < CATEGORIES.length; i++) {
        const cat = CATEGORIES[i];
        let rows = results[i].data;
        if (!rows) {
          try { rows = await fetchAllPages(cat.endpoint, cat.perPage); } catch { continue; }
        }
        sheets.push({ name: cat.sheetName, rows });
      }
      if (!sheets.length) { toast.error(t('common.noData')); return; }
      buildAndDownload(sheets, `flehty-backup-complet-${today()}.xlsx`);
      const now = new Date().toLocaleString();
      localStorage.setItem('flehty.lastBackup', now);
      setLastExport(now);
      toast.success(t('backup.exportedAll', { count: sheets.length }));
    } finally {
      setExportingAll(false);
    }
  }, [results, t]);

  const successCount = results.filter((r) => !r.isLoading && !r.isError).length;
  const errorCount   = results.filter((r) => r.isError).length;
  const loadingCount = results.filter((r) => r.isLoading).length;
  const totalRecords = results.reduce((s, r) => s + (r.data?.length ?? 0), 0);
  const allSettled   = loadingCount === 0;

  return (
    <div className="space-y-6">

      {/* ── Checkpoints / Restore section ── */}
      <CheckpointsSection
        snapshots={snapshotsQuery.data ?? []}
        loading={snapshotsQuery.isLoading}
        creating={createMutation.isPending}
        createLabel={createLabel}
        showCreateInput={showCreateInput}
        onToggleInput={() => setShowCreateInput((v) => !v)}
        onLabelChange={setCreateLabel}
        onCreate={() => createMutation.mutate(createLabel || undefined)}
        onRestore={setRestoreTarget}
        onDelete={(id) => deleteMutation.mutate(id)}
        deletingId={deleteMutation.isPending ? (deleteMutation.variables as string) : null}
        t={t}
      />

      {/* ── Header ── */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold flex items-center gap-2">
            <HardDriveDownload className="h-5 w-5 text-indigo-400 shrink-0" />
            {t('backup.title')}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">{t('backup.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2 shrink-0">
          <button
            onClick={handleRefresh}
            disabled={!allSettled}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background/60 px-3 py-2 text-sm font-medium hover:bg-accent transition-colors disabled:opacity-40"
          >
            <RefreshCw className="h-3.5 w-3.5" />
            <span className="hidden sm:inline">{t('backup.refresh')}</span>
          </button>
          <button
            onClick={handleExportAll}
            disabled={exportingAll || loadingCount > 0}
            className="btn-primary-glass flex items-center gap-2 h-10 px-4 disabled:opacity-50"
          >
            {exportingAll
              ? <Loader2 className="h-4 w-4 animate-spin" />
              : <Download className="h-4 w-4" />}
            {t('backup.exportAll')}
          </button>
        </div>
      </div>

      {/* ── Summary card ── */}
      <div className="rounded-xl border border-border bg-card p-5">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-4">
            <div className="rounded-xl bg-indigo-500/10 p-3 shrink-0">
              <FileSpreadsheet className="h-6 w-6 text-indigo-400" />
            </div>
            <div>
              <p className="text-sm font-medium">
                {allSettled
                  ? t('backup.summary', { total: totalRecords, sheets: successCount })
                  : t('backup.summaryLoading', { done: successCount, total: CATEGORIES.length })}
              </p>
              <p className="text-xs text-muted-foreground mt-0.5">
                {lastExport
                  ? t('backup.lastExport', { date: lastExport })
                  : t('backup.noExportYet')}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-6 text-center">
            <div>
              <p className="text-2xl font-bold tabular-nums text-emerald-400">{successCount}</p>
              <p className="text-[11px] text-muted-foreground">{t('backup.ready')}</p>
            </div>
            {errorCount > 0 && (
              <div>
                <p className="text-2xl font-bold tabular-nums text-rose-400">{errorCount}</p>
                <p className="text-[11px] text-muted-foreground">{t('backup.failed')}</p>
              </div>
            )}
            {loadingCount > 0 && (
              <div>
                <p className="text-2xl font-bold tabular-nums text-muted-foreground">{loadingCount}</p>
                <p className="text-[11px] text-muted-foreground">{t('backup.loading')}</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* ── Reference data ── */}
      <div>
        <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          {t('backup.sectionRef')}
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {CATEGORIES.slice(0, 8).map((cat, i) => (
            <CategoryCard key={cat.key} cat={cat} result={results[i]} onExport={handleExportSingle} t={t} />
          ))}
        </div>
      </div>

      {/* ── Operations ── */}
      <div>
        <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          {t('backup.sectionOps')}
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {CATEGORIES.slice(8).map((cat, i) => (
            <CategoryCard key={cat.key} cat={cat} result={results[8 + i]} onExport={handleExportSingle} t={t} />
          ))}
        </div>
      </div>

      {/* ── Restore confirmation modal ── */}
      {restoreTarget && (
        <RestoreDialog
          snapshot={restoreTarget}
          restoring={restoreMutation.isPending}
          onConfirm={() => restoreMutation.mutate(restoreTarget.id)}
          onClose={() => setRestoreTarget(null)}
          t={t}
        />
      )}

    </div>
  );
};

// ── Checkpoints section ───────────────────────────────────────────────────────

interface CheckpointsSectionProps {
  snapshots: Snapshot[];
  loading: boolean;
  creating: boolean;
  createLabel: string;
  showCreateInput: boolean;
  onToggleInput: () => void;
  onLabelChange: (v: string) => void;
  onCreate: () => void;
  onRestore: (s: Snapshot) => void;
  onDelete: (id: string) => void;
  deletingId: string | null;
  t: (k: string, o?: Record<string, unknown>) => string;
}

const CheckpointsSection = ({
  snapshots, loading, creating, createLabel, showCreateInput,
  onToggleInput, onLabelChange, onCreate, onRestore, onDelete, deletingId, t,
}: CheckpointsSectionProps) => {
  const [expandedMonths, setExpandedMonths] = useState<Set<string>>(() => {
    // Expand the most recent month by default
    const now = new Date().toISOString().slice(0, 7);
    return new Set([now]);
  });

  const toggleMonth = (key: string) =>
    setExpandedMonths((prev) => {
      const next = new Set(prev);
      next.has(key) ? next.delete(key) : next.add(key);
      return next;
    });

  const grouped = groupByMonth(snapshots);

  return (
    <div className="rounded-xl border border-border bg-card overflow-hidden">
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-4 border-b border-border">
        <div className="flex items-center gap-2.5">
          <div className="rounded-lg bg-indigo-500/10 p-2">
            <Camera className="h-4 w-4 text-indigo-400" />
          </div>
          <div>
            <p className="text-sm font-semibold">{t('backup.snap.title')}</p>
            <p className="text-[11px] text-muted-foreground">{t('backup.snap.subtitle')}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {showCreateInput && (
            <input
              type="text"
              value={createLabel}
              onChange={(e) => onLabelChange(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && onCreate()}
              placeholder={t('backup.snap.labelPlaceholder')}
              maxLength={120}
              className="h-8 w-48 rounded-md border border-border bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
              autoFocus
            />
          )}
          <button
            onClick={showCreateInput ? onCreate : onToggleInput}
            disabled={creating}
            className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-500/10 border border-indigo-500/30 px-3 py-1.5 text-xs font-medium text-indigo-300 hover:bg-indigo-500/20 transition-colors disabled:opacity-50"
          >
            {creating
              ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
              : <Camera className="h-3.5 w-3.5" />}
            {creating ? t('backup.snap.creating') : t('backup.snap.create')}
          </button>
          {showCreateInput && !creating && (
            <button
              onClick={onToggleInput}
              className="text-xs text-muted-foreground hover:text-foreground px-1"
            >
              ✕
            </button>
          )}
        </div>
      </div>

      {/* Body */}
      <div className="divide-y divide-border">
        {loading && (
          <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            {t('common.loading')}
          </div>
        )}

        {!loading && snapshots.length === 0 && (
          <div className="flex flex-col items-center gap-2 py-10 text-muted-foreground">
            <Camera className="h-8 w-8 opacity-30" />
            <p className="text-sm">{t('backup.snap.empty')}</p>
            <p className="text-xs opacity-60">{t('backup.snap.emptyHint')}</p>
          </div>
        )}

        {!loading && grouped.map(([monthKey, monthSnaps]) => (
          <div key={monthKey}>
            {/* Month header */}
            <button
              onClick={() => toggleMonth(monthKey)}
              className="flex w-full items-center justify-between px-5 py-2.5 hover:bg-muted/30 transition-colors text-left"
            >
              <div className="flex items-center gap-2">
                {expandedMonths.has(monthKey)
                  ? <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" />
                  : <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />}
                <span className="text-xs font-semibold capitalize text-foreground">
                  {monthLabel(monthKey)}
                </span>
                <span className="text-[10px] text-muted-foreground">
                  ({monthSnaps.length} {monthSnaps.length > 1 ? t('backup.snap.checkpoints') : t('backup.snap.checkpoint')})
                </span>
              </div>
            </button>

            {/* Snapshot rows */}
            {expandedMonths.has(monthKey) && (
              <div className="divide-y divide-border/50">
                {monthSnaps.map((snap) => (
                  <SnapshotRow
                    key={snap.id}
                    snap={snap}
                    onRestore={onRestore}
                    onDelete={onDelete}
                    deleting={deletingId === snap.id}
                    t={t}
                  />
                ))}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};

// ── Single snapshot row ───────────────────────────────────────────────────────

interface SnapshotRowProps {
  snap: Snapshot;
  onRestore: (s: Snapshot) => void;
  onDelete: (id: string) => void;
  deleting: boolean;
  t: (k: string, o?: Record<string, unknown>) => string;
}

const SnapshotRow = ({ snap, onRestore, onDelete, deleting, t }: SnapshotRowProps) => {
  const isRestoring = snap.status === 'restoring';
  const isFailed    = snap.status === 'restore_failed';
  const totalRecords = snap.metadata?.total_records ?? 0;

  return (
    <div className="flex flex-col gap-3 px-5 py-3 sm:flex-row sm:items-center hover:bg-muted/20 transition-colors">
      {/* Left: datetime + label */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <Clock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
          <span className="text-xs text-muted-foreground">
            {formatDateTime(snap.created_at)}
          </span>
          {isFailed && (
            <span className="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2 py-0.5 text-[10px] font-medium text-rose-400 ring-1 ring-inset ring-rose-500/20">
              <AlertCircle className="h-3 w-3" />
              {t('backup.snap.statusFailed')}
            </span>
          )}
          {isRestoring && (
            <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-medium text-amber-400 ring-1 ring-inset ring-amber-500/20">
              <Loader2 className="h-3 w-3 animate-spin" />
              {t('backup.snap.statusRestoring')}
            </span>
          )}
        </div>
        <p className="mt-0.5 text-sm font-medium truncate">{snap.label}</p>
        <p className="text-[11px] text-muted-foreground mt-0.5">
          {snap.created_by?.name ?? '—'}
          {' · '}
          {t('backup.snap.recordCount', { n: totalRecords })}
          {' · '}
          {formatBytes(snap.size_bytes)}
        </p>
      </div>

      {/* Right: actions */}
      <div className="flex items-center gap-2 shrink-0">
        <button
          onClick={() => onRestore(snap)}
          disabled={isRestoring}
          className="inline-flex items-center gap-1.5 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-medium text-amber-300 hover:bg-amber-500/20 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
          <RotateCcw className="h-3.5 w-3.5" />
          {t('backup.snap.restore')}
        </button>
        <button
          onClick={() => onDelete(snap.id)}
          disabled={deleting || isRestoring}
          className="inline-flex items-center gap-1 rounded-lg border border-border bg-background/50 px-2 py-1.5 text-xs text-muted-foreground hover:text-rose-400 hover:border-rose-500/30 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        >
          {deleting
            ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
            : <Trash2 className="h-3.5 w-3.5" />}
        </button>
      </div>
    </div>
  );
};

// ── Restore confirmation dialog ───────────────────────────────────────────────

interface RestoreDialogProps {
  snapshot: Snapshot;
  restoring: boolean;
  onConfirm: () => void;
  onClose: () => void;
  t: (k: string, o?: Record<string, unknown>) => string;
}

const RestoreDialog = ({ snapshot, restoring, onConfirm, onClose, t }: RestoreDialogProps) => {
  const [confirmed, setConfirmed] = useState(false);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop */}
      <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={!restoring ? onClose : undefined} />

      {/* Dialog */}
      <div className="relative z-10 w-full max-w-md rounded-xl border border-border bg-card shadow-2xl">
        {/* Header */}
        <div className="flex items-start gap-3 p-5 pb-4">
          <div className="rounded-xl bg-amber-500/10 p-2.5 shrink-0">
            <TriangleAlert className="h-5 w-5 text-amber-400" />
          </div>
          <div>
            <h2 className="text-base font-semibold">{t('backup.snap.confirmTitle')}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{snapshot.label}</p>
          </div>
        </div>

        {/* Warning body */}
        <div className="px-5 space-y-3">
          <div className="rounded-lg bg-amber-500/5 border border-amber-500/20 p-3 text-xs text-amber-200 leading-relaxed">
            {t('backup.snap.confirmWarning')}
          </div>

          <div className="rounded-lg bg-muted/40 p-3 text-xs space-y-1">
            <p className="text-muted-foreground">{t('backup.snap.confirmSnapshotDate')}
              <span className="font-medium text-foreground ml-1">{formatDateTime(snapshot.created_at)}</span>
            </p>
            <p className="text-muted-foreground">{t('backup.snap.confirmRecords')}
              <span className="font-medium text-foreground ml-1">
                {snapshot.metadata?.total_records ?? '—'}
              </span>
            </p>
          </div>

          <label className="flex items-center gap-2.5 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={confirmed}
              onChange={(e) => setConfirmed(e.target.checked)}
              className="h-4 w-4 rounded border-border"
            />
            <span className="text-xs text-muted-foreground">
              {t('backup.snap.confirmCheck')}
            </span>
          </label>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-2 p-5 pt-4">
          <button
            onClick={onClose}
            disabled={restoring}
            className="rounded-lg border border-border px-4 py-2 text-xs font-medium hover:bg-muted transition-colors disabled:opacity-50"
          >
            {t('common.cancel')}
          </button>
          <button
            onClick={onConfirm}
            disabled={!confirmed || restoring}
            className="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-xs font-semibold text-black hover:bg-amber-400 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {restoring && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
            {restoring ? t('backup.snap.restoring') : t('backup.snap.confirmBtn')}
          </button>
        </div>
      </div>
    </div>
  );
};

// ── Category card ─────────────────────────────────────────────────────────────

interface CardProps {
  cat: CatDef;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  result: any;
  onExport: (cat: CatDef, rows: Record<string, unknown>[]) => void;
  t: (key: string) => string;
}

const CategoryCard = ({ cat, result, onExport, t }: CardProps) => {
  const { isLoading, isError, data } = result;
  const rows: Record<string, unknown>[] = data ?? [];
  const count = rows.length;

  return (
    <div className="rounded-xl border border-border bg-card p-5 flex flex-col gap-4 hover:border-[hsl(var(--primary)/0.4)] transition-colors">
      <div className="flex items-start justify-between">
        <div className={`rounded-lg p-2.5 ${cat.iconBg}`}>
          <cat.Icon className={`h-5 w-5 ${cat.iconColor}`} />
        </div>
        {isLoading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground mt-1" />}
        {!isLoading && !isError && <CheckCircle2 className="h-4 w-4 text-emerald-400 mt-1" />}
        {isError && (
          <span title={t('backup.fetchError')}>
            <AlertCircle className="h-4 w-4 text-rose-400 mt-1" />
          </span>
        )}
      </div>

      <div className="flex-1">
        <p className="font-medium text-sm leading-tight">{t(cat.labelKey)}</p>
        <p className="text-[11px] text-muted-foreground mt-0.5 leading-snug">{t(cat.descKey)}</p>
      </div>

      <div className="flex items-baseline gap-1.5">
        {isLoading ? (
          <span className="text-sm text-muted-foreground animate-pulse">{t('common.loading')}</span>
        ) : isError ? (
          <span className="text-xs text-rose-400">{t('backup.fetchError')}</span>
        ) : (
          <>
            <span className="text-3xl font-bold tabular-nums leading-none">{count}</span>
            <span className="text-xs text-muted-foreground">{t('backup.records')}</span>
          </>
        )}
      </div>

      <button
        onClick={() => onExport(cat, rows)}
        disabled={isLoading || isError || count === 0}
        className="w-full flex items-center justify-center gap-1.5 rounded-lg border border-border bg-background/50 px-3 py-2 text-xs font-medium hover:bg-accent hover:text-accent-foreground disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
      >
        <Download className="h-3.5 w-3.5" />
        {t('backup.exportSheet')}
      </button>
    </div>
  );
};

export default BackupPage;
