import { useRef, useState, type ReactNode } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { TagEditor } from '@/components/tags/TagEditor'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { formatCurrency, formatDate } from '@/lib/utils'
import { Progress } from '@/components/ui/progress'
import { ChatterDialog } from '@/components/chatter'
import { SalesOrderTimeline, type SalesOrderStatusHistoryEntry } from '@/components/sales/SalesOrderTimeline'
import { AuditTab } from '@/components/audit/AuditTab'
import { StockMovesTab } from '@/components/inventory/StockMovesTab'
import { StockCogsBadge } from '@/components/inventory/StockCogsBadge'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { fetchBulkStockAvailability } from '@/components/sales/stockAvailability'
import { StockShortageDialog, type ShortageLine } from '@/components/sales/StockShortageDialog'
import { EditingBanner } from '@/components/sales/EditingBanner'
import { SalesOrderLockControl } from '@/components/sales/SalesOrderLockControl'
import { SaveConflictDialog } from '@/components/sales/SaveConflictDialog'
import { extractSaveConflict, type ActiveEditor, type SaveConflict } from '@/lib/recordConflict'
import { useAuthStore } from '@/store/authStore'
import {
  SALES_ORDER_DELIVERY_STATUS_META,
  getSalesOrderDeliveryStatus,
  getSalesOrderInvoiceStatusMeta,
  getSalesOrderStatusMeta,
} from '@/lib/salesOrderStatus'

interface AddressSnapshot {
  address?: string
  city?: string
  state?: string
  zip?: string
  country?: string
}

interface CreditPreview {
  approved: boolean
  reason: string
  creditLimit: number
  currentBalance: number
  availableCredit: number | null
  orderTotal: number
}

interface ApprovalRequestSummary {
  id: number
  status: string
  request_number?: string
  requested_at?: string
  reviewed_at?: string
}

interface SalesOrder {
  id: number
  sales_order_number: string
  customer_id: number
  // #402 (SU4): the order's commitment warehouse (name/code loaded by the API).
  warehouse_id?: number | null
  warehouse?: { id: number; name?: string; code?: string } | null
  // #402 (SU4): document currency + FX snapshot.
  currency_code?: string | null
  exchange_rate?: number | string | null
  base_currency_code?: string | null
  // #402 (SU4): origin of the order.
  channel?: string | null
  source_document_type?: string | null
  // #402 (SU4): address snapshots captured at creation time.
  billing_address_snapshot?: AddressSnapshot | null
  shipping_address_snapshot?: AddressSnapshot | null
  // #402 (SU4): approval-gate state for this order.
  latest_approval_request?: ApprovalRequestSummary | null
  has_backorder?: boolean
  customer?: {
    id: number
    name: string
    email?: string
    phone?: string
    code?: string
  }
  order_date: string
  // #401 (SU3): the date the customer needs the goods (Sales Order column).
  required_date?: string | null
  // Issue #408: promise dates + partial-delivery policy.
  commitment_date?: string | null
  expected_date?: string | null
  shipping_policy?: 'asap' | 'when_all_ready' | string
  is_late?: boolean
  // Issue #333: Incoterm code + place.
  incoterm_code?: string | null
  incoterm_place?: string | null
  status: string
  // Issue #407: a confirmed order can be locked against amendments.
  is_locked?: boolean
  locked_at?: string | null
  allowed_transitions?: string[]
  invoice_status?: string
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  // #401 (SU3): Sales Orders have no `terms` column, so the page no longer
  // declares or reads one.
  notes?: string
  customer_reference?: string
  internal_reference?: string
  created_at: string
  // Issue #896/#906: optimistic-concurrency token + who last saved the draft.
  lock_version?: number
  active_lock?: ActiveEditor | null
  lines: SalesOrderLine[]
  quotation?: { id: number; quotation_number: string }
  // #403 (SU5): total/balance/currency feed the invoice smart-button summary.
  invoices?: {
    id: number
    invoice_number: string
    status: string
    total?: number | string | null
    balance?: number | string | null
    currency_code?: string | null
  }[]
  delivery_orders?: { id: number; delivery_order_number: string; status: string }[]
  // #404 (SU6): eager-loaded by GET /sales/orders/{id} (statusHistory.actor).
  status_history?: SalesOrderStatusHistoryEntry[]
}

interface SalesOrderLine {
  id: number
  line_type?: string
  product_id: number
  product?: {
    id: number
    name: string
    sku: string
    manufacturer_part_number?: string
  }
  sku?: string
  model_number?: string
  description: string
  product_notes?: string
  quantity: number
  quantity_allocated?: number | string
  quantity_fulfilled?: number | string
  quantity_delivered?: number | string
  quantity_invoiced?: number | string
  quantity_cancelled?: number | string
  backorder_quantity?: number | string
  is_backordered?: boolean
  unit_price: number
  tax_rate: number
  tax_amount: number
  discount_amount: number
  line_total: number
}

type OrderAction = 'confirm' | 'allocate' | 'invoice' | 'reset-to-draft' | null

// #375 (O6): delivery orders are created from orders that are committed but
// not yet fully delivered. The backend (DeliveryOrderService) accepts
// confirmed / allocated / fulfilled / invoiced orders. `partially_delivered`
// is not a backend order status (#399); it is kept here only as a harmless
// legacy value.
const DELIVERY_ORDER_STATUSES = ['confirmed', 'allocated', 'partially_delivered', 'fulfilled', 'invoiced']

const pluralize = (count: number, noun: string) => `${count} ${noun}${count === 1 ? '' : 's'}`

/**
 * #375 (O6): an order is "Delivered" once every deliverable product line has
 * its ordered (minus cancelled) quantity fully delivered; otherwise there is
 * still something "To Deliver".
 */
