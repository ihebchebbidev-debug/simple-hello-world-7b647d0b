import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ArrowDown,
  Check,
  Copy,
  Droplets,
  FileBarChart2,
  Leaf,
  RefreshCw,
  ThumbsUp,
  ThumbsDown,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import AiChatMarkdown from './AiChatMarkdown';
import AiChatTypingIndicator from './AiChatTypingIndicator';
import { useMobileKeyboardInset } from './useMobileKeyboardInset';
import type { AiChatMessage, AiChatRating } from './types';

function CopyButton({ text }: { text: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  return (
    <button
      type="button"
      aria-label={t('aiChat.copy')}
      title={copied ? t('aiChat.copied') : t('aiChat.copy')}
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(text);
          setCopied(true);
          window.setTimeout(() => setCopied(false), 1500);
        } catch {
          /* clipboard unavailable — ignore */
        }
      }}
      className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-[hsl(var(--surface-container-highest))] hover:text-foreground"
    >
      {copied ? <Check className="h-3.5 w-3.5" aria-hidden /> : <Copy className="h-3.5 w-3.5" aria-hidden />}
    </button>
  );
}

function FeedbackButtons({
  message,
  onRate,
}: {
  message: AiChatMessage;
  onRate: (rating: AiChatRating) => void;
}) {
  const { t } = useTranslation();
  const rated = message.rating;

  const base =
    'inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-[hsl(var(--surface-container-highest))] hover:text-foreground disabled:cursor-default';

  return (
    <div className="flex items-center gap-0.5" role="group" aria-label={t('aiChat.feedback.label')}>
      <button
        type="button"
        aria-label={t('aiChat.feedback.up')}
        aria-pressed={rated === 'up'}
        onClick={() => onRate('up')}
        className={`${base} ${rated === 'up' ? 'bg-[hsl(var(--primary)/0.15)] text-[hsl(var(--primary-glow))]' : ''}`}
      >
        <ThumbsUp className="h-3.5 w-3.5" aria-hidden />
      </button>
      <button
        type="button"
        aria-label={t('aiChat.feedback.down')}
        aria-pressed={rated === 'down'}
        onClick={() => onRate('down')}
        className={`${base} ${rated === 'down' ? 'bg-destructive/15 text-destructive' : ''}`}
      >
        <ThumbsDown className="h-3.5 w-3.5" aria-hidden />
      </button>
    </div>
  );
}

function formatTime(ts: number, locale: string): string {
  try {
    return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(ts));
  } catch {
    return '';
  }
}

function MessageBubble({
  message,
  streaming,
  onRate,
  onRegenerate,
}: {
  message: AiChatMessage;
  streaming?: boolean;
  onRate?: (rating: AiChatRating) => void;
  onRegenerate?: () => void;
}) {
  const { i18n, t } = useTranslation();
  const isUser = message.role === 'user';
  const isError = message.status === 'error';
  const emptyAssistant = !isUser && !message.content.trim();

  if (emptyAssistant && streaming) {
    return <AiChatTypingIndicator />;
  }

  if (emptyAssistant) return null;

  const canRate = !isUser && !isError && !streaming && !!onRate && !!message.content.trim();
  const canCopy = !isUser && !isError && !streaming && !!message.content.trim();
  // Regenerate is offered on the last assistant bubble even on error — that's
  // when users most want to retry. Hidden while a stream is in flight.
  const canRegenerate = !isUser && !streaming && !!onRegenerate;
  const timeLabel = formatTime(message.createdAt, i18n.language);

  return (
    <div
      className={`flex animate-fade-in ${isUser ? 'justify-end' : 'justify-start'}`}
      data-role={message.role}
    >
      <div className={`flex flex-col ${isUser ? 'max-w-[min(100%,88%)]' : 'w-full'}`}>
        <div
          className={`leading-relaxed ${
            isUser
              ? 'rounded-2xl rounded-tr-sm bg-primary px-3.5 py-2.5 text-[14px] sm:text-[13px] text-primary-foreground whitespace-pre-wrap break-words shadow-sm'
              : isError
                ? 'rounded-2xl border border-destructive/40 bg-destructive/10 px-3.5 py-2.5 text-[13px] text-destructive whitespace-pre-wrap break-words'
                : 'text-foreground'
          }`}
        >
          {isUser || isError || streaming ? (
            message.content
          ) : (
            <AiChatMarkdown content={message.content} />
          )}
        </div>
        <div
          className={`mt-1 flex items-center gap-2 text-[11px] text-muted-foreground ${
            isUser ? 'justify-end' : 'justify-start'
          }`}
        >
          {timeLabel && <span className="tabular-nums">{timeLabel}</span>}
          {(canCopy || canRate || canRegenerate) && (
            <div className="flex items-center gap-0.5">
              {canCopy && <CopyButton text={message.content} />}
              {canRegenerate && (
                <button
                  type="button"
                  aria-label={t('aiChat.regenerate')}
                  title={t('aiChat.regenerate')}
                  onClick={onRegenerate}
                  className="inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-[hsl(var(--surface-container-highest))] hover:text-foreground"
                >
                  <RefreshCw className="h-3.5 w-3.5" aria-hidden />
                </button>
              )}
              {canRate && <FeedbackButtons message={message} onRate={onRate!} />}
            </div>
          )}
          {message.rating && !isUser && (
            <span className="text-[10.5px] text-muted-foreground/80">{t('aiChat.feedback.thanks')}</span>
          )}
        </div>
      </div>
    </div>
  );
}

