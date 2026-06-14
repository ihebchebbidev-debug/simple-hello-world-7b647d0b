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
      <div className="relative w-full max-w-md rounded-xl bg-card p-6 shadow-lg">
        <h3 className="text-lg font-semibold">{t('mobilePrompt.title', 'Open on mobile?')}</h3>
        <p className="mt-2 text-sm text-muted-foreground">
          {t(
            'mobilePrompt.body',
            "You can continue in the web app or download the Android APK to open the native app on your device."
          )}
        </p>

        <div className="mt-4 flex gap-3">
          <button
            onClick={onClose}
            className="btn-outline"
            type="button"
          >
            {t('mobilePrompt.continueWeb', 'Continue in web')}
          </button>

          <a
            href="/mobileapp/app-debug.apk"
            className="btn-primary"
            download
            onClick={onClose}
          >
            {t('mobilePrompt.downloadApk', 'Download APK')}
          </a>
        </div>

        <p className="mt-3 text-[12px] text-muted-foreground">
          {t(
            'mobilePrompt.note',
            "Note: If the APK doesn't download, make sure 'app-debug.apk' is available at /mobileapp/app-debug.apk on the server (copy the generated APK into the app's public/mobileapp folder)."
          )}
        </p>
      </div>
    </div>
  );
};

export default MobileAppPrompt;
