/**
 * Typography Component System
 * Accessible text components with responsive sizing and WCAG compliance
 */

import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import { validateTextAccessibility, fixAccessibilityIssues } from "@/lib/accessibility-validation"
import { useBreakpoint } from "@/hooks/useBreakpoint"

// Typography variant definitions with mobile-first responsive sizing
const typographyVariants = cva(
  // Base classes for all text elements
  [
    "break-words",
    "select-text",
    "font-sans",
    // High contrast support
    "contrast-more:font-semibold",
    "contrast-more:text-black",
    "dark:contrast-more:text-white",
    // Smooth text rendering
    "antialiased",
    "-webkit-font-smoothing-antialiased",
    "-moz-osx-font-smoothing-grayscale",
  ],
  {
    variants: {
      variant: {
        // Heading variants with proper hierarchy
        h1: [
          "scroll-m-20",
          "text-3xl", // 30px on mobile (above 16px minimum)
          "md:text-4xl", // 36px on desktop
          "lg:text-5xl", // 48px on large screens
          "font-extrabold",
          "tracking-tight",
          "leading-tight",
        ],
        h2: [
          "scroll-m-20",
          "text-2xl", // 24px on mobile
          "md:text-3xl", // 30px on desktop
          "lg:text-4xl", // 36px on large screens
          "font-bold",
          "tracking-tight",
          "leading-tight",
        ],
        h3: [
          "scroll-m-20",
          "text-xl", // 20px on mobile
          "md:text-2xl", // 24px on desktop
          "lg:text-3xl", // 30px on large screens
          "font-semibold",
          "tracking-tight",
          "leading-snug",
        ],
        h4: [
          "scroll-m-20",
          "text-lg", // 18px on mobile
          "md:text-xl", // 20px on desktop
          "lg:text-2xl", // 24px on large screens
          "font-semibold",
          "tracking-tight",
          "leading-snug",
        ],
        h5: [
          "scroll-m-20",
          "text-base", // 16px on mobile (meets minimum)
          "md:text-lg", // 18px on desktop
          "lg:text-xl", // 20px on large screens
          "font-medium",
          "tracking-tight",
          "leading-normal",
        ],
        h6: [
          "scroll-m-20",
          "text-base", // 16px on mobile (meets minimum)
          "md:text-base", // 16px on desktop
          "lg:text-lg", // 18px on large screens
          "font-medium",
          "tracking-tight",
          "leading-normal",
        ],
        // Body text variants
        body: [
          "text-base", // 16px on mobile (prevents iOS zoom)
          "md:text-sm", // 14px on desktop (meets minimum)
          "lg:text-base", // 16px on large screens
          "font-normal",
          "leading-relaxed",
        ],
        bodyLarge: [
          "text-lg", // 18px on mobile
          "md:text-base", // 16px on desktop
          "lg:text-lg", // 18px on large screens
          "font-normal",
          "leading-relaxed",
        ],
        bodySmall: [
          "text-sm", // 14px on mobile (meets minimum)
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-normal",
          "leading-normal",
        ],
        // Caption and label variants
        caption: [
          "text-sm", // 14px on mobile (meets minimum)
          "md:text-xs", // 12px on desktop (only for captions)
          "lg:text-sm", // 14px on large screens
          "font-normal",
          "leading-normal",
          "text-muted-foreground",
        ],
        label: [
          "text-sm", // 14px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-medium",
          "leading-none",
        ],
        // Interactive text variants
        link: [
          "text-base", // 16px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-base", // 16px on large screens
          "font-medium",
          "text-primary",
          "underline-offset-4",
          "hover:underline",
          "focus-visible:outline-none",
          "focus-visible:ring-2",
          "focus-visible:ring-ring",
          "focus-visible:ring-offset-2",
          "cursor-pointer",
          "touch-manipulation",
        ],
        // Code and monospace variants
        code: [
          "text-sm", // 14px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-mono",
          "font-normal",
          "bg-muted",
          "px-1.5",
          "py-0.5",
          "rounded",
        ],
        // Error and status variants
        error: [
          "text-sm", // 14px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-medium",
          "text-destructive",
          "leading-normal",
        ],
        success: [
          "text-sm", // 14px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-medium",
          "text-green-600",
          "dark:text-green-400",
          "leading-normal",
        ],
        warning: [
          "text-sm", // 14px on mobile
          "md:text-sm", // 14px on desktop
          "lg:text-sm", // 14px on large screens
          "font-medium",
          "text-yellow-600",
          "dark:text-yellow-400",
          "leading-normal",
        ],
      },
      // Color variants
      color: {
        default: "text-foreground",
        muted: "text-muted-foreground",
        primary: "text-primary",
        secondary: "text-secondary-foreground",
        accent: "text-accent-foreground",
        destructive: "text-destructive",
      },
      // Alignment variants
      align: {
        left: "text-left",
        center: "text-center",
        right: "text-right",
        justify: "text-justify",
      },
      // Truncation variants
      truncate: {
        none: "",
        truncate: "truncate",
        ellipsis: "text-ellipsis overflow-hidden",
        clip: "text-clip overflow-hidden",
      },
    },
    defaultVariants: {
      variant: "body",
      color: "default",
      align: "left",
      truncate: "none",
    },
  }
)

