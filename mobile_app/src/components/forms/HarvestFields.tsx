import { useTranslation } from 'react-i18next';
import FormField from './FormField';

interface Props {
  quantity: string; onQuantityChange: (v: string) => void;
  workerDays: string; onWorkerDaysChange: (v: string) => void;
}

const HarvestFields = ({ quantity, onQuantityChange, workerDays, onWorkerDaysChange }: Props) => {
  const { t } = useTranslation();
  return (
    <>
      <FormField label={t('form.quantityHarvested')} suffix="kg">
        <input type="number" inputMode="decimal" step="0.001" min="0" required
          value={quantity} onChange={(e) => onQuantityChange(e.target.value)}
          className="cl-input h-12 rounded-xl text-base flex-1" placeholder="0" />
      </FormField>
      <FormField label={t('form.workers', "Main d'œuvre (homme/jour)")}>
        <input type="number" inputMode="numeric" step="1" min="1" required
          value={workerDays} onChange={(e) => onWorkerDaysChange(e.target.value)}
          className="cl-input h-12 rounded-xl text-base flex-1" placeholder="0" />
      </FormField>
    </>
  );
};

export default HarvestFields;
