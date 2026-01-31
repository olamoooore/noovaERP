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
    primary: '#3b82f6',
    secondary: '#8b5cf6',
    success: '#10b981',
    warning: '#f59e0b',
    danger: '#ef4444',
    info: '#06b6d4',
    gray: '#6b7280',
    text: '#1f2937',
    grid: '#e5e7eb',
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
    primary: '#60a5fa',
    secondary: '#a78bfa',
    success: '#34d399',
    warning: '#fbbf24',
    danger: '#f87171',
    info: '#22d3ee',
    gray: '#9ca3af',
    text: '#f9fafb',
    grid: '#374151',
    background: '#1f2937',
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
