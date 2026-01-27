/**
 * Skeleton Loading Components
 * Comprehensive skeleton screens for loading states with smooth transitions
 */

import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"

// Base Skeleton Variants
const skeletonVariants = cva(
  "animate-pulse bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 bg-[length:200%_100%] rounded",
  {
    variants: {
      variant: {
        default: "bg-gray-200",
        shimmer: "bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 bg-[length:200%_100%] animate-shimmer",
        pulse: "animate-pulse bg-gray-200",
        wave: "bg-gradient-to-r from-gray-200 via-gray-100 to-gray-200 bg-[length:200%_100%] animate-wave"
      },
      size: {
        xs: "h-3",
        sm: "h-4", 
        md: "h-5",
        lg: "h-6",
        xl: "h-8"
      }
    },
    defaultVariants: {
      variant: "shimmer",
      size: "md"
    }
  }
)

// Base Skeleton Props Interface
export interface SkeletonProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof skeletonVariants> {
  width?: string | number;
  height?: string | number;
}

// Base Skeleton Component
const Skeleton = React.forwardRef<HTMLDivElement, SkeletonProps>(
  ({ className, variant, size, width, height, style, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn(skeletonVariants({ variant, size }), className)}
        style={{
          width,
          height,
          ...style
        }}
        {...props}
      />
    )
  }
)
Skeleton.displayName = "Skeleton"

// Metric Card Skeleton
export const MetricCardSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("space-y-3", className)}>
    {/* Header with icon and title */}
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-2">
        <Skeleton variant="shimmer" className="w-4 h-4 rounded" />
        <Skeleton variant="shimmer" className="h-4 w-20" />
      </div>
      <Skeleton variant="shimmer" className="w-4 h-4 rounded" />
    </div>
    
    {/* Main metric value */}
    <div className="space-y-2">
      <Skeleton variant="shimmer" className="h-8 w-24" />
      <div className="flex items-center gap-2">
        <Skeleton variant="shimmer" className="w-3 h-3 rounded" />
        <Skeleton variant="shimmer" className="h-3 w-12" />
      </div>
    </div>
    
    {/* Subtitle */}
    <Skeleton variant="shimmer" className="h-3 w-16" />
  </div>
)

// Chart Skeleton
export const ChartSkeleton: React.FC<{ 
  height?: number; 
  className?: string;
  type?: 'bar' | 'line' | 'area' | 'pie';
}> = ({ height = 300, className, type = 'bar' }) => {
  const renderChartSkeleton = () => {
    switch (type) {
      case 'pie':
        return (
          <div className="flex items-center justify-center">
            <Skeleton variant="shimmer" className="w-32 h-32 rounded-full" />
          </div>
        )
      
      case 'line':
      case 'area':
        return (
          <div className="space-y-4">
            {/* Chart area with curved line simulation */}
            <div className="relative">
              <Skeleton variant="shimmer" className="w-full h-48 rounded-lg" />
              <div className="absolute inset-4 flex items-end justify-between">
                {Array.from({ length: 6 }).map((_, i) => (
                  <Skeleton 
                    key={i} 
                    variant="shimmer" 
                    className="w-2 rounded-t"
                    height={Math.random() * 100 + 50}
                  />
                ))}
              </div>
            </div>
            {/* X-axis labels */}
            <div className="flex justify-between px-4">
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} variant="shimmer" className="h-3 w-8" />
              ))}
            </div>
          </div>
        )
      
      case 'bar':
      default:
        return (
          <div className="space-y-4">
            {/* Chart bars */}
            <div className="flex items-end justify-between gap-2 px-4" style={{ height: height - 60 }}>
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="flex-1 flex flex-col gap-1">
                  <Skeleton 
                    variant="shimmer" 
                    className="w-full rounded-t"
                    height={Math.random() * (height - 100) + 50}
                  />
                  <Skeleton 
                    variant="shimmer" 
                    className="w-full rounded-t"
                    height={Math.random() * (height - 100) + 30}
                  />
                </div>
              ))}
            </div>
            {/* X-axis labels */}
            <div className="flex justify-between px-4">
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} variant="shimmer" className="h-3 w-8" />
              ))}
            </div>
          </div>
        )
    }
  }

  return (
    <div 
      className={cn("bg-gray-50 rounded-lg p-4", className)}
      style={{ height }}
    >
      {renderChartSkeleton()}
    </div>
  )
}

