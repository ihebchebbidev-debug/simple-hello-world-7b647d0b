import { useEffect, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';

const decimal4 = /^\d+(\.\d{1,4})?$/;

const buildSchema = (t: (k: string) => string) =>
  z.object({
    daily_rate: z.number().min(0, t('validation.gteZero')).max(99999999.9999, t('validation.tooHigh')),
    effective_from: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, t('validation.dateFormat')),
    effective_to: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, t('validation.dateFormat')).optional().or(z.literal('')),
  });

export type LaborRateSubmit = {
  entity_type: 'labor';
  price_per_unit: number;
  unit?: string;
  effective_from: string;
  effective_to?: string | null;
};

interface Props {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: { price_per_unit: number | string; effective_from: string; effective_to?: string | null } | null;
  submitting?: boolean;
  serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: LaborRateSubmit) => void;
}

const LaborRateFormModal = ({ open, mode, initial, submitting, serverError, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [rate, setRate] = useState('0');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setRate(initial ? String(initial.price_per_unit) : '0');
    setFrom(initial?.effective_from ?? new Date().toISOString().slice(0, 10));
    setTo(initial?.effective_to ?? '');
    setErrors({});
  }, [open, initial]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    if (!decimal4.test(rate)) {
      setErrors({ daily_rate: t('validation.max4dec') });
      return;
    }
    const parsed = buildSchema(t).safeParse({
      daily_rate: Number(rate),
      effective_from: from,
      effective_to: to || '',
    });
    if (!parsed.success) {
      const fe: Record<string, string> = {};
      for (const i of parsed.error.issues) {
        const k = i.path[0] as string;
        if (k && !fe[k]) fe[k] = i.message;
      }
      setErrors(fe);
      return;
    }
    if (to && to < from) {
      setErrors({ effective_to: t('validation.dateAfterStart') });
      return;
    }
    onSubmit({
      entity_type: 'labor',
      price_per_unit: Number(rate),
      unit: 'jour',
      effective_from: from,
      effective_to: to || null,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={mode === 'create' ? t('labor.formRateCreateTitle') : t('labor.formRateEditTitle')}
      description={t('labor.formRateDesc')}
      size="md"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel')}
          </button>
          <button type="submit" form="labor-rate-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="labor-rate-form" onSubmit={handleSubmit} className="space-y-3">
        <label className="block space-y-1">
          <span className="text-xs font-medium text-muted-foreground">{t('labor.rateLabel')}</span>
          <input type="number" step="0.001" min="0" value={rate}
            onChange={(e) => setRate(e.target.value)}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
          {errors.daily_rate && <span className="block text-[11px] text-rose-400">{errors.daily_rate}</span>}
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block space-y-1">
            <span className="text-xs font-medium text-muted-foreground">{t('water.effectiveFrom')}</span>
            <input type="date" value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
            {errors.effective_from && <span className="block text-[11px] text-rose-400">{errors.effective_from}</span>}
          </label>
          <label className="block space-y-1">
            <span className="text-xs font-medium text-muted-foreground">{t('water.effectiveToOpt')}</span>
            <input type="date" value={to}
              onChange={(e) => setTo(e.target.value)}
              className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm" />
            {errors.effective_to && <span className="block text-[11px] text-rose-400">{errors.effective_to}</span>}
          </label>
        </div>

        {serverError && (
          <p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
            {serverError}
          </p>
        )}
      </form>
    </Modal>
  );
};

export default LaborRateFormModal;