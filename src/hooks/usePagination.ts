import { useEffect, useMemo, useState } from 'react';

export const PAGE_SIZE_OPTIONS = [10, 25, 50] as const;
export type PageSize = (typeof PAGE_SIZE_OPTIONS)[number];

interface UsePaginationOptions<T> {
  rows: T[];
  initialPageSize?: PageSize;
  resetKey?: unknown;
}

interface UsePaginationResult<T> {
  page: number;
  pageSize: PageSize;
  pageCount: number;
  total: number;
  start: number;
  end: number;
  pageRows: T[];
  setPage: (page: number) => void;
  setPageSize: (size: PageSize) => void;
}

export function usePagination<T>({
  rows,
  initialPageSize = 10,
  resetKey,
}: UsePaginationOptions<T>): UsePaginationResult<T> {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState<PageSize>(initialPageSize);

  const total = rows.length;
  const pageCount = Math.max(1, Math.ceil(total / pageSize));

  useEffect(() => {
    setPage(1);
  }, [resetKey, pageSize]);

  useEffect(() => {
    if (page > pageCount) setPage(pageCount);
  }, [page, pageCount]);

  const { pageRows, start, end } = useMemo(() => {
    const safePage = Math.min(page, pageCount);
    const startIdx = (safePage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, total);
    return {
      pageRows: rows.slice(startIdx, endIdx),
      start: total === 0 ? 0 : startIdx + 1,
      end: endIdx,
    };
  }, [rows, page, pageCount, pageSize, total]);

  return { page, pageSize, pageCount, total, start, end, pageRows, setPage, setPageSize };
}
