import type { ReactNode } from 'react';

type Props = { content: string };

type Block =
  | { type: 'heading'; text: string; level: number }
  | { type: 'paragraph'; text: string }
  | { type: 'ul'; items: string[] }
  | { type: 'ol'; items: string[] }
  | { type: 'quote'; text: string }
  | { type: 'code'; text: string }
  | { type: 'table'; header: string[]; rows: string[][] };

export default function AiChatMarkdown({ content }: Props) {
  const blocks = parseBlocks(content);

  return (
    <div className="ai-chat-md space-y-2.5 text-[14px] leading-relaxed text-foreground/95">
      {blocks.map((block, i) => {
        if (block.type === 'heading') {
          const cls =
            block.level <= 1
              ? 'text-[15px] font-semibold text-foreground'
              : block.level === 2
              ? 'text-[14px] font-semibold text-foreground'
              : 'text-[12px] font-semibold uppercase tracking-wide text-muted-foreground';
          return (
            <p key={i} className={`${cls} first:mt-0`}>
              {renderInline(block.text)}
            </p>
          );
        }
        if (block.type === 'ul') {
          return (
            <ul key={i} className="list-disc space-y-1 pl-5 marker:text-[hsl(var(--primary-glow))]">
              {block.items.map((item, j) => (
                <li key={j}>{renderInline(item)}</li>
              ))}
            </ul>
          );
        }
        if (block.type === 'ol') {
          return (
            <ol key={i} className="list-decimal space-y-1 pl-5 marker:text-muted-foreground">
              {block.items.map((item, j) => (
                <li key={j}>{renderInline(item)}</li>
              ))}
            </ol>
          );
        }
        if (block.type === 'quote') {
          return (
            <blockquote
              key={i}
              className="border-l-2 border-[hsl(var(--primary-glow))] bg-[hsl(var(--surface-bright))]/40 px-3 py-1.5 text-[13px] italic text-muted-foreground"
            >
              {renderInline(block.text)}
            </blockquote>
          );
        }
        if (block.type === 'code') {
          return (
            <pre
              key={i}
              className="overflow-x-auto rounded-md border border-border/50 bg-[hsl(var(--surface-bright))] px-3 py-2 text-[12px] font-mono text-foreground/90"
            >
              <code>{block.text}</code>
            </pre>
          );
        }
        if (block.type === 'table') {
          return (
            <div key={i} className="overflow-x-auto rounded-md border border-border/50">
              <table className="w-full border-collapse text-[12.5px]">
                <thead className="bg-[hsl(var(--surface-bright))]/60">
                  <tr>
                    {block.header.map((h, j) => (
                      <th
                        key={j}
                        className="border-b border-border/50 px-2.5 py-1.5 text-left font-semibold text-foreground"
                      >
                        {renderInline(h)}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {block.rows.map((row, r) => (
                    <tr key={r} className="odd:bg-[hsl(var(--surface-container-highest))]/30">
                      {row.map((cell, c) => (
                        <td key={c} className="border-t border-border/40 px-2.5 py-1.5 align-top">
                          {renderInline(cell)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          );
        }
        return (
          <p key={i} className="whitespace-pre-wrap break-words first:mt-0">
            {renderInline(block.text)}
          </p>
        );
      })}
    </div>
  );
}

function parseBlocks(raw: string): Block[] {
  const lines = raw.replace(/\r\n/g, '\n').split('\n');
  const blocks: Block[] = [];
  let i = 0;

  while (i < lines.length) {
    const trimmed = lines[i].trim();
    if (!trimmed) {
      i += 1;
      continue;
    }
    if (/^```/.test(trimmed)) {
      const buf: string[] = [];
      i += 1;
      while (i < lines.length && !/^```/.test(lines[i].trim())) {
        buf.push(lines[i]);
        i += 1;
      }
      if (i < lines.length) i += 1;
      blocks.push({ type: 'code', text: buf.join('\n') });
      continue;
    }
    if (
      trimmed.includes('|') &&
      i + 1 < lines.length &&
      /^\s*\|?\s*:?-{3,}/.test(lines[i + 1])
    ) {
      const splitRow = (s: string) =>
        s.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map((c) => c.trim());
      const header = splitRow(trimmed);
      i += 2;
      const rows: string[][] = [];
      while (i < lines.length && lines[i].trim().includes('|')) {
        rows.push(splitRow(lines[i].trim()));
        i += 1;
      }
      blocks.push({ type: 'table', header, rows });
      continue;
    }
    const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
    if (heading) {
      blocks.push({ type: 'heading', text: heading[2], level: heading[1].length });
      i += 1;
      continue;
    }
    if (/^>\s?/.test(trimmed)) {
      blocks.push({ type: 'quote', text: trimmed.replace(/^>\s?/, '') });
      i += 1;
      continue;
    }
    if (/^[-*•]\s+/.test(trimmed)) {
      const items: string[] = [];
      while (i < lines.length && /^[-*•]\s+/.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^[-*•]\s+/, ''));
        i += 1;
      }
      blocks.push({ type: 'ul', items });
      continue;
    }
    if (/^\d+[.)]\s+/.test(trimmed)) {
      const items: string[] = [];
      while (i < lines.length && /^\d+[.)]\s+/.test(lines[i].trim())) {
        items.push(lines[i].trim().replace(/^\d+[.)]\s+/, ''));
        i += 1;
      }
      blocks.push({ type: 'ol', items });
      continue;
    }
    const paraLines: string[] = [trimmed];
    i += 1;
    while (i < lines.length) {
      const next = lines[i].trim();
      if (
        !next ||
        /^#{1,6}\s+/.test(next) ||
        /^[-*•]\s+/.test(next) ||
        /^\d+[.)]\s+/.test(next) ||
        /^>\s?/.test(next) ||
        /^```/.test(next)
      ) {
        break;
      }
      paraLines.push(next);
      i += 1;
    }
    blocks.push({ type: 'paragraph', text: paraLines.join('\n') });
  }
  return blocks.length > 0 ? blocks : [{ type: 'paragraph', text: raw }];
}

function renderInline(text: string): ReactNode[] {
  const parts = text.split(/(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\([^)]+\))/g);
  return parts.map((part, idx) => {
    if (part.startsWith('**') && part.endsWith('**')) {
      return (
        <strong key={idx} className="font-semibold text-foreground">
          {part.slice(2, -2)}
        </strong>
      );
    }
    if (part.startsWith('`') && part.endsWith('`')) {
      return (
        <code
          key={idx}
          className="rounded bg-[hsl(var(--surface-bright))] px-1.5 py-0.5 text-[12px] font-mono text-foreground"
        >
          {part.slice(1, -1)}
        </code>
      );
    }
    const link = part.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
    if (link) {
      return (
        <a
          key={idx}
          href={link[2]}
          target="_blank"
          rel="noreferrer noopener"
          className="text-[hsl(var(--primary-glow))] underline decoration-[hsl(var(--primary-glow)/0.4)] underline-offset-2 hover:decoration-[hsl(var(--primary-glow))]"
        >
          {link[1]}
        </a>
      );
    }
    return part;
  });
}
