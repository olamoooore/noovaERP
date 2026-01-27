import * as React from "react"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import { cva, type VariantProps } from "class-variance-authority"
import { LucideIcon } from "lucide-react"

const enhancedBadgeVariants = cva(
  "inline-flex items-center font-semibold uppercase tracking-wider",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground",
        secondary: "bg-secondary text-secondary-foreground",
        destructive: "bg-destructive text-destructive-foreground",
        outline: "border border-input bg-background",
        success: "bg-green-500 text-white",
        warning: "bg-yellow-500 text-black",
        info: "bg-blue-500 text-white",
        status: "bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100",
        metric: "bg-gradient-to-r from-purple-500 to-pink-500 text-white",
      },
      size: {
        xs: "px-1 py-0.5 text-[9px]",
        sm: "px-1.5 py-0.5 text-[10px]",
        default: "px-2 py-1 text-xs",
        md: "px-2.5 py-1 text-sm",
        lg: "px-3 py-1.5 text-sm",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

export interface EnhancedBadgeProps 
  extends Omit<React.HTMLAttributes<HTMLDivElement>, 'color'>,
    VariantProps<typeof enhancedBadgeVariants> {
  icon?: LucideIcon;
  color?: string; // Custom color override
}

export const EnhancedBadge = ({ className, variant, size, icon: Icon, color, style, ...props }: EnhancedBadgeProps) => {
  const customStyle = color ? { ...style, backgroundColor: color } : style;
  
  return (
    <Badge 
      className={cn(enhancedBadgeVariants({ variant, size }), className)} 
      style={customStyle}
      {...props}
    >
      {Icon && <Icon className="mr-1 h-3 w-3" />}
      {props.children}
    </Badge>
  )
}

export const getSemanticColor = (status: string, context?: 'variant' | 'status' | string): string => {
  const normalizedStatus = status.toLowerCase();
  
  // If context is 'status', return actual CSS color string for the color prop
  if (context === 'status') {
    if (['confirmed', 'active', 'completed', 'paid', 'success'].includes(normalizedStatus)) {
      return 'rgb(34, 197, 94)'; // green-500
    }
    
    if (['cancelled', 'failed', 'refunded', 'rejected', 'error'].includes(normalizedStatus)) {
      return 'rgb(239, 68, 68)'; // red-500
    }
    
    if (['pending', 'processing', 'upcoming', 'waiting'].includes(normalizedStatus)) {
      return 'rgb(234, 179, 8)'; // yellow-500
    }
    
    if (['info', 'new', 'updated'].includes(normalizedStatus)) {
      return 'rgb(59, 130, 246)'; // blue-500
    }
    
    return 'rgb(100, 116, 139)'; // slate-500
  }
  
  // Default: return variant name for use with variant prop
  if (['confirmed', 'active', 'completed', 'paid', 'success'].includes(normalizedStatus)) {
    return 'success';
  }
  
  if (['cancelled', 'failed', 'refunded', 'rejected', 'error'].includes(normalizedStatus)) {
    return 'destructive';
  }
  
  if (['pending', 'processing', 'upcoming', 'waiting'].includes(normalizedStatus)) {
    return 'warning';
  }
  
  if (['info', 'new', 'updated'].includes(normalizedStatus)) {
    return 'info';
  }
  
  return 'default';
}
