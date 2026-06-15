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
  // Render the login form IMMEDIATELY (the common case — an admin already
  // exists). We check setup-status in the background and only switch to the
  // one-time SignupPage if the backend actually reports needs_setup. This
  // avoids blocking the whole login screen behind a network round-trip.
  const [needsSetup, setNeedsSetup] = useState(false);

  useEffect(() => {
    // Once we've confirmed an admin exists, never ask again — setup is a
    // one-time, irreversible state, so this request is pure waste afterwards.
    try { if (localStorage.getItem('flehty.setupDone') === '1') return; } catch { /* ignore */ }
    let cancelled = false;
    auth
      .setupStatus()
      .then((s) => {
        if (cancelled) return;
        if (s?.needs_setup) setNeedsSetup(true);
        else { try { localStorage.setItem('flehty.setupDone', '1'); } catch { /* ignore */ } }
      })
      .catch(() => { /* assume an admin exists — keep the login form */ });
    return () => { cancelled = true; };
  }, []);

  return needsSetup ? <SignupPage /> : <LoginPage />;
};

export default SetupGate;