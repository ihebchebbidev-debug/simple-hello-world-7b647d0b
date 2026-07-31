import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useAiChatOptional } from './AiChatProvider';
import { cn } from '@/lib/utils';

/**
 * Inline AI launcher. Sits in the page header next to the network badge
 * (no floating overlay), so it never covers page content.
 */
const AiLauncherButton = ({ className }: { className?: string }) => {
  const { t } = useTranslation();
  const chat = useAiChatOptional();

  if (!chat) return null;

  return (
    <button
      type="button"
      onClick={chat.openChat}
      aria-label={t('aiChat.open')}
      title={t('aiChat.open')}
      className={cn(
        'inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-[hsl(var(--primary-glow))]/35 bg-[hsl(var(--primary)/0.12)] px-3 text-[hsl(var(--primary-glow))] transition-transform active:scale-95',
        className,
      )}
    >
      <Sparkles className="h-4 w-4" strokeWidth={2} aria-hidden />
      <span className="text-[11px] font-semibold uppercase tracking-[0.16em]">AI</span>
    </button>
  );
};

export default AiLauncherButton;
