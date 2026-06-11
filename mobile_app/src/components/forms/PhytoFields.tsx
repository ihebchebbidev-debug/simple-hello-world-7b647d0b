import { Plus, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import type { RefPesticide, RefPest } from '@/lib/referenceCache';

export interface PestItem {
  id: string;
  pesticide_id: string;
  /** TOTAL quantity of product used for the whole tank (across all selected
   *  plots). The form splits it per plot by surface ratio. */
  quantity: string;
  /** Bioagresseur targeted by THIS pesticide (one per pesticide, ordered). */
  target_pest: string;
}
export const newPestItem = (): PestItem => ({
  id: `p-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
  pesticide_id: '', quantity: '', target_pest: '',
});

/** Plot shape needed for the proportional split preview. */
export interface SelectedPlot { id: string; name: string; surface_area_ha: number }

const round3 = (n: number) => Number(n.toFixed(3));

interface Props {
  items: PestItem[]; onItemsChange: (items: PestItem[]) => void;
  pesticides: RefPesticide[]; pests: RefPest[];
  waterTotalL: string; onWaterChange: (v: string) => void;
  remarks: string; onRemarksChange: (v: string) => void;
  /** Plots selected for this treatment — drives the per-plot split preview. */
  selectedPlots: SelectedPlot[];
}

const PhytoFields = (p: Props) => {
  const { t } = useTranslation();
  const update = (id: string, patch: Partial<PestItem>) =>
    p.onItemsChange(p.items.map((it) => (it.id === id ? { ...it, ...patch } : it)));
  const remove = (id: string) => p.onItemsChange(p.items.filter((it) => it.id !== id));

  const volume = Number(p.waterTotalL) || 0;
  const totalSurface = p.selectedPlots.reduce((s, pl) => s + (Number(pl.surface_area_ha) || 0), 0);
  const filledItems = p.items.filter((it) => it.pesticide_id && Number(it.quantity) > 0);
  const showPreview = p.selectedPlots.length > 0 && totalSurface > 0 && volume > 0;

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
                  <span className="text-xs text-muted-foreground font-medium whitespace-nowrap">
                    {t('form.totalQuantity')}{product?.unit ? ` (${product.unit})` : ''}
                  </span>
                </div>

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

      {/* Live proportional split — what each plot will actually be recorded with. */}
      {showPreview && (
        <div className="rounded-xl p-3" style={{ background: 'hsl(var(--primary) / 0.06)' }}>
          <p className="label-md mb-2 text-[11px]" style={{ color: 'hsl(var(--primary-glow))' }}>
            {t('form.splitPreview')}
          </p>
          <div className="space-y-2">
            {p.selectedPlots.map((plot) => {
              const ratio = (Number(plot.surface_area_ha) || 0) / totalSurface;
              return (
                <div key={plot.id} className="text-xs">
                  <div className="flex justify-between font-semibold text-foreground">
                    <span>{plot.name} · {plot.surface_area_ha} ha</span>
                    <span>{round3(volume * ratio)} L</span>
                  </div>
                  {filledItems.map((it) => {
                    const product = p.pesticides.find((x) => x.id === it.pesticide_id);
                    return (
                      <div key={it.id} className="flex justify-between text-muted-foreground pl-3">
                        <span>{product?.name ?? '—'}</span>
                        <span>{round3(Number(it.quantity) * ratio)} {product?.unit ?? ''}</span>
                      </div>
                    );
                  })}
                </div>
              );
            })}
          </div>
        </div>
      )}

      <div>
        <label className="label-md mb-2 block">{t('form.remarks')}</label>
        <textarea value={p.remarks} onChange={(e) => p.onRemarksChange(e.target.value)}
          rows={3} className="cl-input rounded-xl text-base py-3" placeholder={t('form.remarksPlaceholder')} />
      </div>
    </>
  );
};

export default PhytoFields;
