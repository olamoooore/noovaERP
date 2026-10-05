import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'

export type StockCogsDocumentType = 'sales_order' | 'delivery_order' | 'invoice'

export interface StockCogsMovement {
  id: number
  type: string
  direction: 'in' | 'out'
  quantity: number
  date: string | null
  product: { id: number; name: string; sku?: string | null } | null
  source_document: { type: string | null; id: number | null; number: string | null }
}

export interface StockCogsStockOffender {
  kind: string
  product_id: number
  product: { id: number; name: string; sku?: string | null } | null
  issued: number
  expected: number
  excess: number
  movements: StockCogsMovement[]
}

export interface StockCogsCogsOffender {
  kind: string
  source_document: { type: string | null; id: number | null; number: string | null }
  net_issued: number
  expected_postings: number
  posted_count: number
  journal_entry_ids: number[]
  message: string
}

export interface StockCogsBadgePayload {
  document: { type: string; id: number; number: string | null; label: string }
  has_mismatch: boolean
  stock: { ok: boolean; issued: number; sold: number; offenders: StockCogsStockOffender[] }
  cogs: { ok: boolean; posted_count: number; offenders: StockCogsCogsOffender[] }
}

export interface StockCogsBadgeProps {
  documentType: StockCogsDocumentType
  documentId: number
  /** Permission that opens the record; the badge double-checks it. */
  permission?: string
  className?: string
}

const ENDPOINTS: Record<StockCogsDocumentType, (id: number) => string> = {
  sales_order: (id) => `/sales/orders/${id}/stock-cogs-badge`,
  delivery_order: (id) => `/sales/delivery-orders/${id}/stock-cogs-badge`,
  invoice: (id) => `/invoices/${id}/stock-cogs-badge`,
}

/**
 * Where an offending movement's source document lives, so the accountant can
 * open it and use its existing correction path (delivery reverse,
 * invoice reset-to-draft) — the badge links, it never corrects.
 */
const DOCUMENT_ROUTES: Record<string, (id: number) => string> = {
  DeliveryOrder: (id) => `/delivery-orders/${id}`,
  Invoice: (id) => `/invoices/${id}`,
  SalesOrder: (id) => `/sales/orders/${id}`,
}

function formatQuantity(value: number | string | null | undefined): string {
  const numeric = Number(value ?? 0)
  if (!Number.isFinite(numeric)) return '0'
  return new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(numeric)
}

function documentLink(source: { type: string | null; id: number | null; number: string | null }): string | null {
  if (!source.type || source.id == null) return null
  const route = DOCUMENT_ROUTES[source.type]
  return route ? route(source.id) : null
}

function cogsLabel(postedCount: number): string {
  if (postedCount === 1) return 'posted once'
  if (postedCount === 0) return 'no posting needed'
  return `posted ${postedCount}×`
}

/**
 * Issue #696 (SALES-DIFF / INV-D3): the stock & COGS reconciliation badge for
 * the sales-order, delivery-order and invoice headers.
 *
 * The payload comes from the shared {@link StockCogsBadgePayload} endpoint,
 * which reconciles the #690 movement trail against the document's COGS
 * journal links. Green means "goods issued once and COGS posted once"; a
 * mismatch renders red and opens a popover listing the offending movements
 * and the existing correction path an accountant can use.
 */
