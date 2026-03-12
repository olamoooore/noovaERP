import { Badge } from '@/components/ui/badge'
import type { WorkOrderStatus } from '@/lib/fieldServiceApi'

const config: Record<WorkOrderStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; className: string }> = {
  draft:       { label: 'Draft',       variant: 'secondary',    className: 'bg-gray-100 text-gray-700' },
  scheduled:   { label: 'Scheduled',   variant: 'outline',      className: 'bg-blue-50 text-blue-700 border-blue-200' },
  in_progress: { label: 'In Progress', variant: 'default',      className: 'bg-amber-50 text-amber-700 border-amber-200' },
  on_hold:     { label: 'On Hold',     variant: 'outline',      className: 'bg-orange-50 text-orange-700 border-orange-200' },
  completed:   { label: 'Completed',   variant: 'default',      className: 'bg-green-50 text-green-700 border-green-200' },
  invoiced:    { label: 'Invoiced',    variant: 'default',      className: 'bg-purple-50 text-purple-700 border-purple-200' },
  cancelled:   { label: 'Cancelled',   variant: 'destructive',  className: 'bg-red-50 text-red-700 border-red-200' },
}

interface Props {
  status: WorkOrderStatus
  size?: 'sm' | 'md'
}

export function WorkOrderStatusBadge({ status, size = 'md' }: Props) {
  const c = config[status] ?? config.draft
  return (
    <Badge variant={c.variant} className={`${c.className} border ${size === 'sm' ? 'text-xs px-1.5 py-0' : ''}`}>
      {c.label}
    </Badge>
  )
}
