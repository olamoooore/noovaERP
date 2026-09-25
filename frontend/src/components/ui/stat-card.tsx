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
      <CardContent className="flex items-center justify-between gap-4 p-5">
        <div className="min-w-0">
          <p className="text-sm text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-bold tabular-nums">{value}</p>
          {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </div>
        <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', tone)}>
          <Icon icon={icon} className="h-5 w-5" />
        </div>
      </CardContent>
    </Card>
  )
}
