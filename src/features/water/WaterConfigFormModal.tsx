import { useEffect, useState } from 'react';
import { z } from 'zod';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';

const unitRegex = /^[A-Za-z0-9%/\-]+$/;

const buildSchema = (t: (k: string) => string) =>
  z.object({
    unit: z
      .string()
      .trim()
      .min(1, t('validation.unitRequired'))
      .max(20, t('validation.tooLarge'))
      .regex(unitRegex, t('validation.unitChars')),
    is_active: z.boolean(),
  });

export type WaterConfigSubmit = { unit: string; is_active: boolean };

interface Props {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: { unit: string; is_active: boolean } | null;
  submitting?: boolean;
  serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: WaterConfigSubmit) => void;
}

const UNIT_OPTIONS = ['m3', 'L', 'mm', 'm3'];

const WaterConfigFormModal = ({ open, mode, initial, submitting, serverError, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [unit, setUnit] = useState('m3');
  const [isActive, setIsActive] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setUnit(initial?.unit ?? 'm3');
    setIsActive(initial?.is_active ?? true);
    setErrors({});
  }, [open, initial]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    const parsed = buildSchema(t).safeParse({ unit, is_active: isActive });
    if (!parsed.success) {
      const fe: Record<string, string> = {};
      for (const i of parsed.error.issues) {
        const k = i.path[0] as string;
        if (k && !fe[k]) fe[k] = i.message;
      }
      setErrors(fe);
      return;
    }
    onSubmit(parsed.data);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={mode === 'create' ? t('water.formUnitCreateTitle') : t('water.formUnitEditTitle')}
      size="sm"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel')}
          </button>
          <button type="submit" form="water-config-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="water-config-form" onSubmit={handleSubmit} className="space-y-3">
        <label className="block space-y-1">
          <span className="text-xs font-medium text-muted-foreground">{t('water.unitLabel')}</span>
          <input
            list="water-unit-options"
            type="text"
            value={unit}
            maxLength={20}
            onChange={(e) => setUnit(e.target.value)}
            className="h-9 w-full rounded-md border border-border bg-background px-3 text-sm"
          />
          <datalist id="water-unit-options">
            {UNIT_OPTIONS.map((u) => <option key={u} value={u} />)}
          </datalist>
          {errors.unit && <span className="block text-[11px] text-rose-400">{errors.unit}</span>}
        </label>

        <label className="block space-y-1">
          <span className="text-xs font-medium text-muted-foreground">{t('common.status')}</span>
          <select
            value={isActive ? 'active' : 'inactive'}
            onChange={(e) => setIsActive(e.target.value === 'active')}
            className="h-9 w-full rounded-md border border-border bg-background px-2 text-sm"
          >
            <option value="active">{t('common.active')}</option>
            <option value="inactive">{t('common.inactive')}</option>
          </select>
        </label>

        {serverError && (
          <p className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
            {serverError}
          </p>
        )}
      </form>
    </Modal>
  );
};

export default WaterConfigFormModal;
