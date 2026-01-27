import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import {
  getStaffRoleIcon,
  getStaffStatusIcon,
  getActionIcon,
  getNavigationIcon,
  getSystemIcon,
  getFeedbackIcon,
  generateAriaLabel,
  type StaffRole,
  type StaffStatus,
  type StaffAction,
  type NavigationAction,
  type SystemAction,
  type FeedbackType
} from "@/lib/staff-iconography"

// Enhanced Icon Variants
const enhancedIconVariants = cva(
  "inline-flex items-center justify-center transition-all duration-200 ease-out",
  {
    variants: {
      size: {
        xs: "w-3 h-3",
        sm: "w-4 h-4",
        md: "w-5 h-5",
        lg: "w-6 h-6",
        xl: "w-8 h-8"
      },
      color: {
        primary: "text-blue-600",
        secondary: "text-gray-600",
        success: "text-green-600",
        warning: "text-yellow-600",
        error: "text-red-600",
        info: "text-blue-500",
        inherit: "text-current"
      },
      interactive: {
        true: "cursor-pointer hover:scale-110 active:scale-95",
        false: ""
      },
      pulse: {
        true: "animate-pulse",
        false: ""
      }
    },
    defaultVariants: {
      size: "md",
      color: "inherit",
      interactive: false,
      pulse: false
    }
  }
)

// Base Enhanced Icon Props
interface BaseEnhancedIconProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedIconVariants> {
  ariaLabel?: string;
  context?: string;
}

// Staff Role Icon Props
interface StaffRoleIconProps extends BaseEnhancedIconProps {
  type: 'role';
  name: StaffRole;
}

// Staff Status Icon Props
interface StaffStatusIconProps extends BaseEnhancedIconProps {
  type: 'status';
  name: StaffStatus;
}

// Action Icon Props
interface ActionIconProps extends BaseEnhancedIconProps {
  type: 'action';
  name: StaffAction;
}

// Navigation Icon Props
interface NavigationIconProps extends BaseEnhancedIconProps {
  type: 'navigation';
  name: NavigationAction;
}

// System Icon Props
interface SystemIconProps extends BaseEnhancedIconProps {
  type: 'system';
  name: SystemAction;
}

// Feedback Icon Props
interface FeedbackIconProps extends BaseEnhancedIconProps {
  type: 'feedback';
  name: FeedbackType;
}

// Union type for all icon props
export type EnhancedIconProps = 
  | StaffRoleIconProps 
  | StaffStatusIconProps 
  | ActionIconProps 
  | NavigationIconProps 
  | SystemIconProps 
  | FeedbackIconProps

// Enhanced Icon Component
const EnhancedIcon = React.forwardRef<HTMLDivElement, EnhancedIconProps>(
  ({ 
    className,
    type,
    name,
    size,
    color,
    interactive,
    pulse,
    ariaLabel,
    context,
    onClick,
    ...props 
  }, ref) => {
    // Get icon configuration based on type
    const getIconConfig = () => {
      switch (type) {
        case 'role':
          return getStaffRoleIcon(name)
        case 'status':
          return getStaffStatusIcon(name)
        case 'action':
          return getActionIcon(name)
        case 'navigation':
          return getNavigationIcon(name)
        case 'system':
          return getSystemIcon(name)
        case 'feedback':
          return getFeedbackIcon(name)
        default:
          return null
      }
    }

    const iconConfig = getIconConfig()
    
    if (!iconConfig) {
      console.warn(`Icon not found: ${type}/${name}`)
      return null
    }

    const IconComponent = iconConfig.icon
    
    // Use provided color or fall back to icon's default color
    const iconColor = color || iconConfig.color || 'inherit'
    
    // Use provided pulse or fall back to icon's default pulse
    const shouldPulse = pulse !== undefined ? pulse : (iconConfig as any).pulse || false
    
    // Generate aria label
    const finalAriaLabel = ariaLabel || generateAriaLabel(type, name, context)
    
    // Determine if interactive
    const isInteractive = interactive || !!onClick

    return (
      <div
        ref={ref}
        className={cn(
          enhancedIconVariants({ 
            size, 
            color: iconColor, 
            interactive: isInteractive, 
            pulse: shouldPulse 
          }),
          className
        )}
        onClick={onClick}
        role={isInteractive ? "button" : undefined}
        tabIndex={isInteractive ? 0 : undefined}
        aria-label={finalAriaLabel}
        title={iconConfig.label}
        style={{
          color: iconColor !== 'inherit' ? getColorValue(iconColor) : undefined
        }}
        {...props}
      >
        <IconComponent 
          className="w-full h-full"
          aria-hidden="true"
        />
      </div>
    )
  }
)