export interface TypographyProps
  extends React.HTMLAttributes<HTMLElement>,
    VariantProps<typeof typographyVariants> {
  as?: keyof JSX.IntrinsicElements
  validateAccessibility?: boolean
  screenReaderText?: string
  ariaLabel?: string
  ariaDescribedBy?: string
  maxLines?: number
}

const Typography = React.forwardRef<HTMLElement, TypographyProps>(
  ({ 
    className, 
    variant = "body", 
    color,
    align,
    truncate,
    as,
    validateAccessibility = true,
    screenReaderText,
    ariaLabel,
    ariaDescribedBy,
    maxLines,
    children,
    ...props 
  }, ref) => {
    const { current: breakpoint } = useBreakpoint()
    
    // Determine the HTML element based on variant or explicit 'as' prop
    const getElement = (): keyof JSX.IntrinsicElements => {
      if (as) return as
      
      switch (variant) {
        case 'h1': return 'h1'
        case 'h2': return 'h2'
        case 'h3': return 'h3'
        case 'h4': return 'h4'
        case 'h5': return 'h5'
        case 'h6': return 'h6'
        case 'link': return 'a'
        case 'code': return 'code'
        case 'label': return 'label'
        default: return 'p'
      }
    }

    const Element = getElement()

    // Generate base classes
    let textClasses = cn(
      typographyVariants({ variant, color, align, truncate }),
      maxLines && [
        "overflow-hidden",
        "display-webkit-box",
        "-webkit-box-orient-vertical",
        `-webkit-line-clamp-${maxLines}`,
      ],
      className
    )

    // Validate and fix accessibility issues if enabled
    if (validateAccessibility) {
      const validation = validateTextAccessibility(textClasses, breakpoint)
      if (validation.issues.length > 0) {
        console.warn(`Typography accessibility issues detected:`, validation.issues)
        textClasses = fixAccessibilityIssues(textClasses, 'text')
      }
    }

    return (
      <Element
        ref={ref as any}
        className={textClasses}
        aria-label={ariaLabel}
        aria-describedby={ariaDescribedBy}
        style={{
          WebkitLineClamp: maxLines,
          WebkitBoxOrient: 'vertical',
          display: maxLines ? '-webkit-box' : undefined,
        }}
        {...props}
      >
        {children}
        {screenReaderText && (
          <span className="sr-only">{screenReaderText}</span>
        )}
      </Element>
    )
  }
)
Typography.displayName = "Typography"

// Convenience components for common use cases
const Heading = React.forwardRef<HTMLHeadingElement, Omit<TypographyProps, 'variant'> & { level: 1 | 2 | 3 | 4 | 5 | 6 }>(
  ({ level, ...props }, ref) => (
    <Typography
      ref={ref as any}
      variant={`h${level}` as any}
      {...props}
    />
  )
)
Heading.displayName = "Heading"

const Text = React.forwardRef<HTMLParagraphElement, Omit<TypographyProps, 'variant'> & { size?: 'small' | 'default' | 'large' }>(
  ({ size = 'default', ...props }, ref) => {
    const variant = size === 'small' ? 'bodySmall' : size === 'large' ? 'bodyLarge' : 'body'
    return (
      <Typography
        ref={ref as any}
        variant={variant}
        {...props}
      />
    )
  }
)
Text.displayName = "Text"

const Link = React.forwardRef<HTMLAnchorElement, Omit<TypographyProps, 'variant'> & { href?: string; external?: boolean }>(
  ({ href, external, children, ...props }, ref) => (
    <Typography
      ref={ref as any}
      as="a"
      variant="link"
      href={href}
      target={external ? "_blank" : undefined}
      rel={external ? "noopener noreferrer" : undefined}
      {...props}
    >
      {children}
      {external && (
        <span className="sr-only"> (opens in new tab)</span>
      )}
    </Typography>
  )
)
Link.displayName = "Link"

const Code = React.forwardRef<HTMLElement, Omit<TypographyProps, 'variant'>>(
  (props, ref) => (
    <Typography
      ref={ref as any}
      variant="code"
      {...props}
    />
  )
)
Code.displayName = "Code"

const Label = React.forwardRef<HTMLLabelElement, Omit<TypographyProps, 'variant'> & { htmlFor?: string; required?: boolean }>(
  ({ required, children, ...props }, ref) => (
    <Typography
      ref={ref as any}
      variant="label"
      {...props}
    >
      {children}
      {required && (
        <span className="text-destructive ml-1" aria-label="required">*</span>
      )}
    </Typography>
  )
)
Label.displayName = "Label"

export { 
  Typography, 
  typographyVariants,
  Heading,
  Text,
  Link,
  Code,
  Label,
}