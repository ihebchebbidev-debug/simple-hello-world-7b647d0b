interface DateFilterProps {
  value: string; // "YYYY-MM-DD" or ""
  onChange: (iso: string) => void;
  label?: string;
  placeholder?: string;
  className?: string;
}

const DateFilter = ({ value, onChange, label, placeholder = 'Date', className = '' }: DateFilterProps) => (
  <label className="flex flex-col text-[10px] leading-tight">
    {label ? (
      <span className="mb-1 uppercase tracking-[0.14em] text-muted-foreground">{label}</span>
    ) : null}
    <input
      type="date"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      aria-label={label || placeholder}
      className={`filter-date w-40 ${className}`}
    />
  </label>
);

export default DateFilter;
