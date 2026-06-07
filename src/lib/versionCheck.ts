/**
 * Detects new Vercel deployments by polling `/version.json` (emitted at build
 * time by the `emit-version-json` Vite plugin) and force-reloads the page so
 * users always get the freshest bundle. Safe in dev — no-ops without a build id.
 *
 * Strategy:
 *  1. On boot, fetch `/version.json` and remember the build id in memory.
 *  2. Re-fetch on tab focus / visibilitychange and every 2 min while open.
 *  3. If the build id changed, reload (with a localStorage guard to avoid loops
 *     on edge-cache lag).
 */

const VERSION_URL = '/version.json';
const POLL_MS = 2 * 60 * 1000;
const LOOP_GUARD_KEY = 'vc.lastReloadAt';
const LOOP_GUARD_MS = 30_000;

let bootBuildId: string | null = null;
let pollTimer: number | null = null;

async function fetchBuildId(): Promise<string | null> {
  try {
    const res = await fetch(`${VERSION_URL}?t=${Date.now()}`, {
      cache: 'no-store',
      credentials: 'omit',
    });
    if (!res.ok) return null;
    const json = (await res.json()) as { buildId?: string };
    return typeof json.buildId === 'string' ? json.buildId : null;
  } catch {
    return null;
  }
}

function reloadOnce() {
  try {
    const last = Number(localStorage.getItem(LOOP_GUARD_KEY) || '0');
    if (Date.now() - last < LOOP_GUARD_MS) return;
    localStorage.setItem(LOOP_GUARD_KEY, String(Date.now()));
  } catch {
    /* ignore */
  }
  // Hard reload to bypass HTML cache.
  window.location.reload();
}

async function check() {
  if (!bootBuildId) return;
  const current = await fetchBuildId();
  if (current && current !== bootBuildId) reloadOnce();
}

export async function initVersionCheck() {
  if (typeof window === 'undefined') return;
  bootBuildId = await fetchBuildId();
  if (!bootBuildId) return; // no /version.json (dev) — disabled

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') void check();
  });
  window.addEventListener('focus', () => void check());
  pollTimer = window.setInterval(() => void check(), POLL_MS);
}

export function stopVersionCheck() {
  if (pollTimer !== null) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}
