import type { Config } from 'tailwindcss';

export default {
  content: ['./index.html', './src/**/*.{ts,vue}'],
  theme: {
    extend: {
      colors: {
        'panel-bg': 'rgb(var(--color-panel-bg) / <alpha-value>)',
        'panel-card': 'rgb(var(--color-panel-card) / <alpha-value>)',
        'panel-text': 'rgb(var(--color-panel-text) / <alpha-value>)',
        'panel-accent': 'rgb(var(--color-panel-accent) / <alpha-value>)',
        'panel-muted': 'rgb(var(--color-panel-muted) / <alpha-value>)'
      }
    }
  },
  plugins: []
} satisfies Config;
