import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router-dom';
import logoIcon from '@/assets/logo-icon.png';
import ThemeSwitcher from '@/components/ThemeSwitcher';
import LanguageSwitcher from '@/components/LanguageSwitcher';
import OpenMobileAppButton from '@/components/OpenMobileAppButton';
import { auth } from '@/lib/auth';

const ForgotPasswordPage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (loading) return;
    setLoading(true);
    setError(null);
    try {
      await auth.forgotPassword(email);
      setSent(true);
    } catch (err: unknown) {
      // Generic confirmation to avoid account enumeration on 404.
      // Surface only true network/server errors.
      const e = err as { response?: { status?: number; data?: { error?: { message?: string } } } };
      const status = e?.response?.status;
      if (status && status >= 500) {
        setError(e?.response?.data?.error?.message ?? t('forgot.error', 'Une erreur est survenue. Réessayez.'));
      } else {
        setSent(true);
      }
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

      <div className="absolute inset-0 overflow-hidden pointer-events-none">
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

        <div className="rounded-xl p-6 bg-card transition-all duration-300">
          <h2 className="text-base font-semibold text-foreground">
            {t('forgot.title', 'Mot de passe oublié')}
          </h2>
          <p className="mt-0.5 text-[13px] text-muted-foreground">
            {sent
              ? t('forgot.sentTitle', 'Vérifiez votre boîte mail')
              : t('forgot.subtitle', 'Entrez votre email pour recevoir un lien de réinitialisation.')}
          </p>

          {sent ? (
            <div className="mt-6 space-y-4 animate-fade-in">
              <div className="rounded-lg border border-[hsl(var(--primary)/0.2)] bg-[hsl(var(--primary)/0.06)] p-3">
                <p className="text-[13px] text-foreground">
                  {t('forgot.sent', 'Si un compte existe pour cet email, un lien a été envoyé.')}
                </p>
                {email && (
                  <p className="mt-1 text-[12px] text-muted-foreground break-all">{email}</p>
                )}
              </div>
              <button
                type="button"
                onClick={() => navigate('/login')}
                className="btn-primary-glass w-full h-10"
              >
                {t('forgot.backToLogin', '← Retour à la connexion')}
              </button>
              <button
                type="button"
                onClick={() => { setSent(false); setError(null); }}
                className="block w-full text-center text-[12px] text-muted-foreground hover:text-foreground transition-colors"
              >
                {t('forgot.tryAgain', 'Renvoyer avec un autre email')}
              </button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="mt-6 space-y-4">
              <div>
                <label className="label-md mb-1.5 block">{t('login.email')}</label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  maxLength={180}
                  autoFocus
                  autoComplete="email"
                  className="cl-input"
                  placeholder="admin@agri-sync.tn"
                />
              </div>
              {error && (
                <p className="text-[12px] text-[hsl(var(--accent-danger))]" role="alert">
                  {error}
                </p>
              )}
              <button
                type="submit"
                disabled={loading || !email}
                className="btn-primary-glass w-full h-10 disabled:opacity-50"
              >
                {loading ? t('login.submitting') : t('forgot.submit', 'Envoyer le lien')}
              </button>

              <Link
                to="/login"
                className="block w-full text-center text-[12px] text-[hsl(var(--primary-glow))] transition-colors hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-[hsl(var(--primary-glow))] rounded"
              >
                {t('forgot.backToLogin', '← Retour à la connexion')}
              </Link>
            </form>
          )}
        </div>
      </div>
    </div>
  );
};

export default ForgotPasswordPage;
