import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"

// Enhanced Badge Variants
const enhancedBadgeVariants = cva(
  "inline-flex items-center gap-1 rounded-full font-medium transition-all duration-200 ease-out",
  {
    variants: {
      variant: {
        status: "border",
        role: "border-0",
        priority: "border-0 shadow-sm",
        metric: "border-0 bg-gradient-to-r"
      },
      color: {
        primary: "",
        secondary: "",
        success: "",
        warning: "",
        error: "",
        info: ""
      },
      size: {
        xs: "px-2 py-0.5 text-xs",
        sm: "px-2.5 py-0.5 text-xs",
        md: "px-3 py-1 text-sm",
        lg: "px-4 py-1.5 text-sm"
      },
      pulse: {
        true: "animate-pulse",
        false: ""
      }
    },
    compoundVariants: [
      // Status variant colors
      {
        variant: "status",
        color: "primary",
        className: "bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100"
      },
      {
        variant: "status",
        color: "secondary",
        className: "bg-gray-50 text-gray-700 border-gray-200 hover:bg-gray-100"
      },
      {
        variant: "status",
        color: "success",
        className: "bg-green-50 text-green-700 border-green-200 hover:bg-green-100"
      },
      {
        variant: "status",
        color: "warning",
        className: "bg-yellow-50 text-yellow-700 border-yellow-200 hover:bg-yellow-100"
      },
      {
        variant: "status",
        color: "error",
        className: "bg-red-50 text-red-700 border-red-200 hover:bg-red-100"
      },
      {
        variant: "status",
        color: "info",
        className: "bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100"
      },
      
      // Role variant colors
      {
        variant: "role",
        color: "primary",
        className: "bg-blue-500 text-white hover:bg-blue-600"
      },
      {
        variant: "role",
        color: "secondary",
        className: "bg-gray-500 text-white hover:bg-gray-600"
      },
      {
        variant: "role",
        color: "success",
        className: "bg-green-500 text-white hover:bg-green-600"
      },
      {
        variant: "role",
        color: "warning",
        className: "bg-yellow-500 text-white hover:bg-yellow-600"
      },
      {
        variant: "role",
        color: "error",
        className: "bg-red-500 text-white hover:bg-red-600"
      },
      {
        variant: "role",
        color: "info",
        className: "bg-blue-500 text-white hover:bg-blue-600"
      },
      
      // Priority variant colors
      {
        variant: "priority",
        color: "primary",
        className: "bg-blue-100 text-blue-800 hover:bg-blue-200"
      },
      {
        variant: "priority",
        color: "secondary",
        className: "bg-gray-100 text-gray-800 hover:bg-gray-200"
      },
      {
        variant: "priority",
        color: "success",
        className: "bg-green-100 text-green-800 hover:bg-green-200"
      },
      {
        variant: "priority",
        color: "warning",
        className: "bg-yellow-100 text-yellow-800 hover:bg-yellow-200"
      },
      {
        variant: "priority",
        color: "error",
        className: "bg-red-100 text-red-800 hover:bg-red-200"
      },
      {
        variant: "priority",
        color: "info",
        className: "bg-blue-100 text-blue-800 hover:bg-blue-200"
      },
      
      // Metric variant colors (gradients)
      {
        variant: "metric",
        color: "primary",
        className: "from-blue-500 to-blue-600 text-white hover:from-blue-600 hover:to-blue-700"
      },
      {
        variant: "metric",
        color: "secondary",
        className: "from-gray-500 to-gray-600 text-white hover:from-gray-600 hover:to-gray-700"
      },
      {
        variant: "metric",
        color: "success",
        className: "from-green-500 to-green-600 text-white hover:from-green-600 hover:to-green-700"
      },
      {
        variant: "metric",
        color: "warning",
        className: "from-yellow-500 to-yellow-600 text-white hover:from-yellow-600 hover:to-yellow-700"
      },
      {
        variant: "metric",
        color: "error",
        className: "from-red-500 to-red-600 text-white hover:from-red-600 hover:to-red-700"
      },
      {
        variant: "metric",
        color: "info",
        className: "from-blue-500 to-blue-600 text-white hover:from-blue-600 hover:to-blue-700"
      }
    ],
    defaultVariants: {
      variant: "status",
      color: "primary",
      size: "md",
      pulse: false
    }
  }
)