function isFullyDelivered(lines: SalesOrderLine[]) {
  const deliverable = lines.filter(
    (line) =>
      (line.line_type ?? 'product') === 'product' &&
      Number(line.quantity) - Number(line.quantity_cancelled ?? 0) > 0,
  )

  return (
    deliverable.length > 0 &&
    deliverable.every(
      (line) =>
        Number(line.quantity_delivered ?? 0) + 0.0001 >=
        Number(line.quantity) - Number(line.quantity_cancelled ?? 0),
    )
  )
}

const quantityValue = (value: number | string | null | undefined) => {
  const parsed = Number(value ?? 0)
  return Number.isFinite(parsed) ? parsed : 0
}

/** #400 (SU2): show quantities without decimal-cast noise ("6.0000" -> "6"). */
const formatQuantity = (value: number | string | null | undefined) =>
  quantityValue(value).toLocaleString(undefined, { maximumFractionDigits: 4 })

/** #400 (SU2): still to ship = ordered - delivered - cancelled. */
function remainingToDeliver(line: SalesOrderLine): number {
  return Math.max(
    0,
    quantityValue(line.quantity) - quantityValue(line.quantity_delivered) - quantityValue(line.quantity_cancelled),
  )
}

/** #400 (SU2): still to bill = ordered - invoiced - cancelled. */
function remainingToInvoice(line: SalesOrderLine): number {
  return Math.max(
    0,
    quantityValue(line.quantity) - quantityValue(line.quantity_invoiced) - quantityValue(line.quantity_cancelled),
  )
}

/** #400 (SU2) / DX1: delivered share of the deliverable quantity (0-100). */
function deliveryProgress(line: SalesOrderLine): number {
  const deliverable = quantityValue(line.quantity) - quantityValue(line.quantity_cancelled)
  if (deliverable <= 0) return 0

  return Math.min(100, Math.max(0, Math.round((quantityValue(line.quantity_delivered) / deliverable) * 100)))
}

/** #400 (SU2) / #379: the line has stock that could not be reserved. */
function isBackordered(line: SalesOrderLine): boolean {
  return line.is_backordered === true || quantityValue(line.backorder_quantity) > 0
}

/** #402 (SU4): compact chip used by the order header. */
function OrderInfoChip({
  icon,
  title,
  className,
  children,
}: {
  icon: string
  title?: string
  className?: string
  children: ReactNode
}) {
  return (
    <Badge
      variant="outline"
      title={title}
      className={`gap-1.5 bg-muted/40 font-normal text-muted-foreground ${className ?? ''}`}
    >
      <Icon icon={icon} className="h-3.5 w-3.5" />
      {children}
    </Badge>
  )
}

/** #402 (SU4): keep the FX rate readable ("2.500000" -> "2.5"). */
function formatExchangeRate(rate: number | string | null | undefined): string {
  const parsed = Number(rate)
  if (!Number.isFinite(parsed) || parsed <= 0) return '1'

  return parsed.toLocaleString(undefined, { maximumFractionDigits: 6 })
}

/** #402 (SU4): snapshot arrays carry address/city/state/zip/country. */
function formatSnapshotAddress(snapshot: AddressSnapshot | null | undefined): string {
  if (!snapshot) return ''

  return [snapshot.address, snapshot.city, snapshot.state, snapshot.zip, snapshot.country]
    .filter(Boolean)
    .join(', ')
}

const CHANNEL_LABELS: Record<string, string> = {
  admin: 'Admin',
  pos: 'POS',
  ecommerce: 'E-commerce',
  storefront: 'Web',
}

const SOURCE_DOCUMENT_LABELS: Record<string, string> = {
  PosOrder: 'POS order',
  StorefrontOrder: 'Web order',
  Quotation: 'Quotation',
}

const APPROVAL_STATUS_LABELS: Record<string, string> = {
  pending: 'Approval pending',
  approved: 'Approved',
  rejected: 'Approval rejected',
  expired: 'Approval expired',
  withdrawn: 'Approval withdrawn',
}

const UNPAID_INVOICE_STATUSES = ['posted', 'sent', 'partial', 'overdue']

/**
 * #403 (SU5): one summary line per linked-document type ("1 unpaid · 1 draft
 * · $60.00 due", "1 pending") instead of one chip per document.
 */
function summarizeInvoices(invoices: NonNullable<SalesOrder['invoices']>, fallbackCurrency?: string) {
  const status = (invoice: { status: string }) => String(invoice.status || '').toLowerCase()
  const live = invoices.filter((invoice) => status(invoice) !== 'cancelled')
  const unpaid = live.filter(
    (invoice) => UNPAID_INVOICE_STATUSES.includes(status(invoice)) && (invoice.balance == null || Number(invoice.balance) > 0),
  )
  const drafts = live.filter((invoice) => status(invoice) === 'draft')
  const cancelled = invoices.length - live.length
  const parts: string[] = []
  if (unpaid.length > 0) parts.push(`${unpaid.length} unpaid`)
  if (drafts.length > 0) parts.push(`${drafts.length} draft`)
  if (cancelled > 0) parts.push(`${cancelled} cancelled`)
  const currencies = new Set(unpaid.map((invoice) => invoice.currency_code || fallbackCurrency || ''))
  const due = unpaid.reduce((sum, invoice) => sum + Number(invoice.balance ?? invoice.total ?? 0), 0)
  // Only total the balance when every unpaid invoice shares one currency.
  if (unpaid.length > 0 && currencies.size === 1) {
    const [currency] = [...currencies]
    parts.push(`${formatCurrency(due, currency || undefined)} due`)
  }
  return { live, summary: parts.join(' · ') }
}

