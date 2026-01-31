/**
 * Chart Utilities
 * Helper functions for chart formatting and data manipulation
 */

/**
 * Format number as currency
 */
export const formatCurrency = (value: number, currency: string = 'USD'): string => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value);
};

/**
 * Format number with commas
 */
export const formatNumber = (value: number, decimals: number = 0): string => {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value);
};

/**
 * Format percentage
 */
export const formatPercent = (value: number, decimals: number = 1): string => {
  return `${value.toFixed(decimals)}%`;
};

/**
 * Shorten large numbers (1000 -> 1K, 1000000 -> 1M)
 */
export const formatCompactNumber = (value: number): string => {
  if (value >= 1000000) {
    return `${(value / 1000000).toFixed(1)}M`;
  }
  if (value >= 1000) {
    return `${(value / 1000).toFixed(1)}K`;
  }
  return value.toString();
};

/**
 * Default chart colors
 */
export const CHART_COLORS = {
  primary: '#3b82f6',    // blue-500
  secondary: '#8b5cf6',  // violet-500
  success: '#10b981',    // green-500
  warning: '#f59e0b',    // amber-500
  danger: '#ef4444',     // red-500
  info: '#06b6d4',       // cyan-500
  gray: '#6b7280',       // gray-500
};

/**
 * Color palette for multi-series charts
 */
export const COLOR_PALETTE = [
  '#3b82f6', // blue
  '#8b5cf6', // violet
  '#10b981', // green
  '#f59e0b', // amber
  '#ef4444', // red
  '#06b6d4', // cyan
  '#ec4899', // pink
  '#14b8a6', // teal
];

/**
 * Get color by index
 */
export const getColorByIndex = (index: number): string => {
  return COLOR_PALETTE[index % COLOR_PALETTE.length];
};

/**
 * Export chart as image
 */
export const exportChartAsImage = (_chartRef: HTMLElement, filename: string = 'chart.png') => {
  // This would require html2canvas or similar library
  // For now, just a placeholder
  console.log('Export chart:', filename);
};
