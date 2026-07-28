import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Outlet } from 'react-router-dom';
import AppSidebar from '@/components/layout/AppSidebar';
import TopBar from '@/components/layout/TopBar';
import { AiChatProvider } from '@/features/ai-chat/AiChatProvider';
import AiChatPanel from '@/features/ai-chat/AiChatPanel';
import { api } from '@/lib/api';

/** Unwrap the paginated `{ data: [...] }` envelope into a plain array. */
async function fetchList(path: string) {
  const { data } = await api.get<{ data: unknown[] }>(path, { params: { per_page: 100 } });
  const payload = (data as { data?: unknown[] }).data;
  return Array.isArray(payload) ? payload : [];
}

const AppLayout = () => {
  const qc = useQueryClient();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    return localStorage.getItem('agrysync.sidebar.collapsed') === '1';
  });

  useEffect(() => {
    localStorage.setItem('agrysync.sidebar.collapsed', collapsed ? '1' : '0');
  }, [collapsed]);

  // Warm the shared reference caches (campaigns + plots) the moment the shell
  // mounts, so opening a report doesn't wait on a separate round-trip first —
  // these keys match useReportFilters / usePlotsForFilter, so the report's own
  // hooks read straight from cache. Removes a request waterfall on report loads.
  useEffect(() => {
    void qc.prefetchQuery({ queryKey: ['report-filter-campaigns'], queryFn: () => fetchList('/campaigns'), staleTime: 30_000 });
    void qc.prefetchQuery({ queryKey: ['report-filter-plots'], queryFn: () => fetchList('/plots'), staleTime: 30_000 });
  }, [qc]);

  return (
    <AiChatProvider>
    <div className="flex h-screen overflow-hidden bg-background">
      {sidebarOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/60 lg:hidden"
          onClick={() => setSidebarOpen(false)}
          aria-hidden="true"
        />
      )}

      <div
        className={`fixed inset-y-0 left-0 z-50 transform transition-[transform,width] duration-200 ease-out lg:relative lg:translate-x-0 lg:z-auto ${
          sidebarOpen ? 'translate-x-0' : '-translate-x-full'
        } ${collapsed ? 'w-[72px]' : 'w-[230px]'}`}
      >
        <AppSidebar collapsed={collapsed} onClose={() => setSidebarOpen(false)} />
      </div>

      <div className="flex flex-1 flex-col overflow-hidden min-w-0">
        <TopBar
          collapsed={collapsed}
          onToggleCollapse={() => setCollapsed((c) => !c)}
          menuButton={
            <button
              type="button"
              aria-label="Open navigation"
              className="lg:hidden inline-flex items-center justify-center rounded-md p-2 text-foreground/80 hover:bg-[hsl(var(--surface-bright))] hover:text-foreground transition-colors mr-1"
              onClick={() => setSidebarOpen(true)}
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
          }
        />
        <main className="flex-1 overflow-y-auto px-3 py-4 sm:px-6 sm:py-5 lg:px-8 animate-fade-in">
          <div className="mx-auto w-full max-w-[1400px]">
            <Outlet />
          </div>
        </main>
      </div>
      <AiChatPanel />
    </div>
    </AiChatProvider>
  );
};

export default AppLayout;
