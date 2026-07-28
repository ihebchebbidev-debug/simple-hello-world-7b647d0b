import { useCallback, useEffect, useRef, useState } from 'react';

import { useTranslation } from 'react-i18next';

import { Loader2, Mic, MicOff, SendHorizontal } from 'lucide-react';

import { useMobileKeyboardInset } from './useMobileKeyboardInset';

type Props = {
  disabled?: boolean;
  loading?: boolean;
  onSend: (text: string) => void;
  focusOnMount?: boolean;
};

// Minimal typings for the Web Speech API (not in lib.dom).
type SpeechRecognitionAlternative = { transcript: string };
type SpeechRecognitionResult = { 0: SpeechRecognitionAlternative; isFinal: boolean; length: number };
type SpeechRecognitionEvent = { resultIndex: number; results: ArrayLike<SpeechRecognitionResult> };
type SpeechRecognitionErrorEvent = { error: string };
interface SpeechRecognitionLike {
  lang: string;
  continuous: boolean;
  interimResults: boolean;
  onresult: ((e: SpeechRecognitionEvent) => void) | null;
  onerror: ((e: SpeechRecognitionErrorEvent) => void) | null;
  onend: (() => void) | null;
  start: () => void;
  stop: () => void;
  abort: () => void;
}
type SpeechRecognitionCtor = new () => SpeechRecognitionLike;

function getSpeechRecognitionCtor(): SpeechRecognitionCtor | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as {
    SpeechRecognition?: SpeechRecognitionCtor;
    webkitSpeechRecognition?: SpeechRecognitionCtor;
  };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

function localeToBcp47(lang: string): string {
  const base = (lang || 'en').toLowerCase().split(/[-_]/)[0];
  if (base === 'fr') return 'fr-FR';
  return 'en-US';
}

