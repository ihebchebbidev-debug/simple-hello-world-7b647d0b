/**
 * Generic operation entry page used by all four operation types.
 *
 * Each operation has the same outer skeleton (header, plot picker, date picker,
 * submit button + success screen). The op-specific fields are injected via the
 * `kind` discriminator.
 */
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IonContent, IonPage } from '@ionic/react';
import { Check, Loader2 } from 'lucide-react';
import PageHeader from '@/components/PageHeader';
import Skeleton from '@/components/Skeleton';
import IrrigationFields from '@/components/forms/IrrigationFields';
import FertilizationFields, { newFertItem, FERT_MAX_QTY, type FertItem } from '@/components/forms/FertilizationFields';
import PhytoFields, { newPestItem, type PestItem } from '@/components/forms/PhytoFields';
import HarvestFields from '@/components/forms/HarvestFields';
import RecentEntriesList from '@/components/RecentEntriesList';
import { useReferenceData } from '@/hooks/useReferenceData';
import { useOfflineQueue } from '@/hooks/useOfflineQueue';
import { enqueue, flushOutbox } from '@/lib/offlineQueue';
import iconIrrigation from '@/assets/icons/icon-irrigation.png';
import iconFertilization from '@/assets/icons/icon-fertilization.png';
import iconPhytosanitary from '@/assets/icons/icon-phytosanitary.png';
import iconHarvest from '@/assets/icons/icon-harvest.png';

export type OpKind = 'irrigation' | 'fertilization' | 'phytosanitary' | 'harvest';

const META: Record<OpKind, { icon: string; tint: string }> = {
  irrigation:    { icon: iconIrrigation,    tint: 'hsl(var(--chart-blue))' },
  fertilization: { icon: iconFertilization, tint: 'hsl(var(--chart-green))' },
  phytosanitary: { icon: iconPhytosanitary, tint: 'hsl(var(--chart-orange))' },
  harvest:       { icon: iconHarvest,       tint: 'hsl(var(--chart-red))' },
};

/** Round to 3 decimals (quantities are stored with millesimal precision). */
const round3 = (n: number) => Number((Number.isFinite(n) ? n : 0).toFixed(3));

interface Props { kind: OpKind }

