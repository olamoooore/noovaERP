import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { formatDate } from '@/lib/utils'

export interface SalesOrderLockControlProps {
  /** The confirmed order's current lock state (backend `is_locked`). */
  isLocked: boolean
  /** `sales.write` — viewers see the badge but never the action. */
  canWrite: boolean
  isPending?: boolean
  /** Backend `locked_at`; shown in the badge tooltip when present. */
  lockedAt?: string | null
  onLock: () => void
  onUnlock: () => void
}

/**
 * Issue #407: lock/unlock control for a confirmed sales order.
 *
 * Server-side the lock is a hard block: `PUT /sales/orders/{id}` answers 403
 * for every change until the order is unlocked. This component is the page's
 * only affordance for that state — a read-only lock badge plus, for users with
 * `sales.write`, the matching lock/unlock action.
 */
export function SalesOrderLockControl({
  isLocked,
  canWrite,
  isPending = false,
  lockedAt,
  onLock,
  onUnlock,
}: SalesOrderLockControlProps) {
  if (!isLocked && !canWrite) return null

  return (
    <div className="flex items-center gap-2" data-testid="sales-order-lock-control">
      {isLocked && (
        <Badge
          variant="outline"
          data-testid="sales-order-lock-badge"
          className="gap-1.5 border-amber-500/60 text-amber-600"
          title={
            lockedAt
              ? `Locked on ${formatDate(lockedAt)} — unlock the order to amend it`
              : 'This order is locked against amendments'
          }
        >
          <Icon icon="solar:lock-keyhole-minimalistic-bold-duotone" className="h-3.5 w-3.5" />
          Locked
        </Badge>
      )}
      {canWrite && (
        <HeaderActionButton
          variant="outline"
          icon={
            isLocked
              ? 'solar:lock-keyhole-unlocked-bold-duotone'
              : 'solar:lock-keyhole-minimalistic-bold-duotone'
          }
          loading={isPending}
          disabled={isPending}
          onClick={isLocked ? onUnlock : onLock}
        >
          {isLocked ? 'Unlock' : 'Lock'}
        </HeaderActionButton>
      )}
    </div>
  )
}
