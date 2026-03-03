import { useQuery, UseQueryOptions, UseQueryResult } from '@tanstack/react-query'
import { useRef, useCallback } from 'react'

/**
 * Optimized React Query hook that prevents unnecessary refetches
 * when UI state changes don't affect the query parameters
 */
export function useOptimizedQuery<
  TQueryFnData = unknown,
  TError = Error,
  TData = TQueryFnData,
  TQueryKey extends readonly unknown[] = readonly unknown[]
>(
  options: UseQueryOptions<TQueryFnData, TError, TData, TQueryKey>
): UseQueryResult<TData, TError> {
  const previousQueryKeyRef = useRef<string>()
  const stableOptionsRef = useRef(options)

  // Create a stable query key string for comparison
  const queryKeyString = JSON.stringify(options.queryKey)
  
  // Only update options if query key actually changed
  const shouldUpdateOptions = queryKeyString !== previousQueryKeyRef.current
  
  if (shouldUpdateOptions) {
    stableOptionsRef.current = options
    previousQueryKeyRef.current = queryKeyString
  }

  return useQuery(stableOptionsRef.current)
}

/**
 * Hook to create stable query options that don't change on every render
 */
export function useStableQueryOptions<T extends Record<string, any>>(
  options: T,
  dependencies: readonly unknown[]
): T {
  const optionsRef = useRef<T>(options)
  const depsRef = useRef<readonly unknown[]>(dependencies)
  
  // Only update if dependencies actually changed
  const depsChanged = dependencies.some((dep, index) => dep !== depsRef.current[index])
  
  if (depsChanged) {
    optionsRef.current = options
    depsRef.current = dependencies
  }
  
  return optionsRef.current
}

/**
 * Debounced query hook that prevents rapid successive queries
 */
export function useDebouncedQuery<
  TQueryFnData = unknown,
  TError = Error,
  TData = TQueryFnData,
  TQueryKey extends readonly unknown[] = readonly unknown[]
>(
  options: UseQueryOptions<TQueryFnData, TError, TData, TQueryKey>,
  debounceMs: number = 300
): UseQueryResult<TData, TError> {
  const timeoutRef = useRef<NodeJS.Timeout>()
  const enabledRef = useRef(false)
  
  const debouncedEnabled = useCallback(() => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
    }
    
    timeoutRef.current = setTimeout(() => {
      enabledRef.current = true
    }, debounceMs)
    
    return enabledRef.current
  }, [debounceMs])

  const stableOptions = useStableQueryOptions(
    {
      ...options,
      enabled: options.enabled !== false && debouncedEnabled(),
    },
    [options.queryKey, options.enabled, debounceMs]
  )

  return useQuery(stableOptions)
}