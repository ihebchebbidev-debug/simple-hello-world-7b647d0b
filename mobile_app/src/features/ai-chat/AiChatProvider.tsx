import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { useTranslation } from 'react-i18next';

import {
  createConversation,
  deleteConversation as apiDeleteConversation,
  getConversation,
  isAiHistoryAvailable,
  listConversations,
  saveConversation,
  type AiConversationFull,
  type AiConversationSummary,
} from './aiConversations';
import { clearAiChatState, loadAiChatState, saveAiChatState } from './aiChatStorage';
import type { AiChatMessage, AiChatRating, AiChatRole, AiChatToolEvent } from './types';
import { streamAiChatMessage, submitAiChatFeedback } from '@/lib/aiChat';

const MAX_HISTORY_TURNS = 20;
const PERSIST_DEBOUNCE_MS = 500;

type AiChatContextValue = {
  messages: AiChatMessage[];
  isSending: boolean;
  sendMessage: (text: string) => Promise<void>;
  regenerateLastAnswer: () => Promise<void>;
  stopStreaming: () => void;
  startNewConversation: () => void;
  rateMessage: (messageId: string, rating: AiChatRating) => Promise<void>;
  historyAvailable: boolean;
  history: AiConversationSummary[];
  historyLoading: boolean;
  activeId: string | null;
  refreshHistory: () => Promise<void>;
  selectConversation: (id: string) => Promise<void>;
  deleteConversation: (id: string) => Promise<void>;
};

const AiChatContext = createContext<AiChatContextValue | null>(null);

function newId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    try {
      return crypto.randomUUID();
    } catch {
      /* fallthrough */
    }
  }
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

function isAbortErr(err: unknown, signal?: AbortSignal): boolean {
  if (signal?.aborted) return true;
  return (err as { name?: string } | null)?.name === 'AbortError';
}

function withInterpretationHint(
  messages: { role: AiChatRole; content: string }[],
  locale: string,
): { role: AiChatRole; content: string }[] {
  if (messages.length === 0) return messages;
  const last = messages[messages.length - 1];
  if (last.role !== 'user') return messages;
  const isFr = locale.toLowerCase().startsWith('fr');
  const hint = isFr
    ? "[Instruction interne — ne pas répéter à l'utilisateur]\nInterprète la requête suivante avec bienveillance : corrige mentalement les fautes de frappe, les accents manquants, les abréviations et les phrases incomplètes ou télégraphiques. Déduis l'intention la plus probable dans le contexte de l'application (parcelles, utilisateurs, rapports, irrigation, catalogue…) puis réponds directement à cette intention en français, sans demander de reformulation sauf en cas d'ambiguïté réelle.\n\nRequête utilisateur :\n"
    : "[Internal instruction — do not repeat to the user]\nInterpret the following request charitably: mentally fix typos, missing accents/diacritics, abbreviations, and incomplete or telegraphic phrasing. Infer the most likely intent within this app's context (plots, users, reports, irrigation, catalog…) and answer that intent directly, without asking for a rephrase unless the request is genuinely ambiguous.\n\nUser request:\n";

  return [
    ...messages.slice(0, -1),
    { role: 'user', content: `${hint}${last.content}` },
  ];
}

