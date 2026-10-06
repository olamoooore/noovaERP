/**
 * Chart Utilities
 * Helper functions for chart formatting and data manipulation
 */

import { getCurrencySymbol } from '@/lib/utils';

const normalizeChartCurrency = (currency?: unknown): string | undefined =>
  typeof currency === 'string' && /^[A-Za-z]{3}$/.test(currency) ? currency : undefined;

const withCurrencySymbol = (value: number, currency: unknown, options: Intl.NumberFormatOptions): string => {
  const symbol = getCurrencySymbol(normalizeChartCurrency(currency));
  const sign = value < 0 ? '-' : '';
  const number = new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
    ...options,
  }).format(Math.abs(value));
  const spacing = /[A-Za-z]$/.test(symbol) ? ' ' : '';

  return `${sign}${symbol}${spacing}${number}`;
};

/**
 * Format number as currency using the tenant's configured currency and display format.
 */
export const formatCurrency = (value: number, currency?: string): string => {
  return withCurrencySymbol(value, currency, {});
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
 * Compact currency (e.g. S$3.7M) for axis ticks, where full precision is noise.
 */
export const formatCompactCurrency = (value: number, currency?: string): string => {
  if (!Number.isFinite(value)) return '';
  return withCurrencySymbol(value, currency, { notation: 'compact', maximumFractionDigits: 1 });
};

/**
 * Axis-tick formatter that adapts to magnitude: full precision while the
 * numbers are short, compact notation once they would collide. Callers pass
 * the largest value in the series so the whole axis stays consistent.
 */
export const makeAxisTickFormatter = (
  mode: 'currency' | 'number' | 'percent',
  maxValue: number
): ((value: number) => string) => {
  const compact = Math.abs(maxValue) >= 10000;
  if (mode === 'currency') {
    // Always wrap: the returned function is handed straight to Recharts, which
    // invokes formatters with (value, index).
    return compact
      ? (v: number) => formatCompactCurrency(v)
      : (v: number) => formatCurrency(v);
  }
  if (mode === 'percent') {
    return (v: number) => (Number.isFinite(v) ? `${formatCompactNumber(v)}%` : '');
  }
  return compact
    ? (v: number) => (Number.isFinite(v) ? formatCompactNumber(v) : '')
    : (v: number) => (Number.isFinite(v) ? formatNumber(v) : '');
};

/**
 * Truncate a long category label for an axis tick. The full value stays
 * available in the tooltip, so nothing is lost.
 */
export const truncateLabel = (value: unknown, max: number = 16): string => {
  const text = String(value ?? '');
  return text.length > max ? `${text.slice(0, Math.max(1, max - 1))}…` : text;
};

/** Largest absolute numeric value across the given keys — drives adaptive ticks. */
export const maxAbsOf = (data: any[], keys: string[]): number => {
  let max = 0;
  for (const row of data ?? []) {
    for (const key of keys) {
      const n = Math.abs(Number(row?.[key]));
      if (Number.isFinite(n) && n > max) max = n;
    }
  }
  return max;
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
  '#6366f1', // indigo
  '#8b5cf6', // violet
  '#0ea5e9', // sky
  '#10b981', // emerald
  '#f59e0b', // amber
  '#f43f5e', // rose
  '#14b8a6', // teal
  '#a855f7', // purple
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
