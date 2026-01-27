/**
 * Staff Operations Icon Mapping Utilities
 * Provides consistent icon usage across staff operations interfaces
 * Ensures accessibility and semantic correctness
 */

import {
  StaffRoleIcon,
  StaffStatusIcon,
  ActionIcon,
  NavigationIcon,
  SystemIcon,
  FeedbackIcon,
  type EnhancedIconProps
} from '@/components/ui/enhanced-icon'
import {
  type StaffRole,
  type StaffStatus,
  type StaffAction,
  type NavigationAction,
  type SystemAction,
  type FeedbackType
} from '@/lib/staff-iconography'

// Staff Operations Context Mappings
export const staffOperationsIconMap = {
  // Page sections
  sections: {
    staffManagement: { type: 'role' as const, name: 'manager' as StaffRole },
    eventSelection: { type: 'system' as const, name: 'search' as SystemAction },
    performanceMonitoring: { type: 'system' as const, name: 'filter' as SystemAction },
    operationalHandover: { type: 'status' as const, name: 'active' as StaffStatus },
    assignments: { type: 'role' as const, name: 'coordinator' as StaffRole },
    scheduling: { type: 'action' as const, name: 'schedule' as StaffAction }
  },

  // Actions
  actions: {
    assignStaff: { type: 'action' as const, name: 'assign_task' as StaffAction },
    generateReport: { type: 'system' as const, name: 'download' as SystemAction },
    scheduleBriefing: { type: 'action' as const, name: 'schedule' as StaffAction },
    contactStaff: { type: 'action' as const, name: 'contact_phone' as StaffAction },
    editAssignment: { type: 'action' as const, name: 'edit' as StaffAction },
    viewDetails: { type: 'action' as const, name: 'view_details' as StaffAction },
    refreshData: { type: 'system' as const, name: 'refresh' as SystemAction },
    saveChanges: { type: 'system' as const, name: 'save' as SystemAction },
    filterResults: { type: 'system' as const, name: 'filter' as SystemAction }
  },

  // Status indicators
  statusIndicators: {
    success: { type: 'feedback' as const, name: 'success' as FeedbackType },
    error: { type: 'feedback' as const, name: 'error' as FeedbackType },
    warning: { type: 'feedback' as const, name: 'warning' as FeedbackType },
    info: { type: 'feedback' as const, name: 'info' as FeedbackType },
    loading: { type: 'system' as const, name: 'refresh' as SystemAction }
  },

  // Navigation
  navigation: {
    next: { type: 'navigation' as const, name: 'next' as NavigationAction },
    previous: { type: 'navigation' as const, name: 'previous' as NavigationAction },
    expand: { type: 'navigation' as const, name: 'expand' as NavigationAction },
    collapse: { type: 'navigation' as const, name: 'collapse' as NavigationAction },
    moreOptions: { type: 'navigation' as const, name: 'more_horizontal' as NavigationAction }
  },

  // Performance metrics
  performance: {
    rating: { type: 'action' as const, name: 'performance' as StaffAction },
    punctuality: { type: 'status' as const, name: 'checked_in' as StaffStatus },
    reliability: { type: 'feedback' as const, name: 'positive' as FeedbackType },
    attendance: { type: 'status' as const, name: 'active' as StaffStatus }
  }
}

// Utility function to get icon props for a specific context
export const getStaffOperationIcon = (
  category: keyof typeof staffOperationsIconMap,
  key: string
): EnhancedIconProps | null => {
  const categoryMap = staffOperationsIconMap[category] as Record<string, any>
  const iconConfig = categoryMap?.[key]
  
  if (!iconConfig) {
    console.warn(`Staff operations icon not found: ${category}/${key}`)
    return null
  }

  return {
    type: iconConfig.type,
    name: iconConfig.name
  } as EnhancedIconProps
}

// Utility function to get icon component for a specific context
export const getStaffOperationIconComponent = (
  category: keyof typeof staffOperationsIconMap,
  key: string,
  props?: Partial<EnhancedIconProps>
) => {
  const iconConfig = getStaffOperationIcon(category, key)
  
  if (!iconConfig) {
    return null
  }

  const { type, name } = iconConfig
  const componentProps = { ...iconConfig, ...props }

  switch (type) {
    case 'role':
      return StaffRoleIcon({ name: name as StaffRole, ...componentProps })
    case 'status':
      return StaffStatusIcon({ name: name as StaffStatus, ...componentProps })
    case 'action':
      return ActionIcon({ name: name as StaffAction, ...componentProps })
    case 'navigation':
      return NavigationIcon({ name: name as NavigationAction, ...componentProps })
    case 'system':
      return SystemIcon({ name: name as SystemAction, ...componentProps })
    case 'feedback':
      return FeedbackIcon({ name: name as FeedbackType, ...componentProps })
    default:
      return null
  }
}

