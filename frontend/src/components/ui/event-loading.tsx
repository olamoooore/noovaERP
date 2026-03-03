/**
 * Standardized Loading Components for EventManagement
 * 
 * Provides consistent loading indicators, skeleton screens, and progress
 * indicators specifically designed for EventManagement use cases.
 * 
 * Requirements: 4.3, 4.4
 */

import React from 'react'
import { cn } from '@/lib/utils'
import { LoadingSpinner, Skeleton, MetricCardSkeleton, ChartSkeleton, TableSkeleton, ProgressIndicator } from './skeleton'
import { EVENT_LOADING_PATTERNS, type LoadingPattern } from '@/lib/loading-states'
import { Card, CardContent } from './card'
import { EnhancedCard, EnhancedCardContent } from './enhanced-card'

// Event-specific loading component props
export interface EventLoadingProps {
  pattern?: LoadingPattern
  message?: string
  size?: 'sm' | 'md' | 'lg'
  className?: string
  progress?: number
  showProgress?: boolean
}

/**
 * Main EventLoading component that adapts based on pattern
 */
export const EventLoading: React.FC<EventLoadingProps> = ({
  pattern = 'SECTION',
  message,
  size = 'md',
  className,
  progress,
  showProgress = false
}) => {
  const config = EVENT_LOADING_PATTERNS[pattern]
  
  const renderSpinner = () => {
    if (!config.showSpinner) return null
    
    return (
      <LoadingSpinner 
        size={(config as any).size || size} 
        label={config.showMessage ? message : undefined}
      />
    )
  }

  const renderProgress = () => {
    if (!showProgress || progress === undefined) return null
    
    return (
      <div className="w-full max-w-xs">
        <ProgressIndicator 
          progress={progress}
          label={message}
          showPercentage={true}
          size={size}
        />
      </div>
    )
  }

  const renderContent = () => (
    <div className="flex flex-col items-center justify-center space-y-4">
      {renderSpinner()}
      {renderProgress()}
      {config.showMessage && message && !config.showSpinner && (
        <p className="text-sm text-gray-600 text-center">{message}</p>
      )}
    </div>
  )

  // Full page loading
  if (pattern === 'FULL_PAGE') {
    return (
      <div className={cn(
        "flex items-center justify-center min-h-screen bg-gray-50/50",
        className
      )}>
        {(config as any).overlay && (
          <div className="absolute inset-0 bg-white/80 backdrop-blur-sm z-40" />
        )}
        <div className="relative z-50">
          {renderContent()}
        </div>
      </div>
    )
  }

  // Section loading
  if (pattern === 'SECTION') {
    return (
      <div className={cn(
        "flex items-center justify-center py-8 min-h-32",
        className
      )}>
        {renderContent()}
      </div>
    )
  }

  // Inline loading
  if (pattern === 'INLINE') {
    return (
      <div className={cn("flex items-center space-x-2", className)}>
        {renderContent()}
      </div>
    )
  }

  // Background loading (minimal UI)
  if (pattern === 'BACKGROUND') {
    return (
      <div className={cn("flex items-center space-x-2 text-sm text-gray-500", className)}>
        {renderProgress() || (
          <>
            <div className="w-2 h-2 bg-blue-500 rounded-full animate-pulse" />
            {message && <span>{message}</span>}
          </>
        )}
      </div>
    )
  }

  // Critical loading with overlay
  if (pattern === 'CRITICAL') {
    return (
      <div className="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center">
        <div className="bg-white rounded-lg p-8 shadow-2xl max-w-sm w-full mx-4">
          {renderContent()}
        </div>
      </div>
    )
  }

  return (
    <div className={cn("flex items-center justify-center p-4", className)}>
      {renderContent()}
    </div>
  )
}

/**
 * Dashboard-specific loading skeleton
 */
