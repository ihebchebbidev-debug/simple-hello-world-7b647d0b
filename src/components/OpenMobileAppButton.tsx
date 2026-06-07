import { Smartphone } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * Small pill button used in the auth screens' top-right cluster.
 * Hard-navigates to the deployed mobile app login.
 */
const OpenMobileAppButton = () => {
  const { t } = useTranslation();
  return (
    <a
      href="https://agrysync.vercel.app/mobileapp/login"
      className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background/60 px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors backdrop-blur"
      title={t('common.openMobileApp', 'Open Mobile App')}
    >
      <Smartphone className="h-3.5 w-3.5" />
      <span className="hidden sm:inline">{t('common.openMobileApp', 'Open Mobile App')}</span>
    </a>
  );
};

export default OpenMobileAppButton;
