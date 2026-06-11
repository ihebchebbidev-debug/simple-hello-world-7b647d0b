import { lazy, Suspense, useEffect } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Toaster } from 'sonner';
import AppLayout from './AppLayout';
import RequireAuth from '@/components/RequireAuth';
import { ThemeProvider } from '@/hooks/useTheme';
import SetupGate from '@/components/SetupGate';
import { preloadAllRoutesOnIdle } from './preloadRoutes';

// Route-level code splitting — each chunk loads on demand, shrinking the
// initial bundle (login + dashboard shell) dramatically.
const ForgotPasswordPage   = lazy(() => import('@/features/auth/ForgotPasswordPage'));
const DashboardPage        = lazy(() => import('@/features/dashboard/DashboardPage'));
const PlotsPage            = lazy(() => import('@/features/plots/PlotsPage'));
const FertilizersPage      = lazy(() => import('@/features/fertilizers/FertilizersPage'));
const PesticidesPage       = lazy(() => import('@/features/pesticides/PesticidesPage'));
const WaterPage            = lazy(() => import('@/features/water/WaterPage'));
const LaborPage            = lazy(() => import('@/features/labor/LaborPage'));
const UsersPage            = lazy(() => import('@/features/users/UsersPage'));
const ReportsLayout        = lazy(() => import('@/features/reports/ReportsLayout'));
const IrrigationReport     = lazy(() => import('@/features/reports/IrrigationReport'));
const FertilizationReport  = lazy(() => import('@/features/reports/FertilizationReport'));
const PhytosanitaryReport  = lazy(() => import('@/features/reports/PhytosanitaryReport'));
const HarvestingReport     = lazy(() => import('@/features/reports/HarvestingReport'));
const ProductionCostReport = lazy(() => import('@/features/reports/ProductionCostReport'));
const PlotOperationsHistoryPage = lazy(() => import('@/features/reports/PlotOperationsHistoryPage'));
const CampaignsPage        = lazy(() => import('@/features/campaigns/CampaignsPage'));
const PestsPage            = lazy(() => import('@/features/pests/PestsPage'));
const NotificationsPage    = lazy(() => import('@/features/notifications/NotificationsPage'));
const SyncPage             = lazy(() => import('@/features/sync/SyncPage'));
const LogsPage             = lazy(() => import('@/features/logs/LogsPage'));
const ConfigurationPage    = lazy(() => import('@/features/configuration/ConfigurationPage'));
const HelpPage             = lazy(() => import('@/features/help/HelpPage'));
const NotFoundPage         = lazy(() => import('@/features/system/NotFoundPage'));
const MobileAppRedirect    = lazy(() => import('@/features/mobileapp/MobileAppRedirect'));
const DeveloperPage        = lazy(() => import('@/features/developer/DeveloperPage'));

// Tuned defaults so every page doesn't refetch the same reference data
// on every focus / mount. Mutations still invalidate explicitly.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Freshness-first: data is stale immediately, so every page mount, tab
      // refocus, and network reconnect refetches. This guarantees you always
      // see current data — including operations added or deleted on another
      // workstation — instead of a stale 5-minute cache. keepPreviousData
      // (below) means the refetch happens in the background with no white
      // flash; gcTime keeps the prior result around to serve as that
      // placeholder.
      staleTime: 0,
      // Keep inactive results cached for a while so revisiting a page paints
      // instantly from the prior data while the (always-on) refetch runs in
      // the background — fast *and* fresh. Freshness comes from staleTime: 0,
      // not from dropping the cache.
      gcTime: 30 * 60_000,
      refetchOnWindowFocus: true,
      refetchOnMount: true,
      refetchOnReconnect: true,
      // Show the previous data while the fresh fetch resolves — no flash.
      placeholderData: (prev: unknown) => prev,
      // Backend is an always-warm VPS now (not a Render cold-start), so fail
      // fast: a couple of quick retries for transient blips, then surface the
      // error instead of spinning for ~a minute.
      retry: (failureCount, error: unknown) => {
        const status = (error as { response?: { status?: number } } | undefined)?.response?.status;
        if (status && status >= 400 && status < 500) return false;
        return failureCount < 2;
      },
      retryDelay: (attempt) => Math.min(1000 * 2 ** attempt, 4000),
    },
  },
});

const RouteFallback = () => (
  <div className="flex h-full w-full items-center justify-center p-10">
    <div className="h-1 w-40 overflow-hidden rounded-full bg-[hsl(var(--surface-bright))]">
      <div className="h-full w-1/3 animate-pulse rounded-full bg-[hsl(var(--primary))]" />
    </div>
  </div>
);

const App = () => {
  // Warm every route chunk in the background once the main bundle is idle
  // so navigations after login feel instant (no Suspense flash).
  useEffect(() => { preloadAllRoutesOnIdle(); }, []);
  return (
  <ThemeProvider>
    <QueryClientProvider client={queryClient}>
      <Toaster theme="dark" position="top-right" richColors closeButton />
      <BrowserRouter>
        <Suspense fallback={<RouteFallback />}>
          <Routes>
            <Route path="/" element={<Navigate to="/login" replace />} />
            <Route path="/login" element={<SetupGate />} />
            <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            <Route path="/developer" element={<DeveloperPage />} />
            <Route path="/mobileapp" element={<MobileAppRedirect />} />
            <Route path="/mobileapp/*" element={<MobileAppRedirect />} />
            <Route path="/" element={<RequireAuth><AppLayout /></RequireAuth>}>
              <Route path="dashboard" element={<DashboardPage />} />
              <Route path="configuration" element={<ConfigurationPage />} />
              <Route path="plots" element={<PlotsPage />} />
              <Route path="fertilizers" element={<FertilizersPage />} />
              <Route path="pesticides" element={<PesticidesPage />} />
              <Route path="water" element={<WaterPage />} />
              <Route path="labor" element={<LaborPage />} />
              <Route path="campaigns" element={<CampaignsPage />} />
              <Route path="pests" element={<PestsPage />} />
              <Route path="reports" element={<ReportsLayout />}>
                <Route index element={<Navigate to="/reports/irrigation" replace />} />
                <Route path="irrigation" element={<IrrigationReport />} />
                <Route path="fertilization" element={<FertilizationReport />} />
                <Route path="phytosanitary" element={<PhytosanitaryReport />} />
                <Route path="harvesting" element={<HarvestingReport />} />
                <Route path="production-cost" element={<ProductionCostReport />} />
                <Route path="history/:type/:plotId" element={<PlotOperationsHistoryPage />} />
              </Route>
              <Route path="users" element={<UsersPage />} />
              <Route path="notifications" element={<NotificationsPage />} />
              <Route path="sync" element={<SyncPage />} />
              <Route path="logs" element={<LogsPage />} />
              <Route path="help" element={<HelpPage />} />
            </Route>
            <Route path="*" element={<NotFoundPage />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    </QueryClientProvider>
  </ThemeProvider>
  );
};

export default App;
