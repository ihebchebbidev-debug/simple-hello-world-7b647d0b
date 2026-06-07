import { lazy, Suspense, useEffect } from 'react';
import { IonApp, IonSpinner, setupIonicReact } from '@ionic/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, HashRouter, Navigate, Route, Routes, useNavigate } from 'react-router-dom';
const Router: React.ComponentType<{ children?: React.ReactNode; basename?: string }> =
  import.meta.env.VITE_USE_HASH_ROUTER === 'true' ? HashRouter : BrowserRouter;


/* Ionic core CSS — required */
import '@ionic/react/css/core.css';
import '@ionic/react/css/normalize.css';
import '@ionic/react/css/structure.css';
import '@ionic/react/css/typography.css';
import '@ionic/react/css/padding.css';
import '@ionic/react/css/flex-utils.css';
import '@ionic/react/css/display.css';

import MobileShell from './MobileShell';
import { ThemeProvider } from '@/hooks/useTheme';
import { startOfflineQueue } from '@/lib/offlineQueue';
import { authStore } from '@/lib/auth';
import { registerUnauthorizedHandler } from '@/lib/api';
import { hideSplash, setStatusBarDark } from '@/lib/native';
import '@/i18n';

// Lazy routes — keep the first paint (login) lean. Each screen ships as
// its own chunk so technicians on slow rural connections only pay for
// the page they open.
const LoginPage          = lazy(() => import('@/features/auth/LoginPage'));
const ForgotPasswordPage = lazy(() => import('@/features/auth/ForgotPasswordPage'));
const HomePage           = lazy(() => import('@/features/home/HomePage'));
const IrrigationPage     = lazy(() => import('@/features/operations/IrrigationPage'));
const FertilizationPage  = lazy(() => import('@/features/operations/FertilizationPage'));
const PhytosanitaryPage  = lazy(() => import('@/features/operations/PhytosanitaryPage'));
const HarvestPage        = lazy(() => import('@/features/operations/HarvestPage'));
const SyncPage           = lazy(() => import('@/features/sync/SyncPage'));
const SettingsPage       = lazy(() => import('@/features/settings/SettingsPage'));
const NotFoundPage       = lazy(() => import('@/features/system/NotFoundPage'));
const DiagnosticsPage    = lazy(() => import('@/features/system/DiagnosticsPage'));

setupIonicReact({ mode: 'md', animated: true });

// Reference data (plots, fertilizers, …) rarely changes during a field
// session — keep it fresh for a minute, cached for five, and never
// refetch on focus to save data on mobile networks.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 60_000,
      gcTime: 5 * 60_000,
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
});

const RouteFallback = () => (
  <div className="flex h-full w-full items-center justify-center p-10">
    <IonSpinner name="crescent" />
  </div>
);

const Bootstrapper = () => {
  const navigate = useNavigate();
  useEffect(() => {
    registerUnauthorizedHandler(() => navigate('/login', { replace: true }));
    void authStore.bootstrap();
    void setStatusBarDark();
    void hideSplash();
    const stop = startOfflineQueue();
    return () => { stop(); };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return null;
};

const App = () => (
  <ThemeProvider>
    <QueryClientProvider client={queryClient}>
      <IonApp>
        <Router basename={import.meta.env.BASE_URL.replace(/\/$/, '')}>
          <Bootstrapper />
          <Suspense fallback={<RouteFallback />}>
            <Routes>
              <Route path="/" element={<Navigate to="/home" replace />} />
              <Route path="/login" element={<LoginPage />} />
              <Route path="/forgot-password" element={<ForgotPasswordPage />} />
              <Route element={<MobileShell />}>
                <Route path="/home" element={<HomePage />} />
                <Route path="/irrigation" element={<IrrigationPage />} />
                <Route path="/fertilization" element={<FertilizationPage />} />
                <Route path="/phytosanitary" element={<PhytosanitaryPage />} />
                <Route path="/harvest" element={<HarvestPage />} />
                <Route path="/sync" element={<SyncPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="/diagnostics" element={<DiagnosticsPage />} />
              </Route>
              <Route path="*" element={<NotFoundPage />} />
            </Routes>
          </Suspense>
        </Router>
      </IonApp>
    </QueryClientProvider>
  </ThemeProvider>
);

export default App;
