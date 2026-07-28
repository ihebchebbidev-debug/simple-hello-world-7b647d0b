export type AiChatRole = 'user' | 'assistant';

export type AiChatRating = 'up' | 'down';

export type AiChatMessage = {
  id: string;
  role: AiChatRole;
  content: string;
  createdAt: number;
  status?: 'error';
  /** User rating on an assistant reply, once submitted. */
  rating?: AiChatRating;
};

export type AiChatPersistedState = {
  conversationId: string | null;
  messages: AiChatMessage[];
};
