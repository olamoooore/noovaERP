import React from 'react'
import { toast } from '@/hooks/use-toast'
import { CategorizedError, ErrorCategory } from './api-client'

// Standard error message formats
export const ERROR_MESSAGES = {
  NETWORK: 'Unable to connect to the server. Please check your internet connection.',
  SERVER: 'A server error occurred. Please try again later.',
  VALIDATION: 'Please check your input and try again.',
  AUTHENTICATION: 'Please log in to continue.',
  AUTHORIZATION: 'You do not have permission to perform this action.',
  NOT_FOUND: 'The requested resource was not found.',
  RATE_LIMIT: 'Too many requests. Please wait a moment and try again.',
  GENERIC: 'An unexpected error occurred. Please try again.'
} as const

// Standard success message formats
export const SUCCESS_MESSAGES = {
  SAVE: 'Changes saved successfully',
  CREATE: 'Created successfully',
  UPDATE: 'Updated successfully',
  DELETE: 'Deleted successfully',
  SUBMIT: 'Submitted successfully',
  SEND: 'Sent successfully',
  DOWNLOAD: 'Download completed',
  UPLOAD: 'Upload completed'
} as const

// Error severity levels
export enum ErrorSeverity {
  LOW = 'low',
  MEDIUM = 'medium',
  HIGH = 'high',
  CRITICAL = 'critical'
}

// Standard error handling interface
export interface ErrorHandlingOptions {
  showToast?: boolean
  customMessage?: string
  severity?: ErrorSeverity
  logError?: boolean
  retryable?: boolean
}

// Standard loading state interface
export interface LoadingState {
  isLoading: boolean
  operation?: string
  progress?: number
}

/**
 * Standardized error handler for EventManagement components
 */
export class EventManagementErrorHandler {
  /**
   * Handle API errors with consistent formatting and user feedback
   */
  static handleError(
    error: any,
    options: ErrorHandlingOptions = {}
  ): void {
    const {
      showToast = true,
      customMessage,
      severity = ErrorSeverity.MEDIUM,
      logError = true,
    } = options

    let userMessage = customMessage || ERROR_MESSAGES.GENERIC
    let title = 'Error'

    // Handle CategorizedError from API client
    if (error && typeof error === 'object' && 'category' in error) {
      const categorizedError = error as CategorizedError
      userMessage = customMessage || categorizedError.userMessage
      
      // Set title based on error category
      switch (categorizedError.category) {
        case ErrorCategory.NETWORK:
          title = 'Connection Error'
          break
        case ErrorCategory.SERVER:
          title = 'Server Error'
          break
        case ErrorCategory.VALIDATION:
          title = 'Validation Error'
          break
        case ErrorCategory.AUTHENTICATION:
          title = 'Authentication Required'
          break
        case ErrorCategory.AUTHORIZATION:
          title = 'Access Denied'
          break
        case ErrorCategory.RATE_LIMIT:
          title = 'Rate Limited'
          break
        default:
          title = 'Error'
      }
    } else if (error instanceof Error) {
      userMessage = customMessage || error.message || ERROR_MESSAGES.GENERIC
    } else if (typeof error === 'string') {
      userMessage = customMessage || error
    }

    // Log error for debugging
    if (logError) {
      console.error('EventManagement Error:', {
        error,
        severity,
        userMessage,
        timestamp: new Date().toISOString()
      })
    }

    // Show toast notification
    if (showToast) {
      toast({
        title,
        description: userMessage,
        variant: 'destructive',
        duration: severity === ErrorSeverity.CRITICAL ? 10000 : 5000
      })
    }
  }

  /**
   * Handle success messages with consistent formatting
   */
  static handleSuccess(
    message: string,
    options: { showToast?: boolean; duration?: number } = {}
  ): void {
    const { showToast = true, duration = 3000 } = options

    if (showToast) {
      toast({
        title: 'Success',
        description: message,
        variant: 'default',
        duration
      })
    }
  }

