import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"

// Interactive Element Variants
const interactiveElementVariants = cva(
  "relative transition-all duration-300 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
  {
    variants: {
      variant: {
        default: "hover:bg-gray-50 active:bg-gray-100",
        subtle: "hover:bg-gray-25 active:bg-gray-50",
        elevated: "hover:shadow-md active:shadow-lg hover:-translate-y-0.5 active:translate-y-0",
        scale: "hover:scale-105 active:scale-95",
        glow: "hover:shadow-lg hover:shadow-blue-500/25 active:shadow-md",
        none: ""
      },
      feedback: {
        immediate: "active:scale-95 active:brightness-95",
        delayed: "active:scale-98 active:opacity-80",
        bounce: "active:animate-bounce",
        none: ""
      },
      loading: {
        true: "cursor-wait opacity-75 pointer-events-none",
        false: "cursor-pointer"
      },
      disabled: {
        true: "cursor-not-allowed opacity-50 pointer-events-none",
        false: ""
      }
    },
    defaultVariants: {
      variant: "default",
      feedback: "immediate",
      loading: false,
      disabled: false
    }
  }
)

// Loading States
const loadingVariants = cva(
  "absolute inset-0 flex items-center justify-center",
  {
    variants: {
      type: {
        spinner: "bg-white/80 backdrop-blur-sm",
        pulse: "bg-gradient-to-r from-transparent via-white/50 to-transparent animate-pulse",
        dots: "bg-white/80 backdrop-blur-sm"
      }
    },
    defaultVariants: {
      type: "spinner"
    }
  }
)

// Spinner Component
const Spinner: React.FC<{ size?: 'sm' | 'md' | 'lg' }> = ({ size = 'md' }) => {
  const sizeClasses = {
    sm: 'w-4 h-4',
    md: 'w-6 h-6', 
    lg: 'w-8 h-8'
  }
  
  return (
    <div className={cn("animate-spin rounded-full border-2 border-gray-300 border-t-blue-600", sizeClasses[size])} />
  )
}

// Dots Loader Component
const DotsLoader: React.FC = () => (
  <div className="flex space-x-1">
    {[0, 1, 2].map((i) => (
      <div
        key={i}
        className="w-2 h-2 bg-blue-600 rounded-full animate-bounce"
        style={{ animationDelay: `${i * 0.1}s` }}
      />
    ))}
  </div>
)

// Interactive Element Props Interface
export interface InteractiveElementProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof interactiveElementVariants> {
  children: React.ReactNode;
  loading?: boolean;
  loadingType?: 'spinner' | 'pulse' | 'dots';
  loadingText?: string;
  disabled?: boolean;
  onInteraction?: () => void;
  hoverDelay?: number;
  focusRing?: boolean;
}

