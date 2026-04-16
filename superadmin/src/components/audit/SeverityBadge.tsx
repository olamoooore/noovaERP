import type { AuditSeverity } from '@/types/admin'

const STYLES: Record<AuditSeverity, string> = {
  info:     'bg-gray-100 text-gray-600',
  warning:  'bg-amber-100 text-amber-800',
  critical: 'bg-red-100 text-red-700',
}

const ICONS: Record<AuditSeverity, string> = {
  info:     '○',
  warning:  '⚠',
  critical: '✕',
}

interface SeverityBadgeProps {
  severity: AuditSeverity
  showLabel?: boolean
}

export function SeverityBadge({ severity, showLabel = true }: SeverityBadgeProps) {
  return (
    <span
      className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ${STYLES[severity]}`}
      title={severity}
    >
      <span>{ICONS[severity]}</span>
      {showLabel && <span className="capitalize">{severity}</span>}
    </span>
  )
}
