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
  type AiConversationFull,
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
  regenerateLastAnswer: () => Promise<void>;
  stopStreaming: () => void;
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

// Cap the transcript sent to the backend so cost/latency don't scale linearly
// with conversation length and we stay under provider context limits.
// Older turns remain visible in the UI and persisted server-side.
const MAX_HISTORY_TURNS = 20;

// Preface the LAST user turn with an interpretation hint so the assistant is
// tolerant of typos, missing accents, informal phrasing, and incomplete queries
// (e.g. "nombre utilasateur" → "Nombre d'utilisateurs"). Only the outbound
// payload is modified; the message stored in local/server history is untouched.
type OutboundMessage = { role: 'user' | 'assistant'; content: string };
function withInterpretationHint(messages: OutboundMessage[], locale: string): OutboundMessage[] {
  if (messages.length === 0) return messages;
  const lastIdx = messages.length - 1;
  const last = messages[lastIdx];
  if (last.role !== 'user') return messages;
  const isFr = (locale || '').toLowerCase().startsWith('fr');
  const hint = isFr
    ? "[Instruction interne — ne pas répéter à l'utilisateur]\nInterprète la requête suivante avec bienveillance : corrige mentalement les fautes de frappe, les accents manquants, les abréviations et les phrases incomplètes ou télégraphiques. Déduis l'intention la plus probable dans le contexte de l'application (parcelles, utilisateurs, rapports, irrigation, catalogue…) puis réponds directement à cette intention en français, sans demander de reformulation sauf en cas d'ambiguïté réelle.\n\nRequête utilisateur :\n"
    : "[Internal instruction — do not repeat to the user]\nInterpret the following request charitably: mentally fix typos, missing accents/diacritics, abbreviations, and incomplete or telegraphic phrasing. Infer the most likely intent within this app's context (plots, users, reports, irrigation, catalog…) and answer that intent directly, without asking for a rephrase unless the request is genuinely ambiguous.\n\nUser request:\n";
  return [
    ...messages.slice(0, lastIdx),
    { role: 'user', content: hint + last.content },
  ];
}

// Debounce persistence writes so streaming completion + rating flips + rapid
// back-to-back turns coalesce into fewer PATCHes.
const PERSIST_DEBOUNCE_MS = 500;

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
  const name = (err as { name?: string } | null)?.name;
  return name === 'AbortError' || err instanceof DOMException && err.name === 'AbortError';
}

