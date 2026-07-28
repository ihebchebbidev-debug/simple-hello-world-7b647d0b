import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function AiChatTypingIndicator() {
  const { t, i18n } = useTranslation();
  const [step, setStep] = useState(0);

  const messages = (i18n.language || '').toLowerCase().startsWith('fr')
    ? ['Hmmm…', 'Hmmm… je réfléchis…', 'Je lis les données…', 'Je vérifie les détails…']
    : ['Hmmm…', 'Hmmm… I’m thinking…', 'I’m reading the data…', 'I’m checking the details…'];

  useEffect(() => {
    const interval = window.setInterval(() => {
      setStep((prev) => (prev + 1) % messages.length);
    }, 1200);

    return () => window.clearInterval(interval);
  }, [messages.length]);

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
      <span className="min-w-[180px] text-[12px] text-muted-foreground/90 transition-all duration-300 ease-out">
        {messages[step]}
      </span>
    </div>
  );
}
