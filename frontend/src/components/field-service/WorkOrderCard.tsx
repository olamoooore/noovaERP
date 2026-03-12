import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { WorkOrderStatusBadge } from './WorkOrderStatusBadge'
import { WorkOrderPriorityIcon } from './WorkOrderPriorityIcon'
import type { WorkOrder } from '@/lib/fieldServiceApi'

interface Props {
  workOrder: WorkOrder
}

export function WorkOrderCard({ workOrder }: Props) {
  const scheduled = workOrder.scheduled_start
    ? new Date(workOrder.scheduled_start).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    : null

  return (
    <Link to={`/field-service/work-orders/${workOrder.id}`}>
      <Card className="hover:shadow-md transition-shadow cursor-pointer border border-border/60">
        <CardContent className="p-4 space-y-2">
          <div className="flex items-start justify-between gap-2">
            <div className="flex items-center gap-1.5 min-w-0">
              <WorkOrderPriorityIcon priority={workOrder.priority} size={14} />
              <span className="text-xs text-muted-foreground font-mono">{workOrder.reference}</span>
            </div>
            <WorkOrderStatusBadge status={workOrder.status} size="sm" />
          </div>

          <p className="font-medium text-sm leading-snug line-clamp-2">{workOrder.title}</p>

          {workOrder.customer && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <Icon icon="solar:user-bold-duotone" width={13} />
              <span className="truncate">{workOrder.customer.name}</span>
            </div>
          )}

          {workOrder.location_address && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <Icon icon="solar:map-point-bold-duotone" width={13} />
              <span className="truncate">{workOrder.location_address}</span>
            </div>
          )}

          {scheduled && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <Icon icon="solar:calendar-bold-duotone" width={13} />
              <span>{scheduled}</span>
            </div>
          )}

          {workOrder.technicians && workOrder.technicians.length > 0 && (
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <Icon icon="solar:users-group-two-rounded-bold-duotone" width={13} />
              <span>{workOrder.technicians.map(t => t.user?.name).filter(Boolean).join(', ')}</span>
            </div>
          )}
        </CardContent>
      </Card>
    </Link>
  )
}
