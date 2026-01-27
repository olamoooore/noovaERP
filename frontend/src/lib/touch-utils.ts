/**
 * Touch Optimization Utilities
 * Helper functions and utilities for mobile touch interaction optimization
 */

import { cn } from './utils';

/**
 * Touch target configuration interface
 */
export interface TouchTargetConfig {
  minSize?: number; // Minimum size in pixels (default: 44px)
  padding?: string; // Additional padding for touch area
  tapHighlight?: boolean; // Enable/disable tap highlight
  userSelect?: boolean; // Enable/disable text selection
}

/**
 * Generate touch-optimized classes for interactive elements
 */
export const getTouchTargetClasses = (config: TouchTargetConfig = {}): string => {
  const {
    minSize,
    padding,
    tapHighlight = false,
    userSelect = false,
  } = config;

  const classes: string[] = [];

  // Minimum touch target size (44px is iOS/Android recommendation)
  // Use nullish coalescing to handle null values properly
  // Always enforce minimum 44px even if smaller size is specified
  const actualMinSize = Math.max(minSize ?? 44, 44);
  const actualPadding = padding ?? '0.5rem';
  
  classes.push(`min-h-[${actualMinSize}px]`);
  classes.push(`min-w-[${actualMinSize}px]`);

  // Touch manipulation optimization
  classes.push('touch-manipulation');

  // Tap highlight control
  if (!tapHighlight) {
    classes.push('tap-highlight-transparent');
  }

  // User select control
  if (!userSelect) {
    classes.push('select-none');
  }

  // Additional padding for easier touch
  if (actualPadding) {
    classes.push(`p-[${actualPadding}]`);
  }

  // Cursor pointer for interactive elements
  classes.push('cursor-pointer');

  return classes.join(' ');
};

/**
 * Generate thumb-friendly navigation classes
 */
export const getThumbNavigationClasses = (): string => {
  return [
    // Position elements within thumb reach (bottom 75% of screen)
    'bottom-0',
    'fixed',
    'w-full',
    'pb-safe', // Safe area padding for devices with home indicator
    // Easy thumb access spacing
    'px-4',
    'py-3',
    // Prevent accidental touches
    'touch-manipulation',
  ].join(' ');
};

/**
 * Generate mobile-first button classes
 */
export const getMobileButtonClasses = (size: 'sm' | 'md' | 'lg' = 'md'): string => {
  const baseClasses = [
    'inline-flex',
    'items-center',
    'justify-center',
    'rounded-md',
    'font-medium',
    'transition-colors',
    'focus-visible:outline-none',
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'disabled:pointer-events-none',
    'disabled:opacity-50',
    'touch-manipulation',
    'select-none',
  ];

  // Size-specific classes with mobile-first approach
  switch (size) {
    case 'sm':
      baseClasses.push(
        'h-9',
        'px-3',
        'text-sm',
        'min-w-[44px]', // Ensure minimum touch target
        'md:h-8',
        'md:px-2.5',
        'md:text-xs'
      );
      break;
    case 'lg':
      baseClasses.push(
        'h-12',
        'px-6',
        'text-base',
        'min-w-[44px]',
        'md:h-11',
        'md:px-8',
        'md:text-lg'
      );
      break;
    default: // md
      baseClasses.push(
        'h-11',
        'px-4',
        'text-sm',
        'min-w-[44px]',
        'md:h-10',
        'md:px-4',
        'md:text-sm'
      );
  }

  return baseClasses.join(' ');
};

/**
 * Generate mobile-optimized input classes
 */
export const getMobileInputClasses = (): string => {
  return [
    'flex',
    'h-12', // Larger on mobile for easier touch
    'w-full',
    'rounded-md',
    'border',
    'border-input',
    'bg-background',
    'px-3',
    'py-2',
    'text-base', // Prevent zoom on iOS
    'ring-offset-background',
    'file:border-0',
    'file:bg-transparent',
    'file:text-sm',
    'file:font-medium',
    'placeholder:text-muted-foreground',
    'focus-visible:outline-none',
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'focus-visible:ring-offset-2',
    'disabled:cursor-not-allowed',
    'disabled:opacity-50',
    'touch-manipulation',
    // Responsive adjustments for larger screens
    'md:h-10',
    'md:text-sm',
  ].join(' ');
};

