import { useTranslation } from 'react-i18next';
import { MessageSquare, Trash2 } from 'lucide-react';

import { useAiChat } from './AiChatProvider';

function formatDate(iso: string | null, locale: string): string {
  if (!iso) return '';
  try {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  } catch {
    return '';
  }
}

export default function AiChatHistoryList({ onSelect }: { onSelect: () => void }) {
  const { t, i18n } = useTranslation();
  const { history, historyLoading, activeId, selectConversation, deleteConversation } = useAiChat();

  if (historyLoading && history.length === 0) {
    return (
      <div className="flex-1 overflow-y-auto p-4 text-sm text-muted-foreground">
        {t('aiChat.history.loading')}
      </div>
    );
  }

  if (history.length === 0) {
    return (
      <div className="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center">
        <MessageSquare className="h-6 w-6 text-muted-foreground/60" aria-hidden />
        <p className="text-sm text-muted-foreground">{t('aiChat.history.empty')}</p>
      </div>
    );
  }

  return (
    <ul className="flex-1 divide-y divide-border/40 overflow-y-auto">
      {history.map((conv) => {
        const isActive = conv.id === activeId;
        return (
          <li
            key={conv.id}
            className={`group flex items-start gap-2 px-3 py-2.5 transition-colors ${
              isActive ? 'bg-[hsl(var(--surface-container-highest))]' : 'hover:bg-[hsl(var(--surface-bright))]'
            }`}
          >
            <button
              type="button"
              onClick={() => {
                void selectConversation(conv.id);
                onSelect();
              }}
              className="flex min-w-0 flex-1 flex-col items-start text-left"
              title={conv.title}
            >
              <span className="line-clamp-2 w-full text-sm font-medium text-foreground">{conv.title}</span>
              <span className="mt-0.5 text-[11px] text-muted-foreground">
                {formatDate(conv.updated_at, i18n.language)}
              </span>
            </button>
            <button
              type="button"
              onClick={() => {
                if (confirm(t('aiChat.history.confirmDelete'))) {
                  void deleteConversation(conv.id);
                }
              }}
              aria-label={t('aiChat.history.delete')}
              title={t('aiChat.history.delete')}
              className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground/70 transition-colors hover:bg-destructive/10 hover:text-destructive"
            >
              <Trash2 className="h-4 w-4" aria-hidden />
            </button>
          </li>
        );
      })}
    </ul>
  );
}