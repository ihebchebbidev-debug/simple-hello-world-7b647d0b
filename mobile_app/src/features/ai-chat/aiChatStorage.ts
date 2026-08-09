import type { AiChatPersistedState } from './types';

const STORAGE_KEY = 'agrysync.ai-chat.v1';
const MAX_MESSAGES = 80;

export function loadAiChatState(): AiChatPersistedState {
  if (typeof localStorage === 'undefined') {
    return { conversationId: null, messages: [] };
  }
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return { conversationId: null, messages: [] };
    const parsed = JSON.parse(raw) as AiChatPersistedState;
    if (!parsed || !Array.isArray(parsed.messages)) {
      return { conversationId: null, messages: [] };
    }
    return {
      conversationId: parsed.conversationId ?? null,
      messages: parsed.messages.slice(-MAX_MESSAGES),
    };
  } catch {
    return { conversationId: null, messages: [] };
  }
}

export function saveAiChatState(state: AiChatPersistedState): void {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        conversationId: state.conversationId,
        messages: state.messages.slice(-MAX_MESSAGES),
      }),
    );
  } catch {
    // private mode / quota
  }
}

export function clearAiChatState(): void {
  if (typeof localStorage === 'undefined') return;
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // ignore
  }
}
