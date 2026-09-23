import type { ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { LineItemsColumn } from './LineItemsTable'

/**
 * Canonical product-line presentation shared by every document editor
 * (invoice, quotation, delivery order …).
 *
 * Keeps one definition of the cell/input styling and the row actions so the
 * tables stop drifting apart. Rows are top-aligned and roomy enough for a
 * product name to wrap onto a second line.
 */

/**
 * Row rhythm: every cell content box is exactly `LINE_BOX_H` tall so the row
 * height never varies between columns — single-line fields sit vertically
 * centred inside the same box the text areas fill.
 */
export const LINE_BOX_H = 'h-11'

/**
 * Table cell padding + vertical alignment for every line row.
 *
 * NOTE: this is the class for the <td>. Never put `flex`/`h-*` on a <td> —
 * a flex cell is removed from the table layout algorithm and every column in
 * the row sizes unpredictably. Put the flex box on an inner <div> instead.
 */
export const LINE_CELL = 'px-2 py-2 align-top'

/** Narrow numeric / index cell. */
export const LINE_CELL_TIGHT = 'px-2 py-2 align-top text-right tabular-nums'

/**
 * Inner wrapper that vertically centres a single-line control inside the
 * standard box height. Always applied to a <div> inside the <td>.
 */
export const LINE_CELL_BOX = cn('flex items-center', LINE_BOX_H)

/** Standard editable field inside a line row. */
export const LINE_INPUT =
  'h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'

/** Right-aligned numeric input. */
export const LINE_INPUT_NUMERIC = cn(LINE_INPUT, 'text-right tabular-nums')

/**
 * Read-only mirror that WRAPS instead of truncating (SKU, Model #) — a
 * disabled <input> can never show a long value in full.
 */
export const LINE_VALUE = cn(
  'flex w-full items-start whitespace-normal break-words rounded-md border border-dashed border-input bg-muted/40 px-2.5 py-2 text-sm leading-snug text-muted-foreground',
  'min-h-11',
)

/** Multi-line field (description, product notes) — same height as the box. */
export const LINE_TEXTAREA = cn(
  LINE_INPUT,
  'h-auto min-h-11 resize-y py-2 leading-snug',
)

/** Wrapper for the product picker so its box matches the row height. */
export const LINE_PRODUCT_WRAP = 'min-h-11'

/**
 * Column definitions for the standard product-line table. Widths are
 * min-widths (never fixed) so optional columns degrade gracefully.
 */
export function productLineColumns(options: {
  sku?: boolean
  description?: boolean
  modelNumber?: boolean
  productNotes?: boolean
  lotNumber?: boolean
  expiryDate?: boolean
  taxRate?: boolean
} = {}): LineItemsColumn[] {
  return [
    { key: 'index', label: '#', align: 'center', minWidth: 'w-10', className: 'w-10' },
    { key: 'product', label: 'Product', minWidth: 'min-w-[17rem]' },
    { key: 'sku', label: 'SKU', minWidth: 'min-w-[7rem]', hidden: !options.sku },
    { key: 'description', label: 'Description', minWidth: 'min-w-[16rem]', hidden: !options.description },
    { key: 'model_number', label: 'Model #', minWidth: 'min-w-[7rem]', hidden: !options.modelNumber },
    { key: 'product_notes', label: 'Product Notes', minWidth: 'min-w-[10rem]', hidden: !options.productNotes },
    { key: 'lot_number', label: 'Lot Number', minWidth: 'min-w-[7.5rem]', hidden: !options.lotNumber },
    { key: 'expiry_date', label: 'Expiry Date', minWidth: 'min-w-[9.5rem]', hidden: !options.expiryDate },
    { key: 'qty', label: 'Qty', align: 'right', minWidth: 'min-w-[5.5rem]' },
    { key: 'unit_price', label: 'Unit Price', align: 'right', minWidth: 'min-w-[7rem]' },
    { key: 'tax_rate', label: 'Tax %', align: 'right', minWidth: 'min-w-[5rem]', hidden: !options.taxRate },
    { key: 'line_total', label: 'Line Total', align: 'right', minWidth: 'min-w-[7rem]' },
    { key: 'actions', label: '', align: 'right', minWidth: 'w-[10.5rem]', className: 'w-[10.5rem]' },
  ]
}

/** Compact icon-button used in the row action cluster. */
function RowAction({
  icon,
  title,
  onClick,
  disabled,
  destructive,
}: {
  icon: string
  title: string
  onClick: () => void
  disabled?: boolean
  destructive?: boolean
}) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      title={title}
      aria-label={title}
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'h-7 w-7 rounded-md text-muted-foreground transition-colors',
        destructive
          ? 'hover:bg-destructive/10 hover:text-destructive'
          : 'hover:bg-muted hover:text-foreground',
      )}
    >
      <Icon icon={icon} className="h-4 w-4" />
    </Button>
  )
}

/**
 * Row actions: reorder, duplicate, reset and delete.
 *
 * `reset` clears the row's contents in place — previously a single row could
 * not be cleared at all because delete was hidden when only one line existed.
 */
export function ProductLineActions({
  onMoveUp,
  onMoveDown,
  onDuplicate,
  onReset,
  onDelete,
  canMoveUp,
  canMoveDown,
  extra,
}: {
  onMoveUp?: () => void
  onMoveDown?: () => void
  onDuplicate?: () => void
  onReset?: () => void
  onDelete?: () => void
  canMoveUp?: boolean
  canMoveDown?: boolean
  extra?: ReactNode
}) {
  return (
    <div className="flex items-center justify-end gap-0.5">
      {onMoveUp && (
        <RowAction icon="solar:arrow-up-bold-duotone" title="Move up" onClick={onMoveUp} disabled={canMoveUp === false} />
      )}
      {onMoveDown && (
        <RowAction icon="solar:arrow-down-bold-duotone" title="Move down" onClick={onMoveDown} disabled={canMoveDown === false} />
      )}
      {onDuplicate && (
        <RowAction icon="solar:copy-bold-duotone" title="Duplicate line" onClick={onDuplicate} />
      )}
      {onReset && (
        <RowAction icon="solar:eraser-bold-duotone" title="Clear line" onClick={onReset} />
      )}
      {onDelete && (
        <RowAction icon="solar:trash-bin-trash-bold-duotone" title="Delete line" onClick={onDelete} destructive />
      )}
      {extra}
    </div>
  )
}
