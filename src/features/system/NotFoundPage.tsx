import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

const NotFoundPage = () => {
  const { t } = useTranslation();
  return (
    <main className="min-h-screen flex items-center justify-center bg-background text-foreground">
      <div className="text-center space-y-2">
        <h1 className="text-3xl font-bold">404</h1>
        <p className="text-sm text-muted-foreground">{t('system.notFound')}</p>
        <Link to="/dashboard" className="text-primary text-sm hover:underline">
          {t('system.backDashboard')}
        </Link>
      </div>
    </main>
  );
};

export default NotFoundPage;
