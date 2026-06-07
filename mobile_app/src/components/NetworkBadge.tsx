import { Wifi, WifiOff } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const NetworkBadge = ({ online }: { online: boolean }) => {
  const { t } = useTranslation();
  return (
    <div className="flex items-center gap-1.5 text-xs">
      {online ? (
        <><Wifi className="h-4 w-4 text-[hsl(var(--primary-glow))]" /><span className="text-muted-foreground">{t('common.online')}</span></>
      ) : (
        <><WifiOff className="h-4 w-4 text-[hsl(var(--accent-warning))]" /><span className="text-muted-foreground">{t('common.offline')}</span></>
      )}
    </div>
  );
};

export default NetworkBadge;
