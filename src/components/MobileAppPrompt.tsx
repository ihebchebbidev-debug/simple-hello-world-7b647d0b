import { Smartphone, Download } from 'lucide-react';
import { useTranslation } from 'react-i18next';

type Props = {
  open: boolean;
  onClose: () => void;
};

const MobileAppPrompt = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      <div className="absolute inset-0 bg-black/40" onClick={onClose} />
      <div className="relative w-full max-w-sm rounded-xl bg-card p-6 shadow-lg">
        <h3 className="text-base font-semibold">
          {t('mobilePrompt.title', 'Get the mobile app')}
        </h3>
        <p className="mt-1.5 text-[13px] text-muted-foreground">
          {t(
            'mobilePrompt.body',
            'Access the field app directly in your browser or install the Android APK on your device.'
          )}
        </p>

        <div className="mt-5 flex flex-col gap-3">
          <a
            href="/mobileapp/"
            className="btn-primary-glass flex items-center justify-center gap-2 h-10 w-full rounded-lg text-sm font-medium"
            onClick={onClose}
          >
            <Smartphone className="h-4 w-4" />
            {t('mobilePrompt.openWeb', 'Open mobile web app')}
          </a>

          <a
            href="/mobileapp/app-debug.apk"
            download="flehty.apk"
            className="btn-outline flex items-center justify-center gap-2 h-10 w-full rounded-lg text-sm font-medium"
            onClick={onClose}
          >
            <Download className="h-4 w-4" />
            {t('mobilePrompt.downloadApk', 'Download Android APK')}
          </a>

          <button
            onClick={onClose}
            className="text-[12px] text-muted-foreground hover:text-foreground transition-colors"
            type="button"
          >
            {t('mobilePrompt.continueWeb', 'Continue in admin panel')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default MobileAppPrompt;
