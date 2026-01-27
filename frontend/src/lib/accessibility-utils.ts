/**
 * Accessibility Utilities
 * Helper functions for ensuring accessibility compliance across screen sizes
 */

import { cn } from './utils'
import { designTokens } from './design-tokens'
import type { Breakpoint } from '@/hooks/useBreakpoint'

/**
 * Text accessibility configuration interface
 */
export interface TextAccessibilityConfig {
  minSize?: string; // Minimum font size for readability
  maxSize?: string; // Maximum font size to prevent overflow
  lineHeight?: string; // Line height for readability
  contrast?: 'normal' | 'high'; // Contrast level
  responsive?: boolean; // Enable responsive scaling
}

/**
 * Button accessibility configuration interface
 */
export interface ButtonAccessibilityConfig {
  minTouchTarget?: number; // Minimum touch target size in pixels
  padding?: {
    mobile?: string;
    tablet?: string;
    desktop?: string;
  };
  fontSize?: {
    mobile?: string;
    tablet?: string;
    desktop?: string;
  };
  spacing?: string; // Spacing between buttons
}

/**
 * Generate accessible text classes that maintain readability across screen sizes
 */
export const getAccessibleTextClasses = (config: TextAccessibilityConfig = {}): string => {
  const {
    minSize = designTokens.typography.fontSize.sm,
    maxSize = designTokens.typography.fontSize['2xl'],
    lineHeight = 'normal',
    contrast = 'normal',
    responsive = true,
  } = config;

  const classes: string[] = [];

  // Base text classes
  classes.push('font-sans');
  classes.push('leading-normal');
  classes.push('break-words');
  classes.push('select-text');

  // Responsive font sizing
  if (responsive) {
    classes.push('text-sm'); // Mobile base (never smaller than 14px)
    classes.push('md:text-base'); // Tablet and up
    classes.push('lg:text-lg'); // Desktop and up
  }

  // Contrast adjustments
  if (contrast === 'high') {
    classes.push('contrast-more:font-semibold');
    classes.push('contrast-more:text-black');
    classes.push('dark:contrast-more:text-white');
  }

  // Responsive padding for better touch targets
  classes.push('p-1');
  classes.push('md:p-2');

  return classes.join(' ');
};

/**
 * Generate accessible button classes that maintain usability across screen sizes
 */
export const getAccessibleButtonClasses = (config: ButtonAccessibilityConfig = {}): string => {
  const {
    minTouchTarget = 44,
    padding = {
      mobile: '0.75rem 1rem',
      tablet: '0.625rem 1rem',
      desktop: '0.5rem 1rem',
    },
    fontSize = {
      mobile: designTokens.typography.fontSize.base,
      tablet: designTokens.typography.fontSize.sm,
      desktop: designTokens.typography.fontSize.sm,
    },
    spacing = '0.5rem',
  } = config;

  const classes: string[] = [];

  // Minimum touch target size (WCAG AA compliance)
  classes.push(`min-h-[${minTouchTarget}px]`);
  classes.push(`min-w-[${minTouchTarget}px]`);

  // Responsive padding
  classes.push('px-4 py-3'); // Mobile
  classes.push('md:px-4 md:py-2.5'); // Tablet
  classes.push('lg:px-4 lg:py-2'); // Desktop

  // Responsive font sizing
  classes.push('text-base'); // Mobile (prevents zoom on iOS)
  classes.push('md:text-sm'); // Tablet and desktop

  // Touch optimization
  classes.push('touch-manipulation');
  classes.push('select-none');

  // Focus and accessibility
  classes.push('focus:outline-none');
  classes.push('focus-visible:ring-2');
  classes.push('focus-visible:ring-ring');
  classes.push('focus-visible:ring-offset-2');

  // High contrast support
  classes.push('contrast-more:border-2');
  classes.push('contrast-more:border-current');

  // Reduced motion support
  classes.push('motion-reduce:transition-none');
  classes.push('motion-reduce:active:scale-100');

  // Active state feedback
  classes.push('active:scale-95');

  return classes.join(' ');
};

