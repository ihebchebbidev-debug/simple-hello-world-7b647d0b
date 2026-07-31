import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SendHorizontal, Sparkles, Square, X } from 'lucide-react';
import AiChatMessageList from './AiChatMessageList';
import { useAiChat } from './AiChatProvider';
import { useMobileKeyboardInset } from './useMobileKeyboardInset';

interface Props {
  open: boolean;
  onClose: () => void;
}

const AiChatSheet = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  const {
    messages,
    isSending,
    sendMessage,
    regenerateLastAnswer,
    startNewConversation,
    rateMessage,
    stopStreaming,
  } = useAiChat();
  const [input, setInput] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const keyboardInset = useMobileKeyboardInset();

  // Auto-grow the composer without letting it eat the transcript.
  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 128)}px`;
  }, [input, open]);

  // Lock background scroll while the full-screen sheet is open.
  useEffect(() => {
    if (!open) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const id = window.setTimeout(() => textareaRef.current?.focus(), 120);
    return () => {
      document.body.style.overflow = previous;
      window.clearTimeout(id);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  const handleSend = async () => {
    const text = input.trim();
    if (!text || isSending) return;
    setInput('');
    await sendMessage(text);
    textareaRef.current?.focus();
  };

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-[70] flex flex-col overflow-hidden bg-background"
      role="dialog"
      aria-modal="true"
      style={{ height: '100dvh' }}
    >
      <header
        className="flex shrink-0 items-center gap-3 border-b border-border bg-[hsl(var(--surface-container))] px-3 py-2.5 sm:px-4"
        style={{ paddingTop: 'calc(env(safe-area-inset-top, 0px) + 0.625rem)' }}
      >
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[hsl(var(--primary)/0.15)] text-[hsl(var(--primary-glow))]">
          <Sparkles className="h-5 w-5" strokeWidth={2} aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <h1 className="truncate text-[15px] font-semibold leading-tight text-foreground">{t('aiChat.title')}</h1>
          <p className="truncate text-[11px] text-muted-foreground">{t('aiChat.subtitle')}</p>
        </div>
        {messages.length > 0 && (
          <button
            type="button"
            onClick={startNewConversation}
            className="shrink-0 rounded-md px-2 py-1.5 text-[12px] font-medium text-muted-foreground transition-colors hover:bg-[hsl(var(--surface-bright))] hover:text-foreground active:scale-95"
          >
            {t('aiChat.newChat')}
          </button>
        )}
        <button
          type="button"
          onClick={onClose}
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-foreground hover:bg-[hsl(var(--surface-bright))] active:scale-95"
          aria-label={t('common.back')}
        >
          <X className="h-5 w-5" aria-hidden />
        </button>
      </header>

      <div className="flex min-h-0 flex-1 flex-col">
        <AiChatMessageList
          messages={messages}
          isSending={isSending}
          onSuggestion={(text) => {
            setInput(text);
            textareaRef.current?.focus();
          }}
          onRate={rateMessage}
          onRegenerate={regenerateLastAnswer}
        />
      </div>

      <div
        className="shrink-0 border-t border-border bg-[hsl(var(--surface-container))] px-3 py-2.5"
        style={{
          paddingBottom: keyboardInset > 0 ? '0.625rem' : 'calc(env(safe-area-inset-bottom, 0px) + 0.625rem)',
          marginBottom: keyboardInset,
        }}
      >
        <div className="mx-auto flex w-full max-w-2xl items-end gap-2">
          <textarea
            ref={textareaRef}
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                void handleSend();
              }
            }}
            placeholder={t('aiChat.placeholder')}
            rows={1}
            enterKeyHint="send"
            className="max-h-32 min-h-[44px] flex-1 resize-none overflow-y-auto rounded-2xl border border-border bg-background px-3.5 py-2.5 text-[16px] leading-snug text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-[hsl(var(--primary))] sm:text-[14px]"
          />
          {isSending ? (
            <button
              type="button"
              onClick={stopStreaming}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-border bg-[hsl(var(--surface-container-high))] text-foreground active:scale-95"
              aria-label={t('aiChat.stop', { defaultValue: 'Stop' })}
            >
              <Square className="h-4 w-4 fill-current" aria-hidden />
            </button>
          ) : (
            <button
              type="button"
              onClick={() => void handleSend()}
              disabled={!input.trim()}
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))] transition-transform active:scale-95 disabled:cursor-not-allowed disabled:opacity-40"
              aria-label={t('aiChat.send')}
            >
              <SendHorizontal className="h-5 w-5" aria-hidden />
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default AiChatSheet;
