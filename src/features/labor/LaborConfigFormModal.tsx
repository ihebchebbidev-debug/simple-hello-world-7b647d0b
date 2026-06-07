import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Modal from '@/components/Modal';

export type LaborConfigSubmit = { is_active: boolean };

interface Props {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: { is_active: boolean } | null;
  submitting?: boolean;
  serverError?: string | null;
  onClose: () => void;
  onSubmit: (values: LaborConfigSubmit) => void;
}

const LaborConfigFormModal = ({ open, mode, initial, submitting, serverError, onClose, onSubmit }: Props) => {
  const { t } = useTranslation();
  const [isActive, setIsActive] = useState(true);

  useEffect(() => {
    if (!open) return;
    setIsActive(initial?.is_active ?? true);
  }, [open, initial]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    onSubmit({ is_active: isActive });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={mode === 'create' ? t('labor.formConfigCreateTitle') : t('labor.formConfigEditTitle')}
      description={t('labor.formConfigDesc')}
      size="sm"
      footer={
        <>
          <button type="button" onClick={onClose} className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground">
            {t('common.cancel')}
          </button>
          <button type="submit" form="labor-config-form" disabled={submitting} className="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50">
            {submitting ? t('common.saving') : t('common.save')}
          </button>
        </>
      }
    >
      <form id="labor-config-form" onSubmit={handleSubmit} className="space-y-3">
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

export default LaborConfigFormModal;