// Table Skeleton
export const TableSkeleton: React.FC<{ 
  rows?: number; 
  columns?: number; 
  className?: string;
}> = ({ rows = 5, columns = 5, className }) => (
  <div className={cn("space-y-3", className)}>
    {/* Table header */}
    <div className="flex gap-4 pb-2 border-b border-gray-200">
      {Array.from({ length: columns }).map((_, i) => (
        <Skeleton 
          key={i} 
          variant="shimmer" 
          className="h-4 flex-1" 
          width={i === 0 ? "20%" : i === columns - 1 ? "15%" : "auto"}
        />
      ))}
    </div>
    
    {/* Table rows */}
    {Array.from({ length: rows }).map((_, rowIndex) => (
      <div key={rowIndex} className="flex gap-4 py-2">
        {Array.from({ length: columns }).map((_, colIndex) => (
          <Skeleton 
            key={colIndex} 
            variant="shimmer" 
            className="h-4 flex-1"
            width={
              colIndex === 0 ? "20%" : 
              colIndex === columns - 1 ? "15%" : 
              Math.random() > 0.5 ? "80%" : "60%"
            }
          />
        ))}
      </div>
    ))}
  </div>
)

// Dashboard Layout Skeleton
export const DashboardSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("space-y-6", className)}>
    {/* Header */}
    <div className="flex items-center justify-between">
      <Skeleton variant="shimmer" className="h-8 w-32" />
      <Skeleton variant="shimmer" className="h-8 w-24" />
    </div>

    {/* KPI Cards Grid */}
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
          <MetricCardSkeleton />
        </div>
      ))}
    </div>

    {/* Charts Section */}
    <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
      {/* Large chart */}
      <div className="lg:col-span-2 p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <Skeleton variant="shimmer" className="w-5 h-5 rounded" />
                <Skeleton variant="shimmer" className="h-6 w-32" />
              </div>
              <Skeleton variant="shimmer" className="h-4 w-48" />
            </div>
            <Skeleton variant="shimmer" className="h-8 w-20" />
          </div>
          <ChartSkeleton height={300} type="bar" />
        </div>
      </div>

      {/* Pie chart */}
      <div className="p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <Skeleton variant="shimmer" className="w-5 h-5 rounded" />
                <Skeleton variant="shimmer" className="h-6 w-28" />
              </div>
              <Skeleton variant="shimmer" className="h-4 w-32" />
            </div>
            <Skeleton variant="shimmer" className="h-8 w-20" />
          </div>
          <ChartSkeleton height={300} type="pie" />
        </div>
      </div>
    </div>

    {/* Full width chart */}
    <div className="p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <Skeleton variant="shimmer" className="w-5 h-5 rounded" />
              <Skeleton variant="shimmer" className="h-6 w-36" />
            </div>
            <Skeleton variant="shimmer" className="h-4 w-52" />
          </div>
          <Skeleton variant="shimmer" className="h-8 w-20" />
        </div>
        <ChartSkeleton height={250} type="area" />
      </div>
    </div>

    {/* Table section */}
    <div className="p-6 bg-white border border-gray-200 rounded-lg shadow-sm">
      <div className="space-y-4">
        <Skeleton variant="shimmer" className="h-6 w-32" />
        <TableSkeleton rows={5} columns={5} />
      </div>
    </div>
  </div>
)

// Progress Indicator Component
export const ProgressIndicator: React.FC<{
  progress: number;
  label?: string;
  showPercentage?: boolean;
  className?: string;
  size?: 'sm' | 'md' | 'lg';
}> = ({ 
  progress, 
  label, 
  showPercentage = true, 
  className,
  size = 'md'
}) => {
  const clampedProgress = Math.max(0, Math.min(100, progress))
  
  const sizeClasses = {
    sm: 'h-1',
    md: 'h-2', 
    lg: 'h-3'
  }

  return (
    <div className={cn("space-y-2", className)}>
      {(label || showPercentage) && (
        <div className="flex items-center justify-between text-sm">
          {label && <span className="text-gray-600">{label}</span>}
          {showPercentage && (
            <span className="text-gray-900 font-medium">
              {clampedProgress.toFixed(0)}%
            </span>
          )}
        </div>
      )}
      
      <div className={cn("w-full bg-gray-200 rounded-full overflow-hidden", sizeClasses[size])}>
        <div
          className="h-full bg-gradient-to-r from-blue-500 to-blue-600 transition-all duration-500 ease-out rounded-full"
          style={{ 
            width: `${clampedProgress}%`,
            background: `linear-gradient(90deg, ${designTokens.colors.primary[500]}, ${designTokens.colors.primary[600]})`
          }}
        />
      </div>
    </div>
  )
}

// Loading Spinner Component
export const LoadingSpinner: React.FC<{
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  label?: string;
}> = ({ size = 'md', className, label }) => {
  const sizeClasses = {
    sm: 'w-4 h-4',
    md: 'w-6 h-6',
    lg: 'w-8 h-8'
  }

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <div 
        className={cn(
          "animate-spin rounded-full border-2 border-gray-300 border-t-blue-600",
          sizeClasses[size]
        )}
        style={{
          borderTopColor: designTokens.colors.primary[600]
        }}
      />
      {label && (
        <span className="text-sm text-gray-600">{label}</span>
      )}
    </div>
  )
}

export {
  Skeleton,
  skeletonVariants
}