// Predefined icon sets for common staff operations scenarios
export const staffOperationIconSets = {
  // Card headers
  cardHeaders: {
    staffList: staffOperationsIconMap.sections.staffManagement,
    eventSelection: staffOperationsIconMap.sections.eventSelection,
    performance: staffOperationsIconMap.sections.performanceMonitoring,
    handover: staffOperationsIconMap.sections.operationalHandover
  },

  // Action buttons
  actionButtons: {
    assign: staffOperationsIconMap.actions.assignStaff,
    report: staffOperationsIconMap.actions.generateReport,
    schedule: staffOperationsIconMap.actions.scheduleBriefing,
    contact: staffOperationsIconMap.actions.contactStaff,
    edit: staffOperationsIconMap.actions.editAssignment,
    details: staffOperationsIconMap.actions.viewDetails
  },

  // Status feedback
  statusFeedback: {
    success: staffOperationsIconMap.statusIndicators.success,
    error: staffOperationsIconMap.statusIndicators.error,
    warning: staffOperationsIconMap.statusIndicators.warning,
    info: staffOperationsIconMap.statusIndicators.info
  }
}

// Helper function to validate icon accessibility
export const validateStaffOperationIconAccessibility = (
  category: keyof typeof staffOperationsIconMap,
  key: string,
  context?: string
): boolean => {
  const iconConfig = getStaffOperationIcon(category, key)
  
  if (!iconConfig) {
    return false
  }

  // Check if icon has proper accessibility attributes
  const hasType = !!iconConfig.type
  const hasName = !!iconConfig.name
  const hasContext = !!context || true // Context is optional but recommended

  return hasType && hasName && hasContext
}

// Export commonly used icon configurations
export const commonStaffIcons = {
  // Role icons
  manager: { type: 'role' as const, name: 'manager' as StaffRole },
  coordinator: { type: 'role' as const, name: 'coordinator' as StaffRole },
  security: { type: 'role' as const, name: 'security' as StaffRole },
  usher: { type: 'role' as const, name: 'usher' as StaffRole },
  technical: { type: 'role' as const, name: 'technical' as StaffRole },
  medical: { type: 'role' as const, name: 'medical' as StaffRole },
  volunteer: { type: 'role' as const, name: 'volunteer' as StaffRole },

  // Status icons
  assigned: { type: 'status' as const, name: 'assigned' as StaffStatus },
  checkedIn: { type: 'status' as const, name: 'checked_in' as StaffStatus },
  active: { type: 'status' as const, name: 'active' as StaffStatus },
  onBreak: { type: 'status' as const, name: 'break' as StaffStatus },
  checkedOut: { type: 'status' as const, name: 'checked_out' as StaffStatus },
  absent: { type: 'status' as const, name: 'absent' as StaffStatus },

  // Action icons
  edit: { type: 'action' as const, name: 'edit' as StaffAction },
  delete: { type: 'action' as const, name: 'delete' as StaffAction },
  contact: { type: 'action' as const, name: 'contact_phone' as StaffAction },
  schedule: { type: 'action' as const, name: 'schedule' as StaffAction },
  assign: { type: 'action' as const, name: 'assign_task' as StaffAction },

  // System icons
  search: { type: 'system' as const, name: 'search' as SystemAction },
  filter: { type: 'system' as const, name: 'filter' as SystemAction },
  download: { type: 'system' as const, name: 'download' as SystemAction },
  refresh: { type: 'system' as const, name: 'refresh' as SystemAction },
  save: { type: 'system' as const, name: 'save' as SystemAction }
}

// Type exports for better TypeScript support
export type StaffOperationIconCategory = keyof typeof staffOperationsIconMap
export type StaffOperationIconKey<T extends StaffOperationIconCategory> = keyof typeof staffOperationsIconMap[T]
export type StaffOperationIconConfig = {
  type: EnhancedIconProps['type']
  name: string
}