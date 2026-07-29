import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Phase = { after: number; fr: string[]; en: string[] };

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
    fr: ['Question complexe : je consolide tout…', 'Presque terminé, merci de patienter…'],
    en: ['Complex question: consolidating everything…', 'Almost done, thanks for your patience…'],
  },
];

export default function AiChatThinkingIndicator() {
  const { i18n, t } = useTranslation();
  const [step, setStep] = useState(0);
  const [elapsed, setElapsed] = useState(0);

  const isFr = (i18n.language || '').toLowerCase().startsWith('fr');

  useEffect(() => {
    const started = Date.now();
    const tick = window.setInterval(() => {
      setElapsed(Math.floor((Date.now() - started) / 1000));
    }, 1000);
    return () => window.clearInterval(tick);
  }, []);

  useEffect(() => {
    const rotate = window.setInterval(() => setStep((prev) => prev + 1), 1600);
    return () => window.clearInterval(rotate);
  }, []);

  const phaseIndex = PHASES.reduce((acc, phase, index) => (elapsed >= phase.after ? index : acc), 0);
  const messages = isFr ? PHASES[phaseIndex].fr : PHASES[phaseIndex].en;
  const label = phaseIndex === 0 && elapsed < 2 ? t('aiChat.thinking') : messages[step % messages.length];
  const isLong = phaseIndex > 0;

  return (
    <div className="flex flex-col gap-1.5 pl-1" role="status" aria-live="polite">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <span className="inline-flex gap-1">
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-bounce" />
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-bounce [animation-delay:120ms]" />
          <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60 animate-bounce [animation-delay:240ms]" />
        </span>
        <span key={label} className="animate-fade-in">
          {label}
        </span>
        {isLong ? <span className="tabular-nums text-muted-foreground/60">{elapsed}s</span> : null}
      </div>
      {isLong ? (
        <div className="h-0.5 w-28 overflow-hidden rounded-full bg-muted">
          <div className="h-full w-1/3 rounded-full bg-[hsl(var(--primary))] opacity-70 animate-ai-chat-progress" />
        </div>
      ) : null}
    </div>
  );
}
