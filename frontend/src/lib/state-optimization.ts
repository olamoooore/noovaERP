import { useCallback, useRef } from 'react'

/**
 * Utility to prevent unnecessary Zustand store updates
 * Only updates the store if the new value is actually different
 */
export function useOptimizedStoreUpdate<T>(
  currentValue: T,
  updateFn: (value: T) => void
) {
  const previousValueRef = useRef<T>(currentValue)
  
  return useCallback((newValue: T) => {
    // Deep comparison for objects, shallow for primitives
    const hasChanged = typeof newValue === 'object' 
      ? JSON.stringify(newValue) !== JSON.stringify(previousValueRef.current)
      : newValue !== previousValueRef.current
    
    if (hasChanged) {
      previousValueRef.current = newValue
      updateFn(newValue)
    }
  }, [updateFn])
}

/**
 * Debounced store update to prevent rapid successive updates
 */
export function useDebouncedStoreUpdate<T>(
  updateFn: (value: T) => void,
  delay: number = 300
) {
  const timeoutRef = useRef<ReturnType<typeof setTimeout>>()
  
  return useCallback((value: T) => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
    }
    
    timeoutRef.current = setTimeout(() => {
      updateFn(value)
    }, delay)
  }, [updateFn, delay])
}

/**
 * Batched store updates to combine multiple updates into one
 */
export function useBatchedStoreUpdate<T extends Record<string, unknown>>(
  updateFn: (updates: Partial<T>) => void
) {
  const pendingUpdatesRef = useRef<Partial<T>>({})
  const timeoutRef = useRef<ReturnType<typeof setTimeout>>()
  
  return useCallback((updates: Partial<T>) => {
    // Accumulate updates
    pendingUpdatesRef.current = { ...pendingUpdatesRef.current, ...updates }
    
    // Clear existing timeout
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current)
    }
    
    // Batch updates in next tick
    timeoutRef.current = setTimeout(() => {
      updateFn(pendingUpdatesRef.current)
      pendingUpdatesRef.current = {}
    }, 0)
  }, [updateFn])
}

/**
 * Memoized selector to prevent unnecessary re-renders
 */
export function createOptimizedSelector<TState, TSelected>(
  selector: (state: TState) => TSelected
) {
  let previousState: TState
  let previousResult: TSelected
  
  return (state: TState): TSelected => {
    if (state === previousState) {
      return previousResult
    }
    
    const result = selector(state)
    
    // Only update if result actually changed
    if (typeof result === 'object' 
      ? JSON.stringify(result) !== JSON.stringify(previousResult)
      : result !== previousResult
    ) {
      previousState = state
      previousResult = result
      return result
    }
    
    return previousResult
  }
}

/**
 * Utility to prevent unnecessary React Query invalidations
 */
export function useOptimizedInvalidation(queryClient: unknown) {
  const lastInvalidationRef = useRef<Record<string, number>>({})
  
  return useCallback((queryKey: string[], minInterval: number = 1000) => {
    const keyString = JSON.stringify(queryKey)
    const now = Date.now()
    const lastInvalidation = lastInvalidationRef.current[keyString] || 0
    
    // Only invalidate if enough time has passed
    if (now - lastInvalidation >= minInterval) {
      lastInvalidationRef.current[keyString] = now
      // Type assertion needed since we can't import QueryClient type
      ;(queryClient as { invalidateQueries: (options: { queryKey: string[] }) => void })
        .invalidateQueries({ queryKey })
    }
  }, [queryClient])
}

/**
 * Optimized mutation that prevents duplicate requests
 */
export function useOptimizedMutation<TData, TVariables>(
  mutationFn: (variables: TVariables) => Promise<TData>,
  options?: Record<string, unknown>
) {
  const pendingRequestsRef = useRef<Set<string>>(new Set())
  
  const optimizedMutationFn = useCallback(async (variables: TVariables) => {
    const requestKey = JSON.stringify(variables)
    
    // Prevent duplicate requests
    if (pendingRequestsRef.current.has(requestKey)) {
      return Promise.reject(new Error('Duplicate request prevented'))
    }
    
    pendingRequestsRef.current.add(requestKey)
    
    try {
      const result = await mutationFn(variables)
      return result
    } finally {
      pendingRequestsRef.current.delete(requestKey)
    }
  }, [mutationFn])
  
  return {
    mutationFn: optimizedMutationFn,
    ...options
  }
}