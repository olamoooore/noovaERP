import type { ReactNode } from 'react'
import { Icon } from '@iconify/react'

import { TableHead } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import type { SortDirection } from '@/hooks/useSort'

interface SortableHeadProps {
  /** Column label (or any node). */
  label: ReactNode
  /** Key this column sorts by (dot paths allowed). */
  sortKey: string
  /** Currently active sort key, or null. */
  activeKey: string | null
  /** Current direction. */
  direction: SortDirection
  /** Called with the clicked column key. */
  onSort: (key: string) => void
  align?: 'left' | 'right' | 'center'
  className?: string
}

const ICON = {
  neutral: 'solar:sort-vertical-linear',
  asc: 'solar:sort-from-bottom-to-top-linear',
  desc: 'solar:sort-from-top-to-bottom-linear',
} as const

/**
 * A table header cell that sorts the column on click — the app's standard
 * sortable header (label + direction icon). Pair with `useSort`.
 */
export function SortableHead({
  label,
  sortKey,
  activeKey,
  direction,
  onSort,
  align = 'left',
  className,
}: SortableHeadProps) {
  const active = activeKey === sortKey

  return (
    <TableHead
      className={cn(
        align === 'right' && 'text-right',
        align === 'center' && 'text-center',
        className,
      )}
      aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}
    >
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className={cn(
          'flex w-full items-center gap-2 font-medium transition-colors hover:text-primary',
          align === 'right' ? 'justify-end' : align === 'center' ? 'justify-center' : 'justify-start',
          active && 'text-primary',
        )}
      >
        {label}
        <Icon
          icon={active ? ICON[direction] : ICON.neutral}
          className={cn('h-4 w-4 shrink-0', active ? 'text-primary' : 'text-gray-400')}
        />
      </button>
    </TableHead>
  )
}

export default SortableHead