EnhancedIcon.displayName = "EnhancedIcon"

// Helper function to get color values from design tokens
const getColorValue = (color: string): string => {
  switch (color) {
    case 'primary':
      return designTokens.colors.primary[600]
    case 'secondary':
      return designTokens.colors.secondary[600]
    case 'success':
      return designTokens.colors.semantic.success[600]
    case 'warning':
      return designTokens.colors.semantic.warning[600]
    case 'error':
      return designTokens.colors.semantic.error[600]
    case 'info':
      return designTokens.colors.semantic.info[600]
    default:
      return 'currentColor'
  }
}

// Convenience components for specific icon types

// Staff Role Icon
export const StaffRoleIcon: React.FC<Omit<StaffRoleIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="role" {...props} />
)

// Staff Status Icon
export const StaffStatusIcon: React.FC<Omit<StaffStatusIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="status" {...props} />
)

// Action Icon
export const ActionIcon: React.FC<Omit<ActionIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="action" {...props} />
)

// Navigation Icon
export const NavigationIcon: React.FC<Omit<NavigationIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="navigation" {...props} />
)

// System Icon
export const SystemIcon: React.FC<Omit<SystemIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="system" {...props} />
)

// Feedback Icon
export const FeedbackIcon: React.FC<Omit<FeedbackIconProps, 'type'>> = (props) => (
  <EnhancedIcon type="feedback" {...props} />
)

// Icon Button Component - Interactive icon with button styling
interface IconButtonProps extends BaseEnhancedIconProps {
  type: EnhancedIconProps['type'];
  name: string;
  variant?: 'ghost' | 'outline' | 'solid';
  disabled?: boolean;
}

export const IconButton = React.forwardRef<HTMLButtonElement, IconButtonProps & {
  onClick?: () => void;
}>(
  ({ 
    className,
    type,
    name,
    size = 'md',
    color = 'inherit',
    variant = 'ghost',
    disabled = false,
    ariaLabel,
    context,
    onClick,
    ...props 
  }, ref) => {
    const buttonVariants = cva(
      "inline-flex items-center justify-center rounded-md transition-all duration-200 ease-out focus:outline-none focus:ring-2 focus:ring-offset-2",
      {
        variants: {
          variant: {
            ghost: "hover:bg-gray-100 focus:ring-gray-300",
            outline: "border border-gray-300 hover:bg-gray-50 focus:ring-gray-300",
            solid: "bg-gray-900 text-white hover:bg-gray-800 focus:ring-gray-500"
          },
          size: {
            xs: "p-1",
            sm: "p-1.5",
            md: "p-2",
            lg: "p-2.5",
            xl: "p-3"
          },
          disabled: {
            true: "opacity-50 cursor-not-allowed",
            false: "cursor-pointer"
          }
        },
        defaultVariants: {
          variant: 'ghost',
          size: 'md',
          disabled: false
        }
      }
    )

    return (
      <button
        ref={ref}
        className={cn(buttonVariants({ variant, size, disabled }), className)}
        disabled={disabled}
        onClick={onClick}
        aria-label={ariaLabel || generateAriaLabel(type, name, context)}
        {...props}
      >
        <EnhancedIcon
          type={type as any}
          name={name as any}
          size={size}
          color={color}
          interactive={false}
          context={context}
        />
      </button>
    )
  }
)

IconButton.displayName = "IconButton"

export {
  EnhancedIcon,
  enhancedIconVariants
}