import { NavLink, Outlet } from 'react-router-dom';
import { Home, RefreshCw, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { cn } from '@/lib/utils';
import ProtectedRoute from '@/components/ProtectedRoute';
import OutboxStatusBar from '@/components/OutboxStatusBar';

const MobileShell = () => {
  const { t } = useTranslation();
  const { pending, syncing, failed } = useOfflineQueue();
  const pendingCount = pending.length + syncing.length;
  const failedCount = failed.length;
  const tabBadge = pendingCount + failedCount;

  const tabs = [
    { to: '/home', label: t('nav.home'), Icon: Home, badge: 0, danger: false },
    { to: '/sync', label: t('nav.sync'), Icon: RefreshCw, badge: tabBadge, danger: failedCount > 0 },
    { to: '/settings', label: t('nav.settings'), Icon: Settings, badge: 0, danger: false },
  ];

  return (
    <ProtectedRoute>
      <div className="flex flex-col min-h-screen bg-background text-foreground">
        <OutboxStatusBar />
        <main className="flex-1"><Outlet /></main>
        <nav
          className="fixed bottom-0 inset-x-0 h-16 border-t border-border bg-[hsl(var(--surface-container))] flex"
          style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
        >
          {tabs.map(({ to, label, Icon, badge, danger }) => (
            <NavLink key={to} to={to}
              className={({ isActive }) =>
                cn('flex-1 flex flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors relative',
                  isActive ? 'text-[hsl(var(--primary-glow))]' : 'text-muted-foreground')
              }>
              <Icon className="h-5 w-5" />
              <span>{label}</span>
              {badge > 0 && (
                <span
                  className={cn(
                    'absolute top-2 right-1/4 min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold flex items-center justify-center',
                    danger
                      ? 'bg-[hsl(var(--accent-danger))] text-white'
                      : 'bg-[hsl(var(--accent-warning))] text-black',
                  )}
                >
                  {badge}
                </span>
              )}
            </NavLink>
          ))}
        </nav>
      </div>
    </ProtectedRoute>
  );
};

export default MobileShell;
