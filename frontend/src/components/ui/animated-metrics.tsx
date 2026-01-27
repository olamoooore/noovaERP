import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import { TrendingUp, TrendingDown, Minus } from "lucide-react"
import { Skeleton } from "./skeleton"

// Animated Metrics Variants
const animatedMetricsVariants = cva(
  "flex flex-col gap-1 transition-all duration-300 ease-out",
  {
    variants: {
      size: {
        sm: "text-sm",
        md: "text-base",
        lg: "text-lg"
      }
    },
    defaultVariants: {
      size: "md"
    }
  }
)

// Trend direction type
export type TrendDirection = 'up' | 'down' | 'neutral'

// Format type
export type MetricFormat = 'number' | 'currency' | 'percentage'

// Animated Metrics Props Interface
export interface AnimatedMetricsProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof animatedMetricsVariants> {
  value: number;
  previousValue?: number;
  format: MetricFormat;
  trend?: TrendDirection;
  label: string;
  icon?: React.ComponentType<{ className?: string }>;
  currency?: string;
  precision?: number;
  animationDuration?: number;
  loading?: boolean;
}

// Custom hook for number animation using spring physics
const useAnimatedNumber = (
  targetValue: number,
  duration: number = 1000,
  precision: number = 0
) => {
  const [currentValue, setCurrentValue] = React.useState(targetValue)
  const animationRef = React.useRef<number>()
  const startTimeRef = React.useRef<number>()
  const startValueRef = React.useRef(targetValue)

  React.useEffect(() => {
    if (startValueRef.current === targetValue) return

    startValueRef.current = currentValue
    startTimeRef.current = Date.now()

    const animate = () => {
      const now = Date.now()
      const elapsed = now - (startTimeRef.current || now)
      const progress = Math.min(elapsed / duration, 1)

      // Spring easing function for smooth animation
      const easeOutElastic = (t: number): number => {
        const c4 = (2 * Math.PI) / 3
        return t === 0
          ? 0
          : t === 1
          ? 1
          : Math.pow(2, -10 * t) * Math.sin((t * 10 - 0.75) * c4) + 1
      }

      const easedProgress = easeOutElastic(progress)
      const newValue = startValueRef.current + (targetValue - startValueRef.current) * easedProgress

      setCurrentValue(newValue)

      if (progress < 1) {
        animationRef.current = requestAnimationFrame(animate)
      }
    }

    animationRef.current = requestAnimationFrame(animate)

    return () => {
      if (animationRef.current) {
        cancelAnimationFrame(animationRef.current)
      }
    }
  }, [targetValue, duration])

  return Number(currentValue.toFixed(precision))
}

// Format number based on type
const formatNumber = (
  value: number,
  format: MetricFormat,
  currency: string = 'USD',
  precision: number = 0
): string => {
  switch (format) {
    case 'currency':
      return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        minimumFractionDigits: precision,
        maximumFractionDigits: precision,
      }).format(value)
    
    case 'percentage':
      return new Intl.NumberFormat('en-US', {
        style: 'percent',
        minimumFractionDigits: precision,
        maximumFractionDigits: precision,
      }).format(value / 100)
    
    case 'number':
    default:
      return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision,
      }).format(value)
  }
}

// Get trend direction from values
const getTrendDirection = (current: number, previous?: number): TrendDirection => {
  if (!previous) return 'neutral'
  
  // Use a practical tolerance for trend detection in UI contexts
  // For UI purposes, differences smaller than this are not meaningful
  const tolerance = 1e-10
  const difference = current - previous
  
  if (Math.abs(difference) < tolerance) return 'neutral'
  return difference > 0 ? 'up' : 'down'
}

// Get trend color
const getTrendColor = (trend: TrendDirection): string => {
  switch (trend) {
    case 'up':
      return designTokens.colors.semantic.success[600]
    case 'down':
      return designTokens.colors.semantic.error[600]
    case 'neutral':
    default:
      return designTokens.colors.neutral[500]
  }
}

// Trend Icon Component
const TrendIcon: React.FC<{ trend: TrendDirection; className?: string }> = ({ trend, className }) => {
  const iconProps = { className: cn("w-4 h-4", className) }
  
  switch (trend) {
    case 'up':
      return <TrendingUp {...iconProps} />
    case 'down':
      return <TrendingDown {...iconProps} />
    case 'neutral':
    default:
      return <Minus {...iconProps} />
  }
}

