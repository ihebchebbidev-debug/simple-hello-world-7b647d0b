import { useEffect, useMemo, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import { UNIT_OPTIONS, type AdminPesticide } from './types';

const unitRegex = /^[A-Za-z0-9%/\-]+$/;
const decimal3 = /^\d+(\.\d{1,3})?$/;

export type PesticideFormSubmit = { name: string; unit: string; chemical_composition: string | null; is_active: boolean; initial_price?: number | null };
interface FormState { name: string; unit: string; chemical_composition: string; is_active: boolean; initial_price: string }
const empty: FormState = { name: '', unit: 'L', chemical_composition: '', is_active: true, initial_price: '' };

interface Props {
  open: boolean; mode: 'create' | 'edit';
  initial?: AdminPesticide | null;
  submitting?: boolean; serverError?: string | null;
  onClose: () => void; onSubmit: (values: PesticideFormSubmit) => void;
}

const PesticideFormModal = ({ open, mode, initial, submitting = false, serverError = null, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [values, setValues] = useState<FormState>(empty);
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({});

  useEffect(() => {
    if (!open) return;
    if (initial) {
      setValues({ name: initial.name, unit: initial.unit, chemical_composition: initial.chemical_composition ?? '', is_active: initial.is_active, initial_price: '' });
    } else { setValues(empty); }
    setErrors({});
  }, [open, initial]);

  const schema = useMemo(() => z.object({
    name: z.string().trim().min(1, t('validation.nameRequired')).max(100),
    unit: z.string().trim().min(1, t('validation.unitRequired')).max(20).regex(unitRegex, t('validation.unitChars')),
    chemical_composition: z.string().trim().max(5000, t('validation.max5000')).optional().or(z.literal('')),
    is_active: z.boolean(),
  }), [t]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    if (values.initial_price && !decimal3.test(values.initial_price)) {
      setErrors({ initial_price: t('validation.max3dec', 'Up to 3 decimals') });
      return;
    }
    const parsed = schema.safeParse(values);
    if (!parsed.success) {
      const fe: Partial<Record<keyof FormState, string>> = {};
      for (const issue of parsed.error.issues) {
        const k = issue.path[0] as keyof FormState;
        if (k && !fe[k]) fe[k] = issue.message;
      }
      setErrors(fe); return;
    }
    const composition = parsed.data.chemical_composition?.trim();
    const priceNum = values.initial_price ? Number(values.initial_price) : null;
    onSubmit({
      name: parsed.data.name,
      unit: parsed.data.unit,
      chemical_composition: composition ? composition : null,
      is_active: parsed.data.is_active,
      initial_price: priceNum && priceNum > 0 ? priceNum : null,
    });
  };

  return (
    <Modal open={open} onClose={onClose}
      title={mode === 'create' ? t('pesticides.formCreateTitle') : t('pesticides.formEditTitle')}
      description={mode === 'create' ? t('pesticides.formCreateDesc') : undefined}
      size="md"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">{t('common.cancel')}</button>
          <button type="submit" form="pesticide-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">{submitting ? t('common.saving') : t('common.save')}</button>
        </>
      }
    >
      <form id="pesticide-form" onSubmit={handleSubmit} className="space-y-3">
        <Field label={t('plots.name')} error={errors.name}>
          <input type="text" value={values.name} maxLength={100} placeholder={t('pesticides.namePlaceholder')}
            onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
        </Field>
        <Field label={t('fertilizers.unit')} error={errors.unit}>
          <input list="pesticide-unit-options" type="text" value={values.unit} maxLength={20}
            onChange={(e) => setValues((v) => ({ ...v, unit: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          <datalist id="pesticide-unit-options">{UNIT_OPTIONS.map((u) => (<option key={u} value={u} />))}</datalist>
        </Field>
        <Field label={t('pesticides.compositionLabel')} error={errors.chemical_composition}>
          <textarea value={values.chemical_composition} maxLength={5000} rows={4}
            placeholder={t('pesticides.compositionPlaceholder')}
            onChange={(e) => setValues((v) => ({ ...v, chemical_composition: e.target.value }))}
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" />
          <span className="text-[11px] text-muted-foreground">{values.chemical_composition.length} / 5000</span>
        </Field>
        {mode === 'create' && (
          <Field label={`${t('prices.newPriceLabel', 'Prix initial (TND)')} / ${values.unit || 'unité'} (${t('common.optional')})`} error={errors.initial_price}>
            <input type="number" step="0.001" min="0" value={values.initial_price}
              placeholder="0.000"
              onChange={(e) => setValues((v) => ({ ...v, initial_price: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
        )}
        <Field label={t('common.status')}>
          <select value={values.is_active ? 'active' : 'inactive'}
            onChange={(e) => setValues((v) => ({ ...v, is_active: e.target.value === 'active' }))}
            className="h-9 w-full rounded-md border border-border bg-background px-2 text-sm">
            <option value="active">{t('common.active')}</option>
            <option value="inactive">{t('common.inactive')}</option>
          </select>
        </Field>
        {serverError && (<p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300" role="alert">{serverError}</p>)}
      </form>
    </Modal>
  );
};

const Field = ({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) => (
  <label className="block space-y-1">
    <span className="text-xs font-medium text-muted-foreground">{label}</span>
    {children}
    {error && <span className="block text-[11px] text-rose-400">{error}</span>}
  </label>
);

export default PesticideFormModal;
