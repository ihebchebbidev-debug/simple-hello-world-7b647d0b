import { useTranslation } from 'react-i18next';
import Modal from './Modal';

interface ConfirmDialogProps {
  open: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: 'danger' | 'default';
  loading?: boolean;
  onConfirm: () => void;
  onClose: () => void;
}

const ConfirmDialog = ({
  open,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = 'default',
  loading = false,
  onConfirm,
  onClose,
}: ConfirmDialogProps) => {
  const { t } = useTranslation();
  return (
  <Modal
    open={open}
    onClose={onClose}
    title={title}
    size="sm"
    footer={
      <>
        <button
          type="button"
          onClick={onClose}
          className="rounded-md border border-border px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          {cancelLabel ?? t('common.cancel')}
        </button>
        <button
          type="button"
          disabled={loading}
          onClick={onConfirm}
          className={
            variant === 'danger'
              ? 'rounded-md bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-500 disabled:opacity-50'
              : 'rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50'
          }
        >
          {loading ? '…' : (confirmLabel ?? t('common.confirm'))}
        </button>
      </>
    }
  >
    <p className="text-sm text-muted-foreground">{message}</p>
  </Modal>
  );
};

export default ConfirmDialog;
