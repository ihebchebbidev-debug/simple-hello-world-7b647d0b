import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage } from '@ionic/react';
import { authStore } from '@/lib/auth';

const ForgotPasswordPage = () => {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (loading) return;
    setLoading(true); setError(null);
    try { await authStore.forgotPassword(email.trim()); setSent(true); }
    catch (err: unknown) { setError(err instanceof Error ? err.message : t('auth.error')); }
    finally { setLoading(false); }
  };

  return (
    <IonPage>
      <IonContent fullscreen>
        <div className="flex flex-col min-h-screen px-5 pt-12 pb-8">
          <h1 className="text-xl font-bold text-foreground mb-2">{t('auth.forgotTitle')}</h1>
          <p className="text-sm text-muted-foreground mb-6">{t('auth.forgotHint')}</p>
          {sent ? (
            <div className="rounded-xl p-4 bg-[hsl(var(--primary)/0.12)] text-sm">{t('auth.forgotSent')}</div>
          ) : (
            <form onSubmit={onSubmit} className="space-y-4">
              <input type="email" required value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="cl-input h-12" placeholder={t('auth.email')} />
              {error && <p className="text-sm text-[hsl(var(--accent-danger))]">{error}</p>}
              <button type="submit" disabled={loading} className="btn-primary-glass w-full h-12">
                {loading ? t('auth.signingIn') : t('auth.signIn')}
              </button>
            </form>
          )}
          <Link to="/login" className="block text-center text-xs text-muted-foreground mt-6 underline">
            {t('auth.backToLogin')}
          </Link>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default ForgotPasswordPage;