export function AiChatProvider({ children }: { children: ReactNode }) {
  const { t, i18n } = useTranslation();
  const historyAvailable = isAiHistoryAvailable();

  const [messages, setMessages] = useState<AiChatMessage[]>(() => loadAiChatState().messages);
  const [activeId, setActiveId] = useState<string | null>(() => loadAiChatState().conversationId);
  const [isSending, setIsSending] = useState(false);
  const [history, setHistory] = useState<AiConversationSummary[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  const abortRef = useRef<AbortController | null>(null);
  const sendingRef = useRef(false);
  const activeIdRef = useRef<string | null>(activeId);
  const messagesRef = useRef<AiChatMessage[]>(messages);
  const pendingCreateRef = useRef<Promise<AiConversationFull> | null>(null);
  const saveChainRef = useRef<Promise<unknown>>(Promise.resolve());
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const persistPendingRef = useRef<AiChatMessage[] | null>(null);
  const chunkBufferRef = useRef('');
  const chunkTargetIdRef = useRef<string | null>(null);
  const rafRef = useRef<number | null>(null);

  useEffect(() => {
    activeIdRef.current = activeId;
  }, [activeId]);

  useEffect(() => {
    messagesRef.current = messages;
  }, [messages]);

  const flushPersist = useCallback(() => {
    const snapshot = persistPendingRef.current;
    persistPendingRef.current = null;
    if (persistTimerRef.current) {
      clearTimeout(persistTimerRef.current);
      persistTimerRef.current = null;
    }
    if (!historyAvailable || !snapshot || snapshot.length === 0) return;
    saveChainRef.current = saveChainRef.current
      .catch(() => undefined)
      .then(async () => {
        try {
          let id = activeIdRef.current;
          if (!id) {
            if (!pendingCreateRef.current) {
              pendingCreateRef.current = createConversation().finally(() => {
                pendingCreateRef.current = null;
              });
            }
            const created = await pendingCreateRef.current;
            id = created.id;
            activeIdRef.current = id;
            setActiveId(id);
          }
          const saved = await saveConversation(id, { messages: snapshot });
          setHistory((prev) => {
            const others = prev.filter((item) => item.id !== saved.id);
            return [{ id: saved.id, title: saved.title, updated_at: saved.updated_at }, ...others];
          });
        } catch {
          /* best-effort */
        }
      });
  }, [historyAvailable]);

  const schedulePersist = useCallback(
    (nextMessages: AiChatMessage[]) => {
      if (!historyAvailable || nextMessages.length === 0) return;
      persistPendingRef.current = nextMessages;
      if (persistTimerRef.current) clearTimeout(persistTimerRef.current);
      persistTimerRef.current = setTimeout(flushPersist, PERSIST_DEBOUNCE_MS);
    },
    [flushPersist, historyAvailable],
  );

  useEffect(() => {
    saveAiChatState({ conversationId: activeId, messages });
  }, [activeId, messages]);

  useEffect(
    () => () => {
      abortRef.current?.abort();
      flushPersist();
      if (rafRef.current != null) cancelAnimationFrame(rafRef.current);
    },
    [flushPersist],
  );

  const refreshHistory = useCallback(async () => {
    if (!historyAvailable) return;
    setHistoryLoading(true);
    try {
      setHistory(await listConversations());
    } catch {
      /* silent */
    } finally {
      setHistoryLoading(false);
    }
  }, [historyAvailable]);

  const startNewConversation = useCallback(() => {
    abortRef.current?.abort();
    setIsSending(false);
    sendingRef.current = false;
    setActiveId(null);
    activeIdRef.current = null;
    pendingCreateRef.current = null;
    setMessages([]);
    clearAiChatState();
    persistPendingRef.current = null;
    if (persistTimerRef.current) {
      clearTimeout(persistTimerRef.current);
      persistTimerRef.current = null;
    }
  }, []);

  const selectConversation = useCallback(
    async (id: string) => {
      if (!historyAvailable) return;
      abortRef.current?.abort();
      abortRef.current = null;
      persistPendingRef.current = null;
      if (persistTimerRef.current) {
        clearTimeout(persistTimerRef.current);
        persistTimerRef.current = null;
      }
      pendingCreateRef.current = null;
      setIsSending(false);
      sendingRef.current = false;
      chunkBufferRef.current = '';
      chunkTargetIdRef.current = null;
      if (rafRef.current != null) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }
      try {
        const conv = await getConversation(id);
        const nextMessages = conv.messages ?? [];
        activeIdRef.current = conv.id;
        messagesRef.current = nextMessages;
        setActiveId(conv.id);
        setMessages(nextMessages);
      } catch {
        setHistory((prev) => prev.filter((item) => item.id !== id));
      }
    },
    [historyAvailable],
  );

  const deleteConversation = useCallback(
    async (id: string) => {
      if (!historyAvailable) return;
      setHistory((prev) => prev.filter((item) => item.id !== id));
      try {
        await apiDeleteConversation(id);
      } catch {
        void refreshHistory();
      }
      if (activeIdRef.current === id) {
        startNewConversation();
      }
    },
    [historyAvailable, refreshHistory, startNewConversation],
  );

  const flushChunks = useCallback(() => {
    rafRef.current = null;
    const buffer = chunkBufferRef.current;
    const target = chunkTargetIdRef.current;
    if (!buffer || !target) return;
    chunkBufferRef.current = '';
    setMessages((prev) => prev.map((m) => (m.id === target ? { ...m, content: m.content + buffer } : m)));
  }, []);

  const stopStreaming = useCallback(() => {
    abortRef.current?.abort();
  }, []);

  const sendMessage = useCallback(
    async (raw: string) => {
      const text = raw.trim();
      if (!text || sendingRef.current) return;
      sendingRef.current = true;

      const priorMessages = messagesRef.current;
      const userMessage: AiChatMessage = {
        id: newId(),
        role: 'user',
        content: text,
        createdAt: Date.now(),
      };
      const assistantId = newId();
      const assistantPlaceholder: AiChatMessage = {
        id: assistantId,
        role: 'assistant',
        content: '',
        createdAt: Date.now(),
      };

      const nextMessages = [...priorMessages, userMessage, assistantPlaceholder];
      setMessages(nextMessages);
      setIsSending(true);
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;

      const outboundMessages: { role: AiChatRole; content: string }[] = [...priorMessages, userMessage]
        .filter((m) => m.content.trim() !== '' && (m.role === 'user' || m.status !== 'error'))
        .slice(-MAX_HISTORY_TURNS)
        .map(({ role, content }) => ({ role: role as AiChatRole, content }));

      chunkTargetIdRef.current = assistantId;
      try {
        const { reply, conversationId: nextConversationId } = await streamAiChatMessage(
          {
            messages: withInterpretationHint(outboundMessages, i18n.language),
            locale: i18n.language,
            conversation_id: activeIdRef.current,
          },
          {
            onDelta: (chunk) => {
              chunkBufferRef.current += chunk;
              if (rafRef.current == null) {
                rafRef.current = requestAnimationFrame(flushChunks);
              }
            },
            onRevise: (finalReply) => {
              if (rafRef.current != null) {
                cancelAnimationFrame(rafRef.current);
                rafRef.current = null;
              }
              chunkBufferRef.current = '';
              setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)));
            },
            onPlan: (steps) => {
              setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, plan: steps } : m)));
            },
            onToolStart: (name) => {
              setMessages((prev) =>
                prev.map((m) =>
                  m.id === assistantId
                    ? { ...m, tools: [...(m.tools ?? []), { name }] }
                    : m,
                ),
              );
            },
            onToolEnd: (name, ok, preview) => {
              setMessages((prev) =>
                prev.map((m) => {
                  if (m.id !== assistantId) return m;
                  const tools = [...(m.tools ?? [])];
                  for (let i = tools.length - 1; i >= 0; i -= 1) {
                    if (tools[i].name === name && tools[i].ok === undefined) {
                      tools[i] = { ...tools[i], ok, preview };
                      return { ...m, tools };
                    }
                  }
                  return { ...m, tools: [...tools, { name, ok, preview }] };
                }),
              );
            },
          },
          controller.signal,
        );

        if (rafRef.current != null) {
          cancelAnimationFrame(rafRef.current);
          rafRef.current = null;
        }
        chunkBufferRef.current = '';

        if (nextConversationId && !activeIdRef.current) {
          activeIdRef.current = nextConversationId;
          setActiveId(nextConversationId);
        }

        setMessages((prev) => {
          const updated = prev.map((m) => (m.id === assistantId ? { ...m, content: reply } : m));
          schedulePersist(updated);
          return updated;
        });
      } catch (err: unknown) {
        if (isAbortErr(err, controller.signal)) {
          setMessages((prev) => {
            schedulePersist(prev);
            return prev;
          });
          return;
        }

        const status = (err as { status?: number })?.status;
        const code = (err as { code?: string })?.code;
        const serverMessage = err instanceof Error ? err.message : 'Erreur inconnue';

        const codeKeyMap: Record<string, string> = {
          circuit_open: 'aiChat.errors.circuitOpen',
          rate_limited: 'aiChat.errors.rateLimited',
          quota_exceeded: 'aiChat.errors.quotaExceeded',
          upstream_auth: 'aiChat.errors.upstreamAuth',
          upstream_error: 'aiChat.errors.upstreamError',
          model_not_found: 'aiChat.errors.modelNotFound',
          timeout: 'aiChat.errors.timeout',
          network: 'aiChat.errors.network',
          empty_reply: 'aiChat.errors.emptyReply',
          ai_error: 'aiChat.errors.generic',
        };

        const message =
          (code && codeKeyMap[code] ? t(codeKeyMap[code]) : null) ||
          (status === 404 || status === 501
            ? t('aiChat.errors.notConfigured')
            : status === 429
            ? t('aiChat.errors.rateLimited')
            : status === 504
            ? t('aiChat.errors.timeout')
            : serverMessage && serverMessage !== 'Request failed'
            ? serverMessage
            : t('aiChat.errors.generic'));

        setMessages((prev) => {
          const assistant = prev.find((m) => m.id === assistantId);
          const partial = assistant?.content.trim();
          const next: AiChatMessage[] = partial
            ? prev.map((m) => (m.id === assistantId ? { ...m, content: partial } : m))
            : [
                ...prev.filter((m) => m.id !== assistantId),
                {
                  id: assistantId,
                  role: 'assistant' as const,
                  content: message,
                  createdAt: Date.now(),
                  status: 'error' as const,
                },
              ];
          schedulePersist(next);
          return next;
        });
      } finally {
        setIsSending(false);
        sendingRef.current = false;
        chunkTargetIdRef.current = null;
      }
    },
    [flushChunks, i18n.language, schedulePersist, t],
  );

  const regenerateLastAnswer = useCallback(async () => {
    if (sendingRef.current) return;
    const current = messagesRef.current;
    let assistantIdx = -1;
    for (let i = current.length - 1; i >= 0; i -= 1) {
      if (current[i].role === 'assistant') {
        assistantIdx = i;
        break;
      }
    }
    if (assistantIdx < 0) return;

    let userIdx = -1;
    for (let i = assistantIdx - 1; i >= 0; i -= 1) {
      if (current[i].role === 'user' && current[i].content.trim() !== '') {
        userIdx = i;
        break;
      }
    }
    if (userIdx < 0) return;

    sendingRef.current = true;
    const assistantId = current[assistantIdx].id;
    const cleared = current.map((m, i) =>
      i === assistantIdx
        ? { ...m, content: '', rating: undefined, status: undefined, createdAt: Date.now() }
        : m,
    );
    messagesRef.current = cleared;
    setMessages(cleared);
    setIsSending(true);

    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    const outboundMessages: { role: AiChatRole; content: string }[] = current
      .slice(0, userIdx + 1)
      .filter((m) => m.content.trim() !== '' && (m.role === 'user' || m.status !== 'error'))
      .slice(-MAX_HISTORY_TURNS)
      .map(({ role, content }) => ({ role: role as AiChatRole, content }));
    try {
      const { reply, conversationId: nextConversationId } = await streamAiChatMessage(
        {
          messages: withInterpretationHint(outboundMessages, i18n.language),
          locale: i18n.language,
          conversation_id: activeIdRef.current,
        },
        {
          onDelta: (chunk) => {
            chunkBufferRef.current += chunk;
            if (rafRef.current == null) {
              rafRef.current = requestAnimationFrame(flushChunks);
            }
          },
          onRevise: (finalReply) => {
            if (rafRef.current != null) {
              cancelAnimationFrame(rafRef.current);
              rafRef.current = null;
            }
            chunkBufferRef.current = '';
            setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)));
          },
          onPlan: (steps) => {
            setMessages((prev) => prev.map((m) => (m.id === assistantId ? { ...m, plan: steps } : m)));
          },
          onToolStart: (name) => {
            setMessages((prev) =>
              prev.map((m) =>
                m.id === assistantId
                  ? { ...m, tools: [...(m.tools ?? []), { name }] }
                  : m,
              ),
            );
          },
          onToolEnd: (name, ok, preview) => {
            setMessages((prev) =>
              prev.map((m) => {
                if (m.id !== assistantId) return m;
                const tools = [...(m.tools ?? [])];
                for (let i = tools.length - 1; i >= 0; i -= 1) {
                  if (tools[i].name === name && tools[i].ok === undefined) {
                    tools[i] = { ...tools[i], ok, preview };
                    return { ...m, tools };
                  }
                }
                return { ...m, tools: [...tools, { name, ok, preview }] };
              }),
            );
          },
        },
        controller.signal,
      );

      if (rafRef.current != null) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }
      chunkBufferRef.current = '';

      if (nextConversationId && !activeIdRef.current) {
        activeIdRef.current = nextConversationId;
        setActiveId(nextConversationId);
      }

      setMessages((prev) => {
        const updated = prev.map((m) => (m.id === assistantId ? { ...m, content: reply } : m));
        schedulePersist(updated);
        return updated;
      });
    } catch (err: unknown) {
      if (isAbortErr(err, controller.signal)) {
        setMessages((prev) => {
          schedulePersist(prev);
          return prev;
        });
        return;
      }
      const status = (err as { status?: number })?.status;
      const code = (err as { code?: string })?.code;
      const serverMessage = err instanceof Error ? err.message : 'Erreur inconnue';
      const codeKeyMap: Record<string, string> = {
        circuit_open: 'aiChat.errors.circuitOpen',
        rate_limited: 'aiChat.errors.rateLimited',
        quota_exceeded: 'aiChat.errors.quotaExceeded',
        upstream_auth: 'aiChat.errors.upstreamAuth',
        upstream_error: 'aiChat.errors.upstreamError',
        model_not_found: 'aiChat.errors.modelNotFound',
        timeout: 'aiChat.errors.timeout',
        network: 'aiChat.errors.network',
        empty_reply: 'aiChat.errors.emptyReply',
        ai_error: 'aiChat.errors.generic',
      };
      const message =
        (code && codeKeyMap[code] ? t(codeKeyMap[code]) : null) ||
        (status === 404 || status === 501
          ? t('aiChat.errors.notConfigured')
          : status === 429
          ? t('aiChat.errors.rateLimited')
          : status === 504
          ? t('aiChat.errors.timeout')
          : serverMessage && serverMessage !== 'Request failed'
          ? serverMessage
          : t('aiChat.errors.generic'));

      setMessages((prev) => {
        const next = prev.map((m) =>
          m.id === assistantId ? { ...m, content: message, status: 'error' as const } : m,
        );
        schedulePersist(next);
        return next;
      });
    } finally {
      setIsSending(false);
      sendingRef.current = false;
      chunkTargetIdRef.current = null;
    }
  }, [flushChunks, i18n.language, schedulePersist, t]);

  const rateMessage = useCallback(
    async (messageId: string, rating: AiChatRating) => {
      let previous: AiChatRating | undefined;
      setMessages((prev) =>
        prev.map((m) => {
          if (m.id !== messageId) return m;
          previous = m.rating;
          return { ...m, rating };
        }),
      );

      const current = messagesRef.current;
      const target = current.find((m) => m.id === messageId);
      if (!target || target.role !== 'assistant') return;

      const idx = current.findIndex((m) => m.id === messageId);
      const question =
        idx > 0
          ? current
              .slice(0, idx)
              .reverse()
              .find((m) => m.role === 'user')?.content
          : undefined;

      const isUuid = (v: string | null | undefined) =>
        !!v && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);

      try {
        await submitAiChatFeedback({
          messageId: messageId.slice(0, 64),
          rating,
          conversationId: isUuid(activeIdRef.current) ? activeIdRef.current : undefined,
          locale: i18n.language,
          question: question?.slice(0, 4000),
          answer: target.content.slice(0, 8000),
        });
        schedulePersist(messagesRef.current);
      } catch {
        setMessages((prev) =>
          prev.map((m) => (m.id === messageId ? { ...m, rating: previous } : m)),
        );
        throw new Error(t('aiChat.feedback.failed', { defaultValue: 'Feedback not saved' }));
      }
    },
    [i18n.language, t],
  );

  const value = useMemo(
    () => ({
      messages,
      isSending,
      sendMessage,
      regenerateLastAnswer,
      stopStreaming,
      startNewConversation,
      rateMessage,
      historyAvailable,
      history,
      historyLoading,
      activeId,
      refreshHistory,
      selectConversation,
      deleteConversation,
    }),
    [activeId, deleteConversation, history, historyAvailable, historyLoading, isSending, messages, rateMessage, refreshHistory, selectConversation, sendMessage, startNewConversation, stopStreaming, regenerateLastAnswer],
  );

  return <AiChatContext.Provider value={value}>{children}</AiChatContext.Provider>;
}

export function useAiChat(): AiChatContextValue {
  const ctx = useContext(AiChatContext);
  if (!ctx) throw new Error('useAiChat must be used within AiChatProvider');
  return ctx;
}
