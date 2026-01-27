/**
 * Responsive Text Component
 * Text component that maintains readability across all screen sizes with comprehensive accessibility support
 */

import React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { getAccessibleTextClasses, getResponsiveTextSize, ensureMinimumTextSize } from '@/lib/accessibility-utils';
import { validateTextAccessibility, fixAccessibilityIssues } from '@/lib/accessibility-validation';
import { useBreakpoint } from '@/hooks/useBreakpoint';

const responsiveTextVariants = cva(
  // Enhanced base classes with comprehensive accessibility support
  [
    'font-sans',
    'leading-normal',
    'break-words',
    'select-text',
    // High contrast support
    'contrast-more:font-semibold',
    'contrast-more:text-black',
    'dark:contrast-more:text-white',
    // Responsive text sizing (16px on mobile prevents iOS zoom)
    'text-base', // 16px on mobile
    'md:text-sm', // 14px on desktop (minimum readable size)
  ],
  {
    variants: {
      variant: {
        default: 'text-foreground',
        muted: 'text-muted-foreground contrast-more:text-gray-800 dark:contrast-more:text-gray-200',
        accent: 'text-accent-foreground contrast-more:text-accent-900',
        destructive: 'text-destructive contrast-more:text-red-900',
        success: 'text-green-700 dark:text-green-400 contrast-more:text-green-900',
        warning: 'text-amber-700 dark:text-amber-400 contrast-more:text-amber-900',
      },
      size: {
        // Mobile-first sizing with desktop overrides (never smaller than 14px)
        xs: 'text-base md:text-sm', // 16px mobile, 14px desktop (never text-xs)
        sm: 'text-lg md:text-base', // 18px mobile, 16px desktop
        base: 'text-xl md:text-lg', // 20px mobile, 18px desktop
        lg: 'text-2xl md:text-xl', // 24px mobile, 20px desktop
        xl: 'text-3xl md:text-2xl', // 30px mobile, 24px desktop
        '2xl': 'text-4xl md:text-3xl', // 36px mobile, 30px desktop
        '3xl': 'text-5xl md:text-4xl', // 48px mobile, 36px desktop
        '4xl': 'text-6xl md:text-5xl', // 60px mobile, 48px desktop
      },
      weight: {
        light: 'font-light contrast-more:font-normal',
        normal: 'font-normal contrast-more:font-medium',
        medium: 'font-medium contrast-more:font-semibold',
        semibold: 'font-semibold contrast-more:font-bold',
        bold: 'font-bold contrast-more:font-black',
      },
      align: {
        left: 'text-left',
        center: 'text-center',
        right: 'text-right',
        justify: 'text-justify',
      },
      lineHeight: {
        tight: 'leading-tight',
        normal: 'leading-normal',
        relaxed: 'leading-relaxed',
        loose: 'leading-loose',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'base',
      weight: 'normal',
      align: 'left',
      lineHeight: 'normal',
    },
  }
);

export interface ResponsiveTextProps
  extends React.HTMLAttributes<HTMLElement>,
    VariantProps<typeof responsiveTextVariants> {
  as?: 'p' | 'span' | 'div' | 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'label';
  responsive?: boolean;
  minReadableSize?: boolean;
  highContrast?: boolean;
  validateAccessibility?: boolean;
  screenReaderText?: string;
  ariaLabel?: string;
  ariaDescribedBy?: string;
}

const ResponsiveText = React.forwardRef<HTMLElement, ResponsiveTextProps>(
  ({ 
    className, 
    variant, 
    size, 
    weight, 
    align, 
    lineHeight,
    as: Component = 'p',
    responsive = true,
    minReadableSize = true,
    highContrast = false,
    validateAccessibility = true,
    screenReaderText,
    ariaLabel,
    ariaDescribedBy,
    children,
    ...props 
  }, ref) => {
    const { current: breakpoint } = useBreakpoint();
    
    // Generate accessibility classes
    const accessibilityClasses = getAccessibleTextClasses({
      responsive,
      contrast: highContrast ? 'high' : 'normal',
    });
    
    // Generate base classes
    let textClasses = cn(
      responsiveTextVariants({ variant, size, weight, align, lineHeight }),
      accessibilityClasses,
      className
    );
    
    // Ensure minimum readable size if enabled
    if (minReadableSize) {
      textClasses = ensureMinimumTextSize(textClasses);
    }

    // Validate and fix accessibility issues if enabled
    if (validateAccessibility) {
      const validation = validateTextAccessibility(textClasses, breakpoint);
      if (validation.issues.length > 0) {
        console.warn(`Text accessibility issues detected:`, validation.issues);
        textClasses = fixAccessibilityIssues(textClasses, 'text');
      }
    }

    return (
      <Component
        ref={ref as any}
        className={textClasses}
        aria-label={ariaLabel}
        aria-describedby={ariaDescribedBy}
        role={Component === 'div' || Component === 'span' ? 'text' : undefined}
        {...props}
      >
        {children}
        {screenReaderText && (
          <span className="sr-only">{screenReaderText}</span>
        )}
      </Component>
    );
  }
);

ResponsiveText.displayName = 'ResponsiveText';

// Predefined text components for common use cases
export const Heading1 = React.forwardRef<HTMLHeadingElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="h1"
      size="4xl"
      weight="bold"
      lineHeight="tight"
      ref={ref as any}
    />
  )
);
Heading1.displayName = 'Heading1';

export const Heading2 = React.forwardRef<HTMLHeadingElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="h2"
      size="3xl"
      weight="semibold"
      lineHeight="tight"
      ref={ref as any}
    />
  )
);
Heading2.displayName = 'Heading2';

export const Heading3 = React.forwardRef<HTMLHeadingElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="h3"
      size="2xl"
      weight="semibold"
      lineHeight="tight"
      ref={ref as any}
    />
  )
);
Heading3.displayName = 'Heading3';

export const Heading4 = React.forwardRef<HTMLHeadingElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="h4"
      size="xl"
      weight="medium"
      lineHeight="normal"
      ref={ref as any}
    />
  )
);
Heading4.displayName = 'Heading4';

export const BodyText = React.forwardRef<HTMLParagraphElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="p"
      size="base"
      weight="normal"
      lineHeight="relaxed"
      ref={ref as any}
    />
  )
);
BodyText.displayName = 'BodyText';

export const SmallText = React.forwardRef<HTMLSpanElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="span"
      size="sm"
      weight="normal"
      lineHeight="normal"
      ref={ref as any}
    />
  )
);
SmallText.displayName = 'SmallText';

export const Label = React.forwardRef<HTMLLabelElement, Omit<ResponsiveTextProps, 'as'>>(
  (props, ref) => (
    <ResponsiveText
      {...props}
      as="label"
      size="sm"
      weight="medium"
      lineHeight="normal"
      ref={ref as any}
    />
  )
);
Label.displayName = 'Label';

export { ResponsiveText, responsiveTextVariants };