type Props = {
  messages: AiChatMessage[];
  isSending: boolean;
  onSuggestion: (text: string) => void;
  onRate?: (messageId: string, rating: AiChatRating) => void;
  onRegenerate?: () => void;
};

// Threshold (px) below which we consider the user "at the bottom" and are
// allowed to auto-scroll on new content. Above it, we respect their scroll
// position and surface a "Jump to latest" affordance instead.
const STICK_THRESHOLD_PX = 96;

export default function AiChatMessageList({
  messages,
  isSending,
  onSuggestion,
  onRate,
  onRegenerate,
}: Props) {
  const { t } = useTranslation();
  const scrollRef = useRef<HTMLDivElement>(null);
  const bottomRef = useRef<HTMLDivElement>(null);
  const keyboardInset = useMobileKeyboardInset();

  // Whether we're currently stuck to the bottom (auto-scroll on).
  const [stickToBottom, setStickToBottom] = useState(true);
  // Independent flag: user has scrolled far enough up that we surface a button.
  const [showJump, setShowJump] = useState(false);

  const scrollToBottom = useCallback((smooth: boolean) => {
    bottomRef.current?.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'end' });
  }, []);

  const handleScroll = useCallback(() => {
    const el = scrollRef.current;
    if (!el) return;
    const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
    const atBottom = distance <= STICK_THRESHOLD_PX;
    setStickToBottom(atBottom);
    setShowJump(!atBottom && distance > STICK_THRESHOLD_PX * 2);
  }, []);

  // Only auto-scroll when the user is near the bottom; otherwise leave them be.
  useEffect(() => {
    if (!stickToBottom) return;
    scrollToBottom(!isSending);
  }, [messages, isSending, keyboardInset, stickToBottom, scrollToBottom]);

  const suggestions: Array<{ label: string; icon: typeof Leaf }> = [
    { label: t('aiChat.suggestions.plots'), icon: Leaf },
    { label: t('aiChat.suggestions.water'), icon: Droplets },
    { label: t('aiChat.suggestions.reports'), icon: FileBarChart2 },
  ];

  return (
    <div className="relative flex min-h-0 flex-1 flex-col">
      <div
        ref={scrollRef}
        onScroll={handleScroll}
        className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain px-3 py-4 sm:gap-4 sm:px-4 [-webkit-overflow-scrolling:touch]"
      >
        {messages.length === 0 && !isSending && (
          <div className="mx-auto flex max-w-sm flex-col items-center text-center animate-fade-in pt-4 sm:pt-10">
            <h3 className="headline-lg text-foreground">{t('aiChat.emptyTitle')}</h3>
            <p className="mt-2 text-[13px] text-muted-foreground leading-relaxed">{t('aiChat.emptySubtitle')}</p>
            <div className="mt-6 flex w-full flex-col gap-2">
              {suggestions.map(({ label, icon: Icon }) => (
                <button
                  key={label}
                  type="button"
                  onClick={() => onSuggestion(label)}
                  className="group flex touch-manipulation items-start gap-2.5 rounded-lg border border-border/50 bg-[hsl(var(--surface-container-high))] px-3 py-2.5 text-left text-[13px] text-foreground/90 transition-all hover:border-[hsl(var(--primary)/0.4)] hover:bg-[hsl(var(--surface-bright))] active:scale-[0.99] sm:text-[12px]"
                >
                  <Icon className="mt-0.5 h-4 w-4 shrink-0 text-[hsl(var(--primary-glow))]" aria-hidden />
                  <span className="flex-1 leading-snug">{label}</span>
                </button>
              ))}
            </div>
          </div>
        )}

        {(() => {
          // Only the last assistant message gets a regenerate control.
          let lastAssistantIdx = -1;
          for (let i = messages.length - 1; i >= 0; i--) {
            if (messages[i].role === 'assistant') {
              lastAssistantIdx = i;
              break;
            }
          }
          return messages.map((message, index) => {
            const isLast = index === messages.length - 1;
            const streaming = isSending && isLast && message.role === 'assistant';
            const showRegenerate =
              !!onRegenerate && !isSending && index === lastAssistantIdx;
            return (
              <MessageBubble
                key={message.id}
                message={message}
                streaming={streaming}
                onRate={onRate ? (rating) => onRate(message.id, rating) : undefined}
                onRegenerate={showRegenerate ? onRegenerate : undefined}
              />
            );
          });
        })()}

        <div ref={bottomRef} className="h-px shrink-0" aria-hidden />
      </div>

      {showJump && (
        <button
          type="button"
          onClick={() => {
            setStickToBottom(true);
            scrollToBottom(true);
          }}
          aria-label={t('aiChat.jumpToLatest')}
          title={t('aiChat.jumpToLatest')}
          className="absolute bottom-3 left-1/2 -translate-x-1/2 inline-flex items-center gap-1.5 rounded-full border border-border/50 bg-[hsl(var(--surface-container-highest))] px-3 py-1.5 text-[12px] text-foreground shadow-md transition-all hover:brightness-110 active:scale-95"
        >
          <ArrowDown className="h-3.5 w-3.5" aria-hidden />
          <span>{t('aiChat.jumpToLatest')}</span>
        </button>
      )}
    </div>
  );
}
