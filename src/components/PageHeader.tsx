import type { ReactNode } from 'react';

interface PageHeaderProps {
  icon: string;
  title: string;
  subtitle?: string;
  actions?: ReactNode;
}

const PageHeader = ({ icon, title, subtitle, actions }: PageHeaderProps) => (
  <header className="flex flex-wrap items-end justify-between gap-3">
    <div className="flex items-center gap-3">
      <img src={icon} alt="" className="h-9 w-9 shrink-0" />
      <div>
        <h1 className="display-md text-foreground">{title}</h1>
        {subtitle && <p className="mt-0.5 text-sm text-muted-foreground">{subtitle}</p>}
      </div>
    </div>
    {actions && <div className="flex items-center gap-2">{actions}</div>}
  </header>
);

export default PageHeader;
