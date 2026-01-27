import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/lib/utils"
import { designTokens } from "@/lib/design-tokens"
import { 
  Clock, 
  MapPin, 
  Star, 
  Phone, 
  Mail, 
  MoreHorizontal,
  GripVertical
} from "lucide-react"

// Staff Status Types
export type StaffStatus = 'assigned' | 'checked_in' | 'active' | 'break' | 'checked_out' | 'absent'

// Staff Role Types
export type StaffRole = 'manager' | 'coordinator' | 'security' | 'usher' | 'technical' | 'medical' | 'customer_service' | 'merchandise' | 'cleaning' | 'volunteer'

// Enhanced Staff Card Variants
const enhancedStaffCardVariants = cva(
  "group relative transition-all duration-300 ease-out",
  {
    variants: {
      size: {
        sm: "p-4",
        md: "p-6", 
        lg: "p-8"
      },
      status: {
        assigned: "border-l-4 border-l-blue-500",
        checked_in: "border-l-4 border-l-green-500",
        active: "border-l-4 border-l-green-600",
        break: "border-l-4 border-l-yellow-500",
        checked_out: "border-l-4 border-l-gray-500",
        absent: "border-l-4 border-l-red-500"
      }
    },
    defaultVariants: {
      size: "md",
      status: "assigned"
    }
  }
)

// Staff Card Props Interface
export interface EnhancedStaffCardProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof enhancedStaffCardVariants> {
  // Staff Information
  staffId: string;
  staffName: string;
  role: StaffRole;
  zone?: string;
  status: StaffStatus;
  isLead?: boolean;
  
  // Avatar
  avatarUrl?: string;
  
  // Shift Information
  shiftStart?: string;
  shiftEnd?: string;
  checkedInAt?: string;
  checkedOutAt?: string;
  
  // Performance Metrics
  performanceRating?: number;
  punctualityScore?: number;
  reliabilityScore?: number;
  totalEvents?: number;
  
  // Contact Information
  contactNumber?: string;
  email?: string;
  
  // Actions
  onStatusChange?: (status: StaffStatus) => void;
  onViewDetails?: () => void;
  onContact?: () => void;
  onAssignTask?: () => void;
}

// Status Configuration
export const statusConfig: Record<StaffStatus, {
  label: string;
  color: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
  icon: any;
  pulse?: boolean;
}> = {
  assigned: {
    label: 'Assigned',
    color: 'info',
    icon: Clock,
    pulse: false
  },
  checked_in: {
    label: 'Checked In',
    color: 'success',
    icon: Clock,
    pulse: false
  },
  active: {
    label: 'Active',
    color: 'success',
    icon: Clock,
    pulse: true
  },
  break: {
    label: 'On Break',
    color: 'warning',
    icon: Clock,
    pulse: false
  },
  checked_out: {
    label: 'Checked Out',
    color: 'secondary',
    icon: Clock,
    pulse: false
  },
  absent: {
    label: 'Absent',
    color: 'error',
    icon: Clock,
    pulse: false
  }
}

// Role Configuration
export const roleConfig: Record<StaffRole, {
  label: string;
  color: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
  icon: any;
}> = {
  manager: { label: 'Event Manager', color: 'primary', icon: Star },
  coordinator: { label: 'Event Coordinator', color: 'info', icon: Star },
  security: { label: 'Security Staff', color: 'error', icon: Star },
  usher: { label: 'Usher', color: 'secondary', icon: Star },
  technical: { label: 'Technical Staff', color: 'warning', icon: Star },
  medical: { label: 'Medical Staff', color: 'success', icon: Star },
  customer_service: { label: 'Customer Service', color: 'info', icon: Star },
  merchandise: { label: 'Merchandise Staff', color: 'secondary', icon: Star },
  cleaning: { label: 'Cleaning Staff', color: 'secondary', icon: Star },
  volunteer: { label: 'Volunteer', color: 'primary', icon: Star }
}

