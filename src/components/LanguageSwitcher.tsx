import { useTranslation } from 'react-i18next';
import frFlag from '@/assets/flags/fr.png';
import enFlag from '@/assets/flags/en.png';
import { setAdminLang, type AdminLang } from '@/i18n';

const LanguageSwitcher = () => {
  const { i18n } = useTranslation();
  const current = (i18n.language?.startsWith('en') ? 'en' : 'fr') as AdminLang;

  const langs: { code: AdminLang; flag: string }[] = [
    { code: 'en', flag: enFlag },
    { code: 'fr', flag: frFlag },
  ];

  return (
    <div className="inline-flex items-center rounded-md border border-border bg-[hsl(var(--surface-container-highest))] p-0.5">
      {langs.map(({ code, flag }) => {
        const active = current === code;
        return (
          <button
            key={code}
            onClick={() => setAdminLang(code)}
            aria-pressed={active}
            title={code.toUpperCase()}
            className={`flex items-center gap-1.5 rounded px-2 py-1 text-[11px] font-semibold transition-colors ${
              active
                ? 'bg-primary text-primary-foreground shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            <img src={flag} alt="" className="h-3 w-4 rounded-[2px] object-cover" />
            {code.toUpperCase()}
          </button>
        );
      })}
    </div>
  );
};

export default LanguageSwitcher;
