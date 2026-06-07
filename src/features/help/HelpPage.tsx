import { useTranslation } from 'react-i18next';
import iconHelp from '@/assets/icons/icon-help.png';
import { BACKEND_URL } from '@/lib/api';
import shotDashboard from '@/assets/help/dashboard.png';
import shotReports from '@/assets/help/reports.png';
import shotFertilization from '@/assets/help/fertilization.png';
import shotPhytosanitary from '@/assets/help/phytosanitary.png';
import shotHarvesting from '@/assets/help/harvesting.png';
import shotCost from '@/assets/help/production-cost.png';
import shotConfig from '@/assets/help/configuration.png';
import shotUsers from '@/assets/help/users.png';

const walkthrough = [
  { key: 'dashboard',     image: shotDashboard },
  { key: 'reports',       image: shotReports },
  { key: 'fertilization', image: shotFertilization },
  { key: 'phytosanitary', image: shotPhytosanitary },
  { key: 'harvest',       image: shotHarvesting },
  { key: 'costs',         image: shotCost },
  { key: 'configuration', image: shotConfig },
  { key: 'users',         image: shotUsers },
];

const moduleSections = [
  { key: 'dashboard', icon: '📊' },
  { key: 'plots', icon: '🌱' },
  { key: 'fertilizers', icon: '🧪' },
  { key: 'pesticides', icon: '🧴' },
  { key: 'water', icon: '💧' },
  { key: 'labor', icon: '👷' },
  { key: 'campaigns', icon: '📅' },
  { key: 'pests', icon: '🐛' },
  { key: 'reports', icon: '📈' },
  { key: 'sync', icon: '🔄' },
  { key: 'logs', icon: '📜' },
  { key: 'users', icon: '👤' },
];

const HelpPage = () => {
  const { t } = useTranslation();

  return (
    <section className="space-y-8 max-w-6xl">
      <header className="flex items-center gap-3">
        <img src={iconHelp} alt="" className="h-9 w-9 shrink-0" />
        <div>
          <h1 className="display-md text-foreground">{t('help.title')}</h1>
          <p className="text-sm text-muted-foreground">{t('help.subtitle')}</p>
        </div>
      </header>

      <div className="rounded-lg border border-border bg-muted/20 p-4 text-sm">
        <h2 className="font-semibold text-foreground mb-2">{t('help.aboutTitle')}</h2>
        <p className="text-muted-foreground">{t('help.aboutBody')}</p>
        <p className="text-xs text-muted-foreground mt-3">
          <strong>API:</strong> <code className="text-primary">{BACKEND_URL}</code>
        </p>
      </div>

      {/* Visual walkthrough */}
      <div>
        <h2 className="text-base font-semibold text-foreground mb-4">{t('help.walkthroughTitle')}</h2>
        <div className="space-y-6">
          {walkthrough.map((s, idx) => {
            const features = t(`help.walkthrough.${s.key}.features`, { returnObjects: true }) as string[];
            return (
              <div key={s.key} className="rounded-lg border border-border bg-background p-4 grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div className="rounded-md overflow-hidden border border-border bg-muted/20">
                  <img src={s.image} alt={t(`help.walkthrough.${s.key}.title`)} loading="lazy" className="w-full h-auto block" />
                </div>
                <div className="min-w-0 flex flex-col">
                  <span className="text-[11px] font-semibold uppercase tracking-wider text-primary">
                    {t('help.stepLabel')} {idx + 1}
                  </span>
                  <h3 className="mt-1 text-lg font-semibold text-foreground">
                    {t(`help.walkthrough.${s.key}.title`)}
                  </h3>
                  <p className="mt-2 text-sm text-muted-foreground leading-relaxed">
                    {t(`help.walkthrough.${s.key}.desc`)}
                  </p>
                  <ul className="mt-4 space-y-2">
                    {Array.isArray(features) && features.map((f, i) => (
                      <li key={i} className="flex items-start gap-2 text-xs text-muted-foreground leading-relaxed">
                        <span className="mt-[6px] h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                        <span>{f}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Module reference */}
      <div>
        <h2 className="text-base font-semibold text-foreground mb-3">{t('help.title')}</h2>
        <div className="grid sm:grid-cols-2 gap-3">
          {moduleSections.map((s) => (
            <div key={s.key} className="rounded-lg border border-border bg-background p-4">
              <h3 className="font-medium text-foreground flex items-center gap-2">
                <span>{s.icon}</span>{t(`help.sections.${s.key}.title`)}
              </h3>
              <p className="text-xs text-muted-foreground mt-1.5 leading-relaxed">{t(`help.sections.${s.key}.body`)}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
};

export default HelpPage;
