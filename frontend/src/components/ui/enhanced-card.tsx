import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import { MetricCardSkeleton } from "./skeleton"
import { InteractiveElement } from "./interactive-element"

// Enhanced Card Variants
const enhancedCardVariants = cva(
  "relative rounded-lg transition-all duration-300 ease-out overflow-hidden",
  {
    variants: {
      variant: {
        default: "bg-white border border-gray-200 shadow-sm hover:shadow-md hover:border-gray-300",
        elevated: "bg-white shadow-lg hover:shadow-xl border-0 hover:-translate-y-1",
        outlined: "bg-transparent border-2 border-gray-300 hover:border-gray-400 shadow-none hover:shadow-sm",
        glass: "bg-white/80 backdrop-blur-sm border border-white/20 shadow-lg hover:shadow-xl hover:bg-white/90"
      },
      size: {
        sm: "p-4",
        md: "p-6", 
        lg: "p-8",
        xl: "p-10"
      },
      interactive: {
        true: "cursor-pointer transform hover:scale-[1.02] active:scale-[0.98] transition-transform duration-200",
        false: ""
      }
    },
    defaultVariants: {
      variant: "default",
      size: "md",
      interactive: false
    }
  }
)

// Skeleton Loading Component
const CardSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("animate-pulse", className)}>
    <div className="h-4 bg-gray-200 rounded mb-3"></div>
    <div className="h-3 bg-gray-200 rounded mb-2"></div>
    <div className="h-3 bg-gray-200 rounded w-3/4"></div>
  </div>
)

// Enhanced Card Props Interface
export interface EnhancedCardProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedCardVariants> {
  loading?: boolean;
  loadingType?: 'default' | 'metric' | 'chart' | 'table';
  children?: React.ReactNode;
  onCardClick?: () => void;
  hoverEffect?: boolean;
}

// Enhanced Card Component
const EnhancedCard = React.forwardRef<HTMLDivElement, EnhancedCardProps>(
  ({ 
    className, 
    variant, 
    size, 
    interactive, 
    loading, 
    loadingType = 'default', 
    children, 
    onCardClick,
    hoverEffect = true,
    onClick,
    ...props 
  }, ref) => {
    const [isHovered, setIsHovered] = React.useState(false)
    const [isPressed, setIsPressed] = React.useState(false)

    const handleClick = React.useCallback((e: React.MouseEvent<HTMLDivElement>) => {
      if (loading) return
      
      setIsPressed(true)
      setTimeout(() => setIsPressed(false), 150)
      
      onCardClick?.()
      onClick?.(e)
    }, [loading, onCardClick, onClick])

    if (loading) {
      const SkeletonComponent = loadingType === 'metric' ? MetricCardSkeleton : CardSkeleton
      
      return (
        <div
          ref={ref}
          className={cn(enhancedCardVariants({ variant, size, interactive: false }), className)}
          {...props}
        >
          <SkeletonComponent />
        </div>
      )
    }

    const cardContent = (
      <>
        {/* Hover overlay for glass variant */}
        {variant === "glass" && hoverEffect && (
          <div 
            className={cn(
              "absolute inset-0 bg-gradient-to-br from-white/10 to-transparent opacity-0 transition-opacity duration-300",
              isHovered && "opacity-100"
            )}
          />
        )}
        
        {/* Interactive glow effect */}
        {interactive && hoverEffect && isHovered && (
          <div className="absolute inset-0 bg-gradient-to-r from-blue-500/5 to-purple-500/5 rounded-inherit pointer-events-none" />
        )}
        
        {/* Content */}
        <div className={cn(
          "relative z-10 transition-all duration-200",
          isPressed && "transform scale-[0.98]"
        )}>
          {children}
        </div>
      </>
    )

    if (interactive || onCardClick) {
      return (
        <InteractiveElement
          ref={ref}
          className={cn(enhancedCardVariants({ variant, size, interactive }), className)}
          variant={variant === "elevated" ? "elevated" : "subtle"}
          feedback="immediate"
          onInteraction={() => handleClick({} as React.MouseEvent<HTMLDivElement>)}
          {...props}
        >
          {cardContent}
        </InteractiveElement>
      )
    }

    return (
      <div
        ref={ref}
        className={cn(enhancedCardVariants({ variant, size, interactive }), className)}
        onMouseEnter={() => hoverEffect && setIsHovered(true)}
        onMouseLeave={() => hoverEffect && setIsHovered(false)}
        style={{
          transition: `all ${designTokens.animations.duration.normal} ${designTokens.animations.easing.easeOut}`,
        }}
        {...props}
      >
        {cardContent}
      </div>
    )
  }
)

EnhancedCard.displayName = "EnhancedCard"

// Enhanced Card Header
const EnhancedCardHeader = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn("flex flex-col space-y-1.5 mb-4", className)}
    {...props}
  />
))
EnhancedCardHeader.displayName = "EnhancedCardHeader"

// Enhanced Card Title
const EnhancedCardTitle = React.forwardRef<
  HTMLHeadingElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className, ...props }, ref) => (
  <h3
    ref={ref}
    className={cn(
      "text-xl font-semibold leading-none tracking-tight text-gray-900",
      className
    )}
    style={{
      fontSize: designTokens.typography.fontSize.xl,
      fontWeight: designTokens.typography.fontWeight.semibold,
    }}
    {...props}
  />
))
EnhancedCardTitle.displayName = "EnhancedCardTitle"

// Enhanced Card Description
const EnhancedCardDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("text-sm text-gray-600", className)}
    style={{
      fontSize: designTokens.typography.fontSize.sm,
      color: designTokens.colors.neutral[600],
    }}
    {...props}
  />
))
EnhancedCardDescription.displayName = "EnhancedCardDescription"

// Enhanced Card Content
const EnhancedCardContent = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div ref={ref} className={cn("", className)} {...props} />
))
EnhancedCardContent.displayName = "EnhancedCardContent"

// Enhanced Card Footer
const EnhancedCardFooter = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn("flex items-center mt-4 pt-4 border-t border-gray-100", className)}
    {...props}
  />
))
EnhancedCardFooter.displayName = "EnhancedCardFooter"

export {
  EnhancedCard,
  EnhancedCardHeader,
  EnhancedCardFooter,
  EnhancedCardTitle,
  EnhancedCardDescription,
  EnhancedCardContent,
  enhancedCardVariants
}