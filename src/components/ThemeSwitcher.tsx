import { useTheme } from '@/hooks/useTheme';
import iconSun from '@/assets/icons/icon-sun.png';
import iconMoon from '@/assets/icons/icon-moon.png';

const ThemeSwitcher = () => {
  const { theme, toggleTheme } = useTheme();
  return (
    <button
      onClick={toggleTheme}
      className="rounded-md p-1.5 transition-colors hover:bg-[hsl(var(--surface-bright))]"
      title={theme === 'dark' ? 'Light mode' : 'Dark mode'}
    >
      <img
        src={theme === 'dark' ? iconSun : iconMoon}
        alt={theme === 'dark' ? 'Switch to light' : 'Switch to dark'}
        className="h-6 w-6"
      />
    </button>
  );
};

export default ThemeSwitcher;