export function AiChatProvider({ children }: { children: ReactNode }) {
  const { t, i18n } = useTranslation();

  const historyAvailable = isAiHistoryAvailable();

  const [open, setOpen] = useState(false);
  // Unified conversation id. `activeId` is both the server-side thread id sent
  // as `conversation_id` on every request AND the row we PATCH history into.
  // This closes the fork bug where server context and stored history diverged
  // when selecting a past conversation.
  const [activeId, setActiveId] = useState<string | null>(() => loadAiChatState().conversationId);
  const [messages, setMessages] = useState<AiChatMessage[]>(() => loadAiChatState().messages);
  const [isSending, setIsSending] = useState(false);

  const abortRef = useRef<AbortController | null>(null);
  // Synchronous send guard — closes the double-submit race where two clicks in
  // the same tick both read `isSending === false` from closure.
  const sendingRef = useRef(false);
  // Mirror state into refs so callbacks stay stable (identity doesn't churn
  // per streamed token) and always read the latest value.
  const activeIdRef = useRef<string | null>(activeId);
  const messagesRef = useRef<AiChatMessage[]>(messages);
  useEffect(() => {
    activeIdRef.current = activeId;
  }, [activeId]);
  useEffect(() => {
    messagesRef.current = messages;
  }, [messages]);

  // In-flight createConversation() promise so parallel first-save attempts
  // share ONE server row instead of racing to create duplicates.
  const pendingCreateRef = useRef<Promise<AiConversationFull> | null>(null);
  // Per-conversation write chain — serializes PATCHes so rating/persist calls
  // don't overwrite each other via last-write-wins.
  const saveChainRef = useRef<Promise<unknown>>(Promise.resolve());
  // Pending debounce for persistMessages.
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const persistPendingRef = useRef<AiChatMessage[] | null>(null);

  // Streaming chunk buffer flushed via rAF so setMessages fires ~60/s max
  // instead of per-token, avoiding mobile jitter and cutting render load.
  const chunkBufferRef = useRef<string>('');
  const chunkTargetIdRef = useRef<string | null>(null);
  const rafRef = useRef<number | null>(null);

  const [history, setHistory] = useState<AiConversationSummary[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // ---------- persistence ----------

  const flushPersist = useCallback(() => {
    const snapshot = persistPendingRef.current;
    persistPendingRef.current = null;
    if (persistTimerRef.current) {
      clearTimeout(persistTimerRef.current);
      persistTimerRef.current = null;
    }
    if (!historyAvailable || !snapshot || snapshot.length === 0) return;

    // Chain all writes through a single serialized promise per client so
    // concurrent rate/persist calls can't overwrite each other.
    saveChainRef.current = saveChainRef.current
      .catch(() => undefined)
      .then(async () => {
        try {
          let id = activeIdRef.current;
          if (!id) {
            if (!pendingCreateRef.current) {
              pendingCreateRef.current = createConversation().finally(() => {
                // Clear only after we've adopted the id below.
              });
            }
            const created = await pendingCreateRef.current;
            pendingCreateRef.current = null;
            id = created.id;
            activeIdRef.current = id;
            setActiveId(id);
          }
          const saved = await saveConversation(id, { messages: snapshot });
          setHistory((prev) => {
            const others = prev.filter((c) => c.id !== saved.id);
            return [{ id: saved.id, title: saved.title, updated_at: saved.updated_at }, ...others];
          });
        } catch {
          /* persistence is best-effort */
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

  // ---------- local storage ----------

  useEffect(() => {
    saveAiChatState({ conversationId: activeId, messages });
  }, [activeId, messages]);

  // Flush any pending debounce on unmount.
  useEffect(
    () => () => {
      abortRef.current?.abort();
      flushPersist();
      if (rafRef.current != null) cancelAnimationFrame(rafRef.current);
    },
    [flushPersist],
  );

  // ---------- open / close ----------

  const openChat = useCallback(() => setOpen(true), []);
  // Closing aborts any in-flight stream so we don't keep mutating hidden state
  // (and we flush pending saves so nothing is lost).
  const closeChat = useCallback(() => {
    abortRef.current?.abort();
    setIsSending(false);
    sendingRef.current = false;
    flushPersist();
    setOpen(false);
  }, [flushPersist]);
  const toggleChat = useCallback(() => setOpen((v) => !v), []);

  // ---------- history ----------

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
    flushPersist();
    setActiveId(null);
    activeIdRef.current = null;
    pendingCreateRef.current = null;
    setMessages([]);
    setIsSending(false);
    sendingRef.current = false;
    clearAiChatState();
  }, [flushPersist]);

  const selectConversation = useCallback(
    async (id: string) => {
      if (!historyAvailable) return;
      // Abort any in-flight stream so its onFinish can't adopt a new server id
      // into the conversation we're about to select.
      abortRef.current?.abort();
      abortRef.current = null;
      // Drop any queued persist from the *previous* conversation — it would
      // otherwise flush against the newly-selected activeIdRef and write the
      // wrong messages into the wrong row.
      persistPendingRef.current = null;
      if (persistTimerRef.current) {
        clearTimeout(persistTimerRef.current);
        persistTimerRef.current = null;
      }
      // Discard any pending create promise — its resolved id must not be
      // adopted after we've pinned the active id to a real, existing row.
      pendingCreateRef.current = null;
      setIsSending(false);
      sendingRef.current = false;
      // Reset streaming buffer/target so a late rAF flush can't append tokens
      // from the prior stream into the newly-selected transcript.
      chunkBufferRef.current = '';
      chunkTargetIdRef.current = null;
      if (rafRef.current != null) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }
      try {
        const conv = await getConversation(id);
        const nextMessages = conv.messages ?? [];
        // Unify: use conv.id as BOTH the server thread id and the history row.
        // Update refs SYNCHRONOUSLY so a sendMessage fired in the same tick
        // reads the selected id AND the selected transcript — otherwise the
        // useEffect that mirrors state→ref runs after render and sendMessage
        // ships the previous conversation's history under the new id.
        activeIdRef.current = conv.id;
        messagesRef.current = nextMessages;
        setActiveId(conv.id);
        setMessages(nextMessages);
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

  // ---------- streaming ----------

  const flushChunks = useCallback(() => {
    rafRef.current = null;
    const buf = chunkBufferRef.current;
    const target = chunkTargetIdRef.current;
    if (!buf || !target) return;
    chunkBufferRef.current = '';
    setMessages((prev) =>
      prev.map((m) => (m.id === target ? { ...m, content: m.content + buf } : m)),
    );
  }, []);

  const stopStreaming = useCallback(() => {
    abortRef.current?.abort();
  }, []);

  // sendMessage is intentionally stable: it reads messages/activeId/locale via
  // refs so it doesn't churn per streamed token, which invalidates memoized
  // consumers (keyboard shortcuts, memoized children).
  const sendMessage = useCallback(
    async (raw: string) => {
      const text = raw.trim();
      // Synchronous re-entry guard — two clicks in the same tick both hit here.
      if (!text || sendingRef.current) return;
      sendingRef.current = true;

      // Snapshot the transcript from the ref at call time (documented — the
      // request body uses this exact array, not any later mutation from
      // streaming setMessages calls below).
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

      // Cap outbound history to the most recent N turns and drop empties/errors.
      const outboundMessages = [...priorMessages, userMessage]
        .filter(
          (m) =>
            m.content.trim() !== '' &&
            (m.role === 'user' || (m.role === 'assistant' && m.status !== 'error')),
        )
        .slice(-MAX_HISTORY_TURNS)
        .map(({ role, content }) => ({ role, content }));

      chunkTargetIdRef.current = assistantId;

      try {
        const { reply, conversationId: nextConversationId } = await streamAiChatMessage(
          {
            messages: withInterpretationHint(outboundMessages, i18n.language),
            locale: i18n.language,
            conversation_id: activeIdRef.current,
          },
          (chunk) => {
            chunkBufferRef.current += chunk;
            if (rafRef.current == null) {
              rafRef.current = requestAnimationFrame(flushChunks);
            }
          },
          controller.signal,
          (finalReply) => {
            // Server-side self-check produced a corrected reply; replace draft.
            if (rafRef.current != null) {
              cancelAnimationFrame(rafRef.current);
              rafRef.current = null;
            }
            chunkBufferRef.current = '';
            setMessages((prev) =>
              prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)),
            );
          },
        );

        // Ensure any tail buffered tokens are applied before final replace.
        if (rafRef.current != null) {
          cancelAnimationFrame(rafRef.current);
          rafRef.current = null;
        }
        chunkBufferRef.current = '';

        // Only adopt a server-issued id when we started this send with NO
        // active conversation. If the user selected an existing thread, that
        // id is authoritative — ignore any different id the server echoes so
        // we can never fork the selected thread.
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
          // Persist whatever we streamed so far (best-effort).
          setMessages((prev) => {
            schedulePersist(prev);
            return prev;
          });
          return;
        }

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

  // Regenerate the LAST assistant answer in the transcript. Finds the most
  // recent assistant message, locates the user prompt immediately preceding
  // it, and re-streams into that same assistant id — no new bubble, no
  // duplicated user prompt. Anything after that assistant message (unusual,
  // but possible after edits) is preserved untouched.
  const regenerateLastAnswer = useCallback(async () => {
    if (sendingRef.current) return;

    const current = messagesRef.current;
    // Find last assistant index.
    let assistantIdx = -1;
    for (let i = current.length - 1; i >= 0; i--) {
      if (current[i].role === 'assistant') {
        assistantIdx = i;
        break;
      }
    }
    if (assistantIdx < 0) return;

    // The prompt to resend is the last user message BEFORE that assistant.
    let userIdx = -1;
    for (let i = assistantIdx - 1; i >= 0; i--) {
      if (current[i].role === 'user' && current[i].content.trim() !== '') {
        userIdx = i;
        break;
      }
    }
    if (userIdx < 0) return;

    sendingRef.current = true;

    const assistant = current[assistantIdx];
    const assistantId = assistant.id;

    // Clear the target assistant bubble (content, rating, error status) so the
    // UI shows the typing indicator and the old answer doesn't linger.
    const cleared: AiChatMessage[] = current.map((m, i) =>
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

    // Outbound history = everything up to and INCLUDING the user prompt we're
    // regenerating from. Drop the stale assistant answer and anything after.
    const outboundMessages = current
      .slice(0, userIdx + 1)
      .filter(
        (m) =>
          m.content.trim() !== '' &&
          (m.role === 'user' || (m.role === 'assistant' && m.status !== 'error')),
      )
      .slice(-MAX_HISTORY_TURNS)
      .map(({ role, content }) => ({ role, content }));

    chunkBufferRef.current = '';
    chunkTargetIdRef.current = assistantId;

    try {
      const { reply, conversationId: nextConversationId } = await streamAiChatMessage(
        {
          messages: withInterpretationHint(outboundMessages, i18n.language),
          locale: i18n.language,
          conversation_id: activeIdRef.current,
        },
        (chunk) => {
          chunkBufferRef.current += chunk;
          if (rafRef.current == null) {
            rafRef.current = requestAnimationFrame(flushChunks);
          }
        },
        controller.signal,
        (finalReply) => {
          if (rafRef.current != null) {
            cancelAnimationFrame(rafRef.current);
            rafRef.current = null;
          }
          chunkBufferRef.current = '';
          setMessages((prev) =>
            prev.map((m) => (m.id === assistantId ? { ...m, content: finalReply } : m)),
          );
        },
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

      // Read from ref so we see the latest transcript regardless of closure age.
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

      try {
        await submitAiChatFeedback({
          messageId,
          rating,
          conversationId: activeIdRef.current,
          locale: i18n.language,
          question,
          answer: target.content,
        });
        // Persist the rating on the conversation row too (server dedicated
        // feedback endpoint is canonical; this is a hint for reload UX).
        schedulePersist(messagesRef.current);
      } catch {
        setMessages((prev) =>
          prev.map((m) => (m.id === messageId ? { ...m, rating: previous } : m)),
        );
      }
    },
    [i18n.language, schedulePersist],
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
      regenerateLastAnswer,
      startNewConversation,
      stopStreaming,
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
