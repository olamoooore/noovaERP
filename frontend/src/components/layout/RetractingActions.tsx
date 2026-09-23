import { type ReactNode } from 'react'
import { cn } from '@/lib/utils'

/**
 * Action bar used in list-page headers.
 *
 * Previously collapsed behind an "Actions" pill and revealed only on hover —
 * which confused users into thinking the actions weren't there. The buttons are
 * now always visible; this wrapper only provides consistent right-aligned,
 * evenly-spaced layout.
 */
export function RetractingActions({
  children,
  className,
}: {
  children: ReactNode
  label?: string
  className?: string
}) {
  return <div className={cn('flex items-center justify-end gap-2', className)}>{children}</div>
}
