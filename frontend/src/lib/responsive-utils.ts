/**
 * Responsive Utilities
 * Helper functions and utilities for responsive design implementation
 */

import { designTokens } from './design-tokens';
import type { Breakpoint } from '@/hooks/useBreakpoint';

/**
 * Responsive grid configuration interface
 */
export interface ResponsiveGridConfig {
  columns?: {
    mobile?: number;
    tablet?: number;
    desktop?: number;
    wide?: number;
  };
  gap?: {
    mobile?: string;
    tablet?: string;
    desktop?: string;
    wide?: string;
  };
  minItemWidth?: string;
  maxItemWidth?: string;
}

/**
 * Generate responsive grid CSS classes
 */
export const getResponsiveGridClasses = (config: ResponsiveGridConfig = {}): string => {
  const {
    columns = designTokens.grid.columns,
    gap = designTokens.grid.gap,
  } = config;

  const classes: string[] = ['grid'];

  // Add responsive column classes
  if (columns.mobile) {
    classes.push(`grid-cols-${columns.mobile}`);
  }
  if (columns.tablet) {
    classes.push(`md:grid-cols-${columns.tablet}`);
  }
  if (columns.desktop) {
    classes.push(`lg:grid-cols-${columns.desktop}`);
  }
  if (columns.wide) {
    classes.push(`xl:grid-cols-${columns.wide}`);
  }

  // Add responsive gap classes
  if (gap.mobile) {
    classes.push(`gap-${convertRemToTailwindSpacing(gap.mobile)}`);
  }
  if (gap.tablet) {
    classes.push(`md:gap-${convertRemToTailwindSpacing(gap.tablet)}`);
  }
  if (gap.desktop) {
    classes.push(`lg:gap-${convertRemToTailwindSpacing(gap.desktop)}`);
  }
  if (gap.wide) {
    classes.push(`xl:gap-${convertRemToTailwindSpacing(gap.wide)}`);
  }

  return classes.join(' ');
};

/**
 * Generate responsive container classes
 */
export const getResponsiveContainerClasses = (): string => {
  return [
    'w-full',
    'mx-auto',
    'px-4',
    'sm:px-6',
    'lg:px-8',
    'max-w-sm',
    'sm:max-w-md',
    'md:max-w-lg',
    'lg:max-w-4xl',
    'xl:max-w-6xl',
    '2xl:max-w-7xl',
  ].join(' ');
};

/**
 * Generate responsive padding classes
 */
export const getResponsivePaddingClasses = (config: {
  mobile?: string;
  tablet?: string;
  desktop?: string;
} = {}): string => {
  const classes: string[] = [];

  if (config.mobile) {
    classes.push(`p-${convertRemToTailwindSpacing(config.mobile)}`);
  } else {
    classes.push('p-4');
  }

  if (config.tablet) {
    classes.push(`md:p-${convertRemToTailwindSpacing(config.tablet)}`);
  } else {
    classes.push('md:p-6');
  }

  if (config.desktop) {
    classes.push(`lg:p-${convertRemToTailwindSpacing(config.desktop)}`);
  } else {
    classes.push('lg:p-8');
  }

  return classes.join(' ');
};

/**
 * Generate responsive text size classes
 */
export const getResponsiveTextClasses = (config: {
  mobile?: string;
  tablet?: string;
  desktop?: string;
} = {}): string => {
  const classes: string[] = [];

  if (config.mobile) {
    classes.push(`text-${config.mobile}`);
  }
  if (config.tablet) {
    classes.push(`md:text-${config.tablet}`);
  }
  if (config.desktop) {
    classes.push(`lg:text-${config.desktop}`);
  }

  return classes.join(' ');
};

/**
 * Convert rem values to Tailwind spacing scale
 */