const OperationFormPage = ({ kind }: Props) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const meta = META[kind];
  const { data: refs, isLoading } = useReferenceData();
  const { online } = useOfflineQueue();

  const [plotIds, setPlotIds] = useState<string[]>([]);
  const [plotSearch, setPlotSearch] = useState('');
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState<{ syncedOnline: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Op-specific state
  const [waterQty, setWaterQty] = useState('');
  const [fertItems, setFertItems] = useState<FertItem[]>([newFertItem()]);
  // Optional fertigation water (m³) entered on the fertilization form.
  const [fertWaterM3, setFertWaterM3] = useState('');
  const [pestItems, setPestItems] = useState<PestItem[]>([newPestItem()]);
  const [waterTotalL, setWaterTotalL] = useState('');
  const [remarks, setRemarks] = useState('');
  const [harvestQty, setHarvestQty] = useState('');
  const [harvestWorkerDays, setHarvestWorkerDays] = useState('');


  // Plots selected for a treatment + their total surface (drives the split).
  const selectedPlots = (refs?.plots ?? []).filter((p) => plotIds.includes(p.id));
  const filteredPlots = (refs?.plots ?? [])
    .filter((p) => p.name.toLowerCase().includes(plotSearch.trim().toLowerCase()));
  const totalSurface = selectedPlots.reduce((s, p) => s + (Number(p.surface_area_ha) || 0), 0);

  // Surface share of a plot within the current selection. The user enters the
  // TOTAL quantity once (water / fertilizer / spray + product / harvest) and we
  // split it across the selected plots proportionally to their surface, so e.g.
  // 100 m³ over P3 (1 ha) + P4 (1 ha) records 50 m³ each — not 100 each.
  const plotRatio = (plot: { surface_area_ha?: number }) =>
    totalSurface > 0 ? (Number(plot.surface_area_ha) || 0) / totalSurface : 0;

  // Returns one payload per selected plot so every selected plot is recorded,
  // each with its proportional share of the entered totals.
  const buildPayloads = (): Record<string, unknown>[] => {
    if (selectedPlots.length === 0) return [];

    return selectedPlots.map((plot) => {
      const ratio = plotRatio(plot);
      const base = {
        plot_id: plot.id,
        plot_name: plot.name,
        operation_date: date,
      };
      switch (kind) {
        case 'irrigation':
          return { ...base, water_quantity: round3(Number(waterQty) * ratio) };
        case 'fertilization':
          return {
            ...base,
            items: fertItems
              .filter((it) => it.fertilizer_id && Number(it.quantity) > 0)
              .map((it) => ({
                fertilizer_id: it.fertilizer_id,
                quantity_applied: round3(Number(it.quantity) * ratio),
              })),
          };
        case 'phytosanitary': {
          const plotVol = round3((Number(waterTotalL) || 0) * ratio);
          return {
            ...base,
            water_total_l: plotVol,
            items: pestItems
              .filter((it) => it.pesticide_id && Number(it.quantity) > 0)
              .map((it) => ({
                pesticide_id: it.pesticide_id,
                quantity_applied: round3(Number(it.quantity) * ratio),
                water_volume_l: plotVol,
                target_pest: it.target_pest || null,
              })),
            remarks: remarks || null,
          };
        }
        case 'harvest':
          return {
            ...base,
            quantity_harvested: round3(Number(harvestQty) * ratio),
            num_workers: Math.max(1, Math.round((Number(harvestWorkerDays) || 0) * ratio) || 1),
            days_worked: 1,
          };
      }
      return base;
    });
  };

  // Fertigation: the optional water on a fertilization form is recorded as a
  // real irrigation operation per plot (surface-split), so it flows into the
  // Irrigation report automatically. Empty / 0 → nothing extra is created.
  const buildFertigationPayloads = (): Record<string, unknown>[] => {
    const water = Number(fertWaterM3) || 0;
    if (kind !== 'fertilization' || water <= 0 || selectedPlots.length === 0) return [];
    return selectedPlots.map((plot) => ({
      plot_id: plot.id,
      plot_name: plot.name,
      operation_date: date,
      water_quantity: round3(water * plotRatio(plot)),
    }));
  };

  const validate = (): string | null => {
    if (plotIds.length === 0) return t('form.invalid');
    if (!date) return t('form.invalid');
    if (kind === 'irrigation' && !(Number(waterQty) > 0)) return t('form.invalid');
    if (kind === 'fertilization') {
      if (fertItems.every((i) => !i.fertilizer_id || !i.quantity)) return t('form.invalid');
      if (fertItems.some((i) => Number(i.quantity) > FERT_MAX_QTY)) {
        return t('form.fertOverMax', { max: FERT_MAX_QTY, defaultValue: `Quantity exceeds the maximum of ${FERT_MAX_QTY} kg` });
      }
    }
    if (kind === 'phytosanitary') {
      if (!(Number(waterTotalL) > 0)) return t('form.invalid');
      const filled = pestItems.filter((i) => i.pesticide_id && Number(i.quantity) > 0);
      if (filled.length === 0) return t('form.invalid');
      // One targeted bioagresseur is required per pesticide.
      if (filled.some((i) => !i.target_pest)) return t('form.invalid');
    }
    if (kind === 'harvest') {
      if (!(Number(harvestQty) > 0)) return t('form.invalid');
      if (!(Number(harvestWorkerDays) > 0)) return t('form.invalid');
    }


    return null;
  };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    const err = validate();
    if (err) { setError(err); return; }
    setError(null); setSubmitting(true);
    try {
      // A multi-plot treatment enqueues one operation per plot; everything else
      // is a single payload. Each enqueue() gets its own client_id, so offline
      // replay stays idempotent per plot.
      for (const payload of buildPayloads()) await enqueue(kind, payload);
      // Fertigation water → one irrigation operation per plot (same date).
      for (const w of buildFertigationPayloads()) await enqueue('irrigation', w);
      // Try to flush immediately so the user gets accurate online/offline feedback.
      const result = online ? await flushOutbox().catch(() => ({ sent: 0, failed: 0, remaining: 0 })) : null;
      setSubmitted({ syncedOnline: Boolean(result && result.sent > 0) });
      setTimeout(() => navigate('/home', { replace: true }), 1500);
    } finally {
      setSubmitting(false);
    }
  };

  if (submitted) {
    return (
      <IonPage>
        <IonContent fullscreen>
          <div className="flex flex-col items-center justify-center min-h-screen gap-4 px-5">
            <div className="h-16 w-16 rounded-full flex items-center justify-center" style={{ background: 'hsl(var(--primary) / 0.15)' }}>
              <Check className="h-8 w-8 text-[hsl(var(--primary-glow))]" />
            </div>
            <h2 className="text-lg font-semibold text-foreground">{t('form.saved')}</h2>
            <p className="text-sm text-muted-foreground text-center">
              {submitted.syncedOnline ? t('form.savedOnline') : t('form.savedOffline')}
            </p>
          </div>
        </IonContent>
      </IonPage>
    );
  }

  return (
    <IonPage>
      <IonContent fullscreen>
        <div className="flex flex-col min-h-screen pb-24">
          <PageHeader
            title={t(`form.title.${kind}`)}
            icon={<img src={meta.icon} alt="" className="h-5 w-5" />}
          />
          {isLoading ? (
            <div className="flex-1 px-5 space-y-5" aria-busy="true">
              <div>
                <Skeleton className="h-3.5 w-20 mb-2" />
                <Skeleton className="h-12 w-full rounded-xl" />
              </div>
              <div>
                <Skeleton className="h-3.5 w-16 mb-2" />
                <Skeleton className="h-12 w-full rounded-xl" />
              </div>
              <div className="space-y-3">
                <Skeleton className="h-3.5 w-32" />
                <Skeleton className="h-12 w-full rounded-xl" />
                {(kind === 'fertilization' || kind === 'phytosanitary') && (
                  <Skeleton className="h-12 w-full rounded-xl" />
                )}
                {kind === 'phytosanitary' && (
                  <>
                    <Skeleton className="h-12 w-full rounded-xl" />
                    <Skeleton className="h-12 w-full rounded-xl" />
                  </>
                )}
                {kind === 'harvest' && (
                  <>
                    <Skeleton className="h-12 w-full rounded-xl" />
                    <Skeleton className="h-12 w-full rounded-xl" />
                  </>
                )}
              </div>
              <div className="pt-4">
                <Skeleton className="h-12 w-full rounded-xl" />
              </div>
            </div>
          ) : (
          <form onSubmit={onSubmit} className="flex-1 px-5 space-y-5">
            <div>
              <label className="label-md mb-1 block">{t('form.plots')}</label>
              <p className="text-[11px] text-muted-foreground mb-2">
                {kind === 'phytosanitary' ? t('form.selectPlotsHint') : t('form.selectPlotsMultipleHint')}
              </p>
              <div className="mb-3">
                <input
                  type="search"
                  value={plotSearch}
                  onChange={(e) => setPlotSearch(e.target.value)}
                  placeholder={t('form.searchPlots')}
                  className="cl-input h-12 w-full rounded-xl text-sm"
                />
              </div>
              <div className="rounded-2xl border border-[hsl(var(--border))] overflow-hidden">
                {filteredPlots.length === 0 ? (
                  <div className="px-4 py-5 text-sm text-muted-foreground">{t('form.noPlotsAvailable')}</div>
                ) : (
                  <div className="divide-y divide-[hsl(var(--border))] max-h-60 overflow-y-auto">
                    {filteredPlots.map((p) => {
                      const checked = plotIds.includes(p.id);
                      return (
                        <label
                          key={p.id}
                          className={`group flex items-center gap-3 px-4 py-3 cursor-pointer transition-colors ${checked ? 'bg-[hsl(var(--primary)/0.08)] hover:bg-[hsl(var(--primary)/0.12)]' : 'hover:bg-[hsl(var(--border)/0.08)]'}`}
                        >
                          <input
                            type="checkbox"
                            checked={checked}
                            onChange={() => setPlotIds((prev) => checked ? prev.filter((x) => x !== p.id) : [...prev, p.id])}
                            className="h-5 w-5 rounded border border-[hsl(var(--border))] text-[hsl(var(--primary))] accent-[hsl(var(--primary))]"
                          />
                          <div className="min-w-0 flex-1">
                            <div className="text-sm font-medium text-foreground truncate">{p.name}</div>
                            <div className="text-[11px] text-muted-foreground truncate">{p.surface_area_ha} ha</div>
                          </div>
                        </label>
                      );
                    })}
                  </div>
                )}
              </div>
              {plotIds.length > 0 && (
                <div className="mt-3 rounded-xl border border-[hsl(var(--border))] bg-[hsl(var(--surface)/0.8)] p-3 text-xs text-muted-foreground">
                  <p className="font-medium text-[11px] text-[hsl(var(--primary-glow))] mb-2">
                    {t('form.plotsSelected', { count: plotIds.length, ha: Number(totalSurface.toFixed(3)) })}
                  </p>
                  <div className="space-y-1">
                    {selectedPlots.map((plot) => (
                      <div key={plot.id} className="flex items-center justify-between gap-2">
                        <span className="truncate">{plot.name}</span>
                        <span className="text-[11px] text-muted-foreground">{plot.surface_area_ha} ha</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
            <div>
              <label className="label-md mb-2 block">{t('form.date')}</label>
              <input type="date" required value={date} onChange={(e) => setDate(e.target.value)}
                className="cl-input h-12 rounded-xl text-base" />
            </div>

            {kind === 'irrigation' && <IrrigationFields value={waterQty} onChange={setWaterQty} />}
            {kind === 'fertilization' && (
              <FertilizationFields
                items={fertItems}
                onChange={setFertItems}
                fertilizers={refs.fertilizers}
                surfaceHa={totalSurface}
                waterM3={fertWaterM3}
                onWaterChange={setFertWaterM3}
              />
            )}
            {kind === 'phytosanitary' && (
              <PhytoFields
                items={pestItems} onItemsChange={setPestItems}
                pesticides={refs.pesticides} pests={refs.pests}
                waterTotalL={waterTotalL} onWaterChange={setWaterTotalL}
                remarks={remarks} onRemarksChange={setRemarks}
                selectedPlots={selectedPlots}
              />
            )}
            {kind === 'harvest' && (
              <HarvestFields
                quantity={harvestQty} onQuantityChange={setHarvestQty}
                workerDays={harvestWorkerDays} onWorkerDaysChange={setHarvestWorkerDays}
              />
            )}

            {/* Per-plot split preview (phyto has its own inside PhytoFields). */}
            {kind !== 'phytosanitary' && selectedPlots.length > 1 && totalSurface > 0 && (
              <div className="rounded-xl p-3" style={{ background: 'hsl(var(--primary) / 0.06)' }}>
                <p className="label-md mb-2 text-[11px]" style={{ color: 'hsl(var(--primary-glow))' }}>
                  {t('form.splitPreview')}
                </p>
                <div className="space-y-2">
                  {selectedPlots.map((plot) => {
                    const ratio = plotRatio(plot);
                    return (
                      <div key={plot.id} className="text-xs">
                        <div className="flex justify-between font-semibold text-foreground">
                          <span>{plot.name} · {plot.surface_area_ha} ha</span>
                          {kind === 'irrigation' && <span>{round3(Number(waterQty) * ratio)} m³</span>}
                          {kind === 'harvest' && <span>{round3(Number(harvestQty) * ratio)} kg</span>}
                        </div>
                        {kind === 'fertilization' && fertItems
                          .filter((it) => it.fertilizer_id && Number(it.quantity) > 0)
                          .map((it) => {
                            const f = refs.fertilizers.find((x) => x.id === it.fertilizer_id);
                            return (
                              <div key={it.id} className="flex justify-between text-muted-foreground pl-3">
                                <span>{f?.name ?? '—'}</span>
                                <span>{round3(Number(it.quantity) * ratio)} {f?.unit ?? ''}</span>
                              </div>
                            );
                          })}
                        {kind === 'fertilization' && Number(fertWaterM3) > 0 && (
                          <div className="flex justify-between pl-3" style={{ color: 'hsl(var(--chart-blue))' }}>
                            <span>💧 {t('form.fertigationWater')}</span>
                            <span>{round3(Number(fertWaterM3) * ratio)} m³</span>
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            )}

            {error && <p className="text-sm text-[hsl(var(--accent-danger))]">{error}</p>}
            {!online && <p className="text-xs text-muted-foreground">{t('common.networkError')}</p>}

            <div className="pt-4">
              <button type="submit" disabled={submitting}
                className="btn-primary-glass w-full h-12 text-base flex items-center justify-center gap-2">
                {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
                {submitting ? t('form.submitting') : t('form.submit')}
              </button>
            </div>
          </form>
          )}
          {!isLoading && (
            <div className="px-5 mt-6">
              <RecentEntriesList type={kind} />
            </div>
          )}
        </div>
      </IonContent>
    </IonPage>
  );
};

export default OperationFormPage;