// Generate initials from name
export const getInitials = (name: string): string => {
  if (!name || name.trim().length === 0) return 'U'
  
  const words = name.trim().split(/\s+/).filter(word => word.length > 0)
  if (words.length === 0) return 'U'
  
  if (words.length === 1) {
    const firstChar = words[0].charAt(0).toUpperCase()
    return /^[A-Z]$/.test(firstChar) ? firstChar : 'U'
  }
  
  const firstChar = words[0].charAt(0).toUpperCase()
  const secondChar = words[1].charAt(0).toUpperCase()
  
  const initials = ((/^[A-Z]$/.test(firstChar) ? firstChar : 'U') + 
                   (/^[A-Z]$/.test(secondChar) ? secondChar : '')).slice(0, 2)
  
  return initials || 'U'
}

// Format time
export const formatTime = (dateString?: string): string => {
  if (!dateString) return '--:--'
  try {
    return new Date(dateString).toLocaleTimeString('en-US', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    })
  } catch {
    return '--:--'
  }
}

// Calculate shift duration
export const getShiftDuration = (start?: string, end?: string): string => {
  if (!start || !end) return 'N/A'
  try {
    const startTime = new Date(start)
    const endTime = new Date(end)
    
    // Check if dates are valid
    if (isNaN(startTime.getTime()) || isNaN(endTime.getTime())) {
      return 'N/A'
    }
    
    const durationMs = endTime.getTime() - startTime.getTime()
    
    if (durationMs < 0) return 'N/A'
    
    const hours = Math.floor(durationMs / (1000 * 60 * 60))
    const minutes = Math.floor((durationMs % (1000 * 60 * 60)) / (1000 * 60))
    return `${hours}h ${minutes}m`
  } catch {
    return 'N/A'
  }
}

