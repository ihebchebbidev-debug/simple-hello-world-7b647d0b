import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { RefPesticide, RefPest } from '@/lib/referenceCache';

export interface PestItem {
  id: string;
  pesticide_id: string;
  /** Dose expressed as Qté/100L of spray mix. */
  quantity: string;
  /** Bioagresseur targeted by THIS pesticide (one per pesticide, ordered). */
  target_pest: string;
}
export const newPestItem = (): PestItem => ({
  id: `p-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
  pesticide_id: '', quantity: '', target_pest: '',
});

interface Props {
  items: PestItem[]; onItemsChange: (items: PestItem[]) => void;
  pesticides: RefPesticide[]; pests: RefPest[];
  waterTotalL: string; onWaterChange: (v: string) => void;
  remarks: string; onRemarksChange: (v: string) => void;
}

const PhytoFields = (p: Props) => {
  const { t } = useTranslation();
  const update = (id: string, patch: Partial<PestItem>) =>
    p.onItemsChange(p.items.map((it) => (it.id === id ? { ...it, ...patch } : it)));
  const remove = (id: string) => p.onItemsChange(p.items.filter((it) => it.id !== id));

  const volume = Number(p.waterTotalL) || 0;

  return (
    <>
      <div className="rounded-xl p-4" style={{ background: 'hsl(var(--chart-blue) / 0.08)' }}>
        <label className="label-md mb-2 block" style={{ color: 'hsl(var(--chart-blue))' }}>💧 {t('form.totalWater')}</label>
        <div className="flex gap-2 items-center">
          <input type="number" inputMode="decimal" step="0.001" min="0" required
            value={p.waterTotalL} onChange={(e) => p.onWaterChange(e.target.value)}
            className="cl-input h-12 rounded-xl text-base flex-1" placeholder="0" />
          <span className="text-sm font-semibold" style={{ color: 'hsl(var(--chart-blue))' }}>L</span>
        </div>
      </div>

      <div>
        <div className="flex items-center justify-between mb-2">
          <label className="label-md">{t('form.pesticides')}</label>
          <span className="text-[10px] text-muted-foreground">{p.items.length}</span>
        </div>
        <div className="space-y-3">
          {p.items.map((item, idx) => {
            const product = p.pesticides.find((x) => x.id === item.pesticide_id);
            const dose = Number(item.quantity) || 0;
            // Absolute product quantity = spray volume × dose ÷ 100.
            const computedQty = volume > 0 && dose > 0 ? (volume * dose) / 100 : 0;
            return (
              <div key={item.id} className="rounded-xl p-3 relative bg-surface-high">
                {p.items.length > 1 && (
                  <button type="button" onClick={() => remove(item.id)}
                    className="absolute top-2 right-2 h-7 w-7 rounded-full flex items-center justify-center bg-[hsl(var(--surface-container-lowest))] hover:bg-[hsl(var(--accent-danger)/0.2)]"
                    aria-label={t('common.remove')}>
                    <X className="h-4 w-4 text-muted-foreground" />
                  </button>
                )}
                <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">#{idx + 1}</p>
                <select
                  value={item.pesticide_id} required
                  onChange={(e) => update(item.id, { pesticide_id: e.target.value })}
                  className="cl-input h-11 rounded-lg text-sm mb-2">
                  <option value="">{t('form.selectProduct')}</option>
                  {p.pesticides.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>

                <div className="flex gap-2 items-center">
                  <input type="number" inputMode="decimal" step="0.001" min="0" required
                    value={item.quantity}
                    onChange={(e) => update(item.id, { quantity: e.target.value })}
                    className="cl-input h-11 rounded-lg text-sm flex-1" placeholder="0" />
                  <span className="text-xs text-muted-foreground font-medium whitespace-nowrap">{t('form.dosePer100L')}</span>
                </div>
                {computedQty > 0 && (
                  <p className="mt-1 text-[11px] text-muted-foreground">
                    = {Number(computedQty.toFixed(3))} {product?.unit ?? 'L'} {t('form.perPlot')}
                  </p>
                )}

                <div className="mt-2">
                  <label className="label-md mb-1 block text-[11px]">{t('form.targetPest')}</label>
                  {p.pests.length > 0 ? (
                    <select value={item.target_pest} required
                      onChange={(e) => update(item.id, { target_pest: e.target.value })}
                      className="cl-input h-11 rounded-lg text-sm">
                      <option value="">{t('form.selectPest')}</option>
                      {p.pests.map((x) => <option key={x.id} value={x.name}>{x.name}</option>)}
                    </select>
                  ) : (
                    <input type="text" value={item.target_pest} required
                      onChange={(e) => update(item.id, { target_pest: e.target.value })}
                      className="cl-input h-11 rounded-lg text-sm" placeholder="—" />
                  )}
                </div>
              </div>
            );
          })}
        </div>
        <button type="button" onClick={() => p.onItemsChange([...p.items, newPestItem()])}
          className="mt-3 w-full flex items-center justify-center gap-2 h-11 rounded-xl text-sm font-medium border border-dashed border-[hsl(var(--primary)/0.4)] text-[hsl(var(--primary-glow))]">
          <Plus className="h-4 w-4" />{t('form.addPesticide')}
        </button>
      </div>

      <div>
        <label className="label-md mb-2 block">{t('form.remarks')}</label>
        <textarea value={p.remarks} onChange={(e) => p.onRemarksChange(e.target.value)}
          rows={3} className="cl-input rounded-xl text-base py-3" placeholder={t('form.remarksPlaceholder')} />
      </div>
    </>
  );
};

export default PhytoFields;
