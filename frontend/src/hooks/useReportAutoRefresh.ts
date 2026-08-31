import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'

/**
 * Hook that wires automatic report-data invalidation on any transactional
 * mutation success.
 *
 * Usage:
 *   useReportAutoRefresh()
 *
 * The hook subscribes to the React Query mutation cache and invalidates every
 * known report query key whenever a mutation with a tracked mutation prefix
 * completes successfully.
 */
export function useReportAutoRefresh() {
  const queryClient = useQueryClient()

  useEffect(() => {
    const reportKeys = [
      'profit-loss',
      'balance-sheet',
      'cash-flow',
      'trial-balance',
      'general-ledger',
      'sales-by-product',
      'sales-by-customer',
      'sales-trends',
      'stock-valuation',
      'stock-movement',
      'reorder',
      'aged-receivables',
      'aged-payables',
      'executive-dashboard',
      'sales-dashboard',
      'inventory-dashboard',
      'financial-dashboard',
    ]

    const invalidate = () => {
      reportKeys.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
    }

    // Subscribe to mutation cache — React Query fires this for every mutation
    // event including success.
    const unsubscribe = queryClient.getMutationCache().subscribe((event) => {
      if (event.type !== 'updated') return
      const mutationState = event.mutation
      const status = mutationState.state.status
      if (status !== 'success') return

      const keys = (mutationState.options.mutationKey ?? []) as readonly unknown[]
      const prefix = keys[0]
      // Method lives either on variables (axios-style) or on context.
      const method = (mutationState.options as any)?.variables?.method
        ?? (mutationState.state.context as any)?.method

      const trackedPrefixes = [
        'invoices',
        'bills',
        'payments',
        'journal-entries',
        'quotations',
        'sales-orders',
        'delivery-orders',
        'purchase-orders',
        'stock',
        'products',
        'customers',
        'vendors',
        'corrections',
        'reverse',
        'cancel',
      ]

      const isTrackedPrefix = typeof prefix === 'string' && trackedPrefixes.includes(prefix)
      const isWriteMethod =
        method === 'POST' ||
        method === 'PUT' ||
        method === 'PATCH' ||
        method === 'DELETE'

      if (isTrackedPrefix || isWriteMethod) {
        invalidate()
      }
    })

    return unsubscribe
  }, [queryClient])
}