export const EventDashboardSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("space-y-6", className)}>
    {/* Header */}
    <div className="flex items-center justify-between">
      <div className="space-y-2">
        <Skeleton variant="shimmer" className="h-8 w-48" />
        <Skeleton variant="shimmer" className="h-4 w-64" />
      </div>
      <Skeleton variant="shimmer" className="h-10 w-32" />
    </div>

    {/* Statistics Cards */}
    <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
      {Array.from({ length: 4 }).map((_, i) => (
        <EnhancedCard key={i} variant="elevated" size="sm">
          <EnhancedCardContent>
            <MetricCardSkeleton />
          </EnhancedCardContent>
        </EnhancedCard>
      ))}
    </div>

    {/* Tabs */}
    <div className="space-y-4">
      <div className="flex space-x-1 bg-gray-100 p-1 rounded-lg w-fit">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} variant="shimmer" className="h-8 w-24 rounded-md" />
        ))}
      </div>
      
      {/* Tab content */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {Array.from({ length: 4 }).map((_, i) => (
          <EnhancedCard key={i} variant="elevated" className="overflow-hidden">
            <div className="space-y-4">
              {/* Image placeholder */}
              <Skeleton variant="shimmer" className="w-full h-48" />
              
              {/* Content */}
              <div className="p-6 space-y-4">
                <div className="space-y-2">
                  <Skeleton variant="shimmer" className="h-6 w-3/4" />
                  <Skeleton variant="shimmer" className="h-4 w-1/2" />
                </div>
                
                <div className="space-y-2">
                  <Skeleton variant="shimmer" className="h-4 w-full" />
                  <Skeleton variant="shimmer" className="h-4 w-2/3" />
                  <Skeleton variant="shimmer" className="h-4 w-3/4" />
                </div>
                
                <div className="flex space-x-2">
                  <Skeleton variant="shimmer" className="h-9 flex-1" />
                  <Skeleton variant="shimmer" className="h-9 w-16" />
                </div>
              </div>
            </div>
          </EnhancedCard>
        ))}
      </div>
    </div>
  </div>
)

/**
 * Staff management loading skeleton
 */
export const EventStaffSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("space-y-6", className)}>
    {/* Header with controls */}
    <div className="flex items-center justify-between">
      <div className="space-y-2">
        <Skeleton variant="shimmer" className="h-8 w-40" />
        <Skeleton variant="shimmer" className="h-4 w-56" />
      </div>
      <div className="flex space-x-2">
        <Skeleton variant="shimmer" className="h-10 w-32" />
        <Skeleton variant="shimmer" className="h-10 w-28" />
      </div>
    </div>

    {/* Event selector */}
    <Card>
      <CardContent className="p-6">
        <div className="space-y-4">
          <Skeleton variant="shimmer" className="h-5 w-24" />
          <Skeleton variant="shimmer" className="h-10 w-full" />
        </div>
      </CardContent>
    </Card>

    {/* View mode tabs */}
    <div className="flex space-x-1 bg-gray-100 p-1 rounded-lg w-fit">
      {Array.from({ length: 3 }).map((_, i) => (
        <Skeleton key={i} variant="shimmer" className="h-8 w-20 rounded-md" />
      ))}
    </div>

    {/* Staff cards grid */}
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      {Array.from({ length: 6 }).map((_, i) => (
        <EnhancedCard key={i} variant="outlined" size="sm">
          <EnhancedCardContent className="space-y-4">
            <div className="flex items-center space-x-3">
              <Skeleton variant="shimmer" className="w-12 h-12 rounded-full" />
              <div className="space-y-2 flex-1">
                <Skeleton variant="shimmer" className="h-5 w-24" />
                <Skeleton variant="shimmer" className="h-4 w-16" />
              </div>
              <Skeleton variant="shimmer" className="h-6 w-16 rounded-full" />
            </div>
            
            <div className="space-y-2">
              <Skeleton variant="shimmer" className="h-4 w-full" />
              <Skeleton variant="shimmer" className="h-4 w-2/3" />
            </div>
            
            <div className="flex space-x-2">
              <Skeleton variant="shimmer" className="h-8 flex-1" />
              <Skeleton variant="shimmer" className="h-8 w-20" />
            </div>
          </EnhancedCardContent>
        </EnhancedCard>
      ))}
    </div>
  </div>
)

/**
 * Live operations dashboard loading skeleton
 */
