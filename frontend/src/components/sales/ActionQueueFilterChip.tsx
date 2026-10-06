import { Icon } from '@iconify/react'
import { SALES_ACTION_QUEUE_LABELS } from '@/lib/salesActionQueues'

interface ActionQueueFilterChipProps {
  actionQueue: string
  mine: boolean
  onClear: () => void
}

/** Issue #365: removable chip shown while a list is filtered by a cockpit action queue. */
export function ActionQueueFilterChip({ actionQueue, mine, onClear }: ActionQueueFilterChipProps) {
  if (!actionQueue) return null

  const label = `${SALES_ACTION_QUEUE_LABELS[actionQueue] ?? actionQueue}${mine ? ' (mine)' : ''}`

  return (
    <span
      data-testid="action-queue-filter-chip"
      className="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 pl-3 pr-1.5 text-xs font-medium text-primary"
    >
      <Icon icon="solar:checklist-minimalistic-bold-duotone" className="h-3.5 w-3.5" />
      {label}
      <button
        type="button"
        data-testid="action-queue-filter-chip-remove"
        aria-label={`Remove ${label} filter`}
        onClick={onClear}
        className="ml-0.5 rounded-full p-0.5 hover:bg-primary/20"
      >
        <Icon icon="solar:close-circle-bold" className="h-3.5 w-3.5" />
      </button>
    </span>
  )
}
