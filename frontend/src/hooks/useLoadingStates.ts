/**
 * Loading States Hook
 * Manages different loading states and provides smooth transitions
 */

import { useState, useCallback } from 'react'

export interface LoadingState {
  isLoading: boolean
  progress?: number
  message?: string
  error?: string | null
}

export interface LoadingStatesConfig {
  [key: string]: LoadingState
}

export interface UseLoadingStatesReturn {
  loadingStates: LoadingStatesConfig
  setLoading: (key: string, loading: boolean, options?: { progress?: number; message?: string }) => void
  setProgress: (key: string, progress: number, message?: string) => void
  setError: (key: string, error: string | null) => void
  isAnyLoading: boolean
  getLoadingState: (key: string) => LoadingState
  resetAll: () => void
}

export function useLoadingStates(initialStates: LoadingStatesConfig = {}): UseLoadingStatesReturn {
  const [loadingStates, setLoadingStates] = useState<LoadingStatesConfig>(initialStates)

  const setLoading = useCallback((
    key: string, 
    loading: boolean, 
    options: { progress?: number; message?: string } = {}
  ) => {
    setLoadingStates(prev => ({
      ...prev,
      [key]: {
        ...prev[key],
        isLoading: loading,
        progress: options.progress,
        message: options.message,
        error: loading ? null : prev[key]?.error || null
      }
    }))
  }, [])

  const setProgress = useCallback((key: string, progress: number, message?: string) => {
    setLoadingStates(prev => ({
      ...prev,
      [key]: {
        ...prev[key],
        progress,
        message: message || prev[key]?.message,
        isLoading: true,
        error: null
      }
    }))
  }, [])

  const setError = useCallback((key: string, error: string | null) => {
    setLoadingStates(prev => ({
      ...prev,
      [key]: {
        ...prev[key],
        error,
        isLoading: false
      }
    }))
  }, [])

  const getLoadingState = useCallback((key: string): LoadingState => {
    return loadingStates[key] || { isLoading: false, error: null }
  }, [loadingStates])

  const resetAll = useCallback(() => {
    setLoadingStates({})
  }, [])

  const isAnyLoading = Object.values(loadingStates).some(state => state.isLoading)

  return {
    loadingStates,
    setLoading,
    setProgress,
    setError,
    isAnyLoading,
    getLoadingState,
    resetAll
  }
}

// Predefined loading state keys for common scenarios
export const LOADING_KEYS = {
  DASHBOARD: 'dashboard',
  KPI_CARDS: 'kpi-cards',
  CHARTS: 'charts',
  REVENUE_CHART: 'revenue-chart',
  CUSTOMER_CHART: 'customer-chart',
  SALES_CHART: 'sales-chart',
  RECENT_INVOICES: 'recent-invoices',
  EXPORT: 'export'
} as const

export type LoadingKey = typeof LOADING_KEYS[keyof typeof LOADING_KEYS]

// Hook for dashboard-specific loading states
export function useDashboardLoadingStates() {
  return useLoadingStates({
    [LOADING_KEYS.DASHBOARD]: { isLoading: false, error: null },
    [LOADING_KEYS.KPI_CARDS]: { isLoading: false, error: null },
    [LOADING_KEYS.CHARTS]: { isLoading: false, error: null },
    [LOADING_KEYS.REVENUE_CHART]: { isLoading: false, error: null },
    [LOADING_KEYS.CUSTOMER_CHART]: { isLoading: false, error: null },
    [LOADING_KEYS.SALES_CHART]: { isLoading: false, error: null },
    [LOADING_KEYS.RECENT_INVOICES]: { isLoading: false, error: null },
    [LOADING_KEYS.EXPORT]: { isLoading: false, error: null }
  })
}

// Utility function to simulate loading with progress
export function simulateLoadingWithProgress(
  setProgress: (progress: number, message?: string) => void,
  duration: number = 2000,
  steps: Array<{ progress: number; message: string }> = [
    { progress: 25, message: 'Loading data...' },
    { progress: 50, message: 'Processing...' },
    { progress: 75, message: 'Rendering...' },
    { progress: 100, message: 'Complete!' }
  ]
): Promise<void> {
  return new Promise((resolve) => {
    const stepDuration = duration / steps.length
    let currentStep = 0

    const interval = setInterval(() => {
      if (currentStep < steps.length) {
        const step = steps[currentStep]
        setProgress(step.progress, step.message)
        currentStep++
      } else {
        clearInterval(interval)
        resolve()
      }
    }, stepDuration)
  })
}