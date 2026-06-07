import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import {
  LayoutDashboard, Settings2, CalendarRange, MapPin, HardHat, Droplets,
  Leaf, Biohazard, Bug, BarChart3, UserCog, Bell, RefreshCw, ScrollText,
  Search as SearchIcon, FileText,
} from 'lucide-react';
import { api } from '@/lib/api';
import iconSearch from '@/assets/icons/icon-search.png';

type Hit = {
  id: string;
  group: string;
  label: string;
  sub?: string;
  to: string;
  Icon: React.ComponentType<{ className?: string }>;
};

const useDebounced = (v: string, ms = 250) => {
  const [d, setD] = useState(v);
  useEffect(() => { const t = setTimeout(() => setD(v), ms); return () => clearTimeout(t); }, [v, ms]);
  return d;
};

const GlobalSearch = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const dq = useDebounced(q.trim(), 250);

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        inputRef.current?.focus();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const navItems: Hit[] = useMemo(() => [
    { id: 'n-dash',   group: t('search.group.pages', 'Pages'), label: t('nav.dashboard'),     to: '/dashboard',     Icon: LayoutDashboard },
    { id: 'n-conf',   group: t('search.group.pages', 'Pages'), label: t('nav.configuration'), to: '/configuration', Icon: Settings2 },
    { id: 'n-camp',   group: t('search.group.pages', 'Pages'), label: t('nav.campaigns'),     to: '/campaigns',     Icon: CalendarRange },
    { id: 'n-plots',  group: t('search.group.pages', 'Pages'), label: t('nav.plots'),         to: '/plots',         Icon: MapPin },
    { id: 'n-labor',  group: t('search.group.pages', 'Pages'), label: t('nav.labor'),         to: '/labor',         Icon: HardHat },
    { id: 'n-water',  group: t('search.group.pages', 'Pages'), label: t('nav.water'),         to: '/water',         Icon: Droplets },
    { id: 'n-fert',   group: t('search.group.pages', 'Pages'), label: t('nav.fertilizers'),   to: '/fertilizers',   Icon: Leaf },
    { id: 'n-pest',   group: t('search.group.pages', 'Pages'), label: t('nav.pesticides'),    to: '/pesticides',    Icon: Biohazard },
    { id: 'n-pests',  group: t('search.group.pages', 'Pages'), label: t('nav.pests'),         to: '/pests',         Icon: Bug },
    { id: 'n-rep',    group: t('search.group.pages', 'Pages'), label: t('nav.reports'),       to: '/reports',       Icon: BarChart3 },
    { id: 'n-users',  group: t('search.group.pages', 'Pages'), label: t('nav.users'),         to: '/users',         Icon: UserCog },
    { id: 'n-notif',  group: t('search.group.pages', 'Pages'), label: t('nav.notifications'), to: '/notifications', Icon: Bell },
    { id: 'n-sync',   group: t('search.group.pages', 'Pages'), label: t('nav.sync'),          to: '/sync',          Icon: RefreshCw },
    { id: 'n-logs',   group: t('search.group.pages', 'Pages'), label: t('nav.logs'),          to: '/logs',          Icon: ScrollText },
  ], [t]);

  const reportItems: Hit[] = useMemo(() => [
    { id: 'r-irr',  group: t('search.group.reports', 'Reports'), label: t('reports.irrigation.title', 'Irrigation'),         to: '/reports/irrigation',     Icon: FileText },
    { id: 'r-fer',  group: t('search.group.reports', 'Reports'), label: t('reports.fertilization.title', 'Fertilization'),   to: '/reports/fertilization',  Icon: FileText },
    { id: 'r-phy',  group: t('search.group.reports', 'Reports'), label: t('reports.phyto.title', 'Phytosanitary'),           to: '/reports/phytosanitary',  Icon: FileText },
    { id: 'r-har',  group: t('search.group.reports', 'Reports'), label: t('reports.harvesting.title', 'Harvesting'),         to: '/reports/harvesting',     Icon: FileText },
    { id: 'r-cost', group: t('search.group.reports', 'Reports'), label: t('reports.cost.title', 'Production cost'),          to: '/reports/production-cost', Icon: FileText },
  ], [t]);

  const enabled = dq.length >= 2;
  const params = { page: 1, per_page: 5, search: dq };

  const plotsQ = useQuery({
    queryKey: ['gs-plots', dq],
    enabled,
    queryFn: async () => (await api.get<{ data: any[] }>('/plots', { params })).data.data ?? [],
    staleTime: 30_000,
  });
  const fertQ = useQuery({
    queryKey: ['gs-fert', dq],
    enabled,
    queryFn: async () => (await api.get<{ data: any[] }>('/fertilizers', { params })).data.data ?? [],
    staleTime: 30_000,
  });
  const pestiQ = useQuery({
    queryKey: ['gs-pesti', dq],
    enabled,
    queryFn: async () => (await api.get<{ data: any[] }>('/pesticides', { params })).data.data ?? [],
    staleTime: 30_000,
  });
  const pestsQ = useQuery({
    queryKey: ['gs-pests', dq],
    enabled,
    queryFn: async () => (await api.get<{ data: any[] }>('/pests', { params })).data.data ?? [],
    staleTime: 30_000,
  });

  const hits: Hit[] = useMemo(() => {
    const needle = dq.toLowerCase();
    const filterStatic = (arr: Hit[]) =>
      needle ? arr.filter(h => h.label.toLowerCase().includes(needle)) : arr;
    const pages = filterStatic(navItems);
    const reports = filterStatic(reportItems);
    const plots: Hit[] = (plotsQ.data ?? []).map((p: any) => ({
      id: `p-${p.id}`, group: t('search.group.plots', 'Plots'),
      label: p.name ?? p.label ?? '—',
      sub: [p.crop, p.variety].filter(Boolean).join(' • '),
      to: '/plots', Icon: MapPin,
    }));
    const ferts: Hit[] = (fertQ.data ?? []).map((f: any) => ({
      id: `f-${f.id}`, group: t('search.group.fertilizers', 'Fertilizers'),
      label: f.name ?? '—', sub: f.composition,
      to: '/fertilizers', Icon: Leaf,
    }));
    const pestis: Hit[] = (pestiQ.data ?? []).map((p: any) => ({
      id: `pe-${p.id}`, group: t('search.group.pesticides', 'Pesticides'),
      label: p.name ?? '—', sub: p.composition,
      to: '/pesticides', Icon: Biohazard,
    }));
    const pests: Hit[] = (pestsQ.data ?? []).map((p: any) => ({
      id: `pt-${p.id}`, group: t('search.group.pests', 'Bioaggressors'),
      label: p.name ?? '—', sub: p.scientific_name,
      to: '/pests', Icon: Bug,
    }));
    return [...pages, ...reports, ...plots, ...ferts, ...pestis, ...pests];
  }, [navItems, reportItems, plotsQ.data, fertQ.data, pestiQ.data, pestsQ.data, dq, t]);

  useEffect(() => { setActive(0); }, [dq, hits.length]);

  const grouped = useMemo(() => {
    const m = new Map<string, Hit[]>();
    hits.forEach(h => { if (!m.has(h.group)) m.set(h.group, []); m.get(h.group)!.push(h); });
    return Array.from(m.entries());
  }, [hits]);

  const go = (h: Hit) => {
    setOpen(false);
    setQ('');
    navigate(h.to);
  };

  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(a => Math.min(a + 1, hits.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(a => Math.max(a - 1, 0)); }
    else if (e.key === 'Enter' && hits[active]) { e.preventDefault(); go(hits[active]); }
    else if (e.key === 'Escape') { setOpen(false); }
  };

  const loading = enabled && (plotsQ.isFetching || fertQ.isFetching || pestiQ.isFetching || pestsQ.isFetching);

  let idx = -1;

  return (
    <div ref={rootRef} className="relative hidden sm:block">
      <img src={iconSearch} alt="" className="absolute left-2.5 top-1/2 -translate-y-1/2 h-5 w-5 opacity-60 pointer-events-none" />
      <input
        ref={inputRef}
        type="text"
        value={q}
        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKey}
        placeholder={t('topbar.search', 'Search pages, plots, fertilizers, pesticides, reports…')}
        className="cl-input h-9 w-56 md:w-72 lg:w-96 pl-8 pr-3 text-[13px]"
      />
      {open && (
        <div className="absolute left-0 right-0 mt-1 z-50 max-h-[70vh] overflow-auto rounded-md border border-border/60 bg-[hsl(var(--surface-container))] shadow-lg">
          {loading && (
            <div className="px-3 py-2 text-xs text-muted-foreground">{t('search.loading', 'Searching…')}</div>
          )}
          {!loading && hits.length === 0 && (
            <div className="px-3 py-3 text-xs text-muted-foreground">
              {enabled ? t('search.empty', 'No results') : t('search.hint', 'Type to search pages, plots, fertilizers, pesticides, bioaggressors, reports…')}
            </div>
          )}
          {grouped.map(([group, items]) => (
            <div key={group} className="py-1">
              <div className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{group}</div>
              {items.map((h) => {
                idx++;
                const isActive = idx === active;
                return (
                  <button
                    key={h.id}
                    type="button"
                    onMouseEnter={() => setActive(hits.indexOf(h))}
                    onClick={() => go(h)}
                    className={`w-full flex items-center gap-2 px-3 py-1.5 text-left text-[13px] ${isActive ? 'bg-[hsl(var(--surface-bright))]' : 'hover:bg-[hsl(var(--surface-bright))]'}`}
                  >
                    <h.Icon className="h-4 w-4 opacity-70 shrink-0" />
                    <span className="truncate text-foreground">{h.label}</span>
                    {h.sub && <span className="ml-auto truncate text-[11px] text-muted-foreground">{h.sub}</span>}
                  </button>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default GlobalSearch;
