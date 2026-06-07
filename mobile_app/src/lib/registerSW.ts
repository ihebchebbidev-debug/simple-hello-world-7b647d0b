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

export async function registerServiceWorker(): Promise<void> {
  if (typeof window === 'undefined') return;
  if (Capacitor.isNativePlatform()) return;
  if (import.meta.env.VITE_PWA_ENABLED === 'false') return;

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
