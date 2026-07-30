export type AiChatRole = 'user' | 'assistant';

export type AiChatRating = 'up' | 'down';

export type AiChatToolEvent = {
  name: string;
  args?: Record<string, unknown>;
  ok?: boolean;
  preview?: string;
};

export type AiChatMessage = {
  id: string;
  role: AiChatRole;
  content: string;
  createdAt: number;
  status?: 'error';
  rating?: AiChatRating;
  plan?: string[];
  tools?: AiChatToolEvent[];
};

export type AiChatPersistedState = {
  conversationId: string | null;
  messages: AiChatMessage[];
};
