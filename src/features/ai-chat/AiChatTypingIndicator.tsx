import { useTranslation } from 'react-i18next';

export default function AiChatTypingIndicator() {
  const { t } = useTranslation();

  return (
    <div
      className="flex items-center gap-2 pl-1 animate-fade-in"
      role="status"
      aria-live="polite"
      aria-label={t('aiChat.thinking')}
    >
      <div className="flex items-center gap-1">
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:0ms]" />
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:150ms]" />
        <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:300ms]" />
      </div>
      <span className="text-[12px] text-muted-foreground/90">
        {t('aiChat.thinking')}
      </span>
    </div>
  );
}
