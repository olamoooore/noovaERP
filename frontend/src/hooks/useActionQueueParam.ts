import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { SALES_ACTION_QUEUE_LABELS } from '@/lib/salesActionQueues'

/**
 * Issue #365: a Sales cockpit tile opens a list with `?action_queue=<key>[&mine=1]`.
 * List pages read it from the URL (links and reloads keep the filter), forward
 * it as API params, and drop it from the URL on clear.
 */
export function useActionQueueParam() {
  const [searchParams, setSearchParams] = useSearchParams()
  const raw = searchParams.get('action_queue') ?? ''
  const actionQueue = raw in SALES_ACTION_QUEUE_LABELS ? raw : ''
  const mine = actionQueue !== '' && searchParams.get('mine') === '1'

  const params: Record<string, string> = actionQueue ? { action_queue: actionQueue, ...(mine ? { mine: '1' } : {}) } : {}

  const clear = useCallback(() => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      next.delete('action_queue')
      next.delete('mine')
      return next
    })
  }, [setSearchParams])

  return { actionQueue, mine, params, clear }
}
