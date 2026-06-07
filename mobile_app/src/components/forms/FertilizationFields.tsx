import { Plus, X, AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { RefFertilizer } from '@/lib/referenceCache';

export interface FertItem { id: string; fertilizer_id: string; quantity: string }
export const newFertItem = (): FertItem => ({
  id: `f-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
  fertilizer_id: '', quantity: '',
});

// Sanity bounds — protect against typo / wrong-unit entries.
// Hard cap: 10 000 kg in a single application is implausible.
// Soft warning: > 1 000 kg/ha is well above any normal fertilization rate
// (a Tunisian arboriculture fertilization rarely exceeds 200–400 kg/ha).
export const FERT_MAX_QTY = 10000;
export const FERT_WARN_PER_HA = 1000;

interface Props {
  items: FertItem[];
  onChange: (items: FertItem[]) => void;
  fertilizers: RefFertilizer[];
  surfaceHa?: number;
}

const FertilizationFields = ({ items, onChange, fertilizers, surfaceHa }: Props) => {
  const { t } = useTranslation();
  const update = (id: string, patch: Partial<FertItem>) =>
    onChange(items.map((it) => (it.id === id ? { ...it, ...patch } : it)));
  const remove = (id: string) => onChange(items.filter((it) => it.id !== id));

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <label className="label-md">{t('form.fertilizers')}</label>
        <span className="text-[10px] text-muted-foreground">{items.length}</span>
      </div>
      <div className="space-y-3">
        {items.map((item, idx) => {
          const product = fertilizers.find((f) => f.id === item.fertilizer_id);
          const qtyNum = Number(item.quantity);
          const overMax = qtyNum > FERT_MAX_QTY;
          const perHa = surfaceHa && surfaceHa > 0 && qtyNum > 0 ? qtyNum / surfaceHa : 0;
          const overWarn = perHa > FERT_WARN_PER_HA;
          return (
            <div key={item.id} className="rounded-xl p-3 relative bg-surface-high">
              {items.length > 1 && (
                <button type="button" onClick={() => remove(item.id)}
                  className="absolute top-2 right-2 h-7 w-7 rounded-full flex items-center justify-center bg-[hsl(var(--surface-container-lowest))] hover:bg-[hsl(var(--accent-danger)/0.2)]"
                  aria-label={t('common.remove')}>
                  <X className="h-4 w-4 text-muted-foreground" />
                </button>
              )}
              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">#{idx + 1}</p>
              <select
                value={item.fertilizer_id} required
                onChange={(e) => update(item.id, { fertilizer_id: e.target.value })}
                className="cl-input h-11 rounded-lg text-sm mb-2"
              >
                <option value="">{t('form.selectProduct')}</option>
                {fertilizers.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
              </select>
              <div className="flex gap-2 items-center">
                <input type="number" inputMode="decimal" step="0.1" min="0" max={FERT_MAX_QTY} required
                  value={item.quantity}
                  onChange={(e) => update(item.id, { quantity: e.target.value })}
                  className={`cl-input h-11 rounded-lg text-sm flex-1 ${overMax ? 'border-[hsl(var(--accent-danger))]' : ''}`}
                  placeholder="0" />
                <span className="text-xs text-muted-foreground font-medium">{product?.unit ?? 'kg'}</span>
              </div>
              {overMax && (
                <p className="mt-1.5 text-[11px] text-[hsl(var(--accent-danger))] flex items-center gap-1">
                  <AlertTriangle className="h-3 w-3" />
                  {t('form.fertOverMax', { max: FERT_MAX_QTY, defaultValue: 'Quantity exceeds the maximum of {{max}} kg' })}
                </p>
              )}
              {!overMax && overWarn && (
                <p className="mt-1.5 text-[11px] text-[hsl(var(--accent-warning,38_92%_50%))] flex items-center gap-1">
                  <AlertTriangle className="h-3 w-3" />
                  {t('form.fertOverWarn', {
                    perHa: Math.round(perHa),
                    defaultValue: 'Aberrant value: ~{{perHa}} kg/ha. Please double-check the quantity.',
                  })}
                </p>
              )}
            </div>
          );
        })}
      </div>
      <button type="button" onClick={() => onChange([...items, newFertItem()])}
        className="mt-3 w-full flex items-center justify-center gap-2 h-11 rounded-xl text-sm font-medium border border-dashed border-[hsl(var(--primary)/0.4)] text-[hsl(var(--primary-glow))]">
        <Plus className="h-4 w-4" />{t('form.addFertilizer')}
      </button>
    </div>
  );
};

export default FertilizationFields;