// Enhanced Badge Props Interface
export interface EnhancedBadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedBadgeVariants> {
  icon?: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}

// Enhanced Badge Component
const EnhancedBadge = React.forwardRef<HTMLDivElement, EnhancedBadgeProps>(
  ({ className, variant, color, size, pulse, icon: Icon, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn(enhancedBadgeVariants({ variant, color, size, pulse }), className)}
        style={{
          transition: `all ${designTokens.animations.duration.fast} ${designTokens.animations.easing.easeOut}`,
        }}
        {...props}
      >
        {Icon && (
          <Icon 
            className={cn(
              "flex-shrink-0",
              size === "xs" ? "w-3 h-3" : 
              size === "sm" ? "w-3 h-3" :
              size === "md" ? "w-4 h-4" :
              "w-4 h-4"
            )}
          />
        )}
        <span className="truncate">{children}</span>
      </div>
    )
  }
)

EnhancedBadge.displayName = "EnhancedBadge"

// Predefined Badge Components for Common Use Cases

// Status Badge - for showing status states
export const StatusBadge: React.FC<Omit<EnhancedBadgeProps, 'variant'>> = (props) => (
  <EnhancedBadge variant="status" {...props} />
)

// Role Badge - for showing user roles
export const RoleBadge: React.FC<Omit<EnhancedBadgeProps, 'variant'>> = (props) => (
  <EnhancedBadge variant="role" {...props} />
)

// Priority Badge - for showing priority levels
export const PriorityBadge: React.FC<Omit<EnhancedBadgeProps, 'variant'>> = (props) => (
  <EnhancedBadge variant="priority" {...props} />
)

// Metric Badge - for showing metrics with gradients
export const MetricBadge: React.FC<Omit<EnhancedBadgeProps, 'variant'>> = (props) => (
  <EnhancedBadge variant="metric" {...props} />
)

// Utility function to get semantic color based on value
export const getSemanticColor = (
  value: string | number,
  type: 'status' | 'priority' | 'role' = 'status'
): EnhancedBadgeProps['color'] => {
  if (typeof value === 'string') {
    const lowerValue = value.toLowerCase()
    
    // Status-based color mapping
    if (type === 'status') {
      if (['active', 'online', 'available', 'success', 'completed', 'approved'].includes(lowerValue)) {
        return 'success'
      }
      if (['inactive', 'offline', 'unavailable', 'error', 'failed', 'rejected'].includes(lowerValue)) {
        return 'error'
      }
      if (['pending', 'warning', 'review', 'processing'].includes(lowerValue)) {
        return 'warning'
      }
      if (['info', 'information', 'note'].includes(lowerValue)) {
        return 'info'
      }
      if (['closed', 'disabled', 'suspended'].includes(lowerValue)) {
        return 'secondary'
      }
    }
    
    // Priority-based color mapping
    if (type === 'priority') {
      if (['high', 'urgent', 'critical'].includes(lowerValue)) {
        return 'error'
      }
      if (['medium', 'normal'].includes(lowerValue)) {
        return 'warning'
      }
      if (['low', 'minor'].includes(lowerValue)) {
        return 'success'
      }
    }
    
    // Role-based color mapping
    if (type === 'role') {
      if (['admin', 'administrator', 'manager'].includes(lowerValue)) {
        return 'error'
      }
      if (['moderator', 'supervisor', 'lead'].includes(lowerValue)) {
        return 'warning'
      }
      if (['user', 'member', 'staff'].includes(lowerValue)) {
        return 'primary'
      }
      if (['guest', 'visitor'].includes(lowerValue)) {
        return 'secondary'
      }
    }
  }
  
  // Numeric value mapping (for metrics)
  if (typeof value === 'number') {
    if (value >= 80) return 'success'
    if (value >= 60) return 'warning'
    if (value >= 40) return 'info'
    return 'error'
  }
  
  return 'primary'
}

export {
  EnhancedBadge,
  enhancedBadgeVariants
}