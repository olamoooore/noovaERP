/**
 * Standardized Loading States for EventManagement Module
 * 
 * This module provides consistent loading state management and UI components
 * for all EventManagement components, ensuring uniform user experience
 * during async operations.
 * 
 * Requirements: 4.3, 4.4
 */

import { useLoadingStates } from '@/hooks/useLoadingStates'

// Standard loading state keys for EventManagement
export const EVENT_LOADING_KEYS = {
  // Dashboard operations
  DASHBOARD_DATA: 'dashboard-data',
  STATISTICS: 'statistics',
  UPCOMING_EVENTS: 'upcoming-events',
  PAST_EVENTS: 'past-events',
  RECOMMENDATIONS: 'recommendations',
  ACTIVITY: 'activity',
  
  // Event operations
  EVENT_LIST: 'event-list',
  EVENT_DETAILS: 'event-details',
  EVENT_CREATE: 'event-create',
  EVENT_UPDATE: 'event-update',
  EVENT_DELETE: 'event-delete',
  
  // Ticket operations
  TICKET_DOWNLOAD: 'ticket-download',
  TICKET_VALIDATION: 'ticket-validation',
  CHECK_IN: 'check-in',
  
  // Staff operations
  STAFF_ASSIGNMENTS: 'staff-assignments',
  STAFF_PERFORMANCE: 'staff-performance',
  STAFF_ASSIGN: 'staff-assign',
  STAFF_STATUS_UPDATE: 'staff-status-update',
  
  // Live operations
  OPERATIONS_DASHBOARD: 'operations-dashboard',
  INCIDENT_REPORT: 'incident-report',
  EMERGENCY_BROADCAST: 'emergency-broadcast',
  
  // Feedback operations
  FEEDBACK_SUBMIT: 'feedback-submit',
  FEEDBACK_LOAD: 'feedback-load',
  
  // File operations
  REPORT_GENERATE: 'report-generate',
  DATA_EXPORT: 'data-export',
  
  // General operations
  SAVE: 'save',
  REFRESH: 'refresh'
} as const

export type EventLoadingKey = typeof EVENT_LOADING_KEYS[keyof typeof EVENT_LOADING_KEYS]

// Loading state configuration interface
export interface LoadingStateConfig {
  key: EventLoadingKey
  message?: string
  showProgress?: boolean
  timeout?: number
  critical?: boolean // If true, blocks all other interactions
}

// Standard loading messages for EventManagement operations
export const LOADING_MESSAGES = {
  [EVENT_LOADING_KEYS.DASHBOARD_DATA]: 'Loading your events dashboard...',
  [EVENT_LOADING_KEYS.STATISTICS]: 'Loading statistics...',
  [EVENT_LOADING_KEYS.UPCOMING_EVENTS]: 'Loading upcoming events...',
  [EVENT_LOADING_KEYS.PAST_EVENTS]: 'Loading past events...',
  [EVENT_LOADING_KEYS.RECOMMENDATIONS]: 'Loading recommendations...',
  [EVENT_LOADING_KEYS.ACTIVITY]: 'Loading recent activity...',
  
  [EVENT_LOADING_KEYS.EVENT_LIST]: 'Loading events...',
  [EVENT_LOADING_KEYS.EVENT_DETAILS]: 'Loading event details...',
  [EVENT_LOADING_KEYS.EVENT_CREATE]: 'Creating event...',
  [EVENT_LOADING_KEYS.EVENT_UPDATE]: 'Updating event...',
  [EVENT_LOADING_KEYS.EVENT_DELETE]: 'Deleting event...',
  
  [EVENT_LOADING_KEYS.TICKET_DOWNLOAD]: 'Preparing your tickets...',
  [EVENT_LOADING_KEYS.TICKET_VALIDATION]: 'Validating tickets...',
  [EVENT_LOADING_KEYS.CHECK_IN]: 'Processing check-in...',
  
  [EVENT_LOADING_KEYS.STAFF_ASSIGNMENTS]: 'Loading staff assignments...',
  [EVENT_LOADING_KEYS.STAFF_PERFORMANCE]: 'Loading performance data...',
  [EVENT_LOADING_KEYS.STAFF_ASSIGN]: 'Assigning staff member...',
  [EVENT_LOADING_KEYS.STAFF_STATUS_UPDATE]: 'Updating staff status...',
  
  [EVENT_LOADING_KEYS.OPERATIONS_DASHBOARD]: 'Loading operations dashboard...',
  [EVENT_LOADING_KEYS.INCIDENT_REPORT]: 'Reporting incident...',
  [EVENT_LOADING_KEYS.EMERGENCY_BROADCAST]: 'Sending emergency broadcast...',
  
  [EVENT_LOADING_KEYS.FEEDBACK_SUBMIT]: 'Submitting feedback...',
  [EVENT_LOADING_KEYS.FEEDBACK_LOAD]: 'Loading feedback...',
  
  [EVENT_LOADING_KEYS.REPORT_GENERATE]: 'Generating report...',
  [EVENT_LOADING_KEYS.DATA_EXPORT]: 'Exporting data...',
  
  [EVENT_LOADING_KEYS.SAVE]: 'Saving changes...',
  [EVENT_LOADING_KEYS.REFRESH]: 'Refreshing data...'
} as const

