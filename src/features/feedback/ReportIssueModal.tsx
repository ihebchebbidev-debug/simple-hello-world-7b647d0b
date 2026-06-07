import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';
import { Bug } from 'lucide-react';
import Modal from '@/components/Modal';
import { api } from '@/lib/api';

interface ReportIssueModalProps {
  open: boolean;
  onClose: () => void;
}

type FeedbackType = 'bug' | 'feature' | 'other';
type Severity = 'low' | 'medium' | 'high' | 'critical';

const ReportIssueModal = ({ open, onClose }: ReportIssueModalProps) => {
  const { t } = useTranslation();
  const [type, setType] = useState<FeedbackType>('bug');
  const [severity, setSeverity] = useState<Severity>('medium');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const reset = () => {
    setType('bug');
    setSeverity('medium');
    setTitle('');
    setDescription('');
  };

  const handleClose = () => {
    if (submitting) return;
    reset();
    onClose();
  };

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (submitting) return;
    if (title.trim().length < 3) {
      toast.error(t('feedback.errors.title_short', 'Title is too short'));
      return;
    }
    if (description.trim().length < 5) {
      toast.error(t('feedback.errors.description_short', 'Description is too short'));
      return;
    }
    setSubmitting(true);
    try {
      // PUBLIC endpoint — works whether the reporter is signed in or not.
      await api.post('/feedback/public', {
        type,
        severity,
        title: title.trim(),
        description: description.trim(),
        page_url: typeof window !== 'undefined' ? window.location.href : null,
        app_version: 'admin-web',
      });
      toast.success(t('feedback.success', 'Thanks! Your report has been sent.'));
      reset();
      onClose();
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message
        ?? t('feedback.errors.send_failed', 'Could not send the report. Please try again.');
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={t('feedback.modal.title', 'Report a bug or feature')}
      description={t('feedback.modal.description', 'Help us improve Agri-Sync — your message goes straight to the team.')}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-foreground/80">
              {t('feedback.fields.type', 'Type')}
            </label>
            <select
              className="cl-input h-9 w-full text-[13px]"
              value={type}
              onChange={(e) => setType(e.target.value as FeedbackType)}
              disabled={submitting}
            >
              <option value="bug">{t('feedback.type.bug', 'Bug')}</option>
              <option value="feature">{t('feedback.type.feature', 'Feature request')}</option>
              <option value="other">{t('feedback.type.other', 'Other')}</option>
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-foreground/80">
              {t('feedback.fields.severity', 'Severity')}
            </label>
            <select
              className="cl-input h-9 w-full text-[13px]"
              value={severity}
              onChange={(e) => setSeverity(e.target.value as Severity)}
              disabled={submitting}
            >
              <option value="low">{t('feedback.severity.low', 'Low')}</option>
              <option value="medium">{t('feedback.severity.medium', 'Medium')}</option>
              <option value="high">{t('feedback.severity.high', 'High')}</option>
              <option value="critical">{t('feedback.severity.critical', 'Critical')}</option>
            </select>
          </div>
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-foreground/80">
            {t('feedback.fields.title', 'Title')}
          </label>
          <input
            type="text"
            className="cl-input h-9 w-full text-[13px]"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={180}
            placeholder={t('feedback.placeholders.title', 'Short summary')}
            disabled={submitting}
            required
          />
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-foreground/80">
            {t('feedback.fields.description', 'Description')}
          </label>
          <textarea
            className="cl-input min-h-[120px] w-full resize-y px-3 py-2 text-[13px]"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            maxLength={5000}
            placeholder={t('feedback.placeholders.description', 'Steps to reproduce, expected vs actual, or details of the feature you would like.')}
            disabled={submitting}
            required
          />
          <p className="mt-1 text-[11px] text-muted-foreground">
            {description.length}/5000
          </p>
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <button
            type="button"
            className="rounded-md border border-border px-3 py-1.5 text-[13px] font-medium hover:bg-[hsl(var(--surface-bright))]"
            onClick={handleClose}
            disabled={submitting}
          >
            {t('common.cancel', 'Cancel')}
          </button>
          <button
            type="submit"
            className="inline-flex items-center gap-2 rounded-md bg-[hsl(var(--primary))] px-3 py-1.5 text-[13px] font-semibold text-[hsl(var(--primary-foreground))] hover:opacity-90 disabled:opacity-60"
            disabled={submitting}
          >
            <Bug className="h-4 w-4" />
            {submitting
              ? t('feedback.submitting', 'Sending...')
              : t('feedback.submit', 'Send report')}
          </button>
        </div>
      </form>
    </Modal>
  );
};

export default ReportIssueModal;
