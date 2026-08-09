import { BACKEND_URL, getAuthToken } from '@/lib/api';
import type { AiChatMessage } from './types';

export type AiConversationSummary = {
  id: string;
  title: string;
  updated_at: string | null;
};

export type AiConversationFull = AiConversationSummary & {
  messages: AiChatMessage[];
};

function authHeaders(): HeadersInit {
  const token = getAuthToken();
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };
}

function unwrap<T>(payload: unknown): T {
  const root = payload as Record<string, unknown>;
  return ((root?.data ?? root) as T);
}

async function req<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${BACKEND_URL}/api${path}`, { ...init, headers: authHeaders() });
  if (!res.ok) {
    const message = await res.text().catch(() => '');
    throw Object.assign(new Error(message || `Request failed (${res.status})`), { status: res.status });
  }
  if (res.status === 204) return undefined as unknown as T;
  const json = (await res.json().catch(() => ({}))) as unknown;
  return unwrap<T>(json);
}

export function isAiHistoryAvailable(): boolean {
  return Boolean(getAuthToken());
}

export async function listConversations(): Promise<AiConversationSummary[]> {
  const data = await req<{ items: AiConversationSummary[] }>('/ai/conversations');
  return data.items ?? [];
}

export async function getConversation(id: string): Promise<AiConversationFull> {
  return req<AiConversationFull>(`/ai/conversations/${id}`);
}

export async function createConversation(title?: string): Promise<AiConversationFull> {
  return req<AiConversationFull>('/ai/conversations', {
    method: 'POST',
    body: JSON.stringify({ title: title ?? null, messages: [] }),
  });
}

export async function saveConversation(
  id: string,
  patch: { title?: string; messages?: AiChatMessage[] },
): Promise<AiConversationFull> {
  return req<AiConversationFull>(`/ai/conversations/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(patch),
  });
}

export async function deleteConversation(id: string): Promise<void> {
  await req<void>(`/ai/conversations/${id}`, { method: 'DELETE' });
}