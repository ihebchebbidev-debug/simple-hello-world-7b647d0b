import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export const CAMPAIGN_ALL = 'all';

interface CampaignLite {
  id: string;
  name: string;
}

interface CampaignFilterProps {
  value: string;
  onChange: (id: string) => void;
}

const CampaignFilter = ({ value, onChange }: CampaignFilterProps) => {
  const { t } = useTranslation();

  const { data } = useQuery({
    queryKey: ['report-filter-campaigns'],
    queryFn: async () => {
      const { data } = await api.get<{ data: CampaignLite[] }>('/campaigns', {
        params: { per_page: 100 },
      });
      const payload = (data as unknown as { data?: CampaignLite[] }).data;
      return Array.isArray(payload) ? payload : [];
    },
    staleTime: 5 * 60 * 1000,
  });

  return (
    <select
      className="filter-select w-48"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      title={t('reports.campaignFilter', 'Campaign')}
    >
      <option value={CAMPAIGN_ALL}>{t('reports.allCampaigns', 'All campaigns')}</option>
      {(data ?? []).map((c) => (
        <option key={c.id} value={c.id}>
          {c.name}
        </option>
      ))}
    </select>
  );
};

export default CampaignFilter;