// Animated Metrics Component
const AnimatedMetrics = React.forwardRef<HTMLDivElement, AnimatedMetricsProps>(
  ({ 
    className, 
    size, 
    value, 
    previousValue, 
    format, 
    trend: explicitTrend, 
    label, 
    icon: Icon, 
    currency = 'USD',
    precision = 0,
    animationDuration = 1000,
    loading = false,
    ...props 
  }, ref) => {
    const animatedValue = useAnimatedNumber(value, animationDuration, precision)
    const actualTrend = explicitTrend || getTrendDirection(value, previousValue)
    const trendColor = getTrendColor(actualTrend)
    
    // Calculate percentage change
    const percentageChange = previousValue && previousValue !== 0 
      ? ((value - previousValue) / Math.abs(previousValue)) * 100 
      : 0

    if (loading) {
      return (
        <div
          ref={ref}
          className={cn(animatedMetricsVariants({ size }), className)}
          {...props}
        >
          {/* Label skeleton */}
          <div className="flex items-center gap-2">
            <Skeleton variant="shimmer" className="w-4 h-4 rounded" />
            <Skeleton variant="shimmer" className="h-4 w-20" />
          </div>

          {/* Value and trend skeleton */}
          <div className="flex items-end gap-3">
            <Skeleton 
              variant="shimmer" 
              className={cn(
                "rounded",
                size === "sm" ? "h-6 w-16" :
                size === "md" ? "h-8 w-20" :
                "h-10 w-24"
              )}
            />
            <div className="flex items-center gap-1">
              <Skeleton variant="shimmer" className="w-3 h-3 rounded" />
              <Skeleton variant="shimmer" className="h-3 w-8" />
            </div>
          </div>

          {/* Previous value skeleton */}
          <Skeleton variant="shimmer" className="h-3 w-24" />
        </div>
      )
    }

    return (
      <div
        ref={ref}
        className={cn(animatedMetricsVariants({ size }), className)}
        {...props}
      >
        {/* Label */}
        <div className="flex items-center gap-2 text-gray-600">
          {Icon && (
            <Icon 
              className={cn(
                "flex-shrink-0",
                size === "sm" ? "w-4 h-4" : 
                size === "md" ? "w-5 h-5" :
                "w-6 h-6"
              )}
            />
          )}
          <span 
            className="text-sm font-medium"
            style={{ 
              fontSize: size === "sm" ? designTokens.typography.fontSize.xs : 
                       size === "md" ? designTokens.typography.fontSize.sm :
                       designTokens.typography.fontSize.base
            }}
          >
            {label}
          </span>
        </div>

        {/* Value and Trend */}
        <div className="flex items-end gap-3">
          {/* Animated Value */}
          <span 
            className={cn(
              "font-bold text-gray-900 tabular-nums",
              size === "sm" ? "text-xl" :
              size === "md" ? "text-2xl" :
              "text-3xl"
            )}
            style={{
              fontSize: size === "sm" ? designTokens.typography.fontSize.xl :
                       size === "md" ? designTokens.typography.fontSize['2xl'] :
                       designTokens.typography.fontSize['3xl'],
              fontWeight: designTokens.typography.fontWeight.bold,
              transition: `all ${designTokens.animations.duration.normal} ${designTokens.animations.easing.easeOut}`
            }}
          >
            {formatNumber(animatedValue, format, currency, precision)}
          </span>

          {/* Trend Indicator */}
          {previousValue !== undefined && actualTrend !== 'neutral' && (
            <div 
              className="flex items-center gap-1 text-sm font-medium"
              style={{ color: trendColor }}
            >
              <TrendIcon trend={actualTrend} className="w-3 h-3" />
              <span className="tabular-nums">
                {Math.abs(percentageChange).toFixed(1)}%
              </span>
            </div>
          )}
        </div>

        {/* Previous Value Comparison */}
        {previousValue !== undefined && (
          <div className="text-xs text-gray-500">
            Previous: {formatNumber(previousValue, format, currency, precision)}
          </div>
        )}
      </div>
    )
  }
)

AnimatedMetrics.displayName = "AnimatedMetrics"

// Predefined Metric Components

// Currency Metric
export const CurrencyMetric: React.FC<Omit<AnimatedMetricsProps, 'format'>> = (props) => (
  <AnimatedMetrics format="currency" precision={2} {...props} />
)

// Percentage Metric
export const PercentageMetric: React.FC<Omit<AnimatedMetricsProps, 'format'>> = (props) => (
  <AnimatedMetrics format="percentage" precision={1} {...props} />
)

// Number Metric
export const NumberMetric: React.FC<Omit<AnimatedMetricsProps, 'format'>> = (props) => (
  <AnimatedMetrics format="number" {...props} />
)

// Utility function to create metric data
export const createMetricData = (
  value: number,
  previousValue?: number,
  label: string = '',
  format: MetricFormat = 'number'
) => ({
  value,
  previousValue,
  label,
  format,
  trend: getTrendDirection(value, previousValue)
})

export {
  AnimatedMetrics,
  animatedMetricsVariants,
  formatNumber,
  getTrendDirection,
  getTrendColor
}