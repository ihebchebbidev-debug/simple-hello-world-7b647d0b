/** Turn an ISO date (YYYY-MM-DD) into a French short format (DD/MM/YYYY). */
export const formatDateFr = (iso: string): string => {
  if (!iso) return '—';
  const [y, m, d] = iso.split('-');
  return `${d}/${m}/${y}`;
};

/** Turn a YYYY-MM bucket into a localized "Jan 25" label. */
export const formatMonthFr = (yyyymm: string): string =>
  new Date(`${yyyymm}-01`).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