// Interactive Element Component
const InteractiveElement = React.forwardRef<HTMLDivElement, InteractiveElementProps>(
  ({ 
    className, 
    variant, 
    feedback, 
    loading = false, 
    loadingType = 'spinner',
    loadingText,
    disabled = false,
    children, 
    onInteraction,
    hoverDelay = 0,
    focusRing = true,
    onClick,
    onMouseEnter,
    onMouseLeave,
    onFocus,
    onBlur,
    ...props 
  }, ref) => {
    const [isHovered, setIsHovered] = React.useState(false)
    const [isFocused, setIsFocused] = React.useState(false)
    const [isPressed, setIsPressed] = React.useState(false)
    const [showLoading, setShowLoading] = React.useState(loading)
    const hoverTimeoutRef = React.useRef<NodeJS.Timeout>()

    // Update loading state
    React.useEffect(() => {
      if (loading) {
        setShowLoading(true)
      } else {
        // Delay hiding loading to prevent flicker
        const timeout = setTimeout(() => setShowLoading(false), 150)
        return () => clearTimeout(timeout)
      }
    }, [loading])

    // Handle hover with delay
    const handleMouseEnter = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (disabled || loading) return
      
      if (hoverDelay > 0) {
        hoverTimeoutRef.current = setTimeout(() => {
          setIsHovered(true)
        }, hoverDelay)
      } else {
        setIsHovered(true)
      }
      
      onMouseEnter?.(e)
    }, [disabled, loading, hoverDelay, onMouseEnter])

    const handleMouseLeave = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (hoverTimeoutRef.current) {
        clearTimeout(hoverTimeoutRef.current)
      }
      setIsHovered(false)
      onMouseLeave?.(e)
    }, [onMouseLeave])

    // Handle focus
    const handleFocus = React.useCallback((e: React.FocusEvent<HTMLDivElement>) => {
      if (disabled || loading) return
      setIsFocused(true)
      onFocus?.(e)
    }, [disabled, loading, onFocus])

    const handleBlur = React.useCallback((e: React.FocusEvent<HTMLDivElement>) => {
      setIsFocused(false)
      onBlur?.(e)
    }, [onBlur])

    // Handle click with loading state
    const handleClick = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (disabled || loading) return
      
      setIsPressed(true)
      setTimeout(() => setIsPressed(false), 150)
      
      onInteraction?.()
      onClick?.(e)
    }, [disabled, loading, onInteraction, onClick])

    // Cleanup timeout on unmount
    React.useEffect(() => {
      return () => {
        if (hoverTimeoutRef.current) {
          clearTimeout(hoverTimeoutRef.current)
        }
      }
    }, [])

    return (
      <div
        ref={ref}
        className={cn(
          interactiveElementVariants({ 
            variant, 
            feedback, 
            loading: showLoading, 
            disabled 
          }),
          focusRing && isFocused && "ring-2 ring-blue-500 ring-offset-2",
          className
        )}
        style={{
          transition: `all ${designTokens.animations.duration.normal} ${designTokens.animations.easing.easeOut}`,
        }}
        tabIndex={disabled || loading ? -1 : 0}
        role="button"
        aria-disabled={disabled || loading}
        aria-busy={loading}
        onMouseEnter={handleMouseEnter}
        onMouseLeave={handleMouseLeave}
        onFocus={handleFocus}
        onBlur={handleBlur}
        onClick={handleClick}
        onKeyDown={(e) => {
          if ((e.key === 'Enter' || e.key === ' ') && !disabled && !loading) {
            e.preventDefault()
            handleClick(e as any)
          }
        }}
        {...props}
      >
        {/* Content */}
        <div className={cn(
          "relative transition-all duration-200",
          showLoading && "opacity-50"
        )}>
          {children}
        </div>

        {/* Loading Overlay */}
        {showLoading && (
          <div className={cn(loadingVariants({ type: loadingType }))}>
            {loadingType === 'spinner' && <Spinner />}
            {loadingType === 'dots' && <DotsLoader />}
            {loadingType === 'pulse' && (
              <div className="w-full h-full bg-gradient-to-r from-transparent via-blue-500/20 to-transparent animate-pulse" />
            )}
            {loadingText && (
              <span className="ml-2 text-sm text-gray-600">{loadingText}</span>
            )}
          </div>
        )}

        {/* Hover Indicator */}
        {isHovered && variant === 'glow' && (
          <div className="absolute inset-0 bg-gradient-to-r from-blue-500/10 to-purple-500/10 rounded-inherit pointer-events-none" />
        )}
      </div>
    )
  }
)

InteractiveElement.displayName = "InteractiveElement"

// Enhanced Button with Interactive Element
export interface EnhancedButtonProps extends InteractiveElementProps {
  variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'destructive';
  size?: 'sm' | 'md' | 'lg';
  fullWidth?: boolean;
}

const enhancedButtonVariants = cva(
  "inline-flex items-center justify-center font-medium rounded-md transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
  {
    variants: {
      variant: {
        primary: "bg-blue-600 text-white hover:bg-blue-700 focus-visible:ring-blue-500",
        secondary: "bg-gray-600 text-white hover:bg-gray-700 focus-visible:ring-gray-500",
        outline: "border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus-visible:ring-gray-500",
        ghost: "text-gray-700 hover:bg-gray-100 focus-visible:ring-gray-500",
        destructive: "bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500"
      },
      size: {
        sm: "px-3 py-1.5 text-sm",
        md: "px-4 py-2 text-sm",
        lg: "px-6 py-3 text-base"
      },
      fullWidth: {
        true: "w-full",
        false: ""
      }
    },
    defaultVariants: {
      variant: "primary",
      size: "md",
      fullWidth: false
    }
  }
)

const EnhancedButton = React.forwardRef<HTMLDivElement, EnhancedButtonProps>(
  ({ className, variant: buttonVariant = 'primary', size = 'md', fullWidth = false, children, ...props }, ref) => {
    return (
      <InteractiveElement
        ref={ref}
        className={cn(
          enhancedButtonVariants({ variant: buttonVariant, size, fullWidth }),
          className
        )}
        variant="elevated"
        feedback="immediate"
        {...props}
      >
        {children}
      </InteractiveElement>
    )
  }
)

EnhancedButton.displayName = "EnhancedButton"

// Hook for managing interactive states
export const useInteractiveState = () => {
  const [isHovered, setIsHovered] = React.useState(false)
  const [isFocused, setIsFocused] = React.useState(false)
  const [isPressed, setIsPressed] = React.useState(false)
  const [isLoading, setIsLoading] = React.useState(false)

  const handlers = React.useMemo(() => ({
    onMouseEnter: () => setIsHovered(true),
    onMouseLeave: () => setIsHovered(false),
    onFocus: () => setIsFocused(true),
    onBlur: () => setIsFocused(false),
    onMouseDown: () => setIsPressed(true),
    onMouseUp: () => setIsPressed(false),
  }), [])

  return {
    state: { isHovered, isFocused, isPressed, isLoading },
    handlers,
    setLoading: setIsLoading
  }
}

export {
  InteractiveElement,
  EnhancedButton,
  interactiveElementVariants,
  Spinner,
  DotsLoader
}