import React from 'react'
import { cn } from '@/lib/utils'
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table'

/**
 * Issue #183: the line-items table markup (header row + per-column min-widths)
 * was duplicated across CreateInvoicePage, QuotationCreatePage,
 * DeliveryOrderCreatePage and DeliveryOrderEditPage with slightly different
 * widths in each — which is how the truncation/wrapping defects in #181
 * drifted in. This shared shell owns the header/column-width definition; the
 * pages keep their page-specific row renderers as children.
 *
 * Columns size to their content via min-w-* + whitespace-nowrap (never fixed
 * w-* widths), so toggling optional columns on degrades gracefully.
 */
export interface LineItemsColumn {
  key: string
  label?: React.ReactNode
  align?: 'left' | 'right' | 'center'
  /** Tailwind min-width class, e.g. 'min-w-[18rem]'. */
  minWidth?: string
  /** Extra classes for the header cell. */
  className?: string
  hidden?: boolean
}

interface LineItemsTableProps {
  columns: LineItemsColumn[]
  children: React.ReactNode
  /**
   * 'raw' renders a plain <table> (used by the invoice/quotation pages with
   * their own table-fixed/min-w shells); 'shadcn' renders the design-system
   * Table primitives (delivery-order pages).
   */
  variant?: 'raw' | 'shadcn'
  tableClassName?: string
  headRowClassName?: string
}

function headerCellClassName(column: LineItemsColumn): string {
  const align =
    column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left'

  return cn('px-2 py-2 whitespace-nowrap', align, column.minWidth, column.className)
}

export function LineItemsTable({
  columns,
  children,
  variant = 'raw',
  tableClassName,
  headRowClassName,
}: LineItemsTableProps) {
  const visibleColumns = columns.filter((column) => !column.hidden)

  if (variant === 'shadcn') {
    return (
      <Table className={tableClassName}>
        <TableHeader>
          <TableRow className={headRowClassName}>
            {visibleColumns.map((column) => (
              <TableHead key={column.key} className={headerCellClassName(column)}>
                {column.label}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>{children}</TableBody>
      </Table>
    )
  }

  return (
    <table className={cn('w-full text-sm', tableClassName)}>
      <thead>
        <tr className={headRowClassName}>
          {visibleColumns.map((column) => (
            <th key={column.key} className={headerCellClassName(column)}>
              {column.label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>{children}</tbody>
    </table>
  )
}
