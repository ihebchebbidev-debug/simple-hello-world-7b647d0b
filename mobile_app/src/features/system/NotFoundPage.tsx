import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage } from '@ionic/react';

const NotFoundPage = () => {
  const { t } = useTranslation();
  return (
    <IonPage>
      <IonContent fullscreen>
        <div className="flex flex-col items-center justify-center min-h-screen gap-4 px-5 text-center">
          <h1 className="text-2xl font-bold text-foreground">404</h1>
          <p className="text-sm text-muted-foreground">{t('notFound.title')}</p>
          <Link to="/home" className="btn-primary-glass h-11 px-4 flex items-center">{t('notFound.cta')}</Link>
        </div>
      </IonContent>
    </IonPage>
  );
};

export default NotFoundPage;
