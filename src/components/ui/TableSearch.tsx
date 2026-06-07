import iconSearch from '@/assets/icons/icon-search.png';

interface TableSearchProps {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  className?: string;
  count?: number;
  total?: number;
  resultLabel?: string;
}

const TableSearch = ({
  value,
  onChange,
  placeholder = 'Rechercher...',
  className = '',
  count,
  total,
  resultLabel = 'résultats',
}: TableSearchProps) => {
  const showCounter = typeof count === 'number' && typeof total === 'number';
  return (
    <div className={`flex items-center gap-2 ${className}`}>
      {showCounter && (
        <span className="text-[11px] text-muted-foreground whitespace-nowrap tabular-nums">
          {count} / {total} {resultLabel}
        </span>
      )}
      <div className="relative">
        <img
          src={iconSearch}
          alt=""
          className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 opacity-50 pointer-events-none"
        />
        <input
          type="text"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          className="h-8 w-56 rounded-md pl-8 pr-7 text-[12px] bg-[hsl(var(--surface-container-highest))] focus:outline-none focus:ring-1 focus:ring-ring"
        />
        {value && (
          <button
            type="button"
            onClick={() => onChange('')}
            aria-label="Clear"
            className="absolute right-1.5 top-1/2 -translate-y-1/2 h-5 w-5 inline-flex items-center justify-center rounded-full text-muted-foreground hover:text-foreground hover:bg-[hsl(var(--surface-container))] transition-colors"
          >
            ✕
          </button>
        )}
      </div>
    </div>
  );
};

export default TableSearch;
