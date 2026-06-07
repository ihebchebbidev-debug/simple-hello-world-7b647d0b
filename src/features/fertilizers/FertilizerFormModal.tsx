import { useEffect, useMemo, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import { UNIT_OPTIONS, type AdminFertilizer } from './types';

const unitRegex = /^[A-Za-z0-9%/\-]+$/;
const decimal2 = /^\d+(\.\d{1,2})?$/;
const decimal3 = /^\d+(\.\d{1,3})?$/;

export type FertilizerFormSubmit = {
  name: string; unit: string;
  n_percent: number; p_percent: number; k_percent: number;
  is_active: boolean;
  /** Optional initial price (TND/unit) — posted to /prices on create. */
  initial_price?: number | null;
};

interface FormState { name: string; unit: string; n_percent: string; p_percent: string; k_percent: string; is_active: boolean; initial_price: string; }

const empty: FormState = { name: '', unit: 'kg', n_percent: '0', p_percent: '0', k_percent: '0', is_active: true, initial_price: '' };

interface Props {
  open: boolean; mode: 'create' | 'edit';
  initial?: AdminFertilizer | null;
  submitting?: boolean; serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: FertilizerFormSubmit) => void;
}

const FertilizerFormModal = ({ open, mode, initial, submitting = false, serverError = null, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [values, setValues] = useState<FormState>(empty);
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({});

  useEffect(() => {
    if (!open) return;
    if (initial) {
      setValues({
        name: initial.name, unit: initial.unit,
        n_percent: String(initial.n_percent ?? 0),
        p_percent: String(initial.p_percent ?? 0),
        k_percent: String(initial.k_percent ?? 0),
        is_active: initial.is_active,
        initial_price: '',
      });
    } else { setValues(empty); }
    setErrors({});
  }, [open, initial]);

  const schema = useMemo(() => z.object({
    name: z.string().trim().min(1, t('validation.nameRequired')).max(100),
    unit: z.string().trim().min(1, t('validation.unitRequired')).max(20).regex(unitRegex, t('validation.unitChars')),
    n_percent: z.number().min(0, t('validation.gteZero')).max(100, t('validation.lte100')),
    p_percent: z.number().min(0, t('validation.gteZero')).max(100, t('validation.lte100')),
    k_percent: z.number().min(0, t('validation.gteZero')).max(100, t('validation.lte100')),
    is_active: z.boolean(),
  }), [t]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    const fe: Partial<Record<keyof FormState, string>> = {};
    for (const k of ['n_percent', 'p_percent', 'k_percent'] as const) {
      if (!decimal2.test(values[k])) fe[k] = t('validation.max2dec');
    }
    if (values.initial_price && !decimal3.test(values.initial_price)) {
      fe.initial_price = t('validation.max3dec', 'Up to 3 decimals');
    }
    if (Object.keys(fe).length) { setErrors(fe); return; }
    const parsed = schema.safeParse({
      name: values.name, unit: values.unit,
      n_percent: Number(values.n_percent),
      p_percent: Number(values.p_percent),
      k_percent: Number(values.k_percent),
      is_active: values.is_active,
    });
    if (!parsed.success) {
      const fieldErrors: Partial<Record<keyof FormState, string>> = {};
      for (const issue of parsed.error.issues) {
        const k = issue.path[0] as keyof FormState;
        if (k && !fieldErrors[k]) fieldErrors[k] = issue.message;
      }
      setErrors(fieldErrors); return;
    }
    const priceNum = values.initial_price ? Number(values.initial_price) : null;
    onSubmit({ ...parsed.data, initial_price: priceNum && priceNum > 0 ? priceNum : null });
  };

  return (
    <Modal open={open} onClose={onClose}
      title={mode === 'create' ? t('fertilizers.formCreateTitle') : t('fertilizers.formEditTitle')}
      description={mode === 'create' ? t('fertilizers.formCreateDesc') : undefined}
      size="md"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel')}
          </button>
          <button type="submit" form="fertilizer-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="fertilizer-form" onSubmit={handleSubmit} className="space-y-3">
        <Field label={t('plots.name')} error={errors.name}>
          <input type="text" value={values.name} maxLength={100}
            placeholder={t('fertilizers.namePlaceholder')}
            onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
        </Field>

        <Field label={t('fertilizers.unit')} error={errors.unit}>
          <input list="unit-options" type="text" value={values.unit} maxLength={20}
            onChange={(e) => setValues((v) => ({ ...v, unit: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          <datalist id="unit-options">{UNIT_OPTIONS.map((u) => (<option key={u} value={u} />))}</datalist>
        </Field>

        <div className="grid grid-cols-3 gap-3">
          <Field label="N (%)" error={errors.n_percent}>
            <input type="number" step="0.01" min="0" max="100" value={values.n_percent}
              onChange={(e) => setValues((v) => ({ ...v, n_percent: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
          <Field label="P (%)" error={errors.p_percent}>
            <input type="number" step="0.01" min="0" max="100" value={values.p_percent}
              onChange={(e) => setValues((v) => ({ ...v, p_percent: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
          <Field label="K (%)" error={errors.k_percent}>
            <input type="number" step="0.01" min="0" max="100" value={values.k_percent}
              onChange={(e) => setValues((v) => ({ ...v, k_percent: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
        </div>

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

        {serverError && (
          <p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300" role="alert">
            {serverError}
          </p>
        )}
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

export default FertilizerFormModal;
