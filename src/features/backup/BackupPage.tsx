import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQueries } from '@tanstack/react-query';
import * as XLSX from 'xlsx';
import {
  Users, MapPin, CalendarRange, Leaf, Biohazard, Bug,
  Droplets, Download, CheckCircle2, AlertCircle,
  Loader2, FileSpreadsheet, HardDriveDownload, RefreshCw,
  Wheat,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface CatDef {
  key: string;
  endpoint: string;
  params?: Record<string, string | number | boolean>;
  sheetName: string;
  labelKey: string;
  descKey: string;
  Icon: LucideIcon;
  iconColor: string;
  iconBg: string;
}

function extractRows(apiData: unknown): Record<string, unknown>[] {
  if (!apiData) return [];
  const d = apiData as Record<string, unknown>;
  if (Array.isArray(d.data)) return d.data as Record<string, unknown>[];
  if (d.data && typeof d.data === 'object') return [d.data as Record<string, unknown>];
  if (Array.isArray(apiData)) return apiData as Record<string, unknown>[];
  return [];
}

function flattenRow(row: Record<string, unknown>): Record<string, unknown> {
  const flat: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(row)) {
    if (v === null || v === undefined) {
      flat[k] = '';
    } else if (Array.isArray(v)) {
      flat[k] = v
        .map((i) => (typeof i === 'object' && i !== null ? (i as { role?: string }).role ?? JSON.stringify(i) : String(i)))
        .join(', ');
    } else if (typeof v === 'object') {
      flat[k] = JSON.stringify(v);
    } else {
      flat[k] = v;
    }
  }
  return flat;
}

function buildAndDownload(sheets: { name: string; rows: Record<string, unknown>[] }[], filename: string) {
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

const TODAY = new Date().toISOString().slice(0, 10);

const CATEGORIES: CatDef[] = [
  {
    key: 'users',
    endpoint: '/users',
    params: { per_page: 9999 },
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
    params: { per_page: 9999 },
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
    params: { per_page: 9999 },
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
    params: { per_page: 9999 },
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
    params: { per_page: 9999 },
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
    params: { per_page: 9999 },
    sheetName: 'Bioagresseurs',
    labelKey: 'backup.cat.pests',
    descKey: 'backup.catDesc.pests',
    Icon: Bug,
    iconColor: 'text-orange-400',
    iconBg: 'bg-orange-500/10',
  },
  {
    key: 'irrigation',
    endpoint: '/irrigation',
    params: { per_page: 9999 },
    sheetName: 'Irrigation',
    labelKey: 'backup.cat.irrigation',
    descKey: 'backup.catDesc.irrigation',
    Icon: Droplets,
    iconColor: 'text-sky-400',
    iconBg: 'bg-sky-500/10',
  },
  {
    key: 'fertilization',
    endpoint: '/fertilization',
    params: { per_page: 9999 },
    sheetName: 'Fertilisation',
    labelKey: 'backup.cat.fertilization',
    descKey: 'backup.catDesc.fertilization',
    Icon: Leaf,
    iconColor: 'text-lime-400',
    iconBg: 'bg-lime-500/10',
  },
  {
    key: 'phytosanitary',
    endpoint: '/phytosanitary',
    params: { per_page: 9999 },
    sheetName: 'Phytosanitaire',
    labelKey: 'backup.cat.phytosanitary',
    descKey: 'backup.catDesc.phytosanitary',
    Icon: Biohazard,
    iconColor: 'text-rose-400',
    iconBg: 'bg-rose-500/10',
  },
  {
    key: 'harvest',
    endpoint: '/harvest',
    params: { per_page: 9999 },
    sheetName: 'Récolte',
    labelKey: 'backup.cat.harvest',
    descKey: 'backup.catDesc.harvest',
    Icon: Wheat,
    iconColor: 'text-yellow-400',
    iconBg: 'bg-yellow-500/10',
  },
];

const BackupPage = () => {
  const { t } = useTranslation();
  const [exportingAll, setExportingAll] = useState(false);
  const [lastExport, setLastExport] = useState<string | null>(() => localStorage.getItem('flehty.lastBackup'));

  const results = useQueries({
    queries: CATEGORIES.map((cat) => ({
      queryKey: ['backup', cat.key],
      queryFn: async () => {
        const { data } = await api.get(cat.endpoint, { params: cat.params });
        return extractRows(data);
      },
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
      buildAndDownload([{ name: cat.sheetName, rows }], `flehty-${cat.key}-${TODAY}.xlsx`);
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
          try {
            const { data } = await api.get(cat.endpoint, { params: cat.params });
            rows = extractRows(data);
          } catch {
            continue;
          }
        }
        sheets.push({ name: cat.sheetName, rows });
      }
      if (!sheets.length) { toast.error(t('common.noData')); return; }
      buildAndDownload(sheets, `flehty-backup-complet-${TODAY}.xlsx`);
      const now = new Date().toLocaleString();
      localStorage.setItem('flehty.lastBackup', now);
      setLastExport(now);
      toast.success(t('backup.exportedAll', { count: sheets.length }));
    } finally {
      setExportingAll(false);
    }
  }, [results, t]);

  const successCount = results.filter((r) => !r.isLoading && !r.isError).length;
  const errorCount = results.filter((r) => r.isError).length;
  const loadingCount = results.filter((r) => r.isLoading).length;
  const totalRecords = results.reduce((s, r) => s + (r.data?.length ?? 0), 0);
  const allSettled = loadingCount === 0;

  return (
    <div className="space-y-6">

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
            title={t('backup.refresh')}
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

          <div className="flex items-center gap-4 text-center">
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

      {/* ── Reference data section ── */}
      <div>
        <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          {t('backup.sectionRef')}
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3">
          {CATEGORIES.slice(0, 6).map((cat, i) => (
            <CategoryCard
              key={cat.key}
              cat={cat}
              result={results[i]}
              onExport={handleExportSingle}
              t={t}
            />
          ))}
        </div>
      </div>

      {/* ── Operations section ── */}
      <div>
        <p className="mb-3 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          {t('backup.sectionOps')}
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-4">
          {CATEGORIES.slice(6).map((cat, i) => (
            <CategoryCard
              key={cat.key}
              cat={cat}
              result={results[6 + i]}
              onExport={handleExportSingle}
              t={t}
            />
          ))}
        </div>
      </div>

    </div>
  );
};

/* ─── Category card ─── */
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

      {/* Top row */}
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

      {/* Name & description */}
      <div className="flex-1">
        <p className="font-medium text-sm leading-tight">{t(cat.labelKey)}</p>
        <p className="text-[11px] text-muted-foreground mt-0.5 leading-snug">{t(cat.descKey)}</p>
      </div>

      {/* Count */}
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

      {/* Export button */}
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
