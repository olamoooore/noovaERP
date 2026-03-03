/**
 * Mobile-Optimized Input Component
 * Input component optimized for touch interactions and mobile keyboards
 */

import React from 'react';
import { cn } from '@/lib/utils';
import { getMobileInputClasses, getTouchTargetClasses } from '@/lib/touch-utils';

export interface MobileInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  touchOptimized?: boolean;
  preventZoom?: boolean; // Prevent iOS zoom on focus
}

const MobileInput = React.forwardRef<HTMLInputElement, MobileInputProps>(
  ({ className, type, touchOptimized = true, preventZoom = true, ...props }, ref) => {
    const mobileClasses = getMobileInputClasses();
    const touchClasses = touchOptimized ? getTouchTargetClasses({ minSize: 44 }) : '';
    
    // Prevent iOS zoom by using 16px font size on mobile
    const preventZoomClasses = preventZoom ? 'text-base md:text-sm' : '';
    
    return (
      <input
        type={type}
        className={cn(
          mobileClasses,
          touchClasses,
          preventZoomClasses,
          className
        )}
        ref={ref}
        {...props}
      />
    );
  }
);

MobileInput.displayName = 'MobileInput';

export { MobileInput };