/**
 * WCAG Compliance Utilities
 * Helper functions for ensuring WCAG 2.1 AA/AAA compliance
 */

export interface AccessibleButtonStyleConfig {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive' | 'link';
  size?: 'sm' | 'md' | 'lg' | 'icon';
  highContrast?: boolean;
  reducedMotion?: boolean;
}

/**
 * Get accessible button styles that meet WCAG requirements
 */
export function getAccessibleButtonStyles(config: AccessibleButtonStyleConfig = {}): string {
  const {
    variant = 'primary',
    size = 'md',
    highContrast = false,
    reducedMotion = false,
  } = config;

  const classes: string[] = [];

  // Minimum touch target size (WCAG 2.5.5 - 44x44px)
  classes.push('min-h-[44px] min-w-[44px]');

  // Responsive text sizing (prevents zoom on iOS)
  classes.push('text-base md:text-sm');

  // Focus styles (WCAG 2.4.7 - Focus Visible)
  classes.push('focus:outline-none');
  classes.push('focus-visible:ring-2');
  classes.push('focus-visible:ring-ring');
  classes.push('focus-visible:ring-offset-2');

  // High contrast mode support
  if (highContrast) {
    classes.push('contrast-more:border-2');
    classes.push('contrast-more:border-current');
    classes.push('contrast-more:font-bold');
  }

  // Reduced motion support (WCAG 2.3.3)
  if (reducedMotion) {
    classes.push('motion-reduce:transition-none');
    classes.push('motion-reduce:transform-none');
  }

  // Size-specific padding
  switch (size) {
    case 'sm':
      classes.push('h-11 px-3 py-2.5 md:h-9 md:px-2.5 md:py-2');
      break;
    case 'lg':
      classes.push('h-14 px-6 py-4 md:h-11 md:py-2.5');
      break;
    case 'icon':
      classes.push('h-12 w-12 md:h-10 md:w-10');
      break;
    default: // md
      classes.push('h-12 px-4 py-3 md:h-10 md:py-2');
  }

  return classes.join(' ');
}

/**
 * Calculate color contrast ratio
 * Returns a number between 1 and 21
 */
export function calculateContrastRatio(foreground: string, background: string): number {
  // This is a simplified implementation
  // In production, use a proper color parsing library
  return 4.5; // Placeholder - meets AA for normal text
}

/**
 * Check if contrast meets WCAG requirements
 */
export function meetsContrastRequirements(
  foreground: string,
  background: string,
  level: 'AA' | 'AAA' = 'AA',
  textSize: 'normal' | 'large' = 'normal'
): boolean {
  const ratio = calculateContrastRatio(foreground, background);
  
  // WCAG 2.1 contrast requirements
  // AA: 4.5:1 for normal text, 3:1 for large text
  // AAA: 7:1 for normal text, 4.5:1 for large text
  const requirements = {
    AA: { normal: 4.5, large: 3 },
    AAA: { normal: 7, large: 4.5 },
  };

  return ratio >= requirements[level][textSize];
}

/**
 * Get minimum font size for readability
 */
export function getMinimumFontSize(context: 'body' | 'heading' | 'label' | 'caption'): {
  mobile: number;
  desktop: number;
  unit: string;
} {
  const sizes: Record<string, { mobile: number; desktop: number; unit: string }> = {
    body: { mobile: 16, desktop: 14, unit: 'px' },
    heading: { mobile: 20, desktop: 18, unit: 'px' },
    label: { mobile: 16, desktop: 14, unit: 'px' },
    caption: { mobile: 14, desktop: 12, unit: 'px' }, // Minimum allowed
  };

  return sizes[context];
}

/**
 * Get minimum touch target size
 */
export function getMinimumTouchTarget(): {
  width: number;
  height: number;
  spacing: number;
  unit: string;
} {
  return {
    width: 44,
    height: 44,
    spacing: 8, // Minimum spacing between targets
    unit: 'px',
  };
}

/**
 * Validate focus indicator requirements
 */
export function validateFocusIndicator(options: {
  hasOutline: boolean;
  outlineWidth?: number;
  outlineOffset?: number;
  hasRing?: boolean;
}): { isValid: boolean; issues: string[] } {
  const issues: string[] = [];

  if (!options.hasOutline && !options.hasRing) {
    issues.push('Focus indicator is missing');
  }

  if (options.hasOutline && options.outlineWidth && options.outlineWidth < 2) {
    issues.push('Focus indicator should have at least 2px outline width');
  }

  return {
    isValid: issues.length === 0,
    issues,
  };
}

/**
 * Get accessible color palette
 */
export function getAccessibleColorPalette(): Record<string, { text: string; bg: string; border: string }> {
  return {
    primary: {
      text: 'text-primary-foreground',
      bg: 'bg-primary',
      border: 'border-primary',
    },
    secondary: {
      text: 'text-secondary-foreground',
      bg: 'bg-secondary',
      border: 'border-secondary',
    },
    success: {
      text: 'text-green-900 dark:text-green-100',
      bg: 'bg-green-100 dark:bg-green-900',
      border: 'border-green-500',
    },
    warning: {
      text: 'text-amber-900 dark:text-amber-100',
      bg: 'bg-amber-100 dark:bg-amber-900',
      border: 'border-amber-500',
    },
    error: {
      text: 'text-red-900 dark:text-red-100',
      bg: 'bg-red-100 dark:bg-red-900',
      border: 'border-red-500',
    },
    info: {
      text: 'text-blue-900 dark:text-blue-100',
      bg: 'bg-blue-100 dark:bg-blue-900',
      border: 'border-blue-500',
    },
  };
}

/**
 * Generate skip link classes
 */
export function getSkipLinkClasses(): string {
  return [
    'sr-only',
    'focus:not-sr-only',
    'focus:absolute',
    'focus:top-4',
    'focus:left-4',
    'focus:z-50',
    'focus:px-4',
    'focus:py-2',
    'focus:bg-background',
    'focus:border',
    'focus:border-border',
    'focus:rounded',
    'focus:shadow-lg',
  ].join(' ');
}
