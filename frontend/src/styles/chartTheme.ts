/**
 * Chart Theme Configuration
 * Defines colors, fonts, and styling for all charts
 */

export interface ChartTheme {
  colors: {
    primary: string;
    secondary: string;
    success: string;
    warning: string;
    danger: string;
    info: string;
    gray: string;
    text: string;
    grid: string;
    background: string;
  };
  fonts: {
    family: string;
    size: {
      small: number;
      medium: number;
      large: number;
    };
  };
  spacing: {
    small: number;
    medium: number;
    large: number;
  };
}

/**
 * Light theme
 */
export const lightTheme: ChartTheme = {
  colors: {
    primary: '#6366f1',
    secondary: '#8b5cf6',
    success: '#10b981',
    warning: '#f59e0b',
    danger: '#f43f5e',
    info: '#0ea5e9',
    gray: '#64748b',
    text: '#94a3b8',
    grid: '#eef2f7',
    background: '#ffffff',
  },
  fonts: {
    family: 'Inter, system-ui, sans-serif',
    size: {
      small: 12,
      medium: 14,
      large: 16,
    },
  },
  spacing: {
    small: 8,
    medium: 16,
    large: 24,
  },
};

/**
 * Dark theme
 */
export const darkTheme: ChartTheme = {
  colors: {
    primary: '#818cf8',
    secondary: '#a78bfa',
    success: '#34d399',
    warning: '#fbbf24',
    danger: '#fb7185',
    info: '#38bdf8',
    gray: '#94a3b8',
    text: '#94a3b8',
    grid: '#1f2937',
    background: '#0f172a',
  },
  fonts: {
    family: 'Inter, system-ui, sans-serif',
    size: {
      small: 12,
      medium: 14,
      large: 16,
    },
  },
  spacing: {
    small: 8,
    medium: 16,
    large: 24,
  },
};

/**
 * Get theme based on mode
 */
export const getChartTheme = (isDark: boolean = false): ChartTheme => {
  return isDark ? darkTheme : lightTheme;
};