const convertRemToTailwindSpacing = (remValue: string): string => {
  const numValue = parseFloat(remValue);
  
  // Map common rem values to Tailwind spacing
  const spacingMap: Record<number, string> = {
    0.25: '1',    // 4px
    0.5: '2',     // 8px
    0.75: '3',    // 12px
    1: '4',       // 16px
    1.25: '5',    // 20px
    1.5: '6',     // 24px
    2: '8',       // 32px
    2.5: '10',    // 40px
    3: '12',      // 48px
    4: '16',      // 64px
    5: '20',      // 80px
    6: '24',      // 96px
  };

  return spacingMap[numValue] || '4';
};

/**
 * Get responsive breakpoint media queries
 */
export const getMediaQueries = () => ({
  mobile: `(max-width: ${designTokens.breakpoints.mobile})`,
  tablet: `(min-width: ${designTokens.breakpoints.mobile}) and (max-width: ${designTokens.breakpoints.tablet})`,
  desktop: `(min-width: ${designTokens.breakpoints.tablet}) and (max-width: ${designTokens.breakpoints.desktop})`,
  wide: `(min-width: ${designTokens.breakpoints.desktop})`,
  mobileUp: `(min-width: ${designTokens.breakpoints.mobile})`,
  tabletUp: `(min-width: ${designTokens.breakpoints.tablet})`,
  desktopUp: `(min-width: ${designTokens.breakpoints.desktop})`,
});

/**
 * Touch target size utilities for mobile optimization
 */
export const getTouchTargetClasses = (): string => {
  return [
    'min-h-[44px]',  // Minimum 44px height for touch targets
    'min-w-[44px]',  // Minimum 44px width for touch targets
    'touch-manipulation', // Optimize for touch
  ].join(' ');
};

/**
 * Generate responsive flex classes
 */
export const getResponsiveFlexClasses = (config: {
  mobile?: 'row' | 'col';
  tablet?: 'row' | 'col';
  desktop?: 'row' | 'col';
} = {}): string => {
  const classes: string[] = ['flex'];

  if (config.mobile) {
    classes.push(config.mobile === 'row' ? 'flex-row' : 'flex-col');
  } else {
    classes.push('flex-col'); // Default to column on mobile
  }

  if (config.tablet) {
    classes.push(config.tablet === 'row' ? 'md:flex-row' : 'md:flex-col');
  }

  if (config.desktop) {
    classes.push(config.desktop === 'row' ? 'lg:flex-row' : 'lg:flex-col');
  }

  return classes.join(' ');
};

/**
 * Responsive visibility utilities
 */
export const getResponsiveVisibilityClasses = (config: {
  mobile?: boolean;
  tablet?: boolean;
  desktop?: boolean;
} = {}): string => {
  const classes: string[] = [];

  if (config.mobile === false) {
    classes.push('hidden');
  }
  if (config.tablet === true) {
    classes.push('md:block');
  } else if (config.tablet === false) {
    classes.push('md:hidden');
  }
  if (config.desktop === true) {
    classes.push('lg:block');
  } else if (config.desktop === false) {
    classes.push('lg:hidden');
  }

  return classes.join(' ');
};

/**
 * Calculate responsive dimensions based on breakpoint
 */
export const getResponsiveDimensions = (
  breakpoint: Breakpoint,
  config: {
    mobile?: { width?: string; height?: string };
    tablet?: { width?: string; height?: string };
    desktop?: { width?: string; height?: string };
    wide?: { width?: string; height?: string };
  }
) => {
  switch (breakpoint) {
    case 'mobile':
      return config.mobile || { width: '100%', height: 'auto' };
    case 'tablet':
      return config.tablet || config.mobile || { width: '100%', height: 'auto' };
    case 'desktop':
      return config.desktop || config.tablet || config.mobile || { width: '100%', height: 'auto' };
    case 'wide':
      return config.wide || config.desktop || config.tablet || config.mobile || { width: '100%', height: 'auto' };
    default:
      return { width: '100%', height: 'auto' };
  }
};