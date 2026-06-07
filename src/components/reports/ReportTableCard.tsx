import type { ReactNode } from 'react';
import TablePagination from '@/components/ui/TablePagination';
import TableSearch from '@/components/ui/TableSearch';
import type { PageSize } from '@/hooks/usePagination';

interface Props {
  title: string;
  subtitle?: string;
  search: string;
  onSearchChange: (v: string) => void;
  filteredCount: number;
  totalCount: number;
  pagination: {
    page: number;
    pageSize: PageSize;
    pageCount: number;
    total: number;
    start: number;
    end: number;
    setPage: (p: number) => void;
    setPageSize: (s: PageSize) => void;
  };
  children: ReactNode;
  minWidth?: number;
}

const ReportTableCard = ({
  title,
  subtitle,
  search,
  onSearchChange,
  filteredCount,
  totalCount,
  pagination,
  children,
  minWidth,
}: Props) => (
  <div className="stat-card">
    <div className="mb-3 flex items-start justify-between gap-2 flex-wrap">
      <div>
        <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
        {subtitle && <p className="mt-1 text-[11px] text-muted-foreground">{subtitle}</p>}
      </div>
      <div className="no-print">
        <TableSearch
          value={search}
          onChange={onSearchChange}
          count={filteredCount}
          total={totalCount}
        />
      </div>
    </div>

    <div className="overflow-x-auto -mx-3 sm:mx-0 px-3 sm:px-0">
      <div style={minWidth ? { minWidth } : undefined}>{children}</div>
    </div>

    <div className="table-pagination no-print">
      <TablePagination
        page={pagination.page}
        pageSize={pagination.pageSize}
        pageCount={pagination.pageCount}
        total={pagination.total}
        start={pagination.start}
        end={pagination.end}
        onPageChange={pagination.setPage}
        onPageSizeChange={pagination.setPageSize}
      />
    </div>
  </div>
);

export default ReportTableCard;
