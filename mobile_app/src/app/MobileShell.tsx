import { NavLink, Outlet } from 'react-router-dom';
import { Home, RefreshCw, Settings } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { cn } from '@/lib/utils';
import ProtectedRoute from '@/components/ProtectedRoute';
import OutboxStatusBar from '@/components/OutboxStatusBar';
import AiChatSheet from '@/features/ai-chat/AiChatSheet';
import { AiChatProvider, useAiChat } from '@/features/ai-chat/AiChatProvider';

const ShellChat = () => {
  const { isOpen, closeChat } = useAiChat();
  return <AiChatSheet open={isOpen} onClose={closeChat} />;
};

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
      <AiChatProvider>
        <div className="flex min-h-[100dvh] flex-col bg-background text-foreground">
          <OutboxStatusBar />
          <main className="flex-1">
            <Outlet />
          </main>

          <nav
            className="fixed inset-x-0 bottom-0 z-30 border-t border-border/70 bg-[hsl(var(--surface-container))]/95 backdrop-blur-md"
            style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            aria-label={t('nav.home')}
          >
            <ul className="mx-auto flex w-full max-w-md items-stretch px-2 pt-1.5 pb-1">
              {tabs.map(({ to, label, Icon, badge, danger }) => (
                <li key={to} className="flex-1">
                  <NavLink
                    to={to}
                    className={({ isActive }) =>
                      cn(
                        'group relative flex min-h-[56px] touch-manipulation select-none flex-col items-center justify-center gap-1 rounded-xl px-1 py-1.5 text-[11px] font-medium transition-colors active:scale-[0.97]',
                        isActive ? 'text-[hsl(var(--primary-glow))]' : 'text-muted-foreground',
                      )
                    }
                  >
                    {({ isActive }) => (
                      <>
                        <span
                          className={cn(
                            'relative flex h-7 w-14 items-center justify-center rounded-full transition-colors',
                            isActive && 'bg-[hsl(var(--primary)/0.16)]',
                          )}
                        >
                          <Icon className="h-5 w-5" strokeWidth={isActive ? 2.4 : 2} aria-hidden />
                          {badge > 0 && (
                            <span
                              className={cn(
                                'absolute -right-0.5 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-bold leading-none',
                                danger
                                  ? 'bg-[hsl(var(--accent-danger))] text-white'
                                  : 'bg-[hsl(var(--accent-warning))] text-black',
                              )}
                            >
                              {badge > 99 ? '99+' : badge}
                            </span>
                          )}
                        </span>
                        <span className="max-w-full truncate leading-none">{label}</span>
                      </>
                    )}
                  </NavLink>
                </li>
              ))}
            </ul>
          </nav>

          <ShellChat />
        </div>
      </AiChatProvider>
    </ProtectedRoute>
  );
};

export default MobileShell;
