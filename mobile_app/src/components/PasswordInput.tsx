import { forwardRef, useState, InputHTMLAttributes } from 'react';
import { Eye, EyeOff } from 'lucide-react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
  inputClassName?: string;
};

const PasswordInput = forwardRef<HTMLInputElement, Props>(
  ({ inputClassName, className, ...rest }, ref) => {
    const [visible, setVisible] = useState(false);
    return (
      <div className={`relative ${className ?? ''}`}>
        <input
          ref={ref}
          type={visible ? 'text' : 'password'}
          className={`${inputClassName ?? ''} pr-11`}
          {...rest}
        />
        <button
          type="button"
          tabIndex={-1}
          onClick={() => setVisible((v) => !v)}
          aria-label={visible ? 'Hide password' : 'Show password'}
          className="absolute right-2 top-1/2 -translate-y-1/2 p-2 text-muted-foreground hover:text-foreground"
        >
          {visible ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
        </button>
      </div>
    );
  }
);
PasswordInput.displayName = 'PasswordInput';
export default PasswordInput;
