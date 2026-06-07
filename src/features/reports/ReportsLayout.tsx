import { useTranslation } from 'react-i18next';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';
import iconFertilization from '@/assets/icons/icon-fertilization.png';
import iconPhytosanitary from '@/assets/icons/icon-phytosanitary.png';
import iconHarvest from '@/assets/icons/icon-harvest.png';
import iconCosts from '@/assets/icons/icon-costs.png';

const reportTabs = [
  { path: '/reports/irrigation', labelKey: 'reports.tab.irrigation', icon: iconIrrigation },
  { path: '/reports/fertilization', labelKey: 'reports.tab.fertilization', icon: iconFertilization },
  { path: '/reports/phytosanitary', labelKey: 'reports.tab.phytosanitary', icon: iconPhytosanitary },
  { path: '/reports/harvesting', labelKey: 'reports.tab.harvest', icon: iconHarvest },
  { path: '/reports/production-cost', labelKey: 'reports.tab.production-cost', icon: iconCosts },
];

const ReportsLayout = () => {
  const { t } = useTranslation();
  const location = useLocation();

  return (
    <div className="space-y-5 animate-fade-in">
      <div className="no-print">
        <h1 className="headline-lg text-foreground">{t('reports.title')}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{t('reports.subtitle')}</p>
      </div>

      {/* Print-only header */}
      <div className="print-only" style={{ marginBottom: 12 }}>
        <h1 style={{ fontSize: 20, fontWeight: 700, color: '#111' }}>Agri-Sync — {t('reports.title')}</h1>
        <p style={{ fontSize: 11, color: '#444' }}>{new Date().toLocaleString('fr-FR')}</p>
      </div>

      <div className="reports-subnav no-print overflow-x-auto -mx-4 px-4 sm:mx-0 sm:px-0">
        <div className="flex gap-1 rounded-lg p-1 min-w-max bg-[hsl(var(--surface-container-lowest))]">
          {reportTabs.map((tab) => {
            const isActive = location.pathname === tab.path;
            return (
              <NavLink
                key={tab.path}
                to={tab.path}
                className={`flex items-center gap-1.5 rounded-md px-3 py-2 text-[12px] font-medium transition-all duration-200 whitespace-nowrap ${
                  isActive
                    ? 'text-foreground bg-[hsl(var(--surface-container-high))]'
                    : 'text-muted-foreground hover:text-foreground'
                }`}
              >
                <img src={tab.icon} alt="" className="h-6 w-6" loading="lazy" />
                {t(tab.labelKey)}
              </NavLink>
            );
          })}
        </div>
      </div>

      <Outlet />
    </div>
  );
};

export default ReportsLayout;
