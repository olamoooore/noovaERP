import type { ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

/**
 * Standard document/page header.
 *
 * Layout contract (applied everywhere for consistency):
 *   Back                       ← its own row, plain text button
 *   Title                      actions →
 *   Description
 *
 * Action buttons rendered in the `actions` slot are expected to use the
 * default Button height so every page's header row matches.
 */
export function PageHeader({
  title,
  description,
  actions,
  children,
  onBack,
  backTo,
  backLabel = 'Back',
  guardBack,
  className,
}: {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  children?: ReactNode
  onBack?: () => void
  backTo?: string
  backLabel?: string
  /**
   * #317: wraps the Back action, e.g. to confirm leaving a form with unsaved
   * changes. Receives the navigation to run once the user agrees.
   */
  guardBack?: (proceed: () => void) => void
  className?: string
}) {
  const navigate = useNavigate()
  const location = useLocation()

  /**
   * Back is context-aware, in priority order:
   *   1. explicit onBack handler
   *   2. the page we actually arrived from (`location.state.from`)
   *   3. browser history (returns to the exact previous screen)
   *   4. the list route this document belongs to
   *   5. home
   * So opening a product from a quotation and pressing back returns to that
   * quotation, not to the product list.
   */
  const goBack = () => {
    if (onBack) return onBack()

    const from = (location.state as { from?: string } | null)?.from
    if (from) return navigate(from)

    if (window.history.length > 1) return navigate(-1)
    if (backTo) return navigate(backTo)
    navigate('/')
  }

  return (
    <div className={cn('space-y-2', className)}>
      {/* Back — own row, no container chrome */}
      <button
        type="button"
        onClick={() => (guardBack ? guardBack(goBack) : goBack())}
        className="-ml-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <Icon icon="solar:alt-arrow-left-bold-duotone" className="h-4 w-4" />
        {backLabel}
      </button>

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
          {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
          {children}
        </div>

        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}
