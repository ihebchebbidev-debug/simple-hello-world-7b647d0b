/**
 * Reference-data cache (plots, fertilizers, pesticides, pests).
 *
 * The technician forms need these lists even when the device is offline.
 * We fetch from the API when online and persist the result to IndexedDB
 * (`reference` store, see lib/db.ts). On cold start without network we fall
 * back to the cached bundle. Legacy Capacitor Preferences records are
 * migrated on first read.
 */
import { api, toApiError } from './api';
import { getNetworkStatus, prefGet, prefRemove } from './native';
import { dbReferenceGet, dbReferenceSet, dbReferenceClear } from './db';

export interface RefPlot { id: string; name: string; surface_area_ha: number; crop_type?: string; is_active: boolean }
export interface RefFertilizer { id: string; name: string; unit: string; n_percent: number; p_percent: number; k_percent: number; is_active: boolean }
export interface RefPesticide { id: string; name: string; unit: string; chemical_composition?: string; is_active: boolean }
export interface RefPest { id: string; name: string; is_active: boolean }

export interface ReferenceBundle {
  plots: RefPlot[];
  fertilizers: RefFertilizer[];
  pesticides: RefPesticide[];
  pests: RefPest[];
  fetchedAt: string;
}

const LEGACY_CACHE_KEY = 'agri-sync.reference.v1';
let migrated = false;

async function migrateFromLegacy(): Promise<void> {
  if (migrated) return;
  migrated = true;
  try {
    const raw = await prefGet(LEGACY_CACHE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw) as ReferenceBundle;
      const existing = await dbReferenceGet<ReferenceBundle>();
      if (!existing) await dbReferenceSet(parsed);
      await prefRemove(LEGACY_CACHE_KEY);
    }
  } catch { /* ignore */ }
}

function unwrap<T>(data: unknown): T[] {
  if (Array.isArray(data)) return data as T[];
  const obj = data as Record<string, unknown> | undefined;
  if (Array.isArray(obj?.data)) return obj.data as T[];
  if (Array.isArray((obj?.data as Record<string, unknown>)?.data)) {
    return (obj!.data as Record<string, unknown>).data as T[];
  }
  return [];
}

async function loadList<T>(path: string): Promise<T[]> {
  const { data } = await api.get(path, { params: { per_page: 100 } });
  return unwrap<T>(data);
}

export async function fetchReferences(): Promise<ReferenceBundle> {
  try {
    const [plots, fertilizers, pesticides, pests] = await Promise.all([
      loadList<RefPlot>('/plots'),
      loadList<RefFertilizer>('/fertilizers'),
      loadList<RefPesticide>('/pesticides'),
      loadList<RefPest>('/pests').catch(() => []),
    ]);
    const bundle: ReferenceBundle = {
      plots: plots.filter((p) => p.is_active !== false),
      fertilizers: fertilizers.filter((f) => f.is_active !== false),
      pesticides: pesticides.filter((p) => p.is_active !== false),
      pests: pests.filter((p) => p.is_active !== false),
      fetchedAt: new Date().toISOString(),
    };
    await dbReferenceSet(bundle);
    return bundle;
  } catch (err) {
    throw toApiError(err, 'Impossible de récupérer les données de référence');
  }
}

export async function readCachedReferences(): Promise<ReferenceBundle | null> {
  await migrateFromLegacy();
  return dbReferenceGet<ReferenceBundle>();
}

/** Wipe the on-device reference cache (used by the manual refresh button). */
export async function clearReferences(): Promise<void> {
  await dbReferenceClear();
}

/** Clear the cache and force a network refetch. Throws if offline. */
export async function refreshReferences(): Promise<ReferenceBundle> {
  const status = await getNetworkStatus();
  if (!status.connected) {
    throw toApiError(new Error('offline'), 'Connectez-vous à internet pour rafraîchir les listes.');
  }
  await clearReferences();
  return fetchReferences();
}

/** Try network first, fall back to cache. */
export async function loadReferences(): Promise<ReferenceBundle> {
  await migrateFromLegacy();
  const status = await getNetworkStatus();
  if (status.connected) {
    try { return await fetchReferences(); } catch { /* fall through */ }
  }
  const cached = await readCachedReferences();
  if (cached) return cached;
  return { plots: [], fertilizers: [], pesticides: [], pests: [], fetchedAt: new Date(0).toISOString() };
}
