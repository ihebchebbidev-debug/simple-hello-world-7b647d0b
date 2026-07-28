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

import { streamAiChatMessage, submitAiChatFeedback } from '@/lib/aiChat';
import {
  createConversation,
  deleteConversation as apiDeleteConversation,
  getConversation,
  isAiHistoryAvailable,
  listConversations,
  saveConversation,
  type AiConversationSummary,
} from './aiConversations';
import { clearAiChatState, loadAiChatState, saveAiChatState } from './aiChatStorage';
import type { AiChatMessage, AiChatRating } from './types';

type AiChatContextValue = {
  open: boolean;
  openChat: () => void;
  closeChat: () => void;
  toggleChat: () => void;
  messages: AiChatMessage[];
  isSending: boolean;
  sendMessage: (text: string) => Promise<void>;
  startNewConversation: () => void;
  rateMessage: (messageId: string, rating: AiChatRating) => Promise<void>;
  // Server-backed history (only populated when the user is authenticated).
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
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
}

export function AiChatProvider({ children }: { children: ReactNode }) {
  const { t, i18n } = useTranslation();

  const [open, setOpen] = useState(false);
  const [conversationId, setConversationId] = useState<string | null>(() => loadAiChatState().conversationId);
  const [messages, setMessages] = useState<AiChatMessage[]>(() => loadAiChatState().messages);
  const [isSending, setIsSending] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  const historyAvailable = isAiHistoryAvailable();
  const [history, setHistory] = useState<AiConversationSummary[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);
  // Ref mirrors activeId so persistMessages can read the latest DB row id
  // without forcing sendMessage to re-close over it on every change.
  const activeIdRef = useRef<string | null>(null);
  activeIdRef.current = activeId;

  useEffect(() => {
    saveAiChatState({ conversationId, messages });
  }, [conversationId, messages]);

  useEffect(() => () => abortRef.current?.abort(), []);

  const openChat = useCallback(() => setOpen(true), []);
  const closeChat = useCallback(() => setOpen(false), []);
  const toggleChat = useCallback(() => setOpen((v) => !v), []);

  const refreshHistory = useCallback(async () => {
    if (!historyAvailable) return;
    setHistoryLoading(true);
    try {
      setHistory(await listConversations());
    } catch {
      /* silent — sidebar shows empty */
    } finally {
      setHistoryLoading(false);
    }
  }, [historyAvailable]);

  useEffect(() => {
    if (open && historyAvailable) void refreshHistory();
  }, [open, historyAvailable, refreshHistory]);

  const startNewConversation = useCallback(() => {
    abortRef.current?.abort();
    setConversationId(null);
    setMessages([]);
    setActiveId(null);
    setIsSending(false);
    clearAiChatState();
  }, []);

  const selectConversation = useCallback(
    async (id: string) => {
      if (!historyAvailable) return;
      abortRef.current?.abort();
      setIsSending(false);
      try {
        const conv = await getConversation(id);
        setActiveId(conv.id);
        setConversationId(null);
        setMessages(conv.messages ?? []);
      } catch {
        setHistory((prev) => prev.filter((c) => c.id !== id));
      }
    },
    [historyAvailable],
  );

  const deleteConversation = useCallback(
    async (id: string) => {
      if (!historyAvailable) return;
      setHistory((prev) => prev.filter((c) => c.id !== id));
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

  const persistMessages = useCallback(
    async (nextMessages: AiChatMessage[]) => {
      if (!historyAvailable || nextMessages.length === 0) return;
      try {
        let id = activeIdRef.current;
        if (!id) {
          const created = await createConversation();
          id = created.id;
          activeIdRef.current = id;
          setActiveId(id);
        }
        const saved = await saveConversation(id, { messages: nextMessages });
        setHistory((prev) => {
          const others = prev.filter((c) => c.id !== saved.id);
          return [{ id: saved.id, title: saved.title, updated_at: saved.updated_at }, ...others];
        });
      } catch {
        /* persistence is best-effort */
      }
    },
    [historyAvailable],
  );

  const sendMessage = useCallback(
    async (raw: string) => {
      const text = raw.trim();
      if (!text || isSending) return;

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

      const nextMessages = [...messages, userMessage, assistantPlaceholder];
      setMessages(nextMessages);
      setIsSending(true);

      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;

      try {
        const { reply, conversationId: nextConversationId } = await streamAiChatMessage(
          {
            messages: [...messages, userMessage]
              .filter(
                (m) =>
                  m.content.trim() !== '' &&
                  (m.role === 'user' || (m.role === 'assistant' && m.status !== 'error')),
              )
              .map(({ role, content }) => ({ role, content })),
            locale: i18n.language,
            conversation_id: conversationId,
          },
          (chunk) => {
            setMessages((prev) =>
              prev.map((m) => (m.id === assistantId ? { ...m, content: m.content + chunk } : m)),
            );
          },
          controller.signal,
          (finalReply) => {
            setMessages((prev) =>
              prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)),
            );
          },
        );

        if (nextConversationId) setConversationId(nextConversationId);

        setMessages((prev) => {
          const updated = prev.map((m) => (m.id === assistantId ? { ...m, content: reply } : m));
          void persistMessages(updated);
          return updated;
        });
      } catch (err: unknown) {
        if ((err as { name?: string })?.name === 'AbortError') return;

        const status = (err as { status?: number })?.status;
        const code = (err as { code?: string })?.code;
        const serverMessage = (err as Error)?.message;

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
          code && codeKeyMap[code]
            ? t(codeKeyMap[code])
            : status === 404 || status === 501
              ? t('aiChat.errors.notConfigured')
              : status === 429
                ? t('aiChat.errors.rateLimited')
                : status === 504
                  ? t('aiChat.errors.timeout')
                  : serverMessage && serverMessage !== 'Request failed'
                    ? serverMessage
                    : t('aiChat.errors.generic');

        setMessages((prev) => {
          const assistant = prev.find((m) => m.id === assistantId);
          const partial = assistant?.content.trim();

          const next: AiChatMessage[] = partial
            ? prev.map((m) => (m.id === assistantId ? { ...m, content: partial } : m))
            : [
                ...prev.filter((m) => m.id !== assistantId),
                {
                  id: assistantId,
                  role: 'assistant',
                  content: message,
                  createdAt: Date.now(),
                  status: 'error',
                },
              ];
          void persistMessages(next);
          return next;
        });
      } finally {
        setIsSending(false);
      }
    },
    [conversationId, i18n.language, isSending, messages, persistMessages, t],
  );

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

      const target = messages.find((m) => m.id === messageId);
      if (!target || target.role !== 'assistant') return;

      const idx = messages.findIndex((m) => m.id === messageId);
      const question =
        idx > 0 ? messages.slice(0, idx).reverse().find((m) => m.role === 'user')?.content : undefined;

      try {
        await submitAiChatFeedback({
          messageId,
          rating,
          conversationId,
          locale: i18n.language,
          question,
          answer: target.content,
        });
        setMessages((prev) => {
          void persistMessages(prev);
          return prev;
        });
      } catch {
        setMessages((prev) => prev.map((m) => (m.id === messageId ? { ...m, rating: previous } : m)));
      }
    },
    [conversationId, i18n.language, messages, persistMessages],
  );

  const value = useMemo(
    () => ({
      open,
      openChat,
      closeChat,
      toggleChat,
      messages,
      isSending,
      sendMessage,
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
    [
      activeId,
      closeChat,
      deleteConversation,
      history,
      historyAvailable,
      historyLoading,
      isSending,
      messages,
      open,
      openChat,
      rateMessage,
      refreshHistory,
      selectConversation,
      sendMessage,
      startNewConversation,
      toggleChat,
    ],
  );

  return <AiChatContext.Provider value={value}>{children}</AiChatContext.Provider>;
}

export function useAiChat(): AiChatContextValue {
  const ctx = useContext(AiChatContext);
  if (!ctx) throw new Error('useAiChat must be used within AiChatProvider');
  return ctx;
}