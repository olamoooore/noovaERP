import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatDate } from '@/lib/utils'
import { cn } from '@/lib/utils'

export interface StockMoveProduct {
  id: number
  name: string
  sku?: string | null
}

export interface StockMoveWarehouse {
  id: number
  name?: string | null
  code?: string | null
}

export interface StockMoveSourceDocument {
  type: string | null
  id: number | null
  number: string | null
}

export interface StockMovementRow {
  id: number
  product_id: number
  type: string
  direction: 'in' | 'out'
  quantity: number
  product: StockMoveProduct | null
  warehouse: StockMoveWarehouse | null
  lot_number: string | null
  batch_number: string | null
  expiry_date: string | null
  serial_numbers: string[]
  date: string | null
  reference: string | null
  notes: string | null
  source_document: StockMoveSourceDocument
  created_by: { id: number; name: string } | null
  reverses_movement_id: number | null
  reversed_by_movement_id: number | null
}

export interface StockMoveLineComponent {
  product_id: number
  name: string
  sku?: string | null
  required_quantity: number
  net_issued_quantity: number
}

export interface StockMoveLineRow {
  line_id: number
  product_id: number
  product: StockMoveProduct | null
  description: string | null
  sold_quantity: number
  required_stock_quantity: number
  net_issued_quantity: number
  stock_tracked: boolean
  mismatch: boolean
  components: StockMoveLineComponent[]
}

export interface StockMovesPayload {
  document: { type: string; id: number; number: string | null; label: string }
  sales_order_id: number | null
  has_mismatch: boolean
  movements: StockMovementRow[]
  lines: StockMoveLineRow[]
}

export interface StockMovesTabProps {
  /** Read-only stock-moves endpoint for this document, e.g. `/invoices/5/stock-moves`. */
  endpoint: string
  /** Permission that opens the record; the tab double-checks it like AuditTab. */
  permission?: string
  title?: string
  className?: string
}

function formatQuantity(value: number | string | null | undefined): string {
  const numeric = Number(value ?? 0)
  if (!Number.isFinite(numeric)) return '0'
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(numeric)
}

function sourceDocumentLabel(source: StockMoveSourceDocument): string {
  if (!source.type && !source.number) return '—'
  const type = (source.type ?? '').replace(/([a-z])([A-Z])/g, '$1 $2')
  if (source.number && type) return `${type} ${source.number}`
  return source.number ?? type
}

/**
 * Issue #690 (SALES-INV / INV-UX4): the shared "Stock moves" tab used by the
 * quotation, sales order, delivery order, invoice and credit note detail
 * pages. It renders the document's warehouse trail — every movement with its
 * lot/serial, warehouse, date and source document, sale and reversal linked
 * to each other — plus the net issued quantity per line and a mismatch flag
 * when that net differs from the quantity the document sold.
 */
