import { lazy, Suspense } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Toaster } from 'sonner';
import AppLayout from './AppLayout';
import RequireAuth from '@/components/RequireAuth';
import BackendWakeup from '@/components/BackendWakeup';
import { ThemeProvider } from '@/hooks/useTheme';
import SetupGate from '@/components/SetupGate';

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
      staleTime: 60_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: false,
      // Render free tier cold-starts can take 30-60s. Retry generously
      // (network + 5xx only — TanStack already skips 4xx) so the report
      // pages don't flash "Impossible de charger" during dyno wake-up.
      retry: (failureCount, error: unknown) => {
        const status = (error as { response?: { status?: number } } | undefined)?.response?.status;
        if (status && status >= 400 && status < 500) return false;
        return failureCount < 8;
      },
      retryDelay: (attempt) => Math.min(1500 * 2 ** attempt, 10_000),
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

const App = () => (
  <ThemeProvider>
    <QueryClientProvider client={queryClient}>
      <Toaster theme="dark" position="top-right" richColors closeButton />
      <BackendWakeup>
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
      </BackendWakeup>
    </QueryClientProvider>
  </ThemeProvider>
);

export default App;
