interface DateFilterProps {
  value: string; // "YYYY-MM-DD" or ""
  onChange: (iso: string) => void;
  placeholder?: string;
}

const DateFilter = ({ value, onChange, placeholder = 'Date' }: DateFilterProps) => (
  <input
    type="date"
    value={value}
    onChange={(e) => onChange(e.target.value)}
    placeholder={placeholder}
    aria-label={placeholder}
    className="filter-date w-40"
  />
);

export default DateFilter;
