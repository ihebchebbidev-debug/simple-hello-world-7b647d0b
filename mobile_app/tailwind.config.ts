import type { Config } from 'tailwindcss';

export default {
  darkMode: ['class'],
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
          glow: 'hsl(var(--primary-glow))',
        },
        muted: {
          DEFAULT: 'hsl(var(--muted))',
          foreground: 'hsl(var(--muted-foreground))',
        },
        border: 'hsl(var(--border))',
        surface: {
          DEFAULT: 'hsl(var(--surface-container))',
          high: 'hsl(var(--surface-container-high))',
          highest: 'hsl(var(--surface-container-highest))',
          bright: 'hsl(var(--surface-bright))',
        },
        accent: {
          warning: 'hsl(var(--accent-warning))',
          danger: 'hsl(var(--accent-danger))',
          success: 'hsl(var(--accent-success))',
          info: 'hsl(var(--accent-info))',
        },
        chart: {
          blue: 'hsl(var(--chart-blue))',
          green: 'hsl(var(--chart-green))',
          orange: 'hsl(var(--chart-orange))',
          red: 'hsl(var(--chart-red))',
        },
      },
    },
  },
  plugins: [],
} satisfies Config;
