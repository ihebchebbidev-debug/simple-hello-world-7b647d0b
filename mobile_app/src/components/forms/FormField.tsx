import type { ReactNode } from 'react';

interface Props { label: string; children: ReactNode; suffix?: ReactNode; className?: string }

const FormField = ({ label, children, suffix, className }: Props) => (
  <div className={className}>
    <label className="label-md mb-2 block">{label}</label>
    {suffix ? (
      <div className="flex gap-2 items-center">{children}<span className="text-sm text-muted-foreground font-medium whitespace-nowrap">{suffix}</span></div>
    ) : children}
  </div>
);

export default FormField;
