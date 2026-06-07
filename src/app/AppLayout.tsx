import { useEffect, useState } from 'react';
import { Outlet } from 'react-router-dom';
import AppSidebar from '@/components/layout/AppSidebar';
import TopBar from '@/components/layout/TopBar';

const AppLayout = () => {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    if (typeof window === 'undefined') return false;
    return localStorage.getItem('agrysync.sidebar.collapsed') === '1';
  });

  useEffect(() => {
    localStorage.setItem('agrysync.sidebar.collapsed', collapsed ? '1' : '0');
  }, [collapsed]);

  return (
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
    </div>
  );
};

export default AppLayout;