/**
 * Generate swipe gesture classes
 */
export const getSwipeGestureClasses = (): string => {
  return [
    'touch-pan-x', // Allow horizontal panning
    'select-none', // Prevent text selection during swipe
    'cursor-grab',
    'active:cursor-grabbing',
  ].join(' ');
};

/**
 * Generate mobile-optimized card classes
 */
export const getMobileCardClasses = (): string => {
  return [
    'rounded-lg',
    'border',
    'bg-card',
    'text-card-foreground',
    'shadow-sm',
    // Mobile-specific optimizations
    'touch-manipulation',
    'min-h-[44px]', // Minimum touch target if interactive
    // Responsive padding
    'p-4',
    'md:p-6',
    // Hover states (only on devices that support hover)
    'hover:shadow-md',
    'transition-shadow',
    'duration-200',
  ].join(' ');
};

/**
 * Generate mobile navigation classes
 */
export const getMobileNavigationClasses = (): string => {
  return [
    // Fixed bottom navigation for thumb access
    'fixed',
    'bottom-0',
    'left-0',
    'right-0',
    'z-50',
    // Safe area support
    'pb-safe',
    'bg-background/95',
    'backdrop-blur',
    'border-t',
    // Touch optimizations
    'touch-manipulation',
    // Flex layout for nav items
    'flex',
    'items-center',
    'justify-around',
    'px-4',
    'py-2',
    // Responsive behavior
    'md:relative',
    'md:bottom-auto',
    'md:left-auto',
    'md:right-auto',
    'md:pb-0',
    'md:bg-transparent',
    'md:backdrop-blur-none',
    'md:border-t-0',
    'md:justify-start',
    'md:gap-4',
  ].join(' ');
};

/**
 * Generate mobile table classes for horizontal scrolling
 */
export const getMobileTableClasses = (): string => {
  return [
    'w-full',
    'overflow-x-auto',
    // Smooth scrolling on mobile
    'scroll-smooth',
    // Hide scrollbar on mobile for cleaner look
    'scrollbar-hide',
    'md:scrollbar-default',
    // Touch scrolling optimization
    'touch-pan-x',
    // Minimum width to prevent cramping
    'min-w-full',
  ].join(' ');
};

/**
 * Utility to check if device supports hover
 */
export const supportsHover = (): boolean => {
  if (typeof window === 'undefined') return false;
  return window.matchMedia('(hover: hover)').matches;
};

/**
 * Utility to check if device is touch-capable
 */
export const isTouchDevice = (): boolean => {
  if (typeof window === 'undefined') return false;
  return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
};

/**
 * Generate conditional hover classes (only apply on hover-capable devices)
 */
export const getConditionalHoverClasses = (hoverClasses: string): string => {
  return `hover:${hoverClasses}`;
};

/**
 * Generate mobile-optimized modal/dialog classes
 */
export const getMobileModalClasses = (): string => {
  return [
    // Full screen on mobile
    'fixed',
    'inset-0',
    'z-50',
    'bg-background',
    // Responsive behavior
    'md:relative',
    'md:inset-auto',
    'md:max-w-lg',
    'md:rounded-lg',
    'md:border',
    'md:shadow-lg',
    // Safe area support
    'pt-safe',
    'pb-safe',
    'md:pt-0',
    'md:pb-0',
    // Scrolling
    'overflow-y-auto',
    'touch-manipulation',
  ].join(' ');
};

/**
 * Generate accessibility-focused touch classes
 */
export const getAccessibleTouchClasses = (): string => {
  return [
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
    // Touch feedback
    'active:scale-95',
    'motion-reduce:active:scale-100',
  ].join(' ');
};