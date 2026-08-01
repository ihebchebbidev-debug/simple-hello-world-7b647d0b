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

export type AiChatFeedbackPayload = {
  messageId: string;
  rating: 'up' | 'down';
  conversationId?: string | null;
  locale?: string;
  question?: string;
  answer: string;
};

function parseErrorMessage(json: unknown, fallback: string): string {
  if (json && typeof json === 'object' && 'error' in json) {
    const err = (json as Record<string, unknown>).error;
    if (err && typeof err === 'object' && 'message' in err && typeof (err as Record<string, unknown>).message === 'string') {
      return (err as Record<string, any>).message;
    }
  }
  if (json && typeof json === 'object' && 'message' in json && typeof (json as Record<string, unknown>).message === 'string') {
    return (json as Record<string, any>).message;
  }
  return fallback;
}

export async function submitAiChatFeedback(payload: AiChatFeedbackPayload): Promise<void> {
  const token = getAuthToken();
  const res = await fetch(`${BACKEND_URL}/api/ai/feedback`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({
      message_id: payload.messageId,
      rating: payload.rating,
      conversation_id: payload.conversationId ?? undefined,
      locale: payload.locale,
      question: payload.question,
      answer: payload.answer,
    }),
  });

  if (!res.ok) {
    const json = await res.json().catch(() => ({}));
    throw Object.assign(new Error(parseErrorMessage(json, 'Feedback failed')), {
      status: res.status,
    });
  }
}

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

/** Transient failures are retried silently so users never see a red error. */
function isRetryableAiError(err: unknown): boolean {
  const status = (err as { status?: number })?.status;
  const code = (err as { code?: string })?.code;
  if (code && ['rate_limited', 'upstream_error', 'timeout', 'network', 'empty_reply', 'ai_error'].includes(code)) {
    return true;
  }
  if (typeof status === 'number' && (status === 429 || status === 408 || status >= 500)) return true;
  const msg = (err as Error)?.message ?? '';
  return /empty assistant reply|failed to fetch|network|stream error/i.test(msg);
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

/**
 * Stream a chat completion from the Flehty AI agent, retrying transient
 * upstream failures transparently while nothing has been rendered yet.
 */
export async function streamAiChatMessage(
  body: AiChatRequest,
  cbs: AiChatStreamCallbacks,
  signal?: AbortSignal,
): Promise<AiChatResponse> {
  const delays = [600, 1600];
  for (let attempt = 0; ; attempt++) {
    let rendered = false;
    try {
      return await streamAiChatOnce(
        body,
        { ...cbs, onDelta: (chunk) => { rendered = true; cbs.onDelta(chunk); } },
        signal,
      );
    } catch (err) {
      const aborted = signal?.aborted || (err as Error)?.name === 'AbortError';
      if (aborted || rendered || attempt >= delays.length || !isRetryableAiError(err)) throw err;
      await sleep(delays[attempt]);
    }
  }
}

async function streamAiChatOnce(
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