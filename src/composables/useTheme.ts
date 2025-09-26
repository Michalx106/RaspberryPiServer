import { onMounted, ref, watch } from 'vue';

type Theme = 'light' | 'dark';

const STORAGE_KEY = 'panel-theme';

const resolveInitialTheme = (): Theme => {
  if (typeof window === 'undefined') {
    return 'light';
  }
  const stored = window.localStorage.getItem(STORAGE_KEY) as Theme | null;
  if (stored === 'light' || stored === 'dark') {
    return stored;
  }
  const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
  return prefersDark ? 'dark' : 'light';
};

const applyTheme = (theme: Theme) => {
  if (typeof document === 'undefined') {
    return;
  }
  const root = document.documentElement;
  root.classList.toggle('dark', theme === 'dark');
  root.classList.add('theme-transition');
  document.body.classList.add('theme-transition');
};

export const useTheme = () => {
  const theme = ref<Theme>(resolveInitialTheme());

  onMounted(() => {
    applyTheme(theme.value);
  });

  watch(
    theme,
    (value) => {
      applyTheme(value);
      if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, value);
      }
    },
    { immediate: false }
  );

  const toggleTheme = () => {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
  };

  return { theme, toggleTheme };
};
