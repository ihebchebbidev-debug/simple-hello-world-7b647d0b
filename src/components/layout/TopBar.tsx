import { ReactNode, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Bug } from 'lucide-react';
import LanguageSwitcher from '@/components/LanguageSwitcher';
import ThemeSwitcher from '@/components/ThemeSwitcher';
import iconSearch from '@/assets/icons/icon-search.png';
import iconBell from '@/assets/icons/icon-bell.png';
import { api } from '@/lib/api';
import ReportIssueModal from '@/features/feedback/ReportIssueModal';
import GlobalSearch from '@/components/layout/GlobalSearch';

interface TopBarProps {
  menuButton?: ReactNode;
  collapsed?: boolean;
  onToggleCollapse?: () => void;
}

const TopBar = ({ menuButton, collapsed, onToggleCollapse }: TopBarProps) => {
  const { t } = useTranslation();
  const [reportOpen, setReportOpen] = useState(false);
  const unreadQuery = useQuery({
    queryKey: ['admin-notifications-count'],
    queryFn: async () => (await api.get<{ data: { unread_count: number } }>('/notifications/unread-count')).data.data.unread_count,
    refetchInterval: 60000,
  });
  const unread = unreadQuery.data ?? 0;

  return (
    <>
    <header className="flex h-14 items-center justify-between px-3 sm:px-5 gap-2 bg-[hsl(var(--surface-container))] border-b border-border/40">
      <div className="flex items-center min-w-0">
        {menuButton}
        {onToggleCollapse && (
          <button
            type="button"
            onClick={onToggleCollapse}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            className="hidden lg:inline-flex items-center justify-center rounded-md p-2 text-foreground/70 hover:bg-[hsl(var(--surface-bright))] hover:text-foreground transition-colors mr-1"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <line x1="9" y1="3" x2="9" y2="21"/>
              {collapsed
                ? <polyline points="14 9 17 12 14 15"/>
                : <polyline points="16 9 13 12 16 15"/>}
            </svg>
          </button>
        )}
        <GlobalSearch />
      </div>

      <div className="flex items-center gap-2 shrink-0">
        <button
          type="button"
          aria-label="Search"
          className="sm:hidden inline-flex items-center justify-center rounded-md p-2 hover:bg-[hsl(var(--surface-bright))] transition-colors"
        >
          <img src={iconSearch} alt="" className="h-5 w-5 opacity-70" />
        </button>
        <button
          type="button"
          onClick={() => setReportOpen(true)}
          aria-label={t('feedback.button.label', 'Report a bug or feature')}
          title={t('feedback.button.label', 'Report a bug or feature')}
          className="relative rounded-md p-1.5 hover:bg-[hsl(var(--surface-bright))] transition-colors text-foreground/80"
        >
          <Bug className="h-5 w-5" />
        </button>
        <Link to="/notifications" className="relative rounded-md p-1.5 hover:bg-[hsl(var(--surface-bright))] transition-colors" aria-label="Notifications">
          <img src={iconBell} alt="" className="h-5 w-5 opacity-80" />
          {unread > 0 && (
            <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-rose-500 text-[10px] font-bold text-white flex items-center justify-center">
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </Link>
        <LanguageSwitcher />
        <ThemeSwitcher />
        <div className="ml-1 flex items-center gap-2 rounded-md px-1.5 sm:px-2 py-1 hover:bg-[hsl(var(--surface-bright))] transition-colors cursor-pointer">
          <div className="h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-bold bg-[hsl(var(--primary)/0.15)] text-[hsl(var(--primary-glow))]">
            AD
          </div>
          <span className="text-[13px] font-medium text-foreground hidden md:block">Admin</span>
        </div>
      </div>
    </header>
    <ReportIssueModal open={reportOpen} onClose={() => setReportOpen(false)} />
    </>
  );
};

export default TopBar;
