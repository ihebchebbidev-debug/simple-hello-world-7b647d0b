import { BACKEND_URL, getAuthToken } from '@/lib/api';
import type { AiChatMessage } from '@/features/ai-chat/types';

export type AiChatRequest = {
  messages: Pick<AiChatMessage, 'role' | 'content'>[];
  locale: string;
  conversation_id?: string | null;
};

export type AiChatResponse = {
  reply: string;
  conversationId: string | null;
};

type StreamEvent =
  | { type: 'delta'; content: string }
  | { type: 'revise'; content: string; violations?: string[] }
  | { type: 'done'; reply: string; conversation_id: string | null; revised?: boolean }
  | { type: 'plan'; steps: string[] }
  | { type: 'tool_start'; name: string; args?: Record<string, unknown> }
  | { type: 'tool_end'; name: string; ok?: boolean; preview?: string }
  | { type: 'error'; message: string; code?: string };

export type AiChatStreamCallbacks = {
  onPlan?: (steps: string[]) => void;
  onToolStart?: (name: string, args?: Record<string, unknown>) => void;
  onToolEnd?: (name: string, ok?: boolean, preview?: string) => void;
  onRevise?: (finalReply: string) => void;
};

function unwrapReply(payload: unknown): { reply: string; conversationId: string | null } {
  const root = payload as Record<string, unknown>;
  const data = (root?.data ?? root) as Record<string, unknown>;

  const reply =
    (typeof data.reply === 'string' && data.reply) ||
    (typeof data.message === 'string' && data.message) ||
    (typeof data.content === 'string' && data.content) ||
    (typeof data.answer === 'string' && data.answer) ||
    '';

  const conversationId =
    (typeof data.conversation_id === 'string' && data.conversation_id) ||
    (typeof data.conversationId === 'string' && data.conversationId) ||
    null;

  return { reply, conversationId };
}

export function cleanAssistantText(raw: string): string {
  if (raw.trim() === '') return raw;

  const cleaned = raw
    // Remove stray tool/artifact tokens like `tick :search_catalog`,
    // `tool_call_id: call_123`, or other agent internals from the model stream.
    .replace(/^\s*(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*(?:\{[^}]*\}|\[[^\]]*\]|[^\r\n]*)\s*$/gim, '')
    .replace(/\b(?:tick|tool_call_id|tool_call|tool_calls)\s*:\s*[\w-]+\b/gi, '')
    .replace(/\n{3,}/g, '\n\n')
    .replace(/[ \t]{2,}/g, ' ')
    .trim();

  return cleaned;
}

function parseErrorMessage(payload: unknown, fallback: string): string {
  const root = payload as Record<string, unknown>;
  const error = root?.error as Record<string, unknown> | undefined;
  if (typeof error?.message === 'string' && error.message.trim()) {
    return error.message.trim();
  }
  return fallback;
}

function parseErrorCode(payload: unknown): string | undefined {
  const root = payload as Record<string, unknown>;
  const error = root?.error as Record<string, unknown> | undefined;
  const code = error?.code ?? root?.code;
  return typeof code === 'string' && code ? code : undefined;
}

/**
 * Transient failures the user should never see: we retry them silently
 * instead of painting a red bubble in the transcript.
 */
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
 * Public entry point: streams a reply and transparently retries transient
 * upstream hiccups (up to 2 extra attempts) as long as nothing has been
 * rendered yet, so the user sees a slightly slower answer rather than an error.
 */
export async function streamAiChatMessage(
  body: AiChatRequest,
  onDelta: (chunk: string) => void,
  signal?: AbortSignal,
  onRevise?: ((finalReply: string) => void) | AiChatStreamCallbacks,
): Promise<AiChatResponse> {
  const delays = [600, 1600];
  for (let attempt = 0; ; attempt++) {
    let rendered = false;
    try {
      return await streamAiChatOnce(
        body,
        (chunk) => {
          rendered = true;
          onDelta(chunk);
        },
        signal,
        onRevise,
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
  onDelta: (chunk: string) => void,
  signal?: AbortSignal,
  onRevise?: ((finalReply: string) => void) | AiChatStreamCallbacks,
): Promise<AiChatResponse> {
  const cbs: AiChatStreamCallbacks =
    typeof onRevise === 'function' ? { onRevise } : onRevise ?? {};
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

  if (!res.ok) {
    let message = 'Request failed';
    let code: string | undefined;
    try {
      const json = await res.json();
      message = parseErrorMessage(json, message);
      code = parseErrorCode(json);
    } catch {
      // ignore
    }
    throw Object.assign(new Error(message), { status: res.status, code });
  }

  if (!res.body) {
    throw new Error('Empty stream body');
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

      let event: StreamEvent;
      try {
        event = JSON.parse(trimmed) as StreamEvent;
      } catch {
        continue;
      }

      if (event.type === 'delta' && event.content) {
        const chunk = cleanAssistantText(event.content);
        if (chunk) {
          reply += chunk;
          onDelta(chunk);
        }
      } else if (event.type === 'revise' && event.content) {
        // Server-side self-check produced a corrected reply; replace the streamed draft.
        const revised = cleanAssistantText(event.content);
        reply = revised;
        cbs.onRevise?.(revised);
      } else if (event.type === 'plan') {
        cbs.onPlan?.(event.steps ?? []);
      } else if (event.type === 'tool_start') {
        cbs.onToolStart?.(event.name, event.args);
      } else if (event.type === 'tool_end') {
        cbs.onToolEnd?.(event.name, event.ok, event.preview);
      } else if (event.type === 'done') {
        reply = cleanAssistantText(event.reply || reply);
        conversationId = event.conversation_id ?? conversationId;
      } else if (event.type === 'error') {
        throw Object.assign(new Error(event.message || 'Stream error'), {
          status: 503,
          code: event.code,
        });
      }
    }
  }

  if (!reply.trim()) {
    throw new Error('Empty assistant reply');
  }

  return { reply: reply.trim(), conversationId };
}

export async function postAiChatMessage(body: AiChatRequest): Promise<AiChatResponse> {
  const token = getAuthToken();
  const res = await fetch(`${BACKEND_URL}/api/ai/chat`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({
      messages: body.messages,
      locale: body.locale,
      conversation_id: body.conversation_id ?? undefined,
      stream: false,
    }),
  });

  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw Object.assign(new Error(parseErrorMessage(json, 'Request failed')), {
      status: res.status,
      code: parseErrorCode(json),
    });
  }

  const { reply, conversationId } = unwrapReply(json);
  const cleanedReply = cleanAssistantText(reply);
  if (!cleanedReply.trim()) {
    throw new Error('Empty assistant reply');
  }

  return { reply: cleanedReply.trim(), conversationId };
}

export type AiChatFeedbackPayload = {
  messageId: string;
  rating: 'up' | 'down';
  conversationId?: string | null;
  locale?: string;
  question?: string;
  answer?: string;
  comment?: string;
  tags?: string[];
};

/**
 * Log a rating for one assistant reply. Backend upserts on (user, message_id),
 * so calling again with the opposite rating flips the vote.
 */
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
      comment: payload.comment,
      tags: payload.tags,
    }),
  });

  if (!res.ok) {
    const json = await res.json().catch(() => ({}));
    throw Object.assign(new Error(parseErrorMessage(json, 'Feedback failed')), {
      status: res.status,
    });
  }
}

