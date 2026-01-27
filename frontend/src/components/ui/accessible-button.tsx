/**
 * Accessible Button Component
 * Enhanced button with proper ARIA attributes and semantic HTML
 */

import React, { forwardRef } from 'react'
import { Slot } from '@radix-ui/react-slot'
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'
import { getAccessibleButtonStyles } from '@/lib/wcag-compliance'
import { generateAriaAttributes } from '@/lib/semantic-html'
import { LoadingSpinner } from './skeleton'

const buttonVariants = cva(
  [
    'inline-flex items-center justify-center whitespace-nowrap rounded-md font-medium',
    'ring-offset-background transition-all duration-200',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
    'disabled:pointer-events-none disabled:opacity-50',
    // Accessibility enhancements
    'touch-manipulation select-none',
    'min-h-[44px] min-w-[44px]', // WCAG AA touch target
    'active:scale-95 motion-reduce:active:scale-100',
    'motion-reduce:transition-none',
    'contrast-more:border-2 contrast-more:border-current contrast-more:font-bold',
    // Responsive text sizing
    'text-base md:text-sm', // 16px on mobile prevents zoom
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline min-h-auto min-w-auto',
      },
      size: {
        default: 'h-12 px-4 py-3 md:h-10 md:py-2', // Larger on mobile
        sm: 'h-11 px-3 py-2.5 md:h-9 md:px-2.5 md:py-2',
        lg: 'h-14 px-6 py-4 md:h-11 md:py-2.5',
        icon: 'h-12 w-12 md:h-10 md:w-10', // Square buttons
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
)

export interface AccessibleButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean
  loading?: boolean
  loadingText?: string
  icon?: React.ComponentType<{ className?: string }>
  iconPosition?: 'left' | 'right'
  ariaLabel?: string
  ariaDescribedBy?: string
  ariaControls?: string
  ariaExpanded?: boolean
  ariaPressed?: boolean | 'mixed'
  screenReaderText?: string
  tooltip?: string
}

const AccessibleButton = forwardRef<HTMLButtonElement, AccessibleButtonProps>(
  (
    {
      className,
      variant,
      size,
      asChild = false,
      loading = false,
      loadingText,
      icon: Icon,
      iconPosition = 'left',
      ariaLabel,
      ariaDescribedBy,
      ariaControls,
      ariaExpanded,
      ariaPressed,
      screenReaderText,
      tooltip,
      disabled,
      children,
      ...props
    },
    ref
  ) => {
    const Comp = asChild ? Slot : 'button'
    
    // Generate ARIA attributes
    const ariaAttributes = generateAriaAttributes('button', {
      label: ariaLabel,
      describedBy: ariaDescribedBy,
      controls: ariaControls,
      expanded: ariaExpanded,
      pressed: ariaPressed,
      disabled: disabled || loading,
    })

    // Determine button content
    const hasIcon = Icon && !loading
    const hasText = children || loadingText
    const isIconOnly = hasIcon && !hasText && size === 'icon'

    // Generate accessible name
    const accessibleName = ariaLabel || 
                          (typeof children === 'string' ? children : undefined) ||
                          (isIconOnly ? 'Button' : undefined)

    return (
      <Comp
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        disabled={disabled || loading}
        aria-label={accessibleName}
        aria-describedby={ariaDescribedBy}
        aria-controls={ariaControls}
        aria-expanded={ariaExpanded}
        aria-pressed={ariaPressed}
        aria-busy={loading}
        title={tooltip}
        {...props}
      >
        {/* Loading state */}
        {loading && (
          <>
            <LoadingSpinner 
              size="sm" 
              className="mr-2" 
              aria-hidden="true"
            />
            <span className="sr-only">Loading</span>
          </>
        )}

        {/* Icon - left position */}
        {hasIcon && iconPosition === 'left' && !loading && (
          <Icon 
            className={cn(
              'h-4 w-4',
              hasText && 'mr-2'
            )}
            aria-hidden="true"
          />
        )}

        {/* Button text */}
        {loading ? loadingText : children}

        {/* Icon - right position */}
        {hasIcon && iconPosition === 'right' && !loading && (
          <Icon 
            className={cn(
              'h-4 w-4',
              hasText && 'ml-2'
            )}
            aria-hidden="true"
          />
        )}

        {/* Screen reader only text */}
        {screenReaderText && (
          <span className="sr-only">{screenReaderText}</span>
        )}
      </Comp>
    )
  }
)

AccessibleButton.displayName = 'AccessibleButton'

export { AccessibleButton, buttonVariants }