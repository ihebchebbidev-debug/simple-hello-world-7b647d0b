// Route preloaders — keep in sync with the lazy() imports in App.tsx.
// Preloading a chunk while the user is hovering a NavLink (or during idle
// time after login) means clicking the link almost never triggers the
// Suspense fallback, so navigation feels instant instead of flashing
// blank.

type Loader = () => Promise<unknown>;

const loaders: Record<string, Loader> = {
  '/dashboard':     () => import('@/features/dashboard/DashboardPage'),
  '/configuration': () => import('@/features/configuration/ConfigurationPage'),
  '/campaigns':     () => import('@/features/campaigns/CampaignsPage'),
  '/plots':         () => import('@/features/plots/PlotsPage'),
  '/labor':         () => import('@/features/labor/LaborPage'),
  '/water':         () => import('@/features/water/WaterPage'),
  '/fertilizers':   () => import('@/features/fertilizers/FertilizersPage'),
  '/pesticides':    () => import('@/features/pesticides/PesticidesPage'),
  '/pests':         () => import('@/features/pests/PestsPage'),
  '/reports':       () => import('@/features/reports/ReportsLayout'),
  '/reports/irrigation':      () => import('@/features/reports/IrrigationReport'),
  '/reports/fertilization':   () => import('@/features/reports/FertilizationReport'),
  '/reports/phytosanitary':   () => import('@/features/reports/PhytosanitaryReport'),
  '/reports/harvesting':      () => import('@/features/reports/HarvestingReport'),
  '/reports/production-cost': () => import('@/features/reports/ProductionCostReport'),
  '/users':         () => import('@/features/users/UsersPage'),
  '/notifications': () => import('@/features/notifications/NotificationsPage'),
  '/sync':          () => import('@/features/sync/SyncPage'),
  '/logs':          () => import('@/features/logs/LogsPage'),
  '/help':          () => import('@/features/help/HelpPage'),
};

const started = new Set<string>();

export const preloadRoute = (path: string): void => {
  const loader = loaders[path];
  if (!loader || started.has(path)) return;
  started.add(path);
  // Fire and forget; the Suspense boundary will pick the cached module up.
  loader().catch(() => { started.delete(path); });
};

/** Kick off preloading for every known route during browser idle time. */
export const preloadAllRoutesOnIdle = (): void => {
  const run = () => {
    Object.keys(loaders).forEach((p) => preloadRoute(p));
  };
  type IdleWindow = Window & {
    requestIdleCallback?: (cb: () => void, opts?: { timeout?: number }) => number;
  };
  const w = window as IdleWindow;
  if (typeof w.requestIdleCallback === 'function') {
    w.requestIdleCallback(run, { timeout: 2000 });
  } else {
    setTimeout(run, 800);
  }
};
