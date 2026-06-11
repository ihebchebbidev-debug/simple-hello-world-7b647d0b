/// <reference types="vite-plugin-pwa/client" />
/**
 * Service-worker registration with strict guards:
 *   - Disabled in dev (devOptions.enabled=false in vite.config.ts).
 *   - Skipped when running inside an iframe or on a Lovable preview host
 *     (the SW would intercept HTML and pin the preview to a stale build).
 *   - Skipped on native Capacitor (we already have native background sync
 *     via the in-app outbox and Capacitor App lifecycle hooks).
 */
import { Capacitor } from '@capacitor/core';

const SW_DISABLED_HOSTS = ['lovableproject.com', 'lovable.app', 'id-preview--', 'localhost'];

function isInIframe(): boolean {
  try { return window.self !== window.top; } catch { return true; }
}

function isPreviewHost(): boolean {
  const h = window.location.hostname;
  return SW_DISABLED_HOSTS.some((needle) => h.includes(needle));
}

/**
 * Remove any service worker + caches left over from an earlier PWA-enabled
 * build. Without this, a customer whose browser still has the old SW would keep
 * being served the stale cached bundle and never see new deployments. One-time
 * reload (guarded) so the page comes back fresh from the network.
 */
async function purgeStaleServiceWorkers(): Promise<void> {
  if (!('serviceWorker' in navigator)) return;
  try {
    const regs = await navigator.serviceWorker.getRegistrations();
    if (regs.length === 0) return; // nothing stale — common case, do nothing
    await Promise.all(regs.map((r) => r.unregister()));
    if (typeof caches !== 'undefined') {
      const keys = await caches.keys();
      await Promise.all(keys.map((k) => caches.delete(k)));
    }
    const KEY = 'sw-purged-once';
    if (typeof sessionStorage !== 'undefined' && !sessionStorage.getItem(KEY)) {
      sessionStorage.setItem(KEY, '1');
      window.location.reload();
    }
  } catch { /* ignore */ }
}

export async function registerServiceWorker(): Promise<void> {
  if (typeof window === 'undefined') return;
  if (Capacitor.isNativePlatform()) return;
  // PWA is disabled in the embedded /mobileapp build — make sure no stale SW
  // from a previous PWA build keeps pinning the customer to an old bundle.
  if (import.meta.env.VITE_PWA_ENABLED === 'false') {
    await purgeStaleServiceWorkers();
    return;
  }

  // Hard guard: in iframes / preview hosts, actively *unregister* any
  // previously-installed SW so we never serve a stale build.
  if (isInIframe() || isPreviewHost()) {
    if ('serviceWorker' in navigator) {
      try {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map((r) => r.unregister()));
      } catch { /* ignore */ }
    }
    return;
  }

  if (!('serviceWorker' in navigator)) return;
  try {
    // Use a literal module specifier so Vite can resolve the virtual PWA
    // module during build. If the PWA plugin is not present at runtime,
    // the import will fail and the error is ignored.
    const { registerSW } = await import('virtual:pwa-register');
    registerSW({ immediate: true });
  } catch { /* PWA module unavailable in this build — ignore */ }
}
