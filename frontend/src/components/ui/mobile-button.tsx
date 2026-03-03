/**
 * Mobile-Optimized Button Component
 * Button component optimized for touch interactions and mobile-first design
 */

import React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';
import { getTouchTargetClasses, getAccessibleTouchClasses } from '@/lib/touch-utils';

const mobileButtonVariants = cva(
  // Base classes with mobile-first approach
  [
    'inline-flex',
    'items-center',
    'justify-center',
    'whitespace-nowrap',
    'rounded-md',
    'text-sm',
    'font-medium',
    'ring-offset-background',
    'transition-colors',
    'focus-visible:outline-none',
    'focus-visible:ring-2',
    'focus-visible:ring-ring',
    'focus-visible:ring-offset-2',
    'disabled:pointer-events-none',
    'disabled:opacity-50',
    // Touch optimizations
    'touch-manipulation',
    'select-none',
    'min-h-[44px]', // Minimum touch target
    'min-w-[44px]',
    // Active state for touch feedback
    'active:scale-95',
    'motion-reduce:active:scale-100',
  ],
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-11 px-4 py-2 md:h-10',
        sm: 'h-9 rounded-md px-3 md:h-8 md:px-2.5',
        lg: 'h-12 rounded-md px-8 md:h-11',
        icon: 'h-11 w-11 md:h-10 md:w-10',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

export interface MobileButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof mobileButtonVariants> {
  asChild?: boolean;
  touchOptimized?: boolean;
  thumbFriendly?: boolean;
}

const MobileButton = React.forwardRef<HTMLButtonElement, MobileButtonProps>(
  ({ className, variant, size, asChild = false, touchOptimized = true, thumbFriendly = false, ...props }, ref) => {
    const Comp = asChild ? Slot : 'button';
    
    const touchClasses = touchOptimized ? getTouchTargetClasses() : '';
    const accessibilityClasses = getAccessibleTouchClasses();
    const thumbClasses = thumbFriendly ? 'mb-2 md:mb-0' : ''; // Extra margin for thumb reach
    
    return (
      <Comp
        className={cn(
          mobileButtonVariants({ variant, size, className }),
          touchClasses,
          accessibilityClasses,
          thumbClasses
        )}
        ref={ref}
        {...props}
      />
    );
  }
);

MobileButton.displayName = 'MobileButton';

export { MobileButton, mobileButtonVariants };