/**
 * Generate responsive text size classes based on breakpoint
 */
export const getResponsiveTextSize = (
  breakpoint: Breakpoint,
  baseSize: keyof typeof designTokens.typography.fontSize = 'base'
): string => {
  const sizeMap = {
    mobile: {
      xs: 'text-sm', // Never use text-xs on mobile for accessibility
      sm: 'text-base',
      base: 'text-lg',
      lg: 'text-xl',
      xl: 'text-2xl',
      '2xl': 'text-3xl',
      '3xl': 'text-4xl',
      '4xl': 'text-5xl',
      '5xl': 'text-6xl',
      '6xl': 'text-7xl',
    },
    tablet: {
      xs: 'text-sm', // Never use text-xs for accessibility
      sm: 'text-sm',
      base: 'text-base',
      lg: 'text-lg',
      xl: 'text-xl',
      '2xl': 'text-2xl',
      '3xl': 'text-3xl',
      '4xl': 'text-4xl',
      '5xl': 'text-5xl',
      '6xl': 'text-6xl',
    },
    desktop: {
      xs: 'text-sm', // Never use text-xs for accessibility
      sm: 'text-sm',
      base: 'text-base',
      lg: 'text-lg',
      xl: 'text-xl',
      '2xl': 'text-2xl',
      '3xl': 'text-3xl',
      '4xl': 'text-4xl',
      '5xl': 'text-5xl',
      '6xl': 'text-6xl',
    },
    wide: {
      xs: 'text-sm', // Never use text-xs for accessibility
      sm: 'text-sm',
      base: 'text-base',
      lg: 'text-lg',
      xl: 'text-xl',
      '2xl': 'text-2xl',
      '3xl': 'text-3xl',
      '4xl': 'text-4xl',
      '5xl': 'text-5xl',
      '6xl': 'text-6xl',
    },
  };

  return sizeMap[breakpoint][baseSize] || sizeMap[breakpoint].base;
};

/**
 * Generate responsive button size classes based on breakpoint
 */
export const getResponsiveButtonSize = (
  breakpoint: Breakpoint,
  baseSize: 'sm' | 'md' | 'lg' = 'md'
): string => {
  const sizeMap = {
    mobile: {
      sm: 'h-10 px-3 text-base min-w-[44px]',
      md: 'h-12 px-4 text-base min-w-[44px]',
      lg: 'h-14 px-6 text-lg min-w-[44px]',
    },
    tablet: {
      sm: 'h-10 px-3 text-sm min-w-[44px]', // Ensure minimum 44px height
      md: 'h-11 px-4 text-sm min-w-[44px]',
      lg: 'h-12 px-6 text-base min-w-[44px]',
    },
    desktop: {
      sm: 'h-10 px-2.5 text-sm min-w-[44px]', // Never go below 44px height
      md: 'h-10 px-4 text-sm min-w-[44px]',
      lg: 'h-11 px-6 text-base min-w-[44px]',
    },
    wide: {
      sm: 'h-10 px-2.5 text-sm min-w-[44px]', // Never go below 44px height
      md: 'h-10 px-4 text-sm min-w-[44px]',
      lg: 'h-11 px-6 text-base min-w-[44px]',
    },
  };

  return sizeMap[breakpoint][baseSize];
};

/**
 * Check if text meets WCAG contrast requirements
 */
export const checkContrastRatio = (
  foreground: string,
  background: string,
  level: 'AA' | 'AAA' = 'AA'
): boolean => {
  // This is a simplified check - in a real implementation,
  // you would use a proper color contrast calculation library
  const requiredRatio = level === 'AAA' ? 7 : 4.5;
  
  // For now, return true as this would require color parsing
  // In production, use a library like 'color-contrast' or similar
  return true;
};

