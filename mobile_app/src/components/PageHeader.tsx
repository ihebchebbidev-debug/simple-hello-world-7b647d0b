import type { ReactNode } from 'react';
import { ChevronLeft } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

interface Props { title: string; icon?: ReactNode; right?: ReactNode; onBack?: () => void; showBack?: boolean }

const PageHeader = ({ title, icon, right, onBack, showBack = true }: Props) => {
  const navigate = useNavigate();
  return (
    <div className="flex items-center gap-3 px-4 pt-5 pb-4">
      {showBack && (
        <button
          type="button"
          onClick={() => (onBack ? onBack() : navigate(-1))}
          className="p-1.5 rounded-lg hover:bg-[hsl(var(--surface-bright))]"
          aria-label="Back"
        >
          <ChevronLeft className="h-6 w-6 text-foreground" />
        </button>
      )}
      {icon && <div className="h-9 w-9 rounded-lg flex items-center justify-center bg-[hsl(var(--surface-container-high))]">{icon}</div>}
      <h1 className="text-lg font-semibold text-foreground flex-1 truncate">{title}</h1>
      {right}
    </div>
  );
};

export default PageHeader;
