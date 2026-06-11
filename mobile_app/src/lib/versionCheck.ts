/**
 * Detects new deployments (Vercel etc.) by polling `/version.json` (emitted at
 * build time by the `emit-version-json` Vite plugin) and force-reloads when the
 * build id changes so users always get the freshest bundle.
 *
 * Safe in dev: if `/version.json` is missing, the checker no-ops.
 */

// Resolve against the app's own base ('/mobileapp/') so the mobile PWA checks
// its OWN build id, independent of the dashboard's root /version.json.
const VERSION_URL = `${import.meta.env.BASE_URL ?? '/'}version.json`;
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
  window.location.reload();
}

async function check() {
  if (!bootBuildId) return;
  const current = await fetchBuildId();
  if (current && current !== bootBuildId) reloadOnce();
}

export async function initVersionCheck() {
  if (typeof window === 'undefined') return;
  // Disable inside the Lovable preview iframe so the editor doesn't reload
  // mid-edit on every hot rebuild.
  try {
    if (window.self !== window.top) return;
  } catch {
    return;
  }
  bootBuildId = await fetchBuildId();
  if (!bootBuildId) return;

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