// Enhanced Staff Card Component
const EnhancedStaffCard = React.forwardRef<HTMLDivElement, EnhancedStaffCardProps>(
  ({ 
    className,
    size,
    status,
    staffId,
    staffName,
    role,
    zone,
    isLead = false,
    avatarUrl,
    shiftStart,
    shiftEnd,
    checkedInAt,
    checkedOutAt,
    performanceRating,
    punctualityScore,
    reliabilityScore,
    totalEvents,
    contactNumber,
    email,
    onStatusChange,
    onViewDetails,
    onContact,
    onAssignTask,
    ...props 
  }, ref) => {
    const [showActions, setShowActions] = React.useState(false)
    const [isHovered, setIsHovered] = React.useState(false)
    
    const statusInfo = statusConfig[status]
    const roleInfo = roleConfig[role]
    
    return (
      <div
        ref={ref}
        className={cn(
          enhancedStaffCardVariants({ size, status }),
          "bg-white rounded-lg shadow-md hover:shadow-lg transition-all duration-300 p-6",
          className
        )}
        onMouseEnter={() => {
          setIsHovered(true)
          setShowActions(true)
        }}
        onMouseLeave={() => {
          setIsHovered(false)
          setShowActions(false)
        }}
        {...props}
      >
        {/* Header Section */}
        <div className="flex items-start justify-between mb-4">
          <div className="flex items-center gap-3">
            {/* Avatar */}
            <div className={cn(
              "rounded-full bg-gray-200 flex items-center justify-center font-semibold text-gray-700",
              size === "sm" ? "w-10 h-10 text-sm" :
              size === "md" ? "w-12 h-12 text-base" :
              "w-16 h-16 text-lg"
            )}>
              {avatarUrl ? (
                <img 
                  src={avatarUrl} 
                  alt={staffName} 
                  className="w-full h-full rounded-full object-cover"
                />
              ) : (
                getInitials(staffName)
              )}
            </div>
            
            {/* Name and Role */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2 mb-1">
                <h3 
                  className={cn(
                    "font-semibold text-gray-900 truncate",
                    size === "sm" ? "text-sm" :
                    size === "md" ? "text-base" :
                    "text-lg"
                  )}
                >
                  {staffName}
                </h3>
                {isLead && (
                  <span className="px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                    Lead
                  </span>
                )}
              </div>
              
              <div className="flex items-center gap-2 flex-wrap">
                <span 
                  className={cn(
                    "px-2 py-1 text-xs font-medium rounded-full",
                    roleInfo.color === 'primary' ? "bg-blue-100 text-blue-800" :
                    roleInfo.color === 'secondary' ? "bg-gray-100 text-gray-800" :
                    roleInfo.color === 'success' ? "bg-green-100 text-green-800" :
                    roleInfo.color === 'warning' ? "bg-yellow-100 text-yellow-800" :
                    roleInfo.color === 'error' ? "bg-red-100 text-red-800" :
                    "bg-blue-100 text-blue-800"
                  )}
                >
                  {roleInfo.label}
                </span>
                
                {zone && (
                  <span className="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full flex items-center gap-1">
                    <MapPin className="w-3 h-3" />
                    {zone}
                  </span>
                )}
              </div>
            </div>
          </div>
          
          {/* Status Badge */}
          <span 
            className={cn(
              "px-3 py-1 text-sm font-medium rounded-full flex items-center gap-1",
              statusInfo.color === 'primary' ? "bg-blue-100 text-blue-800" :
              statusInfo.color === 'secondary' ? "bg-gray-100 text-gray-800" :
              statusInfo.color === 'success' ? "bg-green-100 text-green-800" :
              statusInfo.color === 'warning' ? "bg-yellow-100 text-yellow-800" :
              statusInfo.color === 'error' ? "bg-red-100 text-red-800" :
              statusInfo.color === 'info' ? "bg-blue-100 text-blue-800" :
              "bg-gray-100 text-gray-800",
              statusInfo.pulse ? "animate-pulse" : ""
            )}
          >
            <statusInfo.icon className="w-4 h-4" />
            {statusInfo.label}
          </span>
        </div>

        {/* Shift Information */}
        {(shiftStart || shiftEnd) && (
          <div className="mb-4 p-3 bg-gray-50 rounded-lg">
            <div className="flex items-center justify-between text-sm">
              <div className="flex items-center gap-2">
                <Clock className="w-4 h-4 text-gray-500" />
                <span className="font-medium text-gray-700">Shift</span>
              </div>
              <span className="text-gray-600">
                {getShiftDuration(shiftStart, shiftEnd)}
              </span>
            </div>
            
            <div className="mt-2 grid grid-cols-2 gap-4 text-xs">
              <div>
                <span className="text-gray-500">Start:</span>
                <div className="font-mono font-medium">
                  {formatTime(shiftStart)}
                </div>
              </div>
              <div>
                <span className="text-gray-500">End:</span>
                <div className="font-mono font-medium">
                  {formatTime(shiftEnd)}
                </div>
              </div>
            </div>
            
            {(checkedInAt || checkedOutAt) && (
              <div className="mt-2 pt-2 border-t border-gray-200 grid grid-cols-2 gap-4 text-xs">
                {checkedInAt && (
                  <div>
                    <span className="text-gray-500">Checked In:</span>
                    <div className="font-mono font-medium text-green-600">
                      {formatTime(checkedInAt)}
                    </div>
                  </div>
                )}
                {checkedOutAt && (
                  <div>
                    <span className="text-gray-500">Checked Out:</span>
                    <div className="font-mono font-medium text-gray-600">
                      {formatTime(checkedOutAt)}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        )}

        {/* Performance Metrics */}
        {(performanceRating !== undefined && performanceRating !== null || 
          punctualityScore !== undefined && punctualityScore !== null || 
          reliabilityScore !== undefined && reliabilityScore !== null ||
          totalEvents !== undefined && totalEvents !== null) && (
          <div className="mb-4">
            <h4 className="text-sm font-medium text-gray-700 mb-3 flex items-center gap-2">
              <Star className="w-4 h-4" />
              Performance
            </h4>
            
            <div className="space-y-3">
              {performanceRating !== undefined && performanceRating !== null && (
                <div>
                  <div className="flex justify-between items-center mb-1">
                    <span className="text-xs text-gray-600">Rating</span>
                    <span className="text-xs font-medium">
                      {performanceRating.toFixed(1)}/5.0
                    </span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div 
                      className="bg-blue-600 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${(performanceRating / 5) * 100}%` }}
                    />
                  </div>
                </div>
              )}
              
              {punctualityScore !== undefined && punctualityScore !== null && (
                <div>
                  <div className="flex justify-between items-center mb-1">
                    <span className="text-xs text-gray-600">Punctuality</span>
                    <span className="text-xs font-medium">
                      {punctualityScore}%
                    </span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div 
                      className="bg-green-600 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${punctualityScore}%` }}
                    />
                  </div>
                </div>
              )}
              
              {reliabilityScore !== undefined && reliabilityScore !== null && (
                <div>
                  <div className="flex justify-between items-center mb-1">
                    <span className="text-xs text-gray-600">Reliability</span>
                    <span className="text-xs font-medium">
                      {reliabilityScore}%
                    </span>
                  </div>
                  <div className="w-full bg-gray-200 rounded-full h-2">
                    <div 
                      className="bg-purple-600 h-2 rounded-full transition-all duration-300"
                      style={{ width: `${reliabilityScore}%` }}
                    />
                  </div>
                </div>
              )}
              
              {totalEvents !== undefined && totalEvents !== null && (
                <div className="pt-2 border-t border-gray-200">
                  <div className="flex justify-between items-center">
                    <span className="text-xs text-gray-600">Total Events</span>
                    <span className="text-lg font-bold text-gray-900">
                      {totalEvents}
                    </span>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Contact Information */}
        {(contactNumber || email) && (
          <div className="mb-4 p-3 bg-blue-50 rounded-lg">
            <h4 className="text-sm font-medium text-gray-700 mb-2">Contact</h4>
            <div className="space-y-1 text-xs">
              {contactNumber && (
                <div className="flex items-center gap-2">
                  <Phone className="w-3 h-3 text-gray-500" />
                  <span className="font-mono">{contactNumber}</span>
                </div>
              )}
              {email && (
                <div className="flex items-center gap-2">
                  <Mail className="w-3 h-3 text-gray-500" />
                  <span className="truncate">{email}</span>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Action Buttons - Revealed on Hover */}
        <div 
          className={cn(
            "flex items-center gap-2 transition-all duration-300 overflow-hidden",
            showActions && isHovered ? "max-h-12 opacity-100" : "max-h-0 opacity-0"
          )}
        >
          {onViewDetails && (
            <button
              onClick={onViewDetails}
              className="flex-1 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              View Details
            </button>
          )}
          
          {onContact && (
            <button
              onClick={onContact}
              className="flex-1 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              Contact
            </button>
          )}
          
          {onAssignTask && (
            <button
              onClick={onAssignTask}
              className="flex-1 px-3 py-2 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              Assign Task
            </button>
          )}
          
          <button className="px-2 py-2 text-gray-400 hover:text-gray-600 transition-colors">
            <MoreHorizontal className="w-4 h-4" />
          </button>
        </div>

        {/* Staff ID - Small text at bottom */}
        <div className="mt-2 pt-2 border-t border-gray-100">
          <span className="text-xs text-gray-400 font-mono">
            ID: {staffId}
          </span>
        </div>
      </div>
    )
  }
)

EnhancedStaffCard.displayName = "EnhancedStaffCard"

export {
  EnhancedStaffCard,
  enhancedStaffCardVariants
}