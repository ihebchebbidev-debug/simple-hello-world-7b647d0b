import type { AiChatPersistedState } from './types';

const STORAGE_KEY = 'agrysync.ai-chat.v1';

export function loadAiChatState(): AiChatPersistedState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return { conversationId: null, messages: [] };
    const parsed = JSON.parse(raw) as AiChatPersistedState;
    return { conversationId: parsed.conversationId ?? null, messages: parsed.messages ?? [] };
  } catch {
    return { conversationId: null, messages: [] };
  }
}

export function saveAiChatState(state: AiChatPersistedState): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch {
    /* ignore */
  }
}

export function clearAiChatState(): void {
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    /* ignore */
  }
}