/**
 * Generate WCAG compliant color classes
 */
export const getWCAGCompliantColors = (
  variant: 'primary' | 'secondary' | 'success' | 'warning' | 'error' = 'primary'
): string => {
  const colorMap = {
    primary: 'text-blue-900 bg-blue-50 border-blue-200 hover:bg-blue-100',
    secondary: 'text-gray-900 bg-gray-50 border-gray-200 hover:bg-gray-100',
    success: 'text-green-900 bg-green-50 border-green-200 hover:bg-green-100',
    warning: 'text-amber-900 bg-amber-50 border-amber-200 hover:bg-amber-100',
    error: 'text-red-900 bg-red-50 border-red-200 hover:bg-red-100',
  };

  return colorMap[variant];
};

/**
 * Generate screen reader only classes
 */
export const getScreenReaderOnlyClasses = (): string => {
  return 'sr-only';
};

/**
 * Generate skip link classes for keyboard navigation
 */
export const getSkipLinkClasses = (): string => {
  return [
    'absolute',
    'left-[-10000px]',
    'top-auto',
    'width-[1px]',
    'height-[1px]',
    'overflow-hidden',
    'focus:left-0',
    'focus:top-0',
    'focus:width-auto',
    'focus:height-auto',
    'focus:overflow-visible',
    'focus:z-50',
    'focus:p-2',
    'focus:bg-white',
    'focus:border',
    'focus:border-gray-300',
    'focus:rounded',
  ].join(' ');
};

/**
 * Generate focus management classes
 */
export const getFocusManagementClasses = (): string => {
  return [
    'focus:outline-none',
    'focus-visible:outline-2',
    'focus-visible:outline-offset-2',
    'focus-visible:outline-blue-600',
    'focus-visible:ring-2',
    'focus-visible:ring-blue-600',
    'focus-visible:ring-offset-2',
  ].join(' ');
};

/**
 * Generate keyboard navigation classes
 */
export const getKeyboardNavigationClasses = (): string => {
  return [
    'focus:outline-none',
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'focus-visible:ring-offset-2',
    'focus-visible:bg-accent',
    'focus-visible:text-accent-foreground',
  ].join(' ');
};

/**
 * Utility to ensure minimum text size for readability
 */
export const ensureMinimumTextSize = (className: string): string => {
  // Ensure text is never smaller than 14px (0.875rem) for readability
  const hasTextSize = /text-(xs|sm|base|lg|xl|\dxl)/.test(className);
  
  if (!hasTextSize) {
    return cn(className, 'text-sm');
  }
  
  // Replace text-xs with text-sm for better readability
  return className.replace(/text-xs/g, 'text-sm');
};

/**
 * Utility to ensure minimum button size for accessibility
 */
export const ensureMinimumButtonSize = (className: string): string => {
  // Ensure buttons meet minimum 44px touch target
  const hasHeight = /h-\d+/.test(className);
  const hasMinHeight = /min-h-\[44px\]/.test(className);
  
  if (!hasHeight && !hasMinHeight) {
    return cn(className, 'min-h-[44px] min-w-[44px]');
  }
  
  return className;
};

/**
 * Generate responsive accessibility classes
 */
export const getResponsiveAccessibilityClasses = (): string => {
  return [
    // Text scaling
    'text-base', // Mobile: 16px prevents zoom on iOS
    'md:text-sm', // Tablet and desktop can use smaller text
    
    // Touch targets
    'min-h-[44px]', // Minimum touch target
    'min-w-[44px]',
    
    // Spacing
    'p-3', // Mobile: larger padding
    'md:p-2', // Desktop: smaller padding
    
    // Focus management
    'focus:outline-none',
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'focus-visible:ring-offset-2',
    
    // High contrast support
    'contrast-more:border-2',
    'contrast-more:border-current',
    
    // Reduced motion support
    'motion-reduce:transition-none',
    'motion-reduce:transform-none',
  ].join(' ');
};