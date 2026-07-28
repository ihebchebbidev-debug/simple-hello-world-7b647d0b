import { beforeEach, describe, expect, it } from 'vitest';
import { setAuthToken } from '@/lib/api';

describe('auth token change notifications', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('dispatches an auth-token-changed event when the token changes', () => {
    const received: Array<string | null> = [];

    window.addEventListener('auth-token-changed', ((event: Event) => {
      const detail = (event as CustomEvent<{ token: string | null }>).detail;
      received.push(detail?.token ?? null);
    }) as EventListener);

    setAuthToken('abc123');
    setAuthToken(null);

    expect(received).toEqual(['abc123', null]);
  });
});
