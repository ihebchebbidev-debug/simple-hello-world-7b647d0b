import { useEffect } from 'react';

/**
 * Bridge route: visiting /mobileapp in the dashboard hard-redirects to
 * the static mobile-app bundle served from /mobileapp/index.html under
 * /public. The mobile app uses HashRouter, so all of its internal links
 * (e.g. /mobileapp/#/home, /mobileapp/#/irrigation) survive a refresh.
 */
const MobileAppRedirect = () => {
  useEffect(() => {
    window.location.replace('/mobileapp/index.html');
  }, []);
  return (
    <div className="flex min-h-screen items-center justify-center bg-background text-sm text-muted-foreground">
      Loading mobile app…
    </div>
  );
};

export default MobileAppRedirect;
