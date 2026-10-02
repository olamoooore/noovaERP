import type { ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { cn } from '@/lib/utils'

interface StatCardProps {
  label: string
  value: ReactNode
  icon: string
  /** Icon tile classes, e.g. `bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400`. */
  tone?: string
  hint?: string
  onClick?: () => void
  className?: string
}

/**
 * The app's standard list-page metric card: rounded-xl card, tinted icon tile
 * and a tabular value. Used for summary strips at the top of list pages.
 */
export function StatCard({
  label,
  value,
  icon,
  tone = 'bg-primary/10 text-primary',
  hint,
  onClick,
  className,
}: StatCardProps) {
  return (
    <Card
      className={cn(
        'rounded-xl border-slate-200/80 dark:border-slate-800',
        onClick && 'cursor-pointer transition-all hover:-translate-y-0.5 hover:shadow-md',
        className,
      )}
      onClick={onClick}
    >
      <CardContent className="overflow-hidden p-4 sm:p-5">
        {/* Icon sits on the LABEL row: the value then owns the full card
            width, so long amounts (S$17,985.00) stay on one line instead of
            painting over the icon tile (report 2026-10-01). */}
        <div className="flex items-start justify-between gap-3">
          <p className="min-w-0 text-xs text-muted-foreground sm:text-sm">{label}</p>
          <div className={cn('-mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', tone)}>
            <Icon icon={icon} className="h-4 w-4" />
          </div>
        </div>
        {/* Smaller value type keeps amounts like S$24,525.00 on ONE line in
            the narrower five-column strip. */}
        <p className="mt-1 text-base font-bold leading-tight tabular-nums sm:text-lg">{value}</p>
        {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}
