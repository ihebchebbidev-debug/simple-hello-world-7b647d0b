import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Cog, Lightbulb, SendHorizontal, Sparkles, X } from 'lucide-react';
import i18n from '@/i18n';
import { streamAiChatMessage, type AiChatMessage } from '@/lib/aiChat';
import { cn } from '@/lib/utils';
import AiChatThinkingIndicator from './AiChatThinkingIndicator';

interface Props {
  open: boolean;
  onClose: () => void;
}

function newId(): string {
  try { return crypto.randomUUID(); } catch { return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`; }
}

/**
 * Bottom-sheet AI assistant for the field app. Streams NDJSON from the
 * same `/api/ai/chat` endpoint the admin app uses, and surfaces the
 * agent's plan + tool activity while the answer builds.
 */
const AiChatSheet = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  const [messages, setMessages] = useState<AiChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [conversationId, setConversationId] = useState<string | null>(null);
  const abortRef = useRef<AbortController | null>(null);
  const scrollRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    // Auto-scroll to newest content as tokens stream in.
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, open]);

  useEffect(() => () => abortRef.current?.abort(), []);

  const historyForApi = useMemo(
    () => messages.map((m) => ({ role: m.role, content: m.content })),
    [messages],
  );

  const send = async () => {
    const text = input.trim();
    if (!text || sending) return;
    const userMsg: AiChatMessage = { id: newId(), role: 'user', content: text };
    const assistantId = newId();
    const assistantMsg: AiChatMessage = { id: assistantId, role: 'assistant', content: '', plan: [], tools: [] };

    setMessages((prev) => [...prev, userMsg, assistantMsg]);
    setInput('');
    setSending(true);

    const controller = new AbortController();
    abortRef.current = controller;

    try {
      const res = await streamAiChatMessage(
        {
          messages: [...historyForApi, { role: 'user', content: text }],
          locale: i18n.language || 'fr',
          conversation_id: conversationId,
        },
        {
          onDelta: (chunk) => {
            setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, content: m.content + chunk } : m)));
          },
          onRevise: (finalReply) => {
            setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)));
          },
          onPlan: (steps) => {
            setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, plan: steps } : m)));
          },
          onToolStart: (name) => {
            setMessages((prev) => prev.map((m) =>
              m.id === assistantId
                ? { ...m, tools: [...(m.tools ?? []), { name, status: 'pending' }] }
                : m,
            ));
          },
          onToolEnd: (name, ok, preview) => {
            setMessages((prev) => prev.map((m) => {
              if (m.id !== assistantId) return m;
              const tools = [...(m.tools ?? [])];
              // Update the last pending entry for this tool name.
              for (let i = tools.length - 1; i >= 0; i--) {
                if (tools[i].name === name && tools[i].status === 'pending') {
                  tools[i] = { name, status: ok === false ? 'error' : 'ok', preview };
                  break;
                }
              }
              return { ...m, tools };
            }));
          },
        },
        controller.signal,
      );
      setConversationId(res.conversationId);
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Erreur inconnue';
      setMessages((prev) => prev.map((m) =>
        m.id === assistantId
          ? { ...m, content: m.content || `⚠️ ${message}` }
          : m,
      ));
    } finally {
      setSending(false);
      abortRef.current = null;
    }
  };

  const clearThread = () => {
    if (sending) return;
    setMessages([]);
    setConversationId(null);
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-[70] flex flex-col bg-background" role="dialog" aria-modal="true">
      <header className="flex items-center gap-3 px-4 py-3 border-b border-border bg-[hsl(var(--surface-container))]">
        <div className="h-9 w-9 rounded-lg flex items-center justify-center bg-[hsl(var(--primary)/0.15)] text-[hsl(var(--primary-glow))]">
          <Sparkles className="h-5 w-5" strokeWidth={2} />
        </div>
        <div className="flex-1 min-w-0">
          <h1 className="text-base font-semibold text-foreground leading-tight">{t('aiChat.title')}</h1>
          <p className="text-[11px] text-muted-foreground truncate">{t('aiChat.subtitle')}</p>
        </div>
        {messages.length > 0 && (
          <button
            type="button"
            onClick={clearThread}
            className="text-[12px] font-medium text-muted-foreground hover:text-foreground px-2 py-1 rounded-md"
          >
            {t('aiChat.newChat')}
          </button>
        )}
        <button
          type="button"
          onClick={onClose}
          className="p-1.5 rounded-lg hover:bg-[hsl(var(--surface-bright))]"
          aria-label={t('common.back')}
        >
          <X className="h-5 w-5" />
        </button>
      </header>

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-5 space-y-4">
        {messages.length === 0 && (
          <div className="mt-10 flex flex-col items-center gap-3 text-center">
            <div className="h-14 w-14 rounded-2xl flex items-center justify-center bg-[hsl(var(--primary)/0.15)] text-[hsl(var(--primary-glow))]">
              <Sparkles className="h-7 w-7" strokeWidth={1.8} />
            </div>
            <p className="text-sm text-muted-foreground max-w-[280px]">{t('aiChat.empty')}</p>
          </div>
        )}

        {messages.map((m) => (
          <MessageBubble key={m.id} message={m} />
        ))}

        {sending && messages[messages.length - 1]?.role === 'assistant' && !messages[messages.length - 1].content && (
          <AiChatThinkingIndicator />
        )}

      </div>

      <div
        className="border-t border-border bg-[hsl(var(--surface-container))] px-3 py-3"
        style={{ paddingBottom: 'calc(env(safe-area-inset-bottom) + 0.75rem)' }}
      >
        <div className="flex items-end gap-2">
          <textarea
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                void send();
              }
            }}
            placeholder={t('aiChat.placeholder')}
            rows={1}
            className="flex-1 max-h-32 min-h-[42px] resize-none rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-[hsl(var(--primary))]"
          />
          <button
            type="button"
            onClick={() => void send()}
            disabled={sending || !input.trim()}
            className="h-[42px] w-[42px] shrink-0 rounded-xl bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))] flex items-center justify-center disabled:opacity-40 disabled:cursor-not-allowed"
            aria-label={t('aiChat.send')}
          >
            <SendHorizontal className="h-5 w-5" />
          </button>
        </div>
      </div>
    </div>
  );
};

const MessageBubble = ({ message }: { message: AiChatMessage }) => {
  const isUser = message.role === 'user';
  return (
    <div className={cn('flex', isUser ? 'justify-end' : 'justify-start')}>
      <div className={cn('max-w-[85%] space-y-2', isUser ? 'items-end' : 'items-start')}>
        {!isUser && (message.plan?.length || message.tools?.length) ? (
          <AgentActivity plan={message.plan} tools={message.tools} />
        ) : null}

        {(message.content || isUser) && (
          <div
            className={cn(
              'rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words',
              isUser
                ? 'bg-[hsl(var(--primary))] text-[hsl(var(--primary-foreground))] rounded-br-md'
                : 'text-foreground',
            )}
          >
            {message.content || '…'}
          </div>
        )}
      </div>
    </div>
  );
};

const AgentActivity = ({ plan, tools }: { plan?: string[]; tools?: AiChatMessage['tools'] }) => {
  const { t } = useTranslation();
  return (
    <div className="rounded-xl border border-border/60 bg-[hsl(var(--surface-container-high))] p-2.5 space-y-2 text-xs">
      {plan && plan.length > 0 && (
        <div className="space-y-1">
          <div className="flex items-center gap-1.5 text-[hsl(var(--primary-glow))] font-medium">
            <Lightbulb className="h-3.5 w-3.5" />
            <span>{t('aiChat.plan')}</span>
          </div>
          <ol className="list-decimal pl-5 space-y-0.5 text-muted-foreground">
            {plan.map((step, i) => <li key={i}>{step}</li>)}
          </ol>
        </div>
      )}
      {tools && tools.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {tools.map((tool, i) => (
            <span
              key={i}
              className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium border',
                tool.status === 'pending' && 'border-border text-muted-foreground bg-background',
                tool.status === 'ok' && 'border-emerald-500/40 text-emerald-500 bg-emerald-500/10',
                tool.status === 'error' && 'border-red-500/40 text-red-500 bg-red-500/10',
              )}
              title={tool.preview}
            >
              <Cog className={cn('h-3 w-3', tool.status === 'pending' && 'animate-spin')} />
              {tool.name}
            </span>
          ))}
        </div>
      )}
    </div>
  );
};

export default AiChatSheet;