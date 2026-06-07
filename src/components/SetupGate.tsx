import { useEffect, useState } from 'react';
import { auth } from '@/lib/auth';
import LoginPage from '@/features/auth/LoginPage';
import SignupPage from '@/features/auth/SignupPage';

/**
 * Renders the one-time SignupPage when the backend reports no admin
 * exists yet, otherwise falls back to the regular LoginPage. Once the
 * first admin is created the API will report needs_setup=false and this
 * gate becomes a pure pass-through to LoginPage forever.
 */
const SetupGate = () => {
  const [needsSetup, setNeedsSetup] = useState<boolean | null>(null);

  useEffect(() => {
    let cancelled = false;
    auth
      .setupStatus()
      .then((s) => { if (!cancelled) setNeedsSetup(!!s?.needs_setup); })
      .catch(() => { if (!cancelled) setNeedsSetup(false); });
    return () => { cancelled = true; };
  }, []);

  if (needsSetup === null) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="h-8 w-8 rounded-full border-2 border-[hsl(var(--primary-glow))] border-t-transparent animate-spin" />
      </div>
    );
  }

  return needsSetup ? <SignupPage /> : <LoginPage />;
};

export default SetupGate;