import { useEffect, useRef, useState } from 'react';

import { useTranslation } from 'react-i18next';

import { Loader2, SendHorizontal } from 'lucide-react';

import { useMobileKeyboardInset } from './useMobileKeyboardInset';



type Props = {

  disabled?: boolean;

  loading?: boolean;

  onSend: (text: string) => void;

  focusOnMount?: boolean;

};



export default function AiChatComposer({ disabled, loading, onSend, focusOnMount }: Props) {

  const { t } = useTranslation();

  const [value, setValue] = useState('');

  const textareaRef = useRef<HTMLTextAreaElement>(null);

  const keyboardInset = useMobileKeyboardInset();



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



  const canSend = value.trim().length > 0 && !disabled && !loading;



  const submit = () => {

    if (!canSend) return;

    const text = value.trim();

    setValue('');

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

          placeholder={t('aiChat.inputPlaceholder')}

          aria-label={t('aiChat.inputPlaceholder')}

          enterKeyHint="send"

          autoComplete="off"

          autoCorrect="on"

          className="max-h-36 min-h-[44px] flex-1 resize-none bg-transparent px-2 py-2.5 text-base sm:text-[13px] leading-relaxed text-foreground placeholder:text-muted-foreground focus:outline-none disabled:opacity-60"

          onChange={(e) => setValue(e.target.value)}

          onKeyDown={(e) => {

            if (e.key === 'Enter' && !e.shiftKey) {

              e.preventDefault();

              submit();

            }

          }}

        />

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

        <span className="sm:hidden">{t('aiChat.inputHintMobile')}</span>

        <span className="hidden sm:inline">{t('aiChat.inputHint')}</span>

      </p>

    </div>

  );

}

