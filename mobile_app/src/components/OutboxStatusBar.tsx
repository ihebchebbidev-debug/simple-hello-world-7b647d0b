/**
 * Floating outbox status bar.
 *
 * Persistent indicator pinned to the top of the mobile shell that surfaces
 * what is waiting to sync. Hidden when the queue is empty.
 *
 * - Tap the body → /sync (full queue view).
 * - "Sync" button → flush pending items when online.
 * - "Retry" button → replay failed items when online.
 * - Failed items dominate the colour (red) over pending (amber).
 */
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { CloudOff, RefreshCw, RotateCw, AlertCircle, Clock } from 'lucide-react';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { cn } from '@/lib/utils';

const OutboxStatusBar = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { pending, syncing, failed, online, flushing, flushNow, retryAllFailed } = useOfflineQueue();

  const pendingCount = pending.length + syncing.length;
  const failedCount = failed.length;
  const total = pendingCount + failedCount;
  if (total === 0) return null;

  const hasFailed = failedCount > 0;
  const tone = hasFailed
    ? { bg: 'hsl(var(--accent-danger) / 0.14)', fg: 'hsl(var(--accent-danger))', border: 'hsl(var(--accent-danger) / 0.35)' }
    : online
      ? { bg: 'hsl(var(--accent-warning) / 0.14)', fg: 'hsl(var(--accent-warning))', border: 'hsl(var(--accent-warning) / 0.35)' }
      : { bg: 'hsl(var(--surface-container-high))', fg: 'hsl(var(--muted-foreground))', border: 'hsl(var(--border))' };

  const onAction = async (e: React.MouseEvent) => {
    e.stopPropagation();
    if (!online || flushing) return;
    if (hasFailed) await retryAllFailed();
    else await flushNow();
  };

  return (
    <div
      role="status"
      aria-live="polite"
      className="w-full px-3 pb-2 border-b"
      style={{
        paddingTop: 'calc(env(safe-area-inset-top, 0px) + 8px)',
        background: tone.bg,
        borderColor: tone.border,
      }}
    >
      <button
        type="button"
        onClick={() => navigate('/sync')}
        className="w-full flex items-center gap-2.5 px-1 py-1 transition-colors text-left"
        aria-label={t('sync.barOpen')}
      >
        <span className="relative flex h-6 w-6 items-center justify-center shrink-0">
          {hasFailed ? <AlertCircle className="h-4 w-4" /> : online ? <Clock className="h-4 w-4" /> : <CloudOff className="h-4 w-4" />}
        </span>

        <div className="flex-1 min-w-0 text-left">
          <p className="text-[12px] font-semibold leading-tight truncate" style={{ color: 'hsl(var(--foreground))' }}>
            {hasFailed
              ? t('sync.barFailed', { count: failedCount })
              : t('sync.barPending', { count: pendingCount })}
          </p>
          <p className="text-[10.5px] leading-tight truncate" style={{ color: 'hsl(var(--muted-foreground))' }}>
            {!online
              ? t('sync.barWaitingNetwork')
              : hasFailed && pendingCount > 0
                ? t('sync.barMixed', { pending: pendingCount })
                : t('sync.barTapToOpen')}
          </p>
        </div>

        {online && (
          <span
            onClick={onAction}
            className={cn(
              'shrink-0 inline-flex items-center gap-1 px-2.5 h-7 rounded-lg text-[11px] font-semibold transition-opacity',
              flushing && 'opacity-60 pointer-events-none',
            )}
            style={{ background: tone.fg, color: 'hsl(var(--background))' }}
          >
            {hasFailed
              ? <RotateCw className={cn('h-3.5 w-3.5', flushing && 'animate-spin')} />
              : <RefreshCw className={cn('h-3.5 w-3.5', flushing && 'animate-spin')} />}
            {hasFailed ? t('sync.barRetry') : t('sync.barSync')}
          </span>
        )}
      </button>
    </div>
  );
};

export default OutboxStatusBar;
