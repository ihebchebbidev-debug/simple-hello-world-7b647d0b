import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import iconConfig from '@/assets/icons/icon-config.png';
import iconPlots from '@/assets/icons/icon-plots.png';
import iconFert from '@/assets/icons/icon-fertilization.png';
import iconPhyto from '@/assets/icons/icon-phytosanitary.png';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';
import iconUsers from '@/assets/icons/icon-users.png';

interface Tile {
  to: string;
  icon: string;
  titleKey: string;
  bodyKey: string;
}

const tiles: Tile[] = [
  { to: '/plots',       icon: iconPlots,      titleKey: 'configuration.tiles.plots.title',       bodyKey: 'configuration.tiles.plots.body' },
  { to: '/campaigns',   icon: iconConfig,     titleKey: 'configuration.tiles.campaigns.title',   bodyKey: 'configuration.tiles.campaigns.body' },
  { to: '/pests',       icon: iconPhyto,      titleKey: 'configuration.tiles.pests.title',       bodyKey: 'configuration.tiles.pests.body' },
  { to: '/fertilizers', icon: iconFert,       titleKey: 'configuration.tiles.fertilizers.title', bodyKey: 'configuration.tiles.fertilizers.body' },
  { to: '/pesticides',  icon: iconPhyto,      titleKey: 'configuration.tiles.pesticides.title',  bodyKey: 'configuration.tiles.pesticides.body' },
  { to: '/water',       icon: iconIrrigation, titleKey: 'configuration.tiles.water.title',       bodyKey: 'configuration.tiles.water.body' },
  { to: '/labor',       icon: iconUsers,      titleKey: 'configuration.tiles.labor.title',       bodyKey: 'configuration.tiles.labor.body' },
];

const ConfigurationPage = () => {
  const { t } = useTranslation();

  return (
    <section className="space-y-6">
      <header className="flex items-center gap-3">
        <img src={iconConfig} alt="" className="h-9 w-9 shrink-0" />
        <div>
          <h1 className="display-md text-foreground">{t('configuration.title', 'Configuration')}</h1>
          <p className="text-sm text-muted-foreground">
            {t('configuration.subtitle', 'Reference data used across the application.')}
          </p>
        </div>
      </header>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {tiles.map((tile) => (
          <Link
            key={tile.to}
            to={tile.to}
            className="group rounded-lg border border-border bg-card p-4 transition-colors hover:bg-muted/40"
          >
            <div className="flex items-start gap-3">
              <img src={tile.icon} alt="" className="h-9 w-9 shrink-0" />
              <div className="min-w-0">
                <h3 className="font-semibold text-foreground group-hover:text-primary-glow">
                  {t(tile.titleKey)}
                </h3>
                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                  {t(tile.bodyKey)}
                </p>
              </div>
            </div>
          </Link>
        ))}
      </div>
    </section>
  );
};

export default ConfigurationPage;
