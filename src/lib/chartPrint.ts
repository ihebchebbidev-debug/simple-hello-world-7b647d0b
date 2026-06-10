/**
 * Snapshot a live Recharts SVG into a self-contained data-URL image so it
 * survives `window.print()` / "Save as PDF".
 *
 * Why this exists: Recharts renders through a `ResponsiveContainer` whose SVG
 * does not repaint for the print canvas (it exports as a grey/blank box), and
 * its colours are declared with CSS custom properties (`hsl(var(--border))`)
 * that are lost the moment the SVG is serialised out of the document. We clone
 * the SVG, inline the *computed* values of the relevant paint properties (which
 * resolves every CSS variable to a concrete colour), and hand back an
 * `<img>`-ready data URL that prints identically everywhere.
 */

const PAINT_PROPS = [
  'fill',
  'fill-opacity',
  'stroke',
  'stroke-width',
  'stroke-dasharray',
  'stroke-linecap',
  'stroke-linejoin',
  'opacity',
  'color',
  'font-size',
  'font-family',
  'font-weight',
  'text-anchor',
] as const;

/**
 * Build a standalone `image/svg+xml` data URL from the first
 * `svg.recharts-surface` found inside `container`. Returns null when the chart
 * hasn't painted yet (zero size / not mounted).
 */
export function chartToDataUrl(container: HTMLElement | null): string | null {
  if (!container) return null;

  const live = container.querySelector('svg.recharts-surface') as SVGSVGElement | null;
  if (!live) return null;

  const rect = live.getBoundingClientRect();
  const width = live.clientWidth || rect.width;
  const height = live.clientHeight || rect.height;
  if (!width || !height) return null;

  const clone = live.cloneNode(true) as SVGSVGElement;

  // Walk both trees in lockstep and copy resolved paint styles onto the clone.
  const liveNodes = live.querySelectorAll('*');
  const cloneNodes = clone.querySelectorAll('*');
  for (let i = 0; i < liveNodes.length; i++) {
    const cs = getComputedStyle(liveNodes[i]);
    const target = cloneNodes[i] as SVGElement;
    let style = '';
    for (const prop of PAINT_PROPS) {
      const value = cs.getPropertyValue(prop);
      if (value && value !== 'none' && value !== 'normal') style += `${prop}:${value};`;
    }
    if (style) target.setAttribute('style', style);
  }

  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  clone.setAttribute('width', String(Math.round(width)));
  clone.setAttribute('height', String(Math.round(height)));
  // White backing so the chart reads on a printed page regardless of theme.
  clone.insertAdjacentHTML(
    'afterbegin',
    `<rect x="0" y="0" width="${Math.round(width)}" height="${Math.round(height)}" fill="#ffffff"/>`,
  );

  const xml = new XMLSerializer().serializeToString(clone);
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(xml)}`;
}