export const EventOperationsSkeleton: React.FC<{ className?: string }> = ({ className }) => (
  <div className={cn("space-y-6", className)}>
    {/* Header with controls */}
    <div className="flex items-center justify-between">
      <div className="space-y-2">
        <Skeleton variant="shimmer" className="h-8 w-56" />
        <Skeleton variant="shimmer" className="h-4 w-72" />
      </div>
      <div className="flex space-x-2">
        <Skeleton variant="shimmer" className="h-10 w-32" />
        <Skeleton variant="shimmer" className="h-10 w-40" />
        <Skeleton variant="shimmer" className="h-10 w-36" />
      </div>
    </div>

    {/* Key metrics */}
    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
      {Array.from({ length: 4 }).map((_, i) => (
        <EnhancedCard key={i} variant="elevated" size="sm">
          <EnhancedCardContent>
            <MetricCardSkeleton />
          </EnhancedCardContent>
        </EnhancedCard>
      ))}
    </div>

    {/* Operational status */}
    <EnhancedCard variant="elevated">
      <EnhancedCardContent className="p-6">
        <div className="space-y-4">
          <div className="flex items-center gap-2">
            <Skeleton variant="shimmer" className="w-5 h-5" />
            <Skeleton variant="shimmer" className="h-6 w-48" />
          </div>
          
          <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="text-center space-y-2">
                <div className="flex items-center justify-center">
                  <Skeleton variant="shimmer" className="w-5 h-5 mr-2" />
                  <Skeleton variant="shimmer" className="h-4 w-20" />
                </div>
                <Skeleton variant="shimmer" className="h-6 w-16 mx-auto rounded-full" />
              </div>
            ))}
          </div>
        </div>
      </EnhancedCardContent>
    </EnhancedCard>

    {/* Tabs */}
    <div className="space-y-4">
      <div className="flex space-x-1 bg-gray-100 p-1 rounded-lg w-fit">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} variant="shimmer" className="h-8 w-28 rounded-md" />
        ))}
      </div>
      
      {/* Tab content - Zone status grid */}
      <EnhancedCard variant="elevated">
        <EnhancedCardContent className="p-6">
          <div className="space-y-4">
            <div className="flex items-center gap-2">
              <Skeleton variant="shimmer" className="w-5 h-5" />
              <Skeleton variant="shimmer" className="h-6 w-40" />
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {Array.from({ length: 6 }).map((_, i) => (
                <EnhancedCard key={i} variant="outlined" size="sm">
                  <EnhancedCardContent className="space-y-3">
                    <div className="flex justify-between items-center">
                      <Skeleton variant="shimmer" className="h-5 w-24" />
                      <Skeleton variant="shimmer" className="h-5 w-16 rounded-full" />
                    </div>
                    
                    <div className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <Skeleton variant="shimmer" className="h-4 w-16" />
                        <Skeleton variant="shimmer" className="h-4 w-12" />
                      </div>
                      <Skeleton variant="shimmer" className="h-3 w-full rounded-full" />
                      <div className="flex items-center justify-between">
                        <Skeleton variant="shimmer" className="h-3 w-20" />
                        <Skeleton variant="shimmer" className="h-3 w-12" />
                      </div>
                    </div>
                  </EnhancedCardContent>
                </EnhancedCard>
              ))}
            </div>
          </div>
        </EnhancedCardContent>
      </EnhancedCard>
    </div>
  </div>
)

/**
 * Event list loading skeleton
 */
export const EventListSkeleton: React.FC<{ 
  count?: number
  layout?: 'grid' | 'list'
  className?: string 
}> = ({ count = 6, layout = 'grid', className }) => {
  if (layout === 'list') {
    return (
      <div className={cn("space-y-4", className)}>
        {Array.from({ length: count }).map((_, i) => (
          <EnhancedCard key={i} variant="outlined" className="overflow-hidden">
            <div className="flex">
              <Skeleton variant="shimmer" className="w-32 h-24 flex-shrink-0" />
              <div className="flex-1 p-4 space-y-2">
                <Skeleton variant="shimmer" className="h-5 w-3/4" />
                <Skeleton variant="shimmer" className="h-4 w-1/2" />
                <Skeleton variant="shimmer" className="h-4 w-2/3" />
                <div className="flex space-x-2 mt-3">
                  <Skeleton variant="shimmer" className="h-6 w-16 rounded-full" />
                  <Skeleton variant="shimmer" className="h-6 w-20 rounded-full" />
                </div>
              </div>
            </div>
          </EnhancedCard>
        ))}
      </div>
    )
  }

  return (
    <div className={cn("grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6", className)}>
      {Array.from({ length: count }).map((_, i) => (
        <EnhancedCard key={i} variant="elevated" className="overflow-hidden">
          <div className="space-y-4">
            <Skeleton variant="shimmer" className="w-full h-48" />
            <div className="p-6 space-y-4">
              <div className="space-y-2">
                <Skeleton variant="shimmer" className="h-6 w-3/4" />
                <Skeleton variant="shimmer" className="h-4 w-1/2" />
              </div>
              <div className="space-y-2">
                <Skeleton variant="shimmer" className="h-4 w-full" />
                <Skeleton variant="shimmer" className="h-4 w-2/3" />
              </div>
              <div className="flex justify-between items-center">
                <Skeleton variant="shimmer" className="h-6 w-20 rounded-full" />
                <Skeleton variant="shimmer" className="h-9 w-24" />
              </div>
            </div>
          </div>
        </EnhancedCard>
      ))}
    </div>
  )
}

/**
 * Button loading state component
 */
export const EventButtonLoading: React.FC<{
  size?: 'sm' | 'md' | 'lg'
  message?: string
  className?: string
}> = ({ size = 'md', message, className }) => (
  <div className={cn("flex items-center space-x-2", className)}>
    <LoadingSpinner size={size} />
    {message && <span className="text-sm">{message}</span>}
  </div>
)

export {
  LoadingSpinner,
  ProgressIndicator,
  Skeleton,
  MetricCardSkeleton,
  ChartSkeleton,
  TableSkeleton
}