/**
 * Hook for managing EventManagement loading states
 * Provides standardized loading state management with consistent UI patterns
 */
export function useEventLoadingStates() {
  const loadingStates = useLoadingStates()

  const setEventLoading = (
    key: EventLoadingKey, 
    loading: boolean, 
    options?: { progress?: number; message?: string }
  ) => {
    const message = options?.message || LOADING_MESSAGES[key] || 'Loading...'
    loadingStates.setLoading(key, loading, { ...options, message })
  }

  const setEventProgress = (
    key: EventLoadingKey, 
    progress: number, 
    message?: string
  ) => {
    const defaultMessage = message || LOADING_MESSAGES[key] || 'Loading...'
    loadingStates.setProgress(key, progress, defaultMessage)
  }

  const setEventError = (key: EventLoadingKey, error: string | null) => {
    loadingStates.setError(key, error)
  }

  const getEventLoadingState = (key: EventLoadingKey) => {
    return loadingStates.getLoadingState(key)
  }

  const isEventLoading = (key: EventLoadingKey) => {
    return loadingStates.getLoadingState(key).isLoading
  }

  const isAnyEventLoading = () => {
    return loadingStates.isAnyLoading
  }

  const resetEventLoadingStates = () => {
    loadingStates.resetAll()
  }

  return {
    loadingStates: loadingStates.loadingStates,
    setEventLoading,
    setEventProgress,
    setEventError,
    getEventLoadingState,
    isEventLoading,
    isAnyEventLoading,
    resetEventLoadingStates
  }
}

/**
 * Higher-order component for wrapping async operations with loading states
 */
export function withEventLoading<T extends any[]>(
  operation: (...args: T) => Promise<any>,
  config: LoadingStateConfig
) {
  return async (...args: T) => {
    const loadingStates = useEventLoadingStates()
    
    try {
      loadingStates.setEventLoading(config.key, true, { message: config.message })
      
      const result = await operation(...args)
      
      return result
    } catch (error) {
      loadingStates.setEventError(config.key, error instanceof Error ? error.message : 'An error occurred')
      throw error
    } finally {
      loadingStates.setEventLoading(config.key, false)
    }
  }
}

/**
 * Utility function to create consistent loading state setters for EventManagementErrorHandler
 */
export function createEventLoadingSetter(key: EventLoadingKey) {
  return (loading: boolean) => {
    const loadingStates = useEventLoadingStates()
    loadingStates.setEventLoading(key, loading)
  }
}

/**
 * Standard loading state patterns for common EventManagement operations
 */
export const EVENT_LOADING_PATTERNS = {
  // Full page loading (dashboard, main views)
  FULL_PAGE: {
    showSpinner: true,
    showMessage: true,
    blockInteraction: true,
    minHeight: 'min-h-screen',
    position: 'center'
  },
  
  // Section loading (tabs, cards, components)
  SECTION: {
    showSpinner: true,
    showMessage: true,
    blockInteraction: false,
    minHeight: 'min-h-32',
    position: 'center'
  },
  
  // Inline loading (buttons, small actions)
  INLINE: {
    showSpinner: true,
    showMessage: false,
    blockInteraction: true,
    size: 'sm',
    position: 'inline'
  },
  
  // Background loading (auto-refresh, non-critical operations)
  BACKGROUND: {
    showSpinner: false,
    showMessage: false,
    blockInteraction: false,
    showProgress: true
  },
  
  // Critical loading (emergency operations, important saves)
  CRITICAL: {
    showSpinner: true,
    showMessage: true,
    blockInteraction: true,
    overlay: true,
    timeout: 30000 // 30 seconds
  }
} as const

export type LoadingPattern = keyof typeof EVENT_LOADING_PATTERNS