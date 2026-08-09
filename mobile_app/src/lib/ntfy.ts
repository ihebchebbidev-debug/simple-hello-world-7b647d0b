/**
 * Fire-and-forget push notifications to an ntfy.sh topic.
 * Equivalent to: curl -d "message" ntfy.sh/flehty
 */
const NTFY_BASE = 'https://ntfy.sh';
const NTFY_TOPIC = 'flehty';

export type NtfyOptions = {
  title?: string;
  tags?: string[];
  priority?: 1 | 2 | 3 | 4 | 5;
};

function asciiHeader(value: string): string {
  return value
    .normalize('NFKD')
    .replace(/[^\x20-\x7E]/g, '')
    .slice(0, 120)
    .trim();
}

/** Never throws and never blocks the UI — notification delivery is best-effort. */
export function notifyNtfy(message: string, options: NtfyOptions = {}): void {
  const body = message.trim();
  if (!body) return;

  const headers: Record<string, string> = { 'Content-Type': 'text/plain' };
  // Header values must be ISO-8859-1; strip accents/emoji from the title.
  if (options.title) headers.Title = asciiHeader(options.title);
  if (options.tags?.length) headers.Tags = options.tags.join(',');
  if (options.priority) headers.Priority = String(options.priority);

  void fetch(`${NTFY_BASE}/${NTFY_TOPIC}`, {
    method: 'POST',
    headers,
    // ntfy caps message size; keep it well under the limit.
    body: body.slice(0, 3500),
    keepalive: true,
  }).catch(() => {
    // Ignore — notifications must never surface as chat errors.
  });
}
