/**
 * Staff Operations Iconography System
 * Consistent icon mapping for roles, actions, and status indicators
 * Ensures accessibility with proper ARIA labels
 */

import {
  // Staff Role Icons
  Crown,
  Clipboard,
  Shield,
  Users,
  Wrench,
  Heart,
  Headphones,
  ShoppingBag,
  Trash2,
  HandHeart,
  
  // Status Icons
  Clock,
  CheckCircle,
  Play,
  Pause,
  XCircle,
  AlertTriangle,
  
  // Action Icons
  Edit,
  Trash,
  Phone,
  Mail,
  MessageSquare,
  Calendar,
  MapPin,
  Star,
  Award,
  Target,
  
  // Navigation Icons
  ArrowRight,
  ArrowLeft,
  ChevronUp,
  ChevronDown,
  MoreHorizontal,
  MoreVertical,
  
  // System Icons
  Settings,
  Filter,
  Search,
  Download,
  Upload,
  RotateCcw, // Using RotateCcw instead of Refresh
  Save,
  
  // Feedback Icons
  ThumbsUp,
  ThumbsDown,
  AlertCircle,
  Info,
  CheckCircle2,
  X,
  
  type LucideIcon
} from 'lucide-react'

// Staff Role Icon Mapping
export const staffRoleIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
  color: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
}> = {
  manager: {
    icon: Crown,
    label: 'Manager',
    ariaLabel: 'Event Manager role',
    color: 'primary'
  },
  coordinator: {
    icon: Clipboard,
    label: 'Coordinator',
    ariaLabel: 'Event Coordinator role',
    color: 'info'
  },
  security: {
    icon: Shield,
    label: 'Security',
    ariaLabel: 'Security Staff role',
    color: 'error'
  },
  usher: {
    icon: Users,
    label: 'Usher',
    ariaLabel: 'Usher role',
    color: 'secondary'
  },
  technical: {
    icon: Wrench,
    label: 'Technical',
    ariaLabel: 'Technical Staff role',
    color: 'warning'
  },
  medical: {
    icon: Heart,
    label: 'Medical',
    ariaLabel: 'Medical Staff role',
    color: 'success'
  },
  customer_service: {
    icon: Headphones,
    label: 'Customer Service',
    ariaLabel: 'Customer Service role',
    color: 'info'
  },
  merchandise: {
    icon: ShoppingBag,
    label: 'Merchandise',
    ariaLabel: 'Merchandise Staff role',
    color: 'secondary'
  },
  cleaning: {
    icon: Trash2,
    label: 'Cleaning',
    ariaLabel: 'Cleaning Staff role',
    color: 'secondary'
  },
  volunteer: {
    icon: HandHeart,
    label: 'Volunteer',
    ariaLabel: 'Volunteer role',
    color: 'primary'
  }
}

// Staff Status Icon Mapping
export const staffStatusIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
  color: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
  pulse?: boolean;
}> = {
  assigned: {
    icon: Clock,
    label: 'Assigned',
    ariaLabel: 'Staff member is assigned but not yet checked in',
    color: 'info',
    pulse: false
  },
  checked_in: {
    icon: CheckCircle,
    label: 'Checked In',
    ariaLabel: 'Staff member has checked in',
    color: 'success',
    pulse: false
  },
  active: {
    icon: Play,
    label: 'Active',
    ariaLabel: 'Staff member is actively working',
    color: 'success',
    pulse: true
  },
  break: {
    icon: Pause,
    label: 'On Break',
    ariaLabel: 'Staff member is on break',
    color: 'warning',
    pulse: false
  },
  checked_out: {
    icon: XCircle,
    label: 'Checked Out',
    ariaLabel: 'Staff member has checked out',
    color: 'secondary',
    pulse: false
  },
  absent: {
    icon: AlertTriangle,
    label: 'Absent',
    ariaLabel: 'Staff member is absent',
    color: 'error',
    pulse: false
  }
}

