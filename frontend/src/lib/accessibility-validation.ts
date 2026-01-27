/**
 * Accessibility Validation Utilities
 * Validates and fixes accessibility issues for text elements
 */

import type { Breakpoint } from '@/hooks/useBreakpoint';

export interface AccessibilityValidationResult {
  isValid: boolean;
  issues: string[];
  suggestions: string[];
}

/**
 * Validates text accessibility based on class names and breakpoint
 */
export function validateTextAccessibility(
  classNames: string,
  breakpoint: Breakpoint
): AccessibilityValidationResult {
  const issues: string[] = [];
  const suggestions: string[] = [];
  
  const isMobile = breakpoint === 'mobile';
  const isSmallScreen = breakpoint === 'mobile' || breakpoint === 'tablet';

  // Check for text that might be too small
  const tooSmallClasses = ['text-xs', 'text-[10px]', 'text-[11px]', 'text-[12px]', 'text-[13px]'];
  for (const smallClass of tooSmallClasses) {
    // On mobile, small text is more problematic
    if (classNames.includes(smallClass) && isMobile && !classNames.includes('md:')) {
      issues.push(`Text may be too small on ${breakpoint}: ${smallClass}`);
      suggestions.push('Consider using at least text-sm (14px) for readability on mobile');
    } else if (classNames.includes(smallClass) && !classNames.includes('md:')) {
      issues.push(`Text may be too small: ${smallClass}`);
      suggestions.push('Consider using at least text-sm (14px) for readability');
    }
  }

  // Check for sufficient contrast classes
  if (classNames.includes('text-gray-300') || classNames.includes('text-gray-400')) {
    if (!classNames.includes('dark:')) {
      issues.push('Low contrast text detected');
      suggestions.push('Add high contrast alternative for accessibility');
    }
  }

  // Check for line height on small screens where readability is more critical
  if (classNames.includes('leading-none') || classNames.includes('leading-tight')) {
    if (classNames.includes('text-sm') || classNames.includes('text-base')) {
      if (isSmallScreen) {
        issues.push('Tight line height on body text reduces readability on small screens');
      } else {
        issues.push('Tight line height on body text may reduce readability');
      }
      suggestions.push('Consider using leading-normal or leading-relaxed for body text');
    }
  }

  return {
    isValid: issues.length === 0,
    issues,
    suggestions,
  };
}

/**
 * Fixes common accessibility issues in class names
 */
export function fixAccessibilityIssues(
  classNames: string,
  elementType: 'text' | 'heading' | 'label' = 'text'
): string {
  let fixedClasses = classNames;

  // Replace text-xs with minimum readable size on mobile
  if (fixedClasses.includes('text-xs') && !fixedClasses.includes('md:text-xs')) {
    fixedClasses = fixedClasses.replace('text-xs', 'text-base md:text-sm');
  }

  // Add high contrast mode support if not present
  if (!fixedClasses.includes('contrast-more:')) {
    fixedClasses += ' contrast-more:font-semibold';
  }

  // Ensure proper line height for readability
  if (elementType === 'text' && !fixedClasses.includes('leading-')) {
    fixedClasses += ' leading-normal';
  }

  return fixedClasses;
}

/**
 * Checks if text meets WCAG 2.1 size requirements
 */
export function meetsWCAGSizeRequirements(fontSize: number, isMobile: boolean): boolean {
  // WCAG recommends minimum 16px on mobile, 14px on desktop
  const minimumSize = isMobile ? 16 : 14;
  return fontSize >= minimumSize;
}

/**
 * Gets recommended font size based on context
 */
export function getRecommendedFontSize(
  elementType: 'text' | 'heading' | 'label',
  isMobile: boolean
): number {
  const recommendations = {
    text: { mobile: 16, desktop: 14 },
    heading: { mobile: 24, desktop: 20 },
    label: { mobile: 16, desktop: 14 },
  };

  return isMobile ? recommendations[elementType].mobile : recommendations[elementType].desktop;
}
