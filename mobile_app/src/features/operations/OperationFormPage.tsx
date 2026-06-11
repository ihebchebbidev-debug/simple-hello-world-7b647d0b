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

interface Props { kind: OpKind }

const OperationFormPage = ({ kind }: Props) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const meta = META[kind];
  const { data: refs, isLoading } = useReferenceData();
  const { online } = useOfflineQueue();

  const [plotId, setPlotId] = useState('');
  // Treatments are applied to several plots from one tank — phytosanitary uses
  // a multi-select; the other op types stay single-plot.
  const [plotIds, setPlotIds] = useState<string[]>([]);
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [submitting, setSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState<{ syncedOnline: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Op-specific state
  const [waterQty, setWaterQty] = useState('');
  const [fertItems, setFertItems] = useState<FertItem[]>([newFertItem()]);
  const [pestItems, setPestItems] = useState<PestItem[]>([newPestItem()]);
  const [waterTotalL, setWaterTotalL] = useState('');
  const [remarks, setRemarks] = useState('');
  const [harvestQty, setHarvestQty] = useState('');
  const [harvestWorkerDays, setHarvestWorkerDays] = useState('');


  // Plots selected for a treatment + their total surface (drives the split).
  const selectedPlots = (refs?.plots ?? []).filter((p) => plotIds.includes(p.id));
  const totalSurface = selectedPlots.reduce((s, p) => s + (Number(p.surface_area_ha) || 0), 0);

  // Returns ONE payload per operation to enqueue. All op types yield a single
  // payload except a multi-plot treatment, which yields one operation per plot
  // with the spray volume and each product quantity split by surface ratio
  // (plotShare = surfaceᵢ / Σsurface). The customer enters the TOTAL volume and
  // TOTAL product quantity once; the app does the proportional maths.
  const buildPayloads = (): Record<string, unknown>[] => {
    if (kind === 'phytosanitary') {
      const vol = Number(waterTotalL) || 0;
      const validItems = pestItems.filter((it) => it.pesticide_id && Number(it.quantity) > 0);
      return selectedPlots.map((plot) => {
        const ratio = totalSurface > 0 ? (Number(plot.surface_area_ha) || 0) / totalSurface : 0;
        const plotVol = Number((vol * ratio).toFixed(3));
        return {
          plot_id: plot.id,
          plot_name: plot.name,
          operation_date: date,
          water_total_l: plotVol,
          items: validItems.map((it) => ({
            pesticide_id: it.pesticide_id,
            quantity_applied: Number((Number(it.quantity) * ratio).toFixed(3)),
            water_volume_l: plotVol,
            target_pest: it.target_pest || null,
          })),
          remarks: remarks || null,
        };
      });
    }

    const base = {
      plot_id: plotId,
      plot_name: refs.plots.find((p) => p.id === plotId)?.name ?? '',
      operation_date: date,
    };
    switch (kind) {
      case 'irrigation':
        return [{ ...base, water_quantity: Number(waterQty) }];
      case 'fertilization':
        return [{
          ...base,
          items: fertItems
            .filter((it) => it.fertilizer_id && it.quantity)
            .map((it) => ({ fertilizer_id: it.fertilizer_id, quantity_applied: Number(it.quantity) })),
        }];
      case 'harvest':
        return [{
          ...base,
          quantity_harvested: Number(harvestQty),
          // v4: single "Main d'œuvre (homme/jour)" input stored as N x 1.
          num_workers: Math.max(1, Math.round(Number(harvestWorkerDays) || 1)),
          days_worked: 1,
        }];
    }
    return [];
  };

  const validate = (): string | null => {
    if (kind === 'phytosanitary') {
      if (plotIds.length === 0) return t('form.invalid');
    } else if (!plotId) {
      return t('form.invalid');
    }
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
            {kind === 'phytosanitary' ? (
              <div>
                <label className="label-md mb-1 block">{t('form.plots')}</label>
                <p className="text-[11px] text-muted-foreground mb-2">{t('form.selectPlotsHint')}</p>
                <div className="rounded-xl border border-[hsl(var(--border))] divide-y divide-[hsl(var(--border))] max-h-60 overflow-y-auto">
                  {refs.plots.map((p) => {
                    const checked = plotIds.includes(p.id);
                    return (
                      <label
                        key={p.id}
                        className={`w-full flex items-center gap-3 px-3 h-12 text-left cursor-pointer ${checked ? 'bg-[hsl(var(--primary)/0.08)]' : ''}`}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => setPlotIds((prev) => checked ? prev.filter((x) => x !== p.id) : [...prev, p.id])}
                          className="sr-only"
                        />
                        <span className={`h-5 w-5 shrink-0 rounded-md border flex items-center justify-center ${checked ? 'bg-[hsl(var(--primary))] border-[hsl(var(--primary))]' : 'border-[hsl(var(--border))]'}`}>
                          {checked && <Check className="h-3.5 w-3.5 text-[hsl(var(--primary-foreground))]" />}
                        </span>
                        <span className="flex-1 text-sm text-foreground">{p.name}</span>
                        <span className="text-xs text-muted-foreground whitespace-nowrap">{p.surface_area_ha} ha</span>
                      </label>
                    );
                  })}
                </div>
                {plotIds.length > 0 && (
                  <>
                    <p className="mt-2 text-xs font-medium" style={{ color: 'hsl(var(--primary-glow))' }}>
                      {t('form.plotsSelected', { count: plotIds.length, ha: Number(totalSurface.toFixed(3)) })}
                    </p>
                    <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                      {selectedPlots.map((plot) => (
                        <div key={plot.id} className="flex items-center justify-between gap-2">
                          <span className="truncate">{plot.name}</span>
                          <span className="shrink-0">{plot.surface_area_ha} ha</span>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </div>
            ) : (
              <div>
                <label className="label-md mb-2 block">{t('form.plot')}</label>
                <select value={plotId} onChange={(e) => setPlotId(e.target.value)}
                  disabled={isLoading} required className="cl-input h-12 rounded-xl text-base">
                  <option value="">{t('form.selectPlot')}</option>
                  {refs.plots.map((p) => (
                    <option key={p.id} value={p.id}>{p.name} — {p.surface_area_ha} ha</option>
                  ))}
                </select>
              </div>
            )}
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
                surfaceHa={refs.plots.find((p) => p.id === plotId)?.surface_area_ha}
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
