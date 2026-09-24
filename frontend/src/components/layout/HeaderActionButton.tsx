import { forwardRef, type ComponentProps, type ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * The canonical header action button.
 *
 * Every page header uses this so the action row is visually identical
 * everywhere: compact pill (h-8 / rounded-full), 12px label, 14px icon.
 * Pass `icon` for the leading solar icon and any Button props through.
 *
 * Forwards its ref (and therefore spreads props) onto the underlying Button,
 * which is required when it is used as a Radix `asChild` trigger
 * (DropdownMenuTrigger / TooltipTrigger / PopoverTrigger): without the ref the
 * trigger never binds and clicking the button silently does nothing.
 */
export const HeaderActionButton = forwardRef<HTMLButtonElement, ComponentProps<typeof Button> & {
  icon?: string
  children?: ReactNode
  loading?: boolean
}>(function HeaderActionButton(
  { icon, children, className, loading, ...props },
  ref,
) {
  return (
    <Button
      ref={ref}
      size="sm"
      className={cn('h-8 rounded-full px-3 text-xs', className)}
      {...props}
    >
      {loading ? (
        <span className="mr-1.5 h-3.5 w-3.5 animate-spin rounded-full border-b-2 border-current" />
      ) : icon ? (
        <Icon icon={icon} className="mr-1.5 h-3.5 w-3.5" />
      ) : null}
      {children}
    </Button>
  )
})