  /**
   * Create a standardized async operation handler
   */
  static async handleAsyncOperation<T>(
    operation: () => Promise<T>,
    options: {
      loadingState?: (loading: boolean) => void
      successMessage?: string
      errorMessage?: string
      errorOptions?: ErrorHandlingOptions
    } = {}
  ): Promise<T | null> {
    const {
      loadingState,
      successMessage,
      errorMessage,
      errorOptions = {}
    } = options

    try {
      if (loadingState) loadingState(true)
      
      const result = await operation()
      
      if (successMessage) {
        this.handleSuccess(successMessage)
      }
      
      return result
    } catch (error) {
      this.handleError(error, {
        ...errorOptions,
        customMessage: errorMessage
      })
      return null
    } finally {
      if (loadingState) loadingState(false)
    }
  }

  /**
   * Create a retry wrapper for operations
   */
  static async withRetry<T>(
    operation: () => Promise<T>,
    maxRetries: number = 3,
    delay: number = 1000
  ): Promise<T> {
    let lastError: any

    for (let attempt = 1; attempt <= maxRetries; attempt++) {
      try {
        return await operation()
      } catch (error) {
        lastError = error
        
        if (attempt === maxRetries) {
          throw error
        }

        // Wait before retrying
        await new Promise(resolve => setTimeout(resolve, delay * attempt))
      }
    }

    throw lastError
  }
}

/**
 * Hook for managing loading states consistently
 */
export function useLoadingState(initialState: boolean = false) {
  const [loadingState, setLoadingState] = React.useState<LoadingState>({
    isLoading: initialState
  })

  const setLoading = (loading: boolean, operation?: string, progress?: number) => {
    setLoadingState({
      isLoading: loading,
      operation,
      progress
    })
  }

  const withLoading = async <T>(
    operation: () => Promise<T>,
    operationName?: string
  ): Promise<T | null> => {
    return EventManagementErrorHandler.handleAsyncOperation(
      operation,
      {
        loadingState: (loading) => setLoading(loading, operationName)
      }
    )
  }

  return {
    ...loadingState,
    setLoading,
    withLoading
  }
}

/**
 * Standard loading component for EventManagement
 */
export const LoadingSpinner: React.FC<{
  size?: 'sm' | 'md' | 'lg'
  message?: string
}> = ({ size = 'md', message }) => {
  const sizeClasses = {
    sm: 'h-4 w-4',
    md: 'h-8 w-8',
    lg: 'h-12 w-12'
  }

  return React.createElement('div', 
    { className: 'flex flex-col items-center justify-center p-4' },
    React.createElement('div', {
      className: `animate-spin rounded-full border-b-2 border-blue-600 ${sizeClasses[size]}`
    }),
    message && React.createElement('p', {
      className: 'mt-2 text-sm text-gray-600'
    }, message)
  )
}

/**
 * Standard error display component
 */
export const ErrorDisplay: React.FC<{
  error: string
  onRetry?: () => void
  severity?: ErrorSeverity
}> = ({ error, onRetry, severity = ErrorSeverity.MEDIUM }) => {
  const severityColors = {
    [ErrorSeverity.LOW]: 'text-blue-600 bg-blue-50 border-blue-200',
    [ErrorSeverity.MEDIUM]: 'text-yellow-600 bg-yellow-50 border-yellow-200',
    [ErrorSeverity.HIGH]: 'text-orange-600 bg-orange-50 border-orange-200',
    [ErrorSeverity.CRITICAL]: 'text-red-600 bg-red-50 border-red-200'
  }

  return React.createElement('div', 
    { className: `rounded-lg border p-4 ${severityColors[severity]}` },
    React.createElement('div', 
      { className: 'flex items-center' },
      React.createElement('div', 
        { className: 'flex-shrink-0' },
        React.createElement('svg', {
          className: 'h-5 w-5',
          viewBox: '0 0 20 20',
          fill: 'currentColor'
        }, React.createElement('path', {
          fillRule: 'evenodd',
          d: 'M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z',
          clipRule: 'evenodd'
        }))
      ),
      React.createElement('div', 
        { className: 'ml-3 flex-1' },
        React.createElement('p', {
          className: 'text-sm font-medium'
        }, error)
      ),
      onRetry && React.createElement('div', 
        { className: 'ml-3' },
        React.createElement('button', {
          onClick: onRetry,
          className: 'text-sm font-medium underline hover:no-underline'
        }, 'Try Again')
      )
    )
  )
}

// Export commonly used patterns
export const handleEventManagementError = EventManagementErrorHandler.handleError
export const handleEventManagementSuccess = EventManagementErrorHandler.handleSuccess
export const withEventManagementLoading = EventManagementErrorHandler.handleAsyncOperation