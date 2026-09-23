import { useState, type ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

/**
 * Retracting action bar used in every list-page header.
 *
 * Collapsed to a compact "Actions" pill; the buttons slide out on hover or
 * keyboard focus and stay open when the pill is clicked (touch / sticky use).
 * Wrap the real buttons as children — they keep their own variant/size.
 */
export function RetractingActions({
  children,
  label = 'Actions',
  className,
}: {
  children: ReactNode
  label?: string
  className?: string
}) {
  const [pinned, setPinned] = useState(false)

  return (
    <div className={cn('group/actions flex items-center justify-end', className)}>
      <div
        className={cn(
          'flex items-center gap-2 overflow-hidden transition-all duration-300 ease-out',
          pinned ? 'max-w-[720px] translate-x-0 opacity-100' : 'max-w-0 translate-x-3 opacity-0',
          !pinned &&
            'group-hover/actions:max-w-[720px] group-hover/actions:translate-x-0 group-hover/actions:opacity-100',
          !pinned &&
            'group-focus-within/actions:max-w-[720px] group-focus-within/actions:translate-x-0 group-focus-within/actions:opacity-100',
        )}
      >
        {children}
      </div>

      <button
        type="button"
        onClick={() => setPinned((v) => !v)}
        aria-expanded={pinned}
        title={pinned ? 'Collapse actions' : 'Show actions'}
        className={cn(
          'ml-2 inline-flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-full px-3 text-xs font-medium transition-colors',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
          pinned
            ? 'bg-primary text-primary-foreground hover:bg-primary/90'
            : 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
        )}
      >
        <Icon
          icon={pinned ? 'solar:close-circle-bold-duotone' : 'solar:bolt-circle-bold-duotone'}
          className="h-3.5 w-3.5"
        />
        {label}
      </button>
    </div>
  )
}