export function StockMovesTab({ endpoint, permission, title = 'Stock moves', className }: StockMovesTabProps) {
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const allowed = !permission || hasPermission(permission)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['document-stock-moves', endpoint],
    queryFn: async () => {
      const response = await api.get(endpoint)
      return response.data.data as StockMovesPayload
    },
    enabled: allowed,
  })

  if (!allowed) {
    return null
  }

  const movements = data?.movements ?? []
  const lines = data?.lines ?? []
  const hasStockTrackedLines = lines.some((line) => line.stock_tracked)

  return (
    <section data-testid="stock-moves-tab" className={cn('space-y-4', className)}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-primary" />
          <h2 className="text-base font-semibold">{title}</h2>
          <span className="text-xs text-muted-foreground">
            Read-only · every warehouse movement linked to this document, reversals included
          </span>
        </div>

        {data && hasStockTrackedLines && (
          <span
            data-testid={data.has_mismatch ? 'stock-moves-mismatch' : 'stock-moves-balanced'}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
              data.has_mismatch
                ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
            )}
          >
            <Icon
              icon={
                data.has_mismatch
                  ? 'solar:danger-triangle-bold-duotone'
                  : 'solar:check-circle-bold-duotone'
              }
              className="h-3.5 w-3.5"
            />
            {data.has_mismatch ? 'Net issued ≠ sold' : 'Net issued = sold'}
          </span>
        )}
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-10 text-sm text-muted-foreground">
          <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4 animate-spin" />
          Loading stock movements…
        </div>
      ) : isError ? (
        <div className="rounded-md border border-destructive/30 bg-destructive/5 px-4 py-6 text-sm text-destructive">
          Could not load the stock moves for this document.
        </div>
      ) : movements.length === 0 ? (
        <div className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
          No stock movements recorded for this document yet.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-36">Date</TableHead>
                <TableHead className="w-28">Type</TableHead>
                <TableHead>Product</TableHead>
                <TableHead className="text-right">Quantity</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Lot / serial</TableHead>
                <TableHead>Source document</TableHead>
                <TableHead className="w-32">Reversal</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {movements.map((movement) => (
                <TableRow key={movement.id} data-testid="stock-move-row">
                  <TableCell className="whitespace-nowrap text-xs text-muted-foreground">
                    {formatDate(movement.date)}
                  </TableCell>
                  <TableCell>
                    <span
                      className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                        movement.direction === 'in'
                          ? 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'
                          : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
                      )}
                    >
                      <Icon
                        icon={
                          movement.direction === 'in'
                            ? 'solar:arrow-down-bold'
                            : 'solar:arrow-up-bold'
                        }
                        className="h-3 w-3"
                      />
                      {movement.type}
                    </span>
                  </TableCell>
                  <TableCell className="text-sm">
                    <span className="font-medium">{movement.product?.name ?? '—'}</span>
                    {movement.product?.sku && (
                      <span className="ml-1 text-xs text-muted-foreground">{movement.product.sku}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right text-sm tabular-nums">
                    {formatQuantity(movement.quantity)}
                  </TableCell>
                  <TableCell className="text-sm">{movement.warehouse?.name ?? '—'}</TableCell>
                  <TableCell className="text-sm">
                    <span>{movement.lot_number ?? '—'}</span>
                    {movement.serial_numbers.length > 0 && (
                      <span className="ml-1 text-xs text-muted-foreground">
                        {movement.serial_numbers.join(', ')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="text-sm">{sourceDocumentLabel(movement.source_document)}</TableCell>
                  <TableCell className="text-xs">
                    {movement.reversed_by_movement_id ? (
                      <span className="text-amber-700 dark:text-amber-400">
                        Reversed by #{movement.reversed_by_movement_id}
                      </span>
                    ) : movement.reverses_movement_id ? (
                      <span className="text-sky-700 dark:text-sky-400">
                        Reverses #{movement.reverses_movement_id}
                      </span>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {lines.length > 0 && (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Line</TableHead>
                <TableHead className="text-right">Sold</TableHead>
                <TableHead className="text-right">Net issued</TableHead>
                <TableHead>Components</TableHead>
                <TableHead className="w-32">Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line) => (
                <TableRow key={line.line_id} data-testid="stock-move-line-row">
                  <TableCell className="text-sm">
                    <span className="font-medium">{line.product?.name ?? '—'}</span>
                    {line.product?.sku && (
                      <span className="ml-1 text-xs text-muted-foreground">{line.product.sku}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-right text-sm tabular-nums">
                    {formatQuantity(line.sold_quantity)}
                  </TableCell>
                  <TableCell className="text-right text-sm tabular-nums">
                    {line.stock_tracked ? formatQuantity(line.net_issued_quantity) : '—'}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">
                    {line.components.length > 1
                      ? line.components
                          .map(
                            (component) =>
                              `${component.name} ×${formatQuantity(component.required_quantity)}`,
                          )
                          .join(', ')
                      : '—'}
                  </TableCell>
                  <TableCell>
                    {!line.stock_tracked ? (
                      <span className="text-xs text-muted-foreground">Not stock-tracked</span>
                    ) : line.mismatch ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                        <Icon icon="solar:danger-triangle-bold" className="h-3 w-3" />
                        Mismatch
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                        <Icon icon="solar:check-circle-bold" className="h-3 w-3" />
                        Matches
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </section>
  )
}

export default StockMovesTab
