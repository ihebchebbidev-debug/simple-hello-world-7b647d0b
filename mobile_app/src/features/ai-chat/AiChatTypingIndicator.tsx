import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Phase = { after: number; fr: string[]; en: string[] };

// Phases keyed on elapsed seconds: the longer the search, the more it explains itself.
const PHASES: Phase[] = [
  {
    after: 0,
    fr: ['Hmmm…', 'Hmmm… je réfléchis…', 'Je lis les données…', 'Je vérifie les détails…'],
    en: ['Hmmm…', 'Hmmm… I’m thinking…', 'I’m reading the data…', 'I’m checking the details…'],
  },
  {
    after: 8,
    fr: [
      'Cette recherche est un peu longue…',
      'Je croise plusieurs tables…',
      'J’analyse les parcelles concernées…',
    ],
    en: [
      'This is a long search…',
      'I’m cross-checking several tables…',
      'I’m analysing the related plots…',
    ],
  },
  {
    after: 18,
    fr: [
      'Je dois réfléchir plus en profondeur…',
      'Je recalcule les valeurs par hectare…',
      'Encore quelques instants…',
    ],
    en: [
      'I have to think deeper…',
      'I’m recomputing the per-hectare values…',
      'Just a few more moments…',
    ],
  },
  {
    after: 32,
    fr: [
      'Question complexe : je consolide tout…',
      'Presque terminé, merci de patienter…',
    ],
    en: ['Complex question: consolidating everything…', 'Almost done, thanks for your patience…'],
  },
];

export default function AiChatTypingIndicator() {
  const { t, i18n } = useTranslation();
  const [step, setStep] = useState(0);
  const [elapsed, setElapsed] = useState(0);

  const isFr = (i18n.language || '').toLowerCase().startsWith('fr');

  useEffect(() => {
    const started = Date.now();
    const interval = window.setInterval(() => {
      setElapsed(Math.floor((Date.now() - started) / 1000));
    }, 1000);
    return () => window.clearInterval(interval);
  }, []);

  useEffect(() => {
    const interval = window.setInterval(() => setStep((prev) => prev + 1), 1600);
    return () => window.clearInterval(interval);
  }, []);

  const phaseIndex = PHASES.reduce((acc, phase, index) => (elapsed >= phase.after ? index : acc), 0);
  const phase = PHASES[phaseIndex];
  const messages = isFr ? phase.fr : phase.en;
  const label = messages[step % messages.length];
  const isLong = phaseIndex > 0;

  return (
    <div
      className="flex flex-col gap-1.5 pl-1 animate-fade-in"
      role="status"
      aria-live="polite"
      aria-label={t('aiChat.thinking')}
    >
      <div className="flex items-center gap-2">
        <div className="flex items-center gap-1">
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:0ms]" />
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:150ms]" />
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-ai-chat-bounce [animation-delay:300ms]" />
        </div>
        <span
          key={`${phaseIndex}-${step % messages.length}`}
          className="min-w-[180px] animate-fade-in bg-[linear-gradient(90deg,hsl(var(--muted-foreground)/0.45),hsl(var(--foreground)/0.9),hsl(var(--muted-foreground)/0.45))] bg-[length:200%_100%] bg-clip-text text-[12px] text-transparent animate-ai-chat-shimmer"
        >
          {label}
        </span>
        {isLong ? (
          <span className="text-[11px] tabular-nums text-muted-foreground/60">{elapsed}s</span>
        ) : null}
      </div>

      {isLong ? (
        <div className="h-0.5 w-32 overflow-hidden rounded-full bg-muted">
          <div className="h-full w-1/3 rounded-full bg-primary/60 animate-ai-chat-progress" />
        </div>
      ) : null}
    </div>
  );
}
