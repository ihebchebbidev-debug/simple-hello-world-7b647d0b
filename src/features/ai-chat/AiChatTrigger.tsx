import { Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useAiChat } from './AiChatProvider';

type Variant = 'header' | 'sidebar';

type Props = {
  variant?: Variant;
  collapsed?: boolean;
  onNavigate?: () => void;
};

export default function AiChatTrigger({ variant = 'header', collapsed = false, onNavigate }: Props) {
  const { t } = useTranslation();
  const { openChat } = useAiChat();
  const label = t('aiChat.open');

  const handleClick = () => {
    openChat();
    onNavigate?.();
  };

  if (variant === 'sidebar') {
    return (
      <button
        type="button"
        onClick={handleClick}
        title={collapsed ? label : undefined}
        className={`sidebar-nav-item w-full text-[hsl(var(--primary-glow))] ${collapsed ? 'justify-center px-2' : ''}`}
      >
        <Sparkles className="h-5 w-5 shrink-0" strokeWidth={2} aria-hidden />
        {!collapsed && (
          <span className="flex items-center gap-2 truncate">
            <span className="truncate">{t('nav.aiAssistant')}</span>
            <span className="rounded-full border border-[hsl(var(--primary-glow))]/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.2em] text-[hsl(var(--primary-glow))]">
              AI
            </span>
          </span>
        )}
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      aria-label={label}
      title={label}
      className="relative inline-flex items-center gap-1.5 rounded-md p-1.5 text-[hsl(var(--primary-glow))] transition-colors hover:bg-[hsl(var(--surface-bright))]"
    >
      <Sparkles className="h-5 w-5" strokeWidth={2} aria-hidden />
      <span className="text-[11px] font-semibold uppercase tracking-[0.18em] text-[hsl(var(--primary-glow))]">
        AI
      </span>
    </button>
  );
}
