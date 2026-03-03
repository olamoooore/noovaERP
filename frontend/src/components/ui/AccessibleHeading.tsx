/**
 * Accessible Heading Component
 * Semantic heading component with proper hierarchy and ARIA support
 */

import React, { forwardRef } from 'react'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

const headingVariants = cva(
  [
    'font-sans font-semibold tracking-tight',
    'break-words select-text',
    'contrast-more:font-bold contrast-more:text-black dark:contrast-more:text-white',
    // Responsive text sizing for accessibility
    'text-base md:text-sm', // Base responsive sizing
  ],
  {
    variants: {
      level: {
        1: 'text-3xl md:text-4xl lg:text-5xl', // h1 - largest
        2: 'text-2xl md:text-3xl lg:text-4xl', // h2
        3: 'text-xl md:text-2xl lg:text-3xl',  // h3
        4: 'text-lg md:text-xl lg:text-2xl',   // h4
        5: 'text-base md:text-lg lg:text-xl',  // h5
        6: 'text-base md:text-base lg:text-lg', // h6 - smallest
      },
      variant: {
        default: 'text-foreground',
        muted: 'text-muted-foreground',
        destructive: 'text-destructive',
        success: 'text-green-600 dark:text-green-400',
        warning: 'text-amber-600 dark:text-amber-400',
        info: 'text-blue-600 dark:text-blue-400',
      },
      align: {
        left: 'text-left',
        center: 'text-center',
        right: 'text-right',
      },
      spacing: {
        tight: 'mb-2',
        normal: 'mb-4',
        loose: 'mb-6',
        none: '',
      },
    },
    defaultVariants: {
      level: 1,
      variant: 'default',
      align: 'left',
      spacing: 'normal',
    },
  }
)

export interface AccessibleHeadingProps
  extends Omit<React.HTMLAttributes<HTMLHeadingElement>, 'color'>,
    VariantProps<typeof headingVariants> {
  level: 1 | 2 | 3 | 4 | 5 | 6
  as?: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6'
  visualLevel?: 1 | 2 | 3 | 4 | 5 | 6
  ariaLabel?: string
  ariaDescribedBy?: string
  id?: string
  children: React.ReactNode
}

const AccessibleHeading = forwardRef<HTMLHeadingElement, AccessibleHeadingProps>(
  (
    {
      className,
      level,
      as,
      visualLevel,
      variant,
      align,
      spacing,
      ariaLabel,
      ariaDescribedBy,
      id,
      children,
      ...props
    },
    ref
  ) => {
    // Determine the actual HTML element to render
    const Component = (as || `h${level}`) as React.ElementType
    
    // Use visual level for styling if provided, otherwise use semantic level
    const styleLevel = visualLevel || level
    
    // Generate unique ID if not provided
    const headingId = id || `heading-${Math.random().toString(36).substr(2, 9)}`
    
    // Validate heading hierarchy (in development)
    if (process.env.NODE_ENV === 'development') {
      // Check if heading text is meaningful
      const textContent = typeof children === 'string' ? children : ''
      if (!textContent.trim()) {
        console.warn(`Heading ${Component} is empty or has no meaningful text content`)
      }
      
      // Warn about potential hierarchy issues
      if (level > 1) {
        const hasH1 = document.querySelector('h1')
        
        if (!hasH1 && level > 1) {
          console.warn(`Using h${level} without an h1 on the page may create accessibility issues`)
        }
      }
    }

    return (
      <Component
        ref={ref}
        id={headingId}
        className={cn(
          headingVariants({ 
            level: styleLevel, 
            variant, 
            align, 
            spacing 
          }),
          className
        )}
        aria-label={ariaLabel}
        aria-describedby={ariaDescribedBy}
        aria-level={level} // Semantic level for screen readers
        {...props}
      >
        {children}
      </Component>
    )
  }
)

AccessibleHeading.displayName = 'AccessibleHeading'

// Convenience components for each heading level
export const H1 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={1} {...props} />
)
H1.displayName = 'H1'

export const H2 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={2} {...props} />
)
H2.displayName = 'H2'

export const H3 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={3} {...props} />
)
H3.displayName = 'H3'

export const H4 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={4} {...props} />
)
H4.displayName = 'H4'

export const H5 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={5} {...props} />
)
H5.displayName = 'H5'

export const H6 = forwardRef<HTMLHeadingElement, Omit<AccessibleHeadingProps, 'level'>>(
  (props, ref) => <AccessibleHeading ref={ref} level={6} {...props} />
)
H6.displayName = 'H6'

export { AccessibleHeading, headingVariants }