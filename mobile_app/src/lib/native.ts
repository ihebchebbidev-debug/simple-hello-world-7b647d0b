/**
 * Native bridge — thin wrappers around Capacitor plugins with web fallbacks
 * so the same code runs in the Lovable preview and the Android shell.
 */
import { Capacitor } from '@capacitor/core';

export const isNative = Capacitor.isNativePlatform();
export const platform = Capacitor.getPlatform();

/* ── Preferences (key-value) ────────────────────────────────────────────── */

export async function prefGet(key: string): Promise<string | null> {
  if (!isNative) {
    try { return localStorage.getItem(key); } catch { return null; }
  }
  const { Preferences } = await import('@capacitor/preferences');
  const { value } = await Preferences.get({ key });
  return value ?? null;
}

export async function prefSet(key: string, value: string): Promise<void> {
  if (!isNative) {
    try { localStorage.setItem(key, value); } catch { /* ignore */ }
    return;
  }
  const { Preferences } = await import('@capacitor/preferences');
  await Preferences.set({ key, value });
}

export async function prefRemove(key: string): Promise<void> {
  if (!isNative) {
    try { localStorage.removeItem(key); } catch { /* ignore */ }
    return;
  }
  const { Preferences } = await import('@capacitor/preferences');
  await Preferences.remove({ key });
}

/* ── Network status ─────────────────────────────────────────────────────── */

export interface NetStatus { connected: boolean; connectionType?: string }

export async function getNetworkStatus(): Promise<NetStatus> {
  if (!isNative) return { connected: typeof navigator !== 'undefined' ? navigator.onLine : true };
  const { Network } = await import('@capacitor/network');
  const s = await Network.getStatus();
  return { connected: s.connected, connectionType: s.connectionType };
}

export type NetUnsub = { remove: () => void };

export async function onNetworkChange(cb: (connected: boolean) => void): Promise<NetUnsub> {
  if (!isNative) {
    const on = () => cb(true);
    const off = () => cb(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return { remove: () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); } };
  }
  const { Network } = await import('@capacitor/network');
  const h = await Network.addListener('networkStatusChange', (s) => cb(s.connected));
  return { remove: () => { void h.remove(); } };
}

/* ── Geolocation (optional, for irrigation/treatment proof) ─────────────── */

export async function getCurrentPosition() {
  const { Geolocation } = await import('@capacitor/geolocation');
  const perm = await Geolocation.checkPermissions();
  if (perm.location !== 'granted') await Geolocation.requestPermissions();
  return Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10_000 });
}

/* ── Status bar / splash (no-ops on web) ────────────────────────────────── */

export async function setStatusBarDark(): Promise<void> {
  if (!isNative) return;
  try {
    const { StatusBar, Style } = await import('@capacitor/status-bar');
    await StatusBar.setStyle({ style: Style.Dark });
  } catch { /* plugin missing */ }
}

export async function hideSplash(): Promise<void> {
  if (!isNative) return;
  try {
    const { SplashScreen } = await import('@capacitor/splash-screen');
    await SplashScreen.hide();
  } catch { /* plugin missing */ }
}
