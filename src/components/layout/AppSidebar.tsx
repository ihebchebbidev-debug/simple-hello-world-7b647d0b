import { NavLink, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  LayoutDashboard,
  Settings2,
  CalendarRange,
  MapPin,
  HardHat,
  Droplets,
  Leaf,
  Biohazard,
  Bug,
  BarChart3,
  UserCog,
  Bell,
  RefreshCw,
  ScrollText,
  HelpCircle,
  LogOut,
  type LucideIcon,
} from 'lucide-react';
import logoIcon from '@/assets/logo-icon.png';
import { auth } from '@/lib/auth';
import { preloadRoute, preloadAllRoutesOnIdle } from '@/app/preloadRoutes';
import { useEffect } from 'react';

type NavItem = {
  path: string;
  labelKey: string;
  Icon: LucideIcon;
  /** Tailwind text-* class applied to the icon to give it semantic color. */
  tone?: string;
};

// Customer-requested order:
// Dashboard / Configuration / Campagne / Parcelle / Main d'œuvre / Eau /
// Engrais / Pesticides / Bioagresseurs / Rapports / Users / Notif / Sync / Logs
const navItems: NavItem[] = [
  { path: '/dashboard',     labelKey: 'nav.dashboard',     Icon: LayoutDashboard, tone: 'text-[hsl(var(--primary-glow))]' },
  { path: '/configuration', labelKey: 'nav.configuration', Icon: Settings2,       tone: 'text-muted-foreground' },
  { path: '/campaigns',     labelKey: 'nav.campaigns',     Icon: CalendarRange,   tone: 'text-amber-400' },
  { path: '/plots',         labelKey: 'nav.plots',         Icon: MapPin,          tone: 'text-emerald-400' },
  { path: '/labor',         labelKey: 'nav.labor',         Icon: HardHat,         tone: 'text-orange-400' },
  { path: '/water',         labelKey: 'nav.water',         Icon: Droplets,        tone: 'text-sky-400' },
  { path: '/fertilizers',   labelKey: 'nav.fertilizers',   Icon: Leaf,            tone: 'text-lime-400' },
  { path: '/pesticides',    labelKey: 'nav.pesticides',    Icon: Biohazard,       tone: 'text-[hsl(var(--accent-danger))]' },
  { path: '/pests',         labelKey: 'nav.pests',         Icon: Bug,             tone: 'text-rose-400' },
  { path: '/reports',       labelKey: 'nav.reports',       Icon: BarChart3,       tone: 'text-cyan-400' },
  { path: '/users',         labelKey: 'nav.users',         Icon: UserCog,         tone: 'text-violet-400' },
  { path: '/notifications', labelKey: 'nav.notifications', Icon: Bell,            tone: 'text-yellow-400' },
  { path: '/sync',          labelKey: 'nav.sync',          Icon: RefreshCw,       tone: 'text-teal-400' },
  { path: '/logs',          labelKey: 'nav.logs',          Icon: ScrollText,      tone: 'text-slate-400' },
];

interface Props { onClose?: () => void; collapsed?: boolean }

const AppSidebar = ({ onClose, collapsed = false }: Props) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const handleNav = () => onClose?.();

  // Once the shell is mounted, quietly preload every route chunk during
  // idle time so subsequent navigation is instant (no Suspense flash).
  useEffect(() => { preloadAllRoutesOnIdle(); }, []);

  const handleLogout = async () => {
    try { await auth.logout(); } catch {}
    handleNav();
    navigate('/login', { replace: true });
  };

  return (
    <aside className="flex h-screen w-full flex-col shrink-0 bg-[hsl(var(--sidebar-background))] transition-[width] duration-200">
      <div className={`flex items-center gap-3 py-5 ${collapsed ? 'justify-center px-2' : 'px-4'}`}>
        <img src={logoIcon} alt="Agri-Sync" className="h-9 w-9 shrink-0 rounded-lg" />
        {!collapsed && (
          <div className="min-w-0">
            <h1 className="text-[14px] font-bold tracking-wide leading-tight text-[hsl(var(--primary-glow))]">Agri-Sync</h1>
            <span className="text-[10px] font-medium leading-tight text-muted-foreground">Administration</span>
          </div>
        )}
      </div>

      <nav className={`flex-1 py-3 space-y-0.5 overflow-y-auto ${collapsed ? 'px-2' : 'px-3'}`}>
        {!collapsed && (
          <p className="px-3 pb-2 pt-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
            Navigation
          </p>
        )}
        {navItems.map(({ path, labelKey, Icon, tone }) => (
          <NavLink
            key={path}
            to={path}
            onClick={handleNav}
            onMouseEnter={() => preloadRoute(path)}
            onFocus={() => preloadRoute(path)}
            onTouchStart={() => preloadRoute(path)}
            title={collapsed ? t(labelKey) : undefined}
            className={({ isActive }) => `sidebar-nav-item ${isActive ? 'active' : ''} ${collapsed ? 'justify-center px-2' : ''}`}
          >
            <Icon className={`h-5 w-5 shrink-0 ${tone ?? 'text-muted-foreground'}`} strokeWidth={2} aria-hidden />
            {!collapsed && <span className="truncate">{t(labelKey)}</span>}
          </NavLink>
        ))}
      </nav>

      <div className={`pb-4 space-y-1 ${collapsed ? 'px-2' : 'px-3'}`}>
        <NavLink
          to="/help"
          onClick={handleNav}
          onMouseEnter={() => preloadRoute('/help')}
          onFocus={() => preloadRoute('/help')}
          title={collapsed ? t('nav.help') : undefined}
          className={({ isActive }) => `sidebar-nav-item w-full ${isActive ? 'active' : ''} ${collapsed ? 'justify-center px-2' : ''}`}
        >
          <HelpCircle className="h-5 w-5 shrink-0 text-muted-foreground" strokeWidth={2} aria-hidden />
          {!collapsed && <span className="truncate">{t('nav.help')}</span>}
        </NavLink>
        <button
          onClick={handleLogout}
          title={collapsed ? t('nav.logout') : undefined}
          className={`sidebar-nav-item w-full text-[hsl(var(--accent-danger))] ${collapsed ? 'justify-center px-2' : ''}`}
        >
          <LogOut className="h-5 w-5 shrink-0" strokeWidth={2} aria-hidden />
          {!collapsed && <span className="truncate">{t('nav.logout')}</span>}
        </button>
      </div>
    </aside>
  );
};

export default AppSidebar;
