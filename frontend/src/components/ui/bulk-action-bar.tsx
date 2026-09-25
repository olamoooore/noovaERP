import type { ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { BulkSelectionNotice } from '@/components/ui/bulk-selection-notice'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { cn } from '@/lib/utils'

interface BulkActionBarProps {
  selectedCount: number
  pageSize: number
  allPageSelected: boolean
  allMatchingSelected: boolean
  isSelectingAll: boolean
  onSelectAllMatching: () => void
  onClearSelection: () => void
  /** Plural noun used by the selection notice, e.g. "invoices". */
  noun: string
  /** Optional muted clarification (e.g. "3 posted/locked will be skipped"). */
  hint?: ReactNode
  /** Page actions (Bulk Post, …), rendered before the destructive action. */
  children?: ReactNode
  /** Destructive action (bulk delete) — always rendered last. */
  destructiveAction?: ReactNode
  className?: string
}

/**
 * The canonical bulk-selection action bar.
 *
 * One tinted pill bar with the selection notice on the left and the action
 * row on the right ([Clear selection] → page actions → destructive last), so
 * every list page offers bulk actions in the same place with the same style.
 */
export function BulkActionBar({
  selectedCount,
  pageSize,
  allPageSelected,
  allMatchingSelected,
  isSelectingAll,
  onSelectAllMatching,
  onClearSelection,
  noun,
  hint,
  children,
  destructiveAction,
  className,
}: BulkActionBarProps) {
  if (selectedCount === 0) return null

  return (
    <div
      className={cn(
        'flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/20 bg-primary/5 px-4 py-2.5',
        className,
      )}
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium text-primary">
        <Icon icon="solar:checklist-minimalistic-bold-duotone" className="h-4 w-4 shrink-0" />
        <BulkSelectionNotice
          pageSize={pageSize}
          allPageSelected={allPageSelected}
          selectedCount={selectedCount}
          allMatchingSelected={allMatchingSelected}
          isSelectingAll={isSelectingAll}
          onSelectAllMatching={onSelectAllMatching}
          noun={noun}
        />
        {hint && <span className="text-xs font-normal text-muted-foreground">{hint}</span>}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <HeaderActionButton variant="ghost" onClick={onClearSelection}>
          Clear selection
        </HeaderActionButton>
        {children}
        {destructiveAction}
      </div>
    </div>
  )
}
