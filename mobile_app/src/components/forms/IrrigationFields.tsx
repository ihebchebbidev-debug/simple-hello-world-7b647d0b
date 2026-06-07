import { useTranslation } from 'react-i18next';
import FormField from './FormField';

interface Props { value: string; onChange: (v: string) => void; unit?: string }

const IrrigationFields = ({ value, onChange, unit = 'm³' }: Props) => {
  const { t } = useTranslation();
  return (
    <FormField label={t('form.waterQuantity')} suffix={unit}>
      <input
        type="number" inputMode="decimal" step="0.1" min="0" required
        value={value} onChange={(e) => onChange(e.target.value)}
        className="cl-input h-12 rounded-xl text-base flex-1" placeholder="0"
      />
    </FormField>
  );
};

export default IrrigationFields;
