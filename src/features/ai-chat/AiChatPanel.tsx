import { useEffect, useState } from 'react';
import { History, MessageSquarePlus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { useAiChat } from './AiChatProvider';
import AiChatComposer from './AiChatComposer';
import AiChatHistoryList from './AiChatHistoryList';
import AiChatMessageList from './AiChatMessageList';

export default function AiChatPanel() {
  const { t } = useTranslation();
  const {
    open,
    closeChat,
    messages,
    isSending,
    sendMessage,
    regenerateLastAnswer,
    startNewConversation,
    rateMessage,
    historyAvailable,
  } = useAiChat();
  const [showHistory, setShowHistory] = useState(false);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') closeChat();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [closeChat, open]);

  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = prev;
    };
  }, [open]);

  // Reset the panel to the chat view whenever the drawer is closed so the
  // next open starts on the active conversation, not the history list.
  useEffect(() => {
    if (!open) setShowHistory(false);
  }, [open]);

  if (!open) return null;

  return (
    <>
      {/* Backdrop — desktop/tablet only; mobile is full-screen */}
      <button
        type="button"
        aria-label={t('aiChat.close')}
        className="fixed inset-0 z-[55] hidden bg-black/45 backdrop-blur-[1px] animate-fade-in sm:block"
        onClick={closeChat}
      />

      <aside
        role="dialog"
        aria-modal="true"
        aria-labelledby="ai-chat-title"
        className="fixed inset-0 z-[60] flex max-h-[100dvh] flex-col bg-[hsl(var(--surface-container-low))] animate-ai-chat-slide-in sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[min(100vw,440px)] sm:max-h-none sm:border-l sm:border-border/40 sm:shadow-2xl sm:animate-ai-chat-slide-in-right"
      >
        <header
          className="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-border/40 bg-[hsl(var(--surface-container))] px-3 sm:px-4"
          style={{ paddingTop: 'env(safe-area-inset-top, 0px)' }}
        >
          <div className="flex min-w-0 items-center gap-2.5">
            <div className="min-w-0">
              <h2 id="ai-chat-title" className="truncate text-sm font-semibold text-foreground">
                {showHistory ? t('aiChat.history.title') : t('aiChat.title')}
              </h2>
              <p className="truncate text-[11px] text-muted-foreground">{t('aiChat.subtitle')}</p>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-1">
            {historyAvailable ? (
              <button
                type="button"
                onClick={() => setShowHistory((v) => !v)}
                aria-label={showHistory ? t('aiChat.history.hide') : t('aiChat.history.show')}
                title={showHistory ? t('aiChat.history.hide') : t('aiChat.history.show')}
                aria-pressed={showHistory}
                className={`inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-md transition-colors hover:bg-[hsl(var(--surface-bright))] hover:text-foreground ${
                  showHistory ? 'bg-[hsl(var(--surface-bright))] text-foreground' : 'text-foreground/70'
                }`}
              >
                <History className="h-5 w-5" />
              </button>
            ) : null}

            <button
              type="button"
              onClick={() => {
                startNewConversation();
                setShowHistory(false);
              }}
              disabled={isSending || (messages.length === 0 && !showHistory)}
              aria-label={t('aiChat.newChat')}
              title={t('aiChat.newChat')}
              className="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-md text-foreground/70 transition-colors hover:bg-[hsl(var(--surface-bright))] hover:text-foreground disabled:opacity-40"
            >
              <MessageSquarePlus className="h-5 w-5" />
            </button>

            <button
              type="button"
              onClick={closeChat}
              aria-label={t('aiChat.close')}
              title={t('aiChat.close')}
              className="inline-flex h-11 w-11 touch-manipulation items-center justify-center rounded-md text-foreground/70 transition-colors hover:bg-[hsl(var(--surface-bright))] hover:text-foreground"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </header>

        {showHistory ? (
          <AiChatHistoryList onSelect={() => setShowHistory(false)} />
        ) : (
          <>
            <AiChatMessageList
              messages={messages}
              isSending={isSending}
              onSuggestion={(text) => void sendMessage(text)}
              onRate={(id, rating) => void rateMessage(id, rating)}
              onRegenerate={() => void regenerateLastAnswer()}
            />
            <AiChatComposer
              focusOnMount={open}
              loading={isSending}
              disabled={isSending}
              onSend={(text) => void sendMessage(text)}
            />
          </>
        )}
      </aside>
    </>
  );
}