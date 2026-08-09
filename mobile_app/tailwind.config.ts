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
      keyframes: {
        'fade-in': {
          '0%': { opacity: '0', transform: 'translateY(4px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'ai-chat-slide-in': {
          '0%': { opacity: '0', transform: 'translateY(16px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'ai-chat-slide-in-right': {
          '0%': { opacity: '0', transform: 'translateX(100%)' },
          '100%': { opacity: '1', transform: 'translateX(0)' },
        },
        'ai-chat-bounce': {
          '0%, 80%, 100%': { transform: 'translateY(0)', opacity: '0.45' },
          '40%': { transform: 'translateY(-5px)', opacity: '1' },
        },
        'ai-chat-shimmer': {
          '0%': { backgroundPosition: '200% 0' },
          '100%': { backgroundPosition: '-200% 0' },
        },
        'ai-chat-progress': {
          '0%': { transform: 'translateX(-100%)' },
          '100%': { transform: 'translateX(300%)' },
        },
      },
      animation: {
        'fade-in': 'fade-in 220ms ease-out both',
        'ai-chat-slide-in': 'ai-chat-slide-in 280ms cubic-bezier(0.22, 1, 0.36, 1) both',
        'ai-chat-slide-in-right': 'ai-chat-slide-in-right 280ms cubic-bezier(0.22, 1, 0.36, 1) both',
        'ai-chat-bounce': 'ai-chat-bounce 1.1s ease-in-out infinite',
        'ai-chat-shimmer': 'ai-chat-shimmer 2.2s linear infinite',
        'ai-chat-progress': 'ai-chat-progress 1.6s ease-in-out infinite',
      },
    },

  },
  plugins: [],
} satisfies Config;
