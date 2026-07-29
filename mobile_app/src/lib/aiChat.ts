import { BACKEND_URL, getAuthToken } from '@/lib/api';

export type AiChatRole = 'user' | 'assistant';

export type AiChatMessage = {
  id: string;
  role: AiChatRole;
  content: string;
  plan?: string[];
  tools?: { name: string; status: 'pending' | 'ok' | 'error'; preview?: string }[];
};

export type AiChatRequest = {
  messages: { role: AiChatRole; content: string }[];
  locale: string;
  conversation_id?: string | null;
};

export type AiChatStreamCallbacks = {
  onDelta: (chunk: string) => void;
  onPlan?: (steps: string[]) => void;
  onToolStart?: (name: string) => void;
  onToolEnd?: (name: string, ok?: boolean, preview?: string) => void;
  onRevise?: (finalReply: string) => void;
};

export type AiChatResponse = { reply: string; conversationId: string | null };

export function cleanAssistantText(raw: string): string {
  if (raw.trim() === '') return raw;

  const cleaned = raw
    .replace(/^\s*(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*(?:\{[^}]*\}|\[[^\]]*\]|[^\r\n]*)\s*$/gim, '')
    .replace(/\b(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*[\w-]+\b/gi, '')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/[ \t]{2,}/g, ' ')
    .trim();

  return cleaned;
}

type StreamEvent =
  | { type: 'delta'; content: string }
  | { type: 'revise'; content: string }
  | { type: 'done'; reply: string; conversation_id: string | null }
  | { type: 'plan'; steps: string[] }
  | { type: 'tool_start'; name: string }
  | { type: 'tool_end'; name: string; ok?: boolean; preview?: string }
  | { type: 'error'; message: string; code?: string };

/**
 * Stream a chat completion from the Flehty AI agent. Mirrors the admin
 * app's implementation but with a slimmer callback surface — the mobile
 * assistant does not surface revisions or feedback controls yet.
 */
export async function streamAiChatMessage(
  body: AiChatRequest,
  cbs: AiChatStreamCallbacks,
  signal?: AbortSignal,
): Promise<AiChatResponse> {
  const token = getAuthToken();
  const res = await fetch(`${BACKEND_URL}/api/ai/chat`, {
    method: 'POST',
    headers: {
      Accept: 'application/x-ndjson',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({
      messages: body.messages,
      locale: body.locale,
      conversation_id: body.conversation_id ?? undefined,
      stream: true,
    }),
    signal,
  });

  if (!res.ok || !res.body) {
    let message = `Request failed (${res.status})`;
    try {
      const json = (await res.json()) as { error?: { message?: string } };
      if (json?.error?.message) message = json.error.message;
    } catch { /* ignore */ }
    throw Object.assign(new Error(message), { status: res.status });
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let reply = '';
  let conversationId: string | null = body.conversation_id ?? null;

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const lines = buffer.split('\n');
    buffer = lines.pop() ?? '';

    for (const line of lines) {
      const trimmed = line.trim();
      if (!trimmed) continue;
      let evt: StreamEvent;
      try { evt = JSON.parse(trimmed) as StreamEvent; } catch { continue; }

      if (evt.type === 'delta' && evt.content) {
        const chunk = cleanAssistantText(evt.content);
        if (chunk) {
          reply += chunk;
          cbs.onDelta(chunk);
        }
      } else if (evt.type === 'revise' && evt.content) {
        const revised = cleanAssistantText(evt.content);
        reply = revised;
        cbs.onRevise?.(revised);
      } else if (evt.type === 'plan') {
        cbs.onPlan?.(evt.steps ?? []);
      } else if (evt.type === 'tool_start') {
        cbs.onToolStart?.(evt.name);
      } else if (evt.type === 'tool_end') {
        cbs.onToolEnd?.(evt.name, evt.ok, evt.preview);
      } else if (evt.type === 'done') {
        reply = cleanAssistantText(evt.reply || reply);
        conversationId = evt.conversation_id ?? conversationId;
      } else if (evt.type === 'error') {
        throw Object.assign(new Error(evt.message || 'Stream error'), { code: evt.code });
      }
    }
  }

  if (!reply.trim()) throw new Error('Empty assistant reply');
  return { reply: reply.trim(), conversationId };
}