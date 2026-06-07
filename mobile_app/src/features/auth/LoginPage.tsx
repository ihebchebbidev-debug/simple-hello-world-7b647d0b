import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage } from '@ionic/react';
import { Loader2 } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import logoIcon from '@/assets/logo-icon.png';
import PasswordInput from '@/components/PasswordInput';

const LoginPage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (loading) return;
    setLoading(true); setError(null);
    try {
      await login(email.trim(), password);
      navigate('/home', { replace: true });
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('auth.error'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <IonPage>
      <IonContent fullscreen>
        <div className="flex flex-col min-h-screen px-5 pt-12 pb-8">
          <header className="text-center mb-10 space-y-2">
            <img src={logoIcon} alt="Agri-Sync" className="h-16 w-16 rounded-2xl mx-auto" />
            <h1 className="text-2xl font-bold text-primary">{t('auth.title')}</h1>
            <p className="text-xs text-muted-foreground">{t('auth.subtitle')}</p>
          </header>
          <form onSubmit={onSubmit} className="space-y-4 flex-1" noValidate>
            <label className="block space-y-1 text-sm">
              <span className="text-muted-foreground">{t('auth.email')}</span>
              <input
                type="email" autoComplete="email" required
                value={email} onChange={(e) => setEmail(e.target.value)}
                className="cl-input h-12"
              />
            </label>
            <label className="block space-y-1 text-sm">
              <span className="text-muted-foreground">{t('auth.password')}</span>
              <PasswordInput
                autoComplete="current-password" required
                value={password} onChange={(e) => setPassword(e.target.value)}
                inputClassName="cl-input h-12"
              />
            </label>
            {error && <p className="text-sm text-[hsl(var(--accent-danger))]">{error}</p>}
            <button type="submit" disabled={loading}
              className="btn-primary-glass w-full h-12 flex items-center justify-center gap-2">
              {loading && <Loader2 className="h-4 w-4 animate-spin" />}
              {loading ? t('auth.signingIn') : t('auth.signIn')}
            </button>
            <div className="text-center">
              <Link to="/forgot-password" className="text-xs text-muted-foreground underline">
                {t('auth.forgotPassword')}
              </Link>
            </div>
          </form>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default LoginPage;