// Action Icon Mapping
export const staffActionIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
  shortcut?: string;
}> = {
  edit: {
    icon: Edit,
    label: 'Edit',
    ariaLabel: 'Edit staff assignment',
    shortcut: 'E'
  },
  delete: {
    icon: Trash,
    label: 'Delete',
    ariaLabel: 'Delete staff assignment',
    shortcut: 'Del'
  },
  contact_phone: {
    icon: Phone,
    label: 'Call',
    ariaLabel: 'Call staff member',
    shortcut: 'C'
  },
  contact_email: {
    icon: Mail,
    label: 'Email',
    ariaLabel: 'Send email to staff member',
    shortcut: 'M'
  },
  message: {
    icon: MessageSquare,
    label: 'Message',
    ariaLabel: 'Send message to staff member',
    shortcut: 'T'
  },
  schedule: {
    icon: Calendar,
    label: 'Schedule',
    ariaLabel: 'View or edit schedule',
    shortcut: 'S'
  },
  location: {
    icon: MapPin,
    label: 'Location',
    ariaLabel: 'View staff location',
    shortcut: 'L'
  },
  performance: {
    icon: Star,
    label: 'Performance',
    ariaLabel: 'View performance metrics',
    shortcut: 'P'
  },
  assign_task: {
    icon: Target,
    label: 'Assign Task',
    ariaLabel: 'Assign new task to staff member',
    shortcut: 'A'
  },
  view_details: {
    icon: Info,
    label: 'Details',
    ariaLabel: 'View detailed information',
    shortcut: 'I'
  }
}

// Navigation Icon Mapping
export const navigationIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
}> = {
  next: {
    icon: ArrowRight,
    label: 'Next',
    ariaLabel: 'Go to next page or item'
  },
  previous: {
    icon: ArrowLeft,
    label: 'Previous',
    ariaLabel: 'Go to previous page or item'
  },
  expand: {
    icon: ChevronDown,
    label: 'Expand',
    ariaLabel: 'Expand section or menu'
  },
  collapse: {
    icon: ChevronUp,
    label: 'Collapse',
    ariaLabel: 'Collapse section or menu'
  },
  more_horizontal: {
    icon: MoreHorizontal,
    label: 'More',
    ariaLabel: 'More options menu'
  },
  more_vertical: {
    icon: MoreVertical,
    label: 'More',
    ariaLabel: 'More options menu'
  }
}

// System Icon Mapping
export const systemIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
}> = {
  settings: {
    icon: Settings,
    label: 'Settings',
    ariaLabel: 'Open settings'
  },
  filter: {
    icon: Filter,
    label: 'Filter',
    ariaLabel: 'Filter results'
  },
  search: {
    icon: Search,
    label: 'Search',
    ariaLabel: 'Search staff or assignments'
  },
  download: {
    icon: Download,
    label: 'Download',
    ariaLabel: 'Download report or data'
  },
  upload: {
    icon: Upload,
    label: 'Upload',
    ariaLabel: 'Upload file or data'
  },
  refresh: {
    icon: RotateCcw,
    label: 'Refresh',
    ariaLabel: 'Refresh data'
  },
  save: {
    icon: Save,
    label: 'Save',
    ariaLabel: 'Save changes'
  }
}

// Feedback Icon Mapping
export const feedbackIcons: Record<string, {
  icon: LucideIcon;
  label: string;
  ariaLabel: string;
  color: 'primary' | 'secondary' | 'success' | 'warning' | 'error' | 'info';
}> = {
  success: {
    icon: CheckCircle2,
    label: 'Success',
    ariaLabel: 'Operation completed successfully',
    color: 'success'
  },
  error: {
    icon: AlertCircle,
    label: 'Error',
    ariaLabel: 'An error occurred',
    color: 'error'
  },
  warning: {
    icon: AlertTriangle,
    label: 'Warning',
    ariaLabel: 'Warning message',
    color: 'warning'
  },
  info: {
    icon: Info,
    label: 'Information',
    ariaLabel: 'Information message',
    color: 'info'
  },
  positive: {
    icon: ThumbsUp,
    label: 'Positive',
    ariaLabel: 'Positive feedback',
    color: 'success'
  },
  negative: {
    icon: ThumbsDown,
    label: 'Negative',
    ariaLabel: 'Negative feedback',
    color: 'error'
  },
  close: {
    icon: X,
    label: 'Close',
    ariaLabel: 'Close dialog or notification',
    color: 'secondary'
  }
}

