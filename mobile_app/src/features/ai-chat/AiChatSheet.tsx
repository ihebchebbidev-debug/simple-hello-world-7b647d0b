import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SendHorizontal, Sparkles, X } from 'lucide-react';
import AiChatMessageList from './AiChatMessageList';
import { useAiChat } from './AiChatProvider';

interface Props {
  open: boolean;
  onClose: () => void;
}

const AiChatSheet = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  const { messages, isSending, sendMessage, regenerateLastAnswer, startNewConversation, rateMessage } = useAiChat();
  const [input, setInput] = useState('');

  const handleSend = async () => {
    const text = input.trim();
    if (!text || isSending) return;
    setInput('');
    await sendMessage(text);
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
            onClick={startNewConversation}
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

      <div className="flex-1 overflow-y-auto px-4 py-5">
        <AiChatMessageList
          messages={messages}
          isSending={isSending}
          onSuggestion={(text) => setInput(text)}
          onRate={rateMessage}
          onRegenerate={regenerateLastAnswer}
        />
      </div>

      <div className="border-t border-border bg-[hsl(var(--surface-container))] px-3 py-3" style={{ paddingBottom: 'calc(env(safe-area-inset-bottom) + 0.75rem)' }}>
        <div className="flex items-end gap-2">
          <textarea
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
            className="flex-1 max-h-32 min-h-[42px] resize-none rounded-xl border border-border bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-[hsl(var(--primary))]"
          />
          <button
            type="button"
            onClick={() => void handleSend()}
            disabled={isSending || !input.trim()}
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

export default AiChatSheet;