export function StockCogsBadge({ documentType, documentId, permission, className }: StockCogsBadgeProps) {
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const allowed = !permission || hasPermission(permission)

  const endpoint = ENDPOINTS[documentType](documentId)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['stock-cogs-badge', endpoint],
    queryFn: async () => {
      const response = await api.get(endpoint)
      return response.data.data as StockCogsBadgePayload
    },
    enabled: allowed,
  })

  if (!allowed) {
    return null
  }

  if (isLoading) {
    return (
      <span
        data-testid="stock-cogs-badge-loading"
        className={cn('inline-flex items-center gap-1.5 text-xs text-muted-foreground', className)}
      >
        <Icon icon="eos-icons:loading" className="h-3.5 w-3.5 animate-spin" />
        Reconciling stock & COGS…
      </span>
    )
  }

  if (isError || !data?.stock || !data?.cogs) {
    return (
      <span
        data-testid="stock-cogs-badge-unavailable"
        className={cn(
          'inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground',
          className,
        )}
        title="The stock & COGS reconciliation could not be loaded for this document."
      >
        <Icon icon="solar:danger-triangle-bold-duotone" className="h-3.5 w-3.5" />
        Stock & COGS check unavailable
      </span>
    )
  }

  const ok = !data.has_mismatch
  const stockOffenders = data.stock.offenders ?? []
  const cogsOffenders = data.cogs.offenders ?? []

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          data-testid={ok ? 'stock-cogs-badge-ok' : 'stock-cogs-badge-mismatch'}
          aria-label={ok ? 'Stock and COGS reconciled' : 'Stock and COGS mismatch — view offenders'}
          className={cn(
            'inline-flex flex-wrap items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
            ok
              ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
              : 'bg-red-100 text-red-800 hover:bg-red-200 dark:bg-red-950/60 dark:text-red-300',
            className,
          )}
        >
          <Icon
            icon={ok ? 'solar:check-circle-bold-duotone' : 'solar:danger-triangle-bold-duotone'}
            className="h-3.5 w-3.5"
          />
          <span>
            Stock {ok || data.stock.ok ? '✓' : '✕'} ({formatQuantity(data.stock.issued)} issued /{' '}
            {formatQuantity(data.stock.sold)} sold)
          </span>
          <span aria-hidden>·</span>
          <span>
            COGS {data.cogs.ok ? '✓' : '✕'} ({cogsLabel(data.cogs.posted_count)})
          </span>
        </button>
      </PopoverTrigger>

      {!ok && (
        <PopoverContent align="start" className="w-[26rem] space-y-3" data-testid="stock-cogs-badge-popover">
          <div className="flex items-start gap-2">
            <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-4 w-4 text-red-600" />
            <div>
              <p className="text-sm font-semibold">Stock & COGS mismatch</p>
              <p className="text-xs text-muted-foreground">
                The warehouse movements and journal postings for {data.document.label}{' '}
                {data.document.number ?? `#${data.document.id}`} disagree. Review the movements below,
                then use the document's existing correction path.
              </p>
            </div>
          </div>

          {stockOffenders.map((offender) => (
            <div key={`stock-${offender.product_id}`} className="rounded-md border p-2.5">
              <p className="text-xs font-semibold text-red-700 dark:text-red-400">
                Stock over-issued: {offender.product?.name ?? `product #${offender.product_id}`} by{' '}
                {formatQuantity(offender.excess)}
              </p>
              <p className="mt-0.5 text-[11px] text-muted-foreground">
                {formatQuantity(offender.issued)} issued / {formatQuantity(offender.expected)} expected
              </p>
              <ul className="mt-2 space-y-1">
                {offender.movements.map((movement) => {
                  const route = documentLink(movement.source_document)
                  const label = `${movement.source_document.type ?? 'Document'} ${
                    movement.source_document.number ?? `#${movement.source_document.id ?? ''}`
                  }`
                  return (
                    <li
                      key={`movement-${movement.id}`}
                      data-testid="stock-cogs-offending-movement"
                      className="flex items-center justify-between gap-2 text-[11px]"
                    >
                      <span className="text-muted-foreground">
                        {movement.type} · {formatQuantity(movement.quantity)}
                      </span>
                      {route ? (
                        <Link to={route} className="font-medium text-primary hover:underline">
                          {label}
                        </Link>
                      ) : (
                        <span className="font-medium">{label}</span>
                      )}
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}

          {cogsOffenders.map((offender, index) => {
            const route = documentLink(offender.source_document)
            const label = `${offender.source_document.type ?? 'Document'} ${
              offender.source_document.number ?? `#${offender.source_document.id ?? ''}`
            }`
            return (
              <div key={`cogs-${index}`} className="rounded-md border p-2.5" data-testid="stock-cogs-cogs-offender">
                <p className="text-xs font-semibold text-red-700 dark:text-red-400">{offender.message}</p>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                  {label} · {formatQuantity(offender.net_issued)} net issued · {offender.posted_count}{' '}
                  posting(s) (expected {offender.expected_postings})
                </p>
                {route && (
                  <Link to={route} className="mt-1 inline-block text-[11px] font-medium text-primary hover:underline">
                    Open {label}
                  </Link>
                )}
              </div>
            )
          })}

          <p className="rounded-md bg-muted px-2.5 py-2 text-[11px] text-muted-foreground">
            Fix: reverse the duplicate delivery issue from the delivery order, or reset the mis-posted
            invoice to draft (reverses its COGS and restocks). Both are the existing, audited paths —
            this badge never posts a correction itself.
          </p>
        </PopoverContent>
      )}
    </Popover>
  )
}

export default StockCogsBadge
