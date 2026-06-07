import { useTranslation } from 'react-i18next';

interface Props {
  colSpan: number;
  /** Number of skeleton rows to render while loading. */
  rows?: number;
  isLoading: boolean;
  isError: boolean;
  isEmpty: boolean;
  onRetry?: () => void;
}

/**
 * Skeleton + empty + error states rendered *inside* a <tbody>. Used by
 * every report table so users see pulsing placeholder rows while the
 * Render dyno wakes up — instead of a scary top-of-page error banner.
 *
 * - isLoading → N pulsing skeleton rows (lazy/skeleton "data is on its way" UX).
 * - isError (and not loading) → single row with message + Retry button.
 * - isEmpty (and not loading/error) → "no data" row.
 */
const TableSkeletonRows = ({
  colSpan,
  rows = 5,
  isLoading,
  isError,
  isEmpty,
  onRetry,
}: Props) => {
  const { t } = useTranslation();

  if (isLoading) {
    return (
      <>
        {Array.from({ length: rows }).map((_, i) => (
          <tr key={`sk-${i}`} className="animate-pulse">
            {Array.from({ length: colSpan }).map((__, j) => (
              <td key={j}>
                <div
                  className="h-3 rounded bg-[hsl(var(--surface-bright))]"
                  style={{ width: `${60 + ((i * 7 + j * 11) % 35)}%`, opacity: 0.6 }}
                />
              </td>
            ))}
          </tr>
        ))}
      </>
    );
  }

  if (isError) {
    return (
      <tr>
        <td colSpan={colSpan} className="py-6 text-center">
          <div className="flex items-center justify-center gap-3 text-sm">
            <span className="text-[hsl(var(--accent-danger))]">
              {t('reports.loadFailed', 'Impossible de charger ce rapport. Veuillez réessayer.')}
            </span>
            {onRetry && (
              <button type="button" onClick={onRetry} className="btn-secondary text-xs">
                {t('common.retry', 'Réessayer')}
              </button>
            )}
          </div>
        </td>
      </tr>
    );
  }

  if (isEmpty) {
    return (
      <tr>
        <td colSpan={colSpan} className="py-6 text-center text-sm text-muted-foreground">
          {t('reports.noData', 'Aucune donnée à afficher.')}
        </td>
      </tr>
    );
  }

  return null;
};

export default TableSkeletonRows;
