import { QueryClient, QueryClientConfig } from '@tanstack/react-query'

/**
 * Optimized Query Client configuration to prevent unnecessary server requests
 * and improve UI state change efficiency
 */
export const createOptimizedQueryClient = (): QueryClient => {
  const config: QueryClientConfig = {
    defaultOptions: {
      queries: {
        // Prevent refetch on window focus for UI state changes
        refetchOnWindowFocus: false,
        
        // Prevent refetch on reconnect for UI-only changes
        refetchOnReconnect: 'always',
        
        // Increase stale time to reduce unnecessary refetches
        staleTime: 5 * 60 * 1000, // 5 minutes
        
        // Keep data in cache longer
        gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
        
        // Retry failed requests with exponential backoff
        retry: (failureCount: number, error: any) => {
          // Don't retry on 4xx errors (client errors)
          if (error?.response?.status >= 400 && error?.response?.status < 500) {
            return false
          }
          // Retry up to 3 times for other errors
          return failureCount < 3
        },
        
        // Prevent rapid successive requests
        retryDelay: (attemptIndex: number) => Math.min(1000 * 2 ** attemptIndex, 30000),
        
        // Refetch on mount only when data is stale (honours staleTime above).
        // `'always'` forced a network call on every component mount —
        // features/dashboard/menu widgets mounting together multiplied
        // requests and contributed to the 2026-09-21 429 storm.
        refetchOnMount: true,
        
        // Optimize network waterfall
        networkMode: 'online',
      },
      mutations: {
        // Prevent duplicate mutations
        retry: false,
        
        // Network mode for mutations
        networkMode: 'online',
      },
    },
  } as any

  const queryClient = new QueryClient(config)

  // Add global error handler
  queryClient.setMutationDefaults(['mutation'], {
    onError: (error: any) => {
      console.error('Mutation error:', error)
      // Could integrate with error handling system here
    },
  })

  // Add query deduplication
  const originalFetchQuery = queryClient.fetchQuery.bind(queryClient)
  const pendingQueries = new Map<string, Promise<any>>()

  queryClient.fetchQuery = (options: any) => {
    const queryKey = JSON.stringify(options.queryKey)
    
    // Return existing promise if query is already pending
    if (pendingQueries.has(queryKey)) {
      return pendingQueries.get(queryKey)!
    }
    
    const promise = originalFetchQuery(options)
    pendingQueries.set(queryKey, promise)
    
    // Clean up after query completes
    promise.finally(() => {
      pendingQueries.delete(queryKey)
    })
    
    return promise
  }

  return queryClient
}

/**
 * Query key factory to ensure consistent and optimized query keys
 */
export const queryKeys = {
  // Settings queries
  settings: () => ['settings'] as const,
  
  // Auth queries  
  auth: {
    all: () => ['auth'] as const,
    user: () => [...queryKeys.auth.all(), 'user'] as const,
    status: () => [...queryKeys.auth.all(), 'status'] as const,
  },
  
  // POS queries
  pos: {
    all: () => ['pos'] as const,
    products: (filters: { search?: string; category?: number; warehouse?: string }) => 
      [...queryKeys.pos.all(), 'products', filters] as const,
    categories: () => [...queryKeys.pos.all(), 'categories'] as const,
    customers: () => [...queryKeys.pos.all(), 'customers'] as const,
    warehouses: () => [...queryKeys.pos.all(), 'warehouses'] as const,
  },
  
  // Website builder queries
  websiteBuilder: {
    all: () => ['website-builder'] as const,
    website: (id: string) => [...queryKeys.websiteBuilder.all(), 'website', id] as const,
    page: (id: number) => [...queryKeys.websiteBuilder.all(), 'page', id] as const,
  },
  
  // Event management queries
  eventManagement: {
    all: () => ['event-management'] as const,
    events: () => [...queryKeys.eventManagement.all(), 'events'] as const,
    event: (id: number) => [...queryKeys.eventManagement.events(), id] as const,
    staffEvents: () => [...queryKeys.eventManagement.events(), 'staff'] as const,
    staffAssignments: (eventId: number) => 
      [...queryKeys.eventManagement.all(), 'staff', 'assignments', eventId] as const,
    operationsDashboard: (eventId: number) => 
      [...queryKeys.eventManagement.all(), 'operations', 'dashboard', eventId] as const,
  },
}

/**
 * Optimized invalidation helper
 */
export const createOptimizedInvalidation = (queryClient: QueryClient) => {
  const lastInvalidations = new Map<string, number>()
  
  return {
    invalidateQueries: (queryKey: readonly unknown[], minInterval = 1000) => {
      const keyString = JSON.stringify(queryKey)
      const now = Date.now()
      const lastInvalidation = lastInvalidations.get(keyString) || 0
      
      // Only invalidate if enough time has passed
      if (now - lastInvalidation >= minInterval) {
        lastInvalidations.set(keyString, now)
        queryClient.invalidateQueries({ queryKey })
      }
    },
    
    // Batch invalidations to reduce server requests
    batchInvalidateQueries: (queryKeys: readonly unknown[][], delay = 100) => {
      const timeoutId = setTimeout(() => {
        queryKeys.forEach(queryKey => {
          queryClient.invalidateQueries({ queryKey })
        })
      }, delay)
      
      return () => clearTimeout(timeoutId)
    },
  }
}