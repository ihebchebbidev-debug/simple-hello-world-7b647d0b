import { PAGE_SIZE_OPTIONS, type PageSize } from '@/hooks/usePagination';

interface TablePaginationProps {
  page: number;
  pageSize: PageSize;
  pageCount: number;
  total: number;
  start: number;
  end: number;
  onPageChange: (page: number) => void;
  onPageSizeChange: (size: PageSize) => void;
  hideWhenSinglePage?: boolean;
}

const NavButton = ({
  onClick,
  disabled,
  label,
  children,
}: {
  onClick: () => void;
  disabled: boolean;
  label: string;
  children: React.ReactNode;
}) => (
  <button
    type="button"
    onClick={onClick}
    disabled={disabled}
    aria-label={label}
    className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted-foreground hover:text-foreground hover:bg-[hsl(var(--surface-container))] disabled:opacity-30 disabled:pointer-events-none transition-colors"
  >
    {children}
  </button>
);

const TablePagination = ({
  page,
  pageSize,
  pageCount,
  total,
  start,
  end,
  onPageChange,
  onPageSizeChange,
  hideWhenSinglePage = false,
}: TablePaginationProps) => {
  if (hideWhenSinglePage && total <= PAGE_SIZE_OPTIONS[0]) return null;

  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-border/40 pt-3 text-[11px] text-muted-foreground">
      <div className="flex items-center gap-2">
        <label htmlFor="page-size" className="whitespace-nowrap">Lignes / page</label>
        <select
          id="page-size"
          value={pageSize}
          onChange={(e) => onPageSizeChange(Number(e.target.value) as PageSize)}
          className="h-7 rounded-md bg-[hsl(var(--surface-container-highest))] px-2 text-[11px] text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        >
          {PAGE_SIZE_OPTIONS.map((size) => (
            <option key={size} value={size}>{size}</option>
          ))}
        </select>
      </div>

      <span className="tabular-nums">
        {total === 0 ? '0' : `${start}–${end}`} sur {total}
      </span>

      <div className="flex items-center gap-1">
        <NavButton onClick={() => onPageChange(1)} disabled={page <= 1} label="First">«</NavButton>
        <NavButton onClick={() => onPageChange(page - 1)} disabled={page <= 1} label="Prev">‹</NavButton>
        <span className="px-2 tabular-nums text-foreground">{page} / {pageCount}</span>
        <NavButton onClick={() => onPageChange(page + 1)} disabled={page >= pageCount} label="Next">›</NavButton>
        <NavButton onClick={() => onPageChange(pageCount)} disabled={page >= pageCount} label="Last">»</NavButton>
      </div>
    </div>
  );
};

export default TablePagination;
