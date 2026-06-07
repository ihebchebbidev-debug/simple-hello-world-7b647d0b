import { useEffect, useMemo, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';
import type { AdminPlot } from './types';

export type PlotFormSubmit = {
  name: string;
  surface_area_ha: number;
  crop_type?: string | null;
  variety?: string | null;
  is_active: boolean;
};

interface FormState {
  name: string;
  surface_area_ha: string;
  crop_type: string;
  variety: string;
  is_active: boolean;
}

const empty: FormState = {
  name: '',
  surface_area_ha: '',
  crop_type: '',
  variety: '',
  is_active: true,
};

interface Props {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: AdminPlot | null;
  submitting?: boolean;
  serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: PlotFormSubmit) => void;
}

const PlotFormModal = ({ open, mode, initial, submitting = false, serverError = null, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [values, setValues] = useState<FormState>(empty);
  const [errors, setErrors] = useState<Partial<Record<keyof FormState, string>>>({});

  useEffect(() => {
    if (!open) return;
    if (initial) {
      setValues({
        name: initial.name,
        surface_area_ha: String(initial.surface_area_ha ?? ''),
        crop_type: initial.crop_type ?? '',
        variety: initial.variety ?? '',
        is_active: initial.is_active,
      });
    } else {
      setValues(empty);
    }
    setErrors({});
  }, [open, initial]);

  const schema = useMemo(() => z.object({
    name: z.string().trim().min(1, t('validation.nameRequired')).max(100),
    surface_area_ha: z.number().gt(0, t('validation.gtZero')).max(999999.9999, t('validation.tooLarge')),
    crop_type: z.string().trim().max(100).optional().or(z.literal('')),
    variety: z.string().trim().max(100).optional().or(z.literal('')),
    is_active: z.boolean(),
  }), [t]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    const parsed = schema.safeParse({
      name: values.name,
      surface_area_ha: Number(values.surface_area_ha),
      crop_type: values.crop_type,
      variety: values.variety,
      is_active: values.is_active,
    });
    if (!parsed.success) {
      const fe: Partial<Record<keyof FormState, string>> = {};
      for (const issue of parsed.error.issues) {
        const k = issue.path[0] as keyof FormState;
        if (k && !fe[k]) fe[k] = issue.message;
      }
      setErrors(fe);
      return;
    }
    onSubmit({
      name: parsed.data.name,
      surface_area_ha: parsed.data.surface_area_ha,
      crop_type: parsed.data.crop_type ? parsed.data.crop_type : null,
      variety: parsed.data.variety ? parsed.data.variety : null,
      is_active: parsed.data.is_active,
    });
  };

  return (
    <Modal
      open={open} onClose={onClose}
      title={mode === 'create' ? t('plots.formCreateTitle') : t('plots.formEditTitle')}
      description={mode === 'create' ? t('plots.formCreateDesc') : undefined}
      size="md"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel')}
          </button>
          <button type="submit" form="plot-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="plot-form" onSubmit={handleSubmit} className="space-y-3">
        <Field label={t('plots.name')} error={errors.name}>
          <input type="text" value={values.name} maxLength={100}
            onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
        </Field>

        <Field label={t('plots.surfaceHa')} error={errors.surface_area_ha}>
          <input type="number" step="0.0001" min="0" value={values.surface_area_ha}
            onChange={(e) => setValues((v) => ({ ...v, surface_area_ha: e.target.value }))}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label={t('plots.crop')} error={errors.crop_type}>
            <input type="text" value={values.crop_type} maxLength={100}
              placeholder={t('plots.cropPlaceholder')}
              onChange={(e) => setValues((v) => ({ ...v, crop_type: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
          <Field label={t('plots.variety')} error={errors.variety}>
            <input type="text" value={values.variety} maxLength={100}
              placeholder={t('plots.varietyPlaceholder')}
              onChange={(e) => setValues((v) => ({ ...v, variety: e.target.value }))}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          </Field>
        </div>

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

export default PlotFormModal;