// Utility Functions

/**
 * Get icon configuration for a staff role
 */
export const getStaffRoleIcon = (role: string) => {
  return staffRoleIcons[role] || staffRoleIcons.volunteer
}

/**
 * Get icon configuration for a staff status
 */
export const getStaffStatusIcon = (status: string) => {
  return staffStatusIcons[status] || staffStatusIcons.assigned
}

/**
 * Get icon configuration for an action
 */
export const getActionIcon = (action: string) => {
  return staffActionIcons[action] || staffActionIcons.view_details
}

/**
 * Get icon configuration for navigation
 */
export const getNavigationIcon = (navigation: string) => {
  return navigationIcons[navigation] || navigationIcons.more_horizontal
}

/**
 * Get icon configuration for system actions
 */
export const getSystemIcon = (system: string) => {
  return systemIcons[system] || systemIcons.settings
}

/**
 * Get icon configuration for feedback
 */
export const getFeedbackIcon = (feedback: string) => {
  return feedbackIcons[feedback] || feedbackIcons.info
}

/**
 * Generate ARIA label for icon with context
 */
export const generateAriaLabel = (
  iconType: 'role' | 'status' | 'action' | 'navigation' | 'system' | 'feedback',
  iconKey: string,
  context?: string
): string => {
  let baseLabel: string
  
  switch (iconType) {
    case 'role':
      baseLabel = getStaffRoleIcon(iconKey).ariaLabel
      break
    case 'status':
      baseLabel = getStaffStatusIcon(iconKey).ariaLabel
      break
    case 'action':
      baseLabel = getActionIcon(iconKey).ariaLabel
      break
    case 'navigation':
      baseLabel = getNavigationIcon(iconKey).ariaLabel
      break
    case 'system':
      baseLabel = getSystemIcon(iconKey).ariaLabel
      break
    case 'feedback':
      baseLabel = getFeedbackIcon(iconKey).ariaLabel
      break
    default:
      baseLabel = 'Icon'
  }
  
  return context ? `${baseLabel} for ${context}` : baseLabel
}

/**
 * Get all available icons for a category
 */
export const getIconsByCategory = (category: 'role' | 'status' | 'action' | 'navigation' | 'system' | 'feedback') => {
  switch (category) {
    case 'role':
      return staffRoleIcons
    case 'status':
      return staffStatusIcons
    case 'action':
      return staffActionIcons
    case 'navigation':
      return navigationIcons
    case 'system':
      return systemIcons
    case 'feedback':
      return feedbackIcons
    default:
      return {}
  }
}

/**
 * Validate icon accessibility
 */
export const validateIconAccessibility = (iconConfig: any): boolean => {
  return !!(
    iconConfig.icon &&
    iconConfig.label &&
    iconConfig.ariaLabel &&
    iconConfig.ariaLabel.length > 0
  )
}

// Export all icon categories for easy access
export const allIcons = {
  roles: staffRoleIcons,
  statuses: staffStatusIcons,
  actions: staffActionIcons,
  navigation: navigationIcons,
  system: systemIcons,
  feedback: feedbackIcons
}

// Export icon types for TypeScript
export type StaffRole = keyof typeof staffRoleIcons
export type StaffStatus = keyof typeof staffStatusIcons
export type StaffAction = keyof typeof staffActionIcons
export type NavigationAction = keyof typeof navigationIcons
export type SystemAction = keyof typeof systemIcons
export type FeedbackType = keyof typeof feedbackIcons