export default function AiChatComposer({ disabled, loading, onSend, focusOnMount }: Props) {
  const { t, i18n } = useTranslation();
  const [value, setValue] = useState('');
  const [listening, setListening] = useState(false);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const recognitionRef = useRef<SpeechRecognitionLike | null>(null);
  const baseTextRef = useRef('');
  const keyboardInset = useMobileKeyboardInset();

  const voiceSupported = typeof window !== 'undefined' && getSpeechRecognitionCtor() !== null;

  useEffect(() => {
    if (!focusOnMount) return;
    const desktop = window.matchMedia('(min-width: 640px)');
    if (!desktop.matches) return;
    requestAnimationFrame(() => textareaRef.current?.focus({ preventScroll: true }));
  }, [focusOnMount]);

  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
  }, [value]);

  useEffect(() => {
    return () => {
      try {
        recognitionRef.current?.abort();
      } catch {
        // ignore
      }
      recognitionRef.current = null;
    };
  }, []);

  const stopListening = useCallback(() => {
    try {
      recognitionRef.current?.stop();
    } catch {
      // ignore
    }
  }, []);

  const startListening = useCallback(() => {
    const Ctor = getSpeechRecognitionCtor();
    if (!Ctor) return;
    try {
      const rec = new Ctor();
      rec.lang = localeToBcp47(i18n.language);
      rec.continuous = true;
      rec.interimResults = true;
      baseTextRef.current = value ? value.replace(/\s+$/, '') + ' ' : '';

      rec.onresult = (event) => {
        let finalText = '';
        let interimText = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
          const result = event.results[i];
          const chunk = result[0]?.transcript ?? '';
          if (result.isFinal) finalText += chunk;
          else interimText += chunk;
        }
        if (finalText) {
          baseTextRef.current = (baseTextRef.current + finalText).replace(/\s+/g, ' ');
          if (!baseTextRef.current.endsWith(' ')) baseTextRef.current += ' ';
        }
        setValue((baseTextRef.current + interimText).trimStart());
      };

      rec.onerror = () => {
        setListening(false);
      };
      rec.onend = () => {
        setListening(false);
        recognitionRef.current = null;
      };

      recognitionRef.current = rec;
      rec.start();
      setListening(true);
      requestAnimationFrame(() => textareaRef.current?.focus({ preventScroll: true }));
    } catch {
      setListening(false);
    }
  }, [i18n.language, value]);

  const toggleVoice = () => {
    if (disabled || loading) return;
    if (listening) stopListening();
    else startListening();
  };

  const canSend = value.trim().length > 0 && !disabled && !loading;

  const submit = () => {
    if (!canSend) return;
    if (listening) stopListening();
    const text = value.trim();
    setValue('');
    baseTextRef.current = '';
    onSend(text);
    requestAnimationFrame(() => textareaRef.current?.focus({ preventScroll: true }));
  };

  return (
    <div
      className="shrink-0 border-t border-border/40 bg-[hsl(var(--surface-container))] px-3 pt-3 sm:px-4 sm:pt-4"
      style={{
        paddingBottom: `max(0.75rem, calc(env(safe-area-inset-bottom, 0px) + ${keyboardInset}px))`,
      }}
    >
      <div className="flex items-end gap-2 rounded-xl border border-border/50 bg-[hsl(var(--surface-container-highest))] p-2 shadow-sm focus-within:ring-2 focus-within:ring-[hsl(var(--primary)/0.35)]">
        <textarea
          ref={textareaRef}
          rows={1}
          value={value}
          disabled={disabled || loading}
          placeholder={listening ? t('aiChat.voice.listening') : t('aiChat.inputPlaceholder')}
          aria-label={t('aiChat.inputPlaceholder')}
          enterKeyHint="send"
          autoComplete="off"
          autoCorrect="on"
          className="max-h-36 min-h-[44px] flex-1 resize-none bg-transparent px-2 py-2.5 text-base sm:text-[13px] leading-relaxed text-foreground placeholder:text-muted-foreground focus:outline-none disabled:opacity-60"
          onChange={(e) => {
            setValue(e.target.value);
            baseTextRef.current = e.target.value;
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              submit();
            }
          }}
        />
        {voiceSupported && (
          <button
            type="button"
            onClick={toggleVoice}
            disabled={disabled || loading}
            aria-label={listening ? t('aiChat.voice.stop') : t('aiChat.voice.start')}
            aria-pressed={listening}
            title={listening ? t('aiChat.voice.stop') : t('aiChat.voice.start')}
            className={`relative inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center rounded-lg border transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-40 ${
              listening
                ? 'border-red-500/40 bg-red-500/10 text-red-500 dark:text-red-400'
                : 'border-border/50 bg-transparent text-muted-foreground hover:text-foreground hover:bg-[hsl(var(--surface-container-high))]'
            }`}
          >
            {listening ? (
              <>
                <span className="absolute inset-0 rounded-lg animate-ping bg-red-500/20" aria-hidden />
                <MicOff className="h-4 w-4 relative" aria-hidden />
              </>
            ) : (
              <Mic className="h-4 w-4" aria-hidden />
            )}
          </button>
        )}
        <button
          type="button"
          disabled={!canSend}
          onClick={submit}
          aria-label={t('aiChat.send')}
          title={t('aiChat.send')}
          className="inline-flex h-11 w-11 shrink-0 touch-manipulation items-center justify-center rounded-lg bg-primary text-primary-foreground transition-all hover:brightness-110 active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
        >
          {loading ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
          ) : (
            <SendHorizontal className="h-4 w-4" aria-hidden />
          )}
        </button>
      </div>
      <p className="mt-2 text-[10px] text-muted-foreground sm:text-[10px]">
        {listening ? (
          <span className="text-red-500 dark:text-red-400">{t('aiChat.voice.hint')}</span>
        ) : (
          <>
            <span className="sm:hidden">{t('aiChat.inputHintMobile')}</span>
            <span className="hidden sm:inline">{t('aiChat.inputHint')}</span>
          </>
        )}
      </p>
    </div>
  );
}
