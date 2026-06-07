import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import logoIcon from '@/assets/logo-icon.png';
import ThemeSwitcher from '@/components/ThemeSwitcher';
import LanguageSwitcher from '@/components/LanguageSwitcher';
import OpenMobileAppButton from '@/components/OpenMobileAppButton';
import { auth } from '@/lib/auth';
import PasswordInput from '@/components/PasswordInput';

const SignupPage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (loading) return;
    setError(null);
    if (password.length < 8) {
      setError(t('signup.passwordTooShort', 'Password must be at least 8 characters.'));
      return;
    }
    if (password !== confirm) {
      setError(t('signup.passwordMismatch', 'Passwords do not match.'));
      return;
    }
    setLoading(true);
    try {
      await auth.register({ name, email, password, password_confirmation: confirm });
      navigate('/dashboard', { replace: true });
    } catch (e: any) {
      const msg = e?.response?.data?.message || t('signup.error', 'Could not create the admin account.');
      setError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="absolute top-4 right-4 flex items-center gap-2 z-10">
        <OpenMobileAppButton />
        <LanguageSwitcher />
        <ThemeSwitcher />
      </div>

      <div className="absolute inset-0 overflow-hidden">
        <div
          className="absolute top-1/4 left-1/2 -translate-x-1/2 w-[600px] h-[600px] rounded-full opacity-[0.04]"
          style={{ background: 'radial-gradient(circle, hsl(142 72% 29%), transparent)' }}
        />
      </div>

      <div className="relative w-full max-w-sm px-4 animate-fade-in">
        <div className="mb-8 flex flex-col items-center gap-3">
          <img src={logoIcon} alt="Agri-Sync" className="h-14 w-14 rounded-xl" />
          <div className="text-center">
            <h1 className="text-xl font-bold tracking-wide text-[hsl(var(--primary-glow))]">Agri-Sync</h1>
            <p className="text-[11px] mt-0.5 text-muted-foreground">Administration</p>
          </div>
        </div>

        <div className="rounded-xl p-6 bg-card">
          <h2 className="text-base font-semibold text-foreground">
            {t('signup.title', 'Create the first administrator')}
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            {t('signup.subtitle', 'No admin account exists yet. This one-time form creates the initial admin.')}
          </p>

          <form onSubmit={handleSubmit} className="mt-6 space-y-4">
            <div>
              <label className="label-md mb-1.5 block">{t('signup.name', 'Full name')}</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                minLength={2}
                maxLength={120}
                className="cl-input"
                placeholder="Jane Doe"
              />
            </div>
            <div>
              <label className="label-md mb-1.5 block">{t('login.email')}</label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                maxLength={180}
                className="cl-input"
                placeholder="admin@agri-sync.tn"
              />
            </div>
            <div>
              <label className="label-md mb-1.5 block">{t('login.password')}</label>
              <PasswordInput
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={8}
                maxLength={200}
                inputClassName="cl-input"
                placeholder="••••••••"
              />
            </div>
            <div>
              <label className="label-md mb-1.5 block">{t('signup.confirm', 'Confirm password')}</label>
              <PasswordInput
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                required
                minLength={8}
                maxLength={200}
                inputClassName="cl-input"
                placeholder="••••••••"
              />
            </div>

            {error && (
              <p className="text-[12px] text-[hsl(var(--accent-danger))]" role="alert">
                {error}
              </p>
            )}

            <button
              type="submit"
              disabled={loading}
              className="btn-primary-glass w-full h-10 disabled:opacity-50"
            >
              {loading ? t('signup.submitting', 'Creating…') : t('signup.submit', 'Create admin account')}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
};

export default SignupPage;