import { Icon } from '@iconify/react'
import type { WorkOrderPriority } from '@/lib/fieldServiceApi'

const config: Record<WorkOrderPriority, { icon: string; color: string; label: string }> = {
  low:    { icon: 'solar:arrow-down-bold',        color: 'text-slate-400',  label: 'Low' },
  normal: { icon: 'solar:arrow-right-bold',       color: 'text-blue-500',   label: 'Normal' },
  high:   { icon: 'solar:arrow-up-bold',          color: 'text-amber-500',  label: 'High' },
  urgent: { icon: 'solar:danger-triangle-bold',   color: 'text-red-500',    label: 'Urgent' },
}

interface Props {
  priority: WorkOrderPriority
  showLabel?: boolean
  size?: number
}

export function WorkOrderPriorityIcon({ priority, showLabel = false, size = 16 }: Props) {
  const c = config[priority] ?? config.normal
  return (
    <span className={`inline-flex items-center gap-1 ${c.color}`} title={c.label}>
      <Icon icon={c.icon} width={size} height={size} />
      {showLabel && <span className="text-xs font-medium">{c.label}</span>}
    </span>
  )
}