function summarizeDeliveries(deliveries: NonNullable<SalesOrder['delivery_orders']>) {
  const status = (delivery: { status: string }) => String(delivery.status || '').toLowerCase()
  const live = deliveries.filter((delivery) => status(delivery) !== 'cancelled')
  const pending = live.filter((delivery) => status(delivery) !== 'done')
  const cancelled = deliveries.length - live.length
  const parts: string[] = []
  if (pending.length > 0) parts.push(`${pending.length} pending`)
  if (cancelled > 0) parts.push(`${cancelled} cancelled`)
  return { live, summary: parts.join(' · ') }
}

function getInvoiceStatusLabel(invoices: NonNullable<SalesOrder['invoices']>) {
  if (invoices.length === 0) return 'Not Invoiced'

  const statuses = invoices.map((invoice) => String(invoice.status || '').toLowerCase())
  if (statuses.every((status) => status === 'paid')) return 'Paid'
  if (statuses.some((status) => ['posted', 'sent', 'partial', 'overdue', 'paid'].includes(status))) {
    return 'Invoiced'
  }

  return 'Draft'
}

export function SalesOrderDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [chatterOpen, setChatterOpen] = useState(false)
  const [action, setAction] = useState<OrderAction>(null)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  // Issue #408: draft orders can edit the commitment date + shipping policy.
  const [promiseOpen, setPromiseOpen] = useState(false)
  const [commitmentDraft, setCommitmentDraft] = useState('')
  const [policyDraft, setPolicyDraft] = useState<'asap' | 'when_all_ready'>('asap')
  // Issue #687 (UX1): confirm preflights the order's lines against the shared
  // stock availability; when short, the explicit-choice dialog replaces the
  // silent backorder path (#379).
  const [shortageLines, setShortageLines] = useState<ShortageLine[] | null>(null)
  const [preflightPending, setPreflightPending] = useState(false)
  const confirmIntentRef = useRef<'backorder' | 'partial' | null>(null)
  // Issue #906: stale-save conflict state (dialog) + the payload to resend.
  const [conflict, setConflict] = useState<SaveConflict | null>(null)
  const lastPromisePayloadRef = useRef<Record<string, any> | null>(null)
  const currentUserId = useAuthStore((state) => state.user?.id)
  const canOverwrite = useAuthStore((state) => state.hasPermission('sales.write'))
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: order, isLoading, refetch: refetchOrder } = useQuery({
    queryKey: ['sales-order', id],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${id}`)
      return response.data as SalesOrder
    },
    enabled: !!id,
  })

  // #402 (SU4): the credit-preview endpoint already computes the customer's
  // exposure; the header reports it instead of re-deriving credit locally.
  const { data: creditPreview, isError: creditPreviewFailed } = useQuery({
    queryKey: ['sales-order-credit-preview', id],
    queryFn: async () => {
      const response = await api.get(`/sales/orders/${id}/credit-preview`)
      return response.data as CreditPreview
    },
    enabled: !!id,
  })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['sales-order', id] })
    queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
  }

  const actionMutation = useMutation({
    mutationFn: async (target: Exclude<OrderAction, null>) => {
      const response = await api.post(`/sales/orders/${id}/${target}`)
      return response.data
    },
    onSuccess: (data, target) => {
      refresh()
      setAction(null)
      if (target === 'invoice' && data?.invoice?.id) {
        toast({ title: 'Invoice created', description: data.invoice.invoice_number })
        navigate(`/invoices/${data.invoice.id}`)
        return
      }

      // Issue #687: the explicit choice made in the shortage dialog decides
      // what happens after the confirm lands.
      const intent = confirmIntentRef.current
      confirmIntentRef.current = null
      if (target === 'confirm' && intent) {
        if (data?.requires_approval) {
          toast({
            title: 'Approval required',
            description: data?.message || 'An approval request has been submitted for this order.',
          })
          return
        }
        if (intent === 'partial') {
          toast({
            title: 'Order confirmed',
            description: 'Create the delivery order for the available quantity; short lines stay backordered.',
          })
          navigate(`/delivery-orders/create?sales_order_id=${order?.id}`)
          return
        }
        toast({
          title: 'Order confirmed',
          description: 'Short lines continue as backorders until stock arrives.',
        })
        return
      }

      toast({ title: 'Success', description: data?.message || 'Order updated' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update order',
        variant: 'destructive',
      })
    },
  })

  // Issue #407: lock/unlock a confirmed order (Odoo parity). The lock is
  // enforced server-side; this only mirrors the resulting state.
  const lockMutation = useMutation({
    mutationFn: async (target: 'lock' | 'unlock') => {
      const response = await api.post(`/sales/orders/${id}/${target}`)
      return response.data
    },
    onSuccess: (data, target) => {
      refresh()
      toast({
        title: 'Success',
        description: data?.message || (target === 'lock' ? 'Order locked' : 'Order unlocked'),
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update the order lock',
        variant: 'destructive',
      })
    },
  })

  /**
   * Issue #687: before confirming, check whether every product line can be
   * met from the order's commitment warehouse. The preflight is advisory —
   * if the availability service is unreachable the user is told and the
   * confirm proceeds as before, it never silently blocks a confirmation.
   */
  const beginConfirm = async () => {
    const productLines = (order?.lines ?? []).filter(
      (line) =>
        (line.line_type ?? 'product') === 'product' &&
        line.product_id &&
        Number(line.quantity) > 0,
    )
    const warehouseId = order?.warehouse_id

    if (!warehouseId || productLines.length === 0) {
      actionMutation.mutate('confirm')
      return
    }

    setPreflightPending(true)
    try {
      const availability = await fetchBulkStockAvailability(
        warehouseId,
        productLines.map((line) => ({
          product_id: line.product_id,
          quantity: Number(line.quantity),
        })),
      )

      const short = (availability.lines ?? []).filter((line) => !line.sufficient)
      if (short.length > 0) {
        setShortageLines(
          short.map((line) => {
            const orderLine = productLines.find(
              (candidate) => Number(candidate.product_id) === Number(line.product_id),
            )
            return {
              product_id: line.product_id,
              product_name: orderLine?.product?.name ?? null,
              requested: Number(line.requested),
              available: Number(line.available),
              incoming_quantity: Number(line.incoming_quantity ?? 0),
            }
          }),
        )
        return
      }
    } catch {
      toast({
        title: 'Stock check unavailable',
        description: 'Confirming without the availability summary — the order may include backorders.',
        variant: 'destructive',
      })
    } finally {
      setPreflightPending(false)
    }

    actionMutation.mutate('confirm')
  }

  const chooseShortageAction = (intent: 'backorder' | 'partial') => {
    setShortageLines(null)
    confirmIntentRef.current = intent
    actionMutation.mutate('confirm')
  }

  const changeShortageWarehouse = () => {
    setShortageLines(null)
    toast({
      title: 'Not confirmed',
      description: 'Change the commitment warehouse, then confirm the order again.',
    })
  }

  const cancelMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/sales/orders/${id}/cancel`, { reason: cancelReason })
    },
    onSuccess: () => {
      refresh()
      setCancelOpen(false)
      setCancelReason('')
      toast({ title: 'Success', description: 'Order cancelled' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to cancel order',
        variant: 'destructive',
      })
    },
  })

  // Issue #408: commitment date + shipping policy edit for draft orders.
  const promiseMutation = useMutation({
    mutationFn: async (payload: Record<string, any>) => {
      // Issue #906: keep the failed payload so "Overwrite" can resend it
      // against the conflict's current version.
      lastPromisePayloadRef.current = payload
      await api.put(`/sales/orders/${id}`, payload)
    },
    onSuccess: () => {
      setConflict(null)
      refresh()
      setPromiseOpen(false)
      toast({ title: 'Success', description: 'Promise date and shipping policy updated' })
    },
    onError: (error: any, variables: unknown) => {
      // Issue #906: a stale 409 opens the compare-and-choose dialog.
      const saveConflict = extractSaveConflict(error, (variables ?? {}) as Record<string, any>)
      if (saveConflict) {
        setConflict(saveConflict)
        return
      }
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update the promise dates',
        variant: 'destructive',
      })
    },
  })

  const savePromiseDraft = () => {
    promiseMutation.mutate({
      commitment_date: commitmentDraft || null,
      shipping_policy: policyDraft,
      // Issue #896/#906: echo the loaded version so a concurrent editor's
      // save is detected instead of silently overwritten.
      version: order?.lock_version ?? undefined,
    })
  }

  // Issue #906: discard local edits, refetch and re-hydrate the server copy.
  const handleReloadTheirs = () => {
    setConflict(null)
    refresh()
    void refetchOrder()
  }

  // Issue #906: resend my payload against the version the conflict reported.
  const handleOverwrite = () => {
    if (!conflict || !lastPromisePayloadRef.current) return
    promiseMutation.mutate({ ...lastPromisePayloadRef.current, version: conflict.currentVersion })
  }

  const openPromiseDialog = () => {
    setCommitmentDraft(order?.commitment_date ? String(order.commitment_date).split('T')[0] : '')
    setPolicyDraft(order?.shipping_policy === 'when_all_ready' ? 'when_all_ready' : 'asap')
    setPromiseOpen(true)
  }

  // #399 (SU1): labels and colours come from the shared lifecycle mapping
  // (also used by the order list), covering every real backend status.
  const getStatusBadge = (status: string) => {
    const meta = getSalesOrderStatusMeta(status)
    return (
      <Badge variant="secondary" className={meta.className} data-testid="sales-order-status-badge">
        {meta.label}
      </Badge>
    )
  }

  if (isLoading) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <div className="flex items-center justify-center h-64">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      </div>
    )
  }

  if (!order) {
    return (
      <div className="container mx-auto py-6 space-y-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="material-symbols:receipt-long-off" className="h-16 w-16 text-muted-foreground mb-4" />
            <h2 className="text-xl font-semibold mb-2">Sales Order Not Found</h2>
            <p className="text-muted-foreground mb-4">The sales order you're looking for doesn't exist or has been deleted.</p>
            <Button onClick={() => navigate('/sales/orders')}>
              <Icon icon="material-symbols:arrow-back" className="mr-2 h-4 w-4" />
              Back to Sales Orders
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const showFulfilmentSmartButtons = !['draft', 'cancelled'].includes(order.status)
  const fullyDelivered = isFullyDelivered(order.lines ?? [])
  const deliveryStatusMeta = SALES_ORDER_DELIVERY_STATUS_META[getSalesOrderDeliveryStatus(order.lines ?? [])]
  const invoiceStatusMeta = getSalesOrderInvoiceStatusMeta(order.invoice_status)
  // #403 (SU5): cancelled documents are summarised, not counted as live.
  const deliverySummary = summarizeDeliveries(order.delivery_orders ?? [])
  const deliveryOrdersCount = deliverySummary.live.length
  const invoiceSummary = summarizeInvoices(order.invoices ?? [], order.currency_code || undefined)
  const linkedInvoices = invoiceSummary.live
  const showDeliveryButton = showFulfilmentSmartButtons || (order.delivery_orders?.length ?? 0) > 0
  const showInvoiceButton = showFulfilmentSmartButtons || (order.invoices?.length ?? 0) > 0

  // #402 (SU4): compact order-info chips. Every value comes from the order
  // payload (snapshots/columns) or the existing credit-preview endpoint.
  const productLines = (order.lines ?? []).filter((line) => (line.line_type ?? 'product') === 'product')
  const orderedQuantity = productLines.reduce((sum, line) => sum + quantityValue(line.quantity), 0)
  const reservedQuantity = productLines.reduce((sum, line) => sum + quantityValue(line.quantity_allocated), 0)
  const orderHasBackorder = order.has_backorder === true || productLines.some(isBackordered)

  const warehouseLabel = order.warehouse?.name || (order.warehouse_id != null ? `Warehouse #${order.warehouse_id}` : null)
  const exchangeRate = Number(order.exchange_rate)
  const showExchangeRate =
    order.currency_code != null && Number.isFinite(exchangeRate) && exchangeRate > 0 && exchangeRate !== 1
  // #406 (SU8): amounts are shown in the order's document currency (shared
  // formatter), with the base-currency equivalent at the snapshot rate when
  // the two differ — same convention as the invoice page (1 doc = rate base).
  const docCurrency = order.currency_code || undefined
  const money = (value: number | string | null | undefined) => formatCurrency(Number(value ?? 0), docCurrency)
  const baseCurrency = order.base_currency_code || undefined
  const showBaseEquivalent =
    Boolean(docCurrency && baseCurrency && docCurrency !== baseCurrency) && Number.isFinite(exchangeRate) && exchangeRate > 0
  const channelLabel = order.channel ? (CHANNEL_LABELS[order.channel] ?? order.channel) : null
  const sourceLabel = order.source_document_type
    ? (SOURCE_DOCUMENT_LABELS[order.source_document_type] ?? order.source_document_type)
    : order.quotation
      ? `Quotation ${order.quotation.quotation_number}`
      : null
  const channelSourceLabel = [channelLabel, sourceLabel].filter(Boolean).join(' · ')
  const approval = order.latest_approval_request
  const approvalLabel = approval
    ? (APPROVAL_STATUS_LABELS[String(approval.status).toLowerCase()] ?? `Approval ${approval.status}`)
    : null
  const hasCreditResult = creditPreview != null && typeof creditPreview.approved === 'boolean'
  const billingAddress = formatSnapshotAddress(order.billing_address_snapshot)
  const shippingAddress = formatSnapshotAddress(order.shipping_address_snapshot)

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1 space-y-2">
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 text-muted-foreground hover:text-foreground"
          onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/sales/orders'))}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
          <div className="min-w-0">
            <h1 className="truncate text-2xl font-bold">Sales Order {order.sales_order_number}</h1>
            <p
              className="mt-1 max-w-lg truncate text-sm text-muted-foreground"
              title={`Created on ${formatDate(order.created_at)} for ${order.customer?.name || 'Unknown Customer'}`}
            >
              Created on {formatDate(order.created_at)} for {order.customer?.name || 'Unknown Customer'}
            </p>
            {/* Issue #696 (INV-D3): stock & COGS reconciliation badge. */}
            <StockCogsBadge
              documentType="sales_order"
              documentId={Number(id)}
              permission="sales.view"
              className="mt-2"
            />
            {/* #402 (SU4): compact order-info chips. */}
            <div className="mt-2 flex flex-wrap items-center gap-1.5">
              {warehouseLabel && (
                <OrderInfoChip
                  icon="solar:warehouse-bold-duotone"
                  title={order.warehouse?.code ? `Warehouse code ${order.warehouse.code}` : 'Commitment warehouse'}
                >
                  {warehouseLabel}
                </OrderInfoChip>
              )}
              {order.currency_code && (
                <OrderInfoChip
                  icon="solar:wallet-money-bold-duotone"
                  title={
                    order.base_currency_code && order.base_currency_code !== order.currency_code
                      ? `1 ${order.currency_code} = ${formatExchangeRate(order.exchange_rate)} ${order.base_currency_code}`
                      : undefined
                  }
                >
                  {order.currency_code}
                  {showExchangeRate && <> @ {formatExchangeRate(order.exchange_rate)}</>}
                </OrderInfoChip>
              )}
              {channelSourceLabel && (
                <OrderInfoChip icon="solar:routing-bold-duotone" title="How this order was created">
                  {channelSourceLabel}
                </OrderInfoChip>
              )}
              {approvalLabel && (
                <OrderInfoChip
                  icon="solar:shield-check-bold-duotone"
                  title={
                    approval?.request_number
                      ? `Approval request ${approval.request_number}`
                      : 'Approval request for this order'
                  }
                  className={approval?.status === 'pending' ? 'border-orange-500/60 text-orange-600' : undefined}
                >
                  {approvalLabel}
                </OrderInfoChip>
              )}
              {hasCreditResult && (
                <OrderInfoChip
                  icon="solar:card-bold-duotone"
                  title={creditPreview.reason}
                  className={creditPreview.approved ? undefined : 'border-destructive/50 text-destructive'}
                >
                  {creditPreview.approved
                    ? creditPreview.availableCredit == null
                      ? 'Credit unlimited'
                      : 'Credit OK'
                    : 'Over credit limit'}
                </OrderInfoChip>
              )}
              {creditPreviewFailed && (
                <OrderInfoChip icon="solar:danger-triangle-bold-duotone">Credit check unavailable</OrderInfoChip>
              )}
              {orderHasBackorder && (
                <OrderInfoChip
                  icon="solar:danger-triangle-bold-duotone"
                  title="At least one line has stock that could not be reserved"
                  className="border-destructive/50 text-destructive"
                >
                  Backordered
                </OrderInfoChip>
              )}
              {reservedQuantity > 0 && (
                <OrderInfoChip icon="solar:box-bold-duotone" title="Quantity reserved from stock">
                  Reserved {formatQuantity(reservedQuantity)} of {formatQuantity(orderedQuantity)}
                </OrderInfoChip>
              )}
            </div>
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          {getStatusBadge(order.status)}
          {/* #399 (SU1): delivery and invoice progress, separate from the document status. */}
          {showFulfilmentSmartButtons && (
            <Badge
              variant="outline"
              className={deliveryStatusMeta.className}
              data-testid="sales-order-delivery-status"
            >
              {`Delivery: ${deliveryStatusMeta.label}`}
            </Badge>
          )}
          {showFulfilmentSmartButtons && invoiceStatusMeta && (
            <Badge
              variant="outline"
              className={invoiceStatusMeta.className}
              data-testid="sales-order-invoice-status"
            >
              {`Invoicing: ${invoiceStatusMeta.label}`}
            </Badge>
          )}
          {order.is_late && (
            <Badge variant="destructive" className="shrink-0" title="Commitment date has passed and the order is not fully delivered">
              Late
            </Badge>
          )}
          {/* Issue #407: the lock only exists for confirmed orders. */}
          {(order.is_locked === true || order.status === 'confirmed') && (
            <SalesOrderLockControl
              isLocked={order.is_locked === true}
              canWrite={canOverwrite}
              isPending={lockMutation.isPending}
              lockedAt={order.locked_at}
              onLock={() => lockMutation.mutate('lock')}
              onUnlock={() => lockMutation.mutate('unlock')}
            />
          )}
          {/* Issue #374: a draft order is edited (header + lines) on the
              shared sales order form; confirmed orders use lock/amend (#407). */}
          {order.status === 'draft' && canOverwrite && (
            <HeaderActionButton
              variant="outline"
              icon="solar:pen-bold-duotone"
              onClick={() => navigate(`/sales/orders/${order.id}/edit`)}
            >
              Edit
            </HeaderActionButton>
          )}
          {order.status === 'draft' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:calendar-mark-bold-duotone"
              onClick={openPromiseDialog}
            >
              Promise Date
            </HeaderActionButton>
          )}
          <TagEditor subjectType="sales_order" subjectId={id!} />
          <HeaderActionButton
            variant="outline"
            icon="solar:chat-round-dots-bold-duotone"
            onClick={() => setChatterOpen(true)}
          >
            Chatter
          </HeaderActionButton>
          {order.status === 'draft' && (
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              loading={actionMutation.isPending || preflightPending}
              onClick={() => void beginConfirm()}
              disabled={actionMutation.isPending || preflightPending}
            >
              Confirm
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('allocated') && (
            <HeaderActionButton
              icon="solar:box-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('allocate')}
              disabled={actionMutation.isPending}
            >
              Allocate
            </HeaderActionButton>
          )}
          {DELIVERY_ORDER_STATUSES.includes(order.status) && (
            <HeaderActionButton
              variant="outline"
              icon="solar:delivery-bold-duotone"
              onClick={() => navigate(`/delivery-orders/create?sales_order_id=${order.id}`)}
            >
              Create Delivery Order
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('invoiced') && order.invoice_status !== 'fully_invoiced' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:file-text-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('invoice')}
              disabled={actionMutation.isPending}
            >
              Create Invoice
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('draft') && (
            <HeaderActionButton
              variant="outline"
              icon="solar:undo-left-round-bold-duotone"
              loading={actionMutation.isPending}
              onClick={() => actionMutation.mutate('reset-to-draft')}
              disabled={actionMutation.isPending}
            >
              Reset to Draft
            </HeaderActionButton>
          )}
          {(order.allowed_transitions ?? []).includes('cancelled') && (
            <HeaderActionButton
              variant="outline"
              icon="solar:close-circle-bold-duotone"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => setCancelOpen(true)}
            >
              Cancel
            </HeaderActionButton>
          )}
          {/* #618: the one print/send menu, matching every other sales document. */}
          <PrintSendMenu
            documentType="sales_order"
            pdfPath={`/sales/orders/${id}/pdf`}
            filename={`SO-${order.sales_order_number}.pdf`}
            title="Sales Order"
            resolutionPath={`/documents/sales_order/resolution?document_id=${id}`}
          />
          <ChatterDialog
            open={chatterOpen}
            onOpenChange={setChatterOpen}
            subjectType="Modules\\Sales\\Models\\SalesOrder"
            subjectId={id as string}
            documentLabel={order.sales_order_number}
          />
        </div>
      </div>

      <EditingBanner activeEditor={order.active_lock} currentUserId={currentUserId} />

      <Tabs defaultValue="document" className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="document" className="gap-2 rounded-full px-4">
            Document
          </TabsTrigger>
          <TabsTrigger value="audit" className="gap-2 rounded-full px-4">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            Audit
          </TabsTrigger>
          <TabsTrigger value="stock-moves" className="gap-2 rounded-full px-4">
            <Icon icon="solar:box-bold-duotone" className="h-4 w-4" />
            Stock moves
          </TabsTrigger>
        </TabsList>

        <TabsContent value="document" className="space-y-6 mt-6">
      {/* Smart Buttons */}
      {/* #403 (SU5): one summary button per document type (counts + state), wrapping on small screens. */}
      <div className="flex flex-wrap gap-3" data-testid="sales-order-smart-buttons">
        {showDeliveryButton && (
          <button
            data-testid="smart-button-deliveries"
            onClick={() => navigate(`/delivery-orders?sales_order_id=${order.id}`)}
            title={`${pluralize(deliveryOrdersCount, 'delivery order')} — open the sales order's deliveries`}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:delivery-bold-duotone" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">
                {/* Draft/cancelled orders only show the button for existing documents — nothing is "to deliver". */}
                {!showFulfilmentSmartButtons ? 'Deliveries' : fullyDelivered ? 'Delivered' : 'To Deliver'}
              </div>
              <div className="text-xs text-muted-foreground">
                <span>{pluralize(deliveryOrdersCount, 'Delivery Order')}</span>
                {deliverySummary.summary && <span data-testid="smart-button-summary"> · {deliverySummary.summary}</span>}
              </div>
            </div>
          </button>
        )}
        {showInvoiceButton && (
          <button
            data-testid="smart-button-invoices"
            onClick={() => navigate(`/invoices?sales_order_id=${order.id}`)}
            title={`${pluralize(linkedInvoices.length, 'invoice')} — open the sales order's invoices`}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{getInvoiceStatusLabel(linkedInvoices)}</div>
              <div className="text-xs text-muted-foreground">
                <span>{pluralize(linkedInvoices.length, 'Invoice')}</span>
                {invoiceSummary.summary && <span data-testid="smart-button-summary"> · {invoiceSummary.summary}</span>}
              </div>
            </div>
          </button>
        )}
        {order.quotation && (
          <button
            onClick={() => navigate(`/quotations/${order.quotation!.id}`)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-purple-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{order.quotation.quotation_number}</div>
              <div className="text-xs text-muted-foreground">Quotation</div>
            </div>
          </button>
        )}
      </div>

      {/* Order Details */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Customer</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="font-medium">{order.customer?.name || '-'}</p>
            {order.customer?.email && <p className="text-sm text-muted-foreground">{order.customer.email}</p>}
            {order.customer?.phone && <p className="text-sm text-muted-foreground">{order.customer.phone}</p>}
            {order.customer?.code && <p className="text-sm text-muted-foreground">Code: {order.customer.code}</p>}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Order Info</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Order Date:</span>
                <span className="text-sm">{formatDate(order.order_date)}</span>
              </div>
              {/* #401 (SU3): read the real `required_date` column. */}
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Required Date:</span>
                <span className="text-sm">{order.required_date ? formatDate(order.required_date) : '-'}</span>
              </div>
              {/* Issue #408: promise date + computed expected date + policy. */}
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Commitment Date:</span>
                <span className="text-sm">{order.commitment_date ? formatDate(order.commitment_date) : '-'}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Expected Date:</span>
                <span className="text-sm" title="Computed from the product/supplier lead times">
                  {order.expected_date ? formatDate(order.expected_date) : '-'}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Shipping Policy:</span>
                <span className="text-sm">
                  {order.shipping_policy === 'when_all_ready' ? 'When all products are ready' : 'As soon as possible'}
                </span>
              </div>
              {order.customer_reference && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Customer Ref:</span>
                  <span className="text-sm">{order.customer_reference}</span>
                </div>
              )}
              {order.internal_reference && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Internal Ref:</span>
                  <span className="text-sm">{order.internal_reference}</span>
                </div>
              )}
              {/* Issue #333: accepted Incoterm (code + place). */}
              {order.incoterm_code && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Incoterm:</span>
                  <span className="text-sm">
                    {order.incoterm_code}
                    {order.incoterm_place ? ` — ${order.incoterm_place}` : ''}
                  </span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Totals</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-1">
              <div className="flex justify-between">
                <span className="text-sm text-muted-foreground">Subtotal:</span>
                <span className="text-sm">{money(order.subtotal)}</span>
              </div>
              {order.tax_amount > 0 && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Tax:</span>
                  <span className="text-sm">{money(order.tax_amount)}</span>
                </div>
              )}
              {order.discount_amount > 0 && (
                <div className="flex justify-between">
                  <span className="text-sm text-muted-foreground">Discount:</span>
                  <span className="text-sm">{money(order.discount_amount)}</span>
                </div>
              )}
              <div className="flex justify-between font-bold pt-1 border-t">
                <span className="text-sm">Total:</span>
                <span className="text-sm">{money(order.total)}</span>
              </div>
              {showBaseEquivalent && (
                <div className="flex justify-between text-xs text-muted-foreground" data-testid="sales-order-base-total">
                  <span>Base equivalent ({baseCurrency}):</span>
                  <span>{formatCurrency(Number(order.total ?? 0) * exchangeRate, baseCurrency)}</span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* #402 (SU4): address snapshots captured with the order */}
      {(billingAddress || shippingAddress) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {billingAddress && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Billing Address</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{billingAddress}</p>
              </CardContent>
            </Card>
          )}
          {shippingAddress && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Shipping Address</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{shippingAddress}</p>
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {/* Line Items */}
      <Card>
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>#</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead>Description</TableHead>
                <TableHead>Model #</TableHead>
                <TableHead>Product Notes</TableHead>
                <TableHead className="text-right">Ordered</TableHead>
                <TableHead className="text-right">Reserved</TableHead>
                <TableHead className="text-right">Delivered Qty</TableHead>
                <TableHead className="text-right">Invoiced Qty</TableHead>
                <TableHead className="text-right">Remaining to Deliver</TableHead>
                <TableHead className="text-right">Remaining to Invoice</TableHead>
                <TableHead className="text-right">Discount</TableHead>
                <TableHead className="text-right">Unit Price</TableHead>
                <TableHead className="text-right">Tax</TableHead>
                <TableHead className="text-right">Total</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {order.lines?.map((line, index) => {
                const lineType = line.line_type ?? 'product'

                // #400 (SU2): notes and sections are text rows, not empty product rows.
                if (lineType === 'note' || lineType === 'section') {
                  const isSection = lineType === 'section'
                  return (
                    <TableRow key={line.id} className={isSection ? 'bg-muted/60' : 'bg-muted/30'}>
                      <TableCell
                        colSpan={16}
                        className={isSection ? 'font-semibold' : 'italic text-muted-foreground'}
                      >
                        <div className="flex items-center justify-between gap-4">
                          <span>{line.description}</span>
                          {isSection && (
                            <span className="whitespace-nowrap tabular-nums">
                              {money(
                                getDocumentSectionTotal(order.lines, index, (item) => Number(item.line_total ?? 0)),
                              )}
                            </span>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  )
                }

                return (
                  <TableRow key={line.id}>
                    <TableCell>{index + 1}</TableCell>
                    <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product?.sku || line.sku || '-'}</TableCell>
                    <TableCell>{line.description || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                    <TableCell className="text-right">
                      <div>{formatQuantity(line.quantity)}</div>
                      {quantityValue(line.quantity_cancelled) > 0 && (
                        <div className="text-xs text-muted-foreground">
                          Cancelled {formatQuantity(line.quantity_cancelled)}
                        </div>
                      )}
                      {isBackordered(line) && (
                        <Badge
                          variant="destructive"
                          className="mt-1"
                          title={`${formatQuantity(line.backorder_quantity)} not reserved`}
                        >
                          Backorder {formatQuantity(line.backorder_quantity)}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">{formatQuantity(line.quantity_allocated)}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex flex-col items-end gap-1">
                        <span>{formatQuantity(line.quantity_delivered)}</span>
                        <Progress
                          value={deliveryProgress(line)}
                          className="h-1.5 w-16"
                          aria-label={`Delivered ${deliveryProgress(line)}%`}
                        />
                      </div>
                    </TableCell>
                    <TableCell className="text-right">{formatQuantity(line.quantity_invoiced)}</TableCell>
                    <TableCell className="text-right">{formatQuantity(remainingToDeliver(line))}</TableCell>
                    <TableCell className="text-right">{formatQuantity(remainingToInvoice(line))}</TableCell>
                    <TableCell className="text-right">{money(line.discount_amount)}</TableCell>
                    <TableCell className="text-right">{money(line.unit_price)}</TableCell>
                    <TableCell className="text-right">{money(line.tax_amount)}</TableCell>
                    <TableCell className="text-right font-medium">{money(line.line_total)}</TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Notes — #401 (SU3): Sales Orders carry no terms field, so the dead
          Terms card was removed. */}
      {order.notes && (
        <Card>
          <CardHeader>
            <CardTitle>Notes</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm whitespace-pre-wrap">{order.notes}</p>
          </CardContent>
        </Card>
      )}
      {/* #404 (SU6): inline status timeline merged with chatter activity. */}
      <SalesOrderTimeline
        subjectType="Modules\\Sales\\Models\\SalesOrder"
        subjectId={id as string}
        statusHistory={order.status_history ?? []}
      />
        </TabsContent>

        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/sales/orders/${id}/audit`} permission="sales.view" />
        </TabsContent>

        <TabsContent value="stock-moves" className="mt-6">
          <StockMovesTab endpoint={`/sales/orders/${id}/stock-moves`} permission="sales.view" />
        </TabsContent>
      </Tabs>

      <StockShortageDialog
        open={shortageLines !== null}
        onOpenChange={(open) => {
          if (!open) setShortageLines(null)
        }}
        lines={shortageLines ?? []}
        pending={actionMutation.isPending}
        onBackorder={() => chooseShortageAction('backorder')}
        onDeliverPartially={() => chooseShortageAction('partial')}
        onChangeWarehouse={changeShortageWarehouse}
      />
      <Dialog open={promiseOpen} onOpenChange={setPromiseOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Commitment Date &amp; Shipping Policy</DialogTitle>
            <DialogDescription>
              The commitment date is the date promised to the customer. The expected date is computed
              from product/supplier lead times. With &quot;when all products are ready&quot;, delivery orders
              must cover every remaining line in full.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="commitment-date">Commitment Date</Label>
              <Input
                id="commitment-date"
                type="date"
                value={commitmentDraft}
                onChange={(event) => setCommitmentDraft(event.target.value)}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="shipping-policy">Shipping Policy</Label>
              <select
                id="shipping-policy"
                value={policyDraft}
                onChange={(event) => setPolicyDraft(event.target.value as 'asap' | 'when_all_ready')}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="asap">As soon as possible (partial deliveries allowed)</option>
                <option value="when_all_ready">When all products are ready (no partials)</option>
              </select>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPromiseOpen(false)}>
              Keep
            </Button>
            <Button disabled={promiseMutation.isPending} onClick={savePromiseDraft}>
              {promiseMutation.isPending ? 'Saving...' : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel Sales Order</DialogTitle>
            <DialogDescription>
              This releases any stock reservations. Provide a reason for the audit trail.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="cancel-reason">Reason</Label>
            <Input
              id="cancel-reason"
              value={cancelReason}
              onChange={(event) => setCancelReason(event.target.value)}
              placeholder="Why is this order being cancelled?"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelOpen(false)}>
              Keep Order
            </Button>
            <Button
              variant="destructive"
              disabled={cancelMutation.isPending || cancelReason.trim().length < 3}
              onClick={() => cancelMutation.mutate()}
            >
              {cancelMutation.isPending ? 'Cancelling...' : 'Cancel Order'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <SaveConflictDialog
        open={conflict !== null}
        conflict={conflict}
        canOverwrite={canOverwrite}
        onOpenChange={(open) => { if (!open) setConflict(null) }}
        onReloadTheirs={handleReloadTheirs}
        onOverwrite={handleOverwrite}
      />
    </div>
  )
}
