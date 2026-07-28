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
  HardDriveDownload,
  type LucideIcon,
} from 'lucide-react';
import logoIcon from '@/assets/logo-icon.png';
import { auth } from '@/lib/auth';
import { preloadRoute, preloadAllRoutesOnIdle } from '@/app/preloadRoutes';
import AiChatTrigger from '@/features/ai-chat/AiChatTrigger';
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
  { path: '/backup',        labelKey: 'nav.backup',        Icon: HardDriveDownload, tone: 'text-indigo-400' },
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
        {/* Larger logo; in dark mode apply a filter so the icon appears white */}
        <img src={logoIcon} alt="Flehty" className="h-16 w-16 shrink-0 rounded-lg filter dark:brightness-0 dark:invert" />
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
        <AiChatTrigger variant="sidebar" collapsed={collapsed} onNavigate={handleNav} />
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
