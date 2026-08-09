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
  /** Optional reasoning plan the agent announced before calling tools. */
  plan?: string[];
  /** Optional tool-call activity for this assistant reply. */
  tools?: AiChatToolEvent[];
};

export type AiChatToolEvent = {
  name: string;
  args?: Record<string, unknown>;
  ok?: boolean;
  preview?: string;
};

export type AiChatPersistedState = {
  conversationId: string | null;
  messages: AiChatMessage[];
};
