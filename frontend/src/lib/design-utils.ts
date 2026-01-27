/**
 * Design System Utilities
 * Helper functions for working with design tokens and CSS custom properties
 */

import { designTokens, type ColorScale, type SpacingScale } from './design-tokens';

// CSS Custom Property Utilities
export const getCSSVar = (property: string): string => {
  if (typeof window !== 'undefined') {
    return getComputedStyle(document.documentElement).getPropertyValue(property).trim();
  }
  return '';
};

export const setCSSVar = (property: string, value: string): void => {
  if (typeof window !== 'undefined') {
    document.documentElement.style.setProperty(property, value);
  }
};

// Color Utilities
export const getColorValue = (colorPath: string, shade?: keyof ColorScale): string => {
  const [category, subcategory] = colorPath.split('.');
  
  if (category === 'semantic' && subcategory && shade) {
    const semanticColor = designTokens.colors.semantic[subcategory as keyof typeof designTokens.colors.semantic];
    return semanticColor?.[shade] || designTokens.colors.neutral[500];
  }
  
  if (shade) {
    const colorScale = designTokens.colors[category as keyof typeof designTokens.colors] as ColorScale;
    return colorScale?.[shade] || designTokens.colors.neutral[500];
  }
  
  // Return base color (500) if no shade specified
  const colorScale = designTokens.colors[category as keyof typeof designTokens.colors] as ColorScale;
  return colorScale?.[500] || designTokens.colors.neutral[500];
};

// Generate CSS custom property name for colors
export const getColorCSSVar = (colorPath: string, shade: keyof ColorScale): string => {
  const [category, subcategory] = colorPath.split('.');
  
  if (category === 'semantic' && subcategory) {
    return `--${subcategory}-${shade}`;
  }
  
  return `--${category}-${shade}`;
};

// Spacing Utilities
export const getSpacingValue = (key: keyof SpacingScale): string => {
  return designTokens.spacing[key];
};

// Typography Utilities
export const getFontSizeValue = (size: keyof typeof designTokens.typography.fontSize): string => {
  return designTokens.typography.fontSize[size];
};

export const getFontWeightValue = (weight: keyof typeof designTokens.typography.fontWeight): string => {
  return designTokens.typography.fontWeight[weight];
};

export const getLineHeightValue = (height: keyof typeof designTokens.typography.lineHeight): string => {
  return designTokens.typography.lineHeight[height];
};

// Shadow Utilities
export const getShadowValue = (shadow: keyof typeof designTokens.shadows): string => {
  return designTokens.shadows[shadow];
};

// Border Radius Utilities
export const getBorderRadiusValue = (radius: keyof typeof designTokens.borderRadius): string => {
  return designTokens.borderRadius[radius];
};

// Animation Utilities
export const getAnimationDurationValue = (duration: keyof typeof designTokens.animations.duration): string => {
  return designTokens.animations.duration[duration];
};

export const getAnimationEasingValue = (easing: keyof typeof designTokens.animations.easing): string => {
  return designTokens.animations.easing[easing];
};

// Responsive Utilities
export const breakpoints = {
  mobile: 768,
  tablet: 1024,
  desktop: 1440,
} as const;

export const mediaQueries = {
  mobile: `@media (max-width: ${breakpoints.mobile - 1}px)`,
  tablet: `@media (min-width: ${breakpoints.mobile}px) and (max-width: ${breakpoints.tablet - 1}px)`,
  desktop: `@media (min-width: ${breakpoints.tablet}px)`,
  'mobile-up': `@media (min-width: ${breakpoints.mobile}px)`,
  'tablet-up': `@media (min-width: ${breakpoints.tablet}px)`,
} as const;

// Accessibility Utilities
export const getContrastRatio = (color1: string, color2: string): number => {
  // Simplified contrast ratio calculation
  // In a real implementation, you'd want a more robust color parsing and contrast calculation
  const getLuminance = (color: string): number => {
    // This is a simplified version - you'd want to use a proper color library
    // for accurate luminance calculation
    return 0.5; // Placeholder
  };

  const lum1 = getLuminance(color1);
  const lum2 = getLuminance(color2);
  const brightest = Math.max(lum1, lum2);
  const darkest = Math.min(lum1, lum2);

  return (brightest + 0.05) / (darkest + 0.05);
};

export const meetsWCAGAA = (color1: string, color2: string, isLargeText = false): boolean => {
  const ratio = getContrastRatio(color1, color2);
  return isLargeText ? ratio >= 3 : ratio >= 4.5;
};

// Component Styling Utilities
export const createComponentStyles = (baseStyles: Record<string, any>, variants?: Record<string, Record<string, any>>) => {
  return {
    base: baseStyles,
    variants: variants || {},
  };
};

// CSS-in-JS Style Object Utilities
export const createStyleObject = (styles: Record<string, string | number>): React.CSSProperties => {
  const processedStyles: React.CSSProperties = {};
  
  Object.entries(styles).forEach(([key, value]) => {
    // Convert kebab-case to camelCase for React CSS properties
    const camelKey = key.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    processedStyles[camelKey as keyof React.CSSProperties] = value as any;
  });
  
  return processedStyles;
};

// Theme-aware color utilities
export const getThemeAwareColor = (lightColor: string, darkColor: string, theme: 'light' | 'dark'): string => {
  return theme === 'dark' ? darkColor : lightColor;
};

// Generate CSS custom properties for a color scale
export const generateColorCSSVars = (colorName: string, colorScale: ColorScale): Record<string, string> => {
  const vars: Record<string, string> = {};
  
  Object.entries(colorScale).forEach(([shade, value]) => {
    vars[`--${colorName}-${shade}`] = value;
  });
  
  return vars;
};

// Validation utilities
export const isValidColorPath = (path: string): boolean => {
  const [category, subcategory] = path.split('.');
  
  if (category === 'semantic') {
    return subcategory in designTokens.colors.semantic;
  }
  
  return category in designTokens.colors;
};

export const isValidSpacingKey = (key: string): key is keyof SpacingScale => {
  return key in designTokens.spacing;
};

// Export commonly used values
export const commonColors = {
  primary: designTokens.colors.primary[500],
  secondary: designTokens.colors.secondary[500],
  accent: designTokens.colors.accent[500],
  success: designTokens.colors.semantic.success[500],
  warning: designTokens.colors.semantic.warning[500],
  error: designTokens.colors.semantic.error[500],
  info: designTokens.colors.semantic.info[500],
} as const;

export const commonSpacing = {
  xs: designTokens.spacing[1],
  sm: designTokens.spacing[2],
  md: designTokens.spacing[4],
  lg: designTokens.spacing[6],
  xl: designTokens.spacing[8],
} as const;

export const commonShadows = {
  sm: designTokens.shadows.sm,
  md: designTokens.shadows.md,
  lg: designTokens.shadows.lg,
} as const;