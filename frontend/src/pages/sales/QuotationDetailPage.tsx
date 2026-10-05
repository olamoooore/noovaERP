import { useRef, useState } from 'react'
import { useAuthStore } from '@/store/authStore'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { TagEditor } from '@/components/tags/TagEditor'
import { DocumentLinkChip } from '@/components/documents/DocumentLinkChip'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { QuotationCaseInformationDisplay } from '@/components/sales/QuotationCaseInformation'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { ChatterDock } from '@/components/chatter'
import { AuditTab } from '@/components/audit/AuditTab'
import { StockMovesTab } from '@/components/inventory/StockMovesTab'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatCurrency, formatDate } from '@/lib/utils'
import { SalesLineColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { fetchBulkStockAvailability } from '@/components/sales/stockAvailability'
import { StockShortageDialog, type ShortageLine } from '@/components/sales/StockShortageDialog'

const QUOTATION_LINE_COLUMNS = [
  '#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax', 'Total',
].map((label, index) => ({ label, index: index + 1 }))

export function QuotationDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const { data: quotation, isLoading } = useQuery({
    queryKey: ['quotation', id],
    queryFn: async () => {
      const response = await api.get(`/quotations/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // MRD pricelist-multicurrency FR-5.4: document currency primary; base
  // equivalent at the snapshot rate when the quote is foreign.
  const docCurrency = (quotation as any)?.currency_code || undefined
  const money = (value: number) => formatCurrency(value, docCurrency)
  const baseCurrency = (quotation as any)?.base_currency_code || undefined
  const fxRate = Number((quotation as any)?.exchange_rate ?? 1) || 1
  const showBaseEquivalent = Boolean(docCurrency && baseCurrency && docCurrency !== baseCurrency)
  const baseMoney = (value: number) => formatCurrency(value * fxRate, baseCurrency)

  const { hasPermission } = useAuthStore()
  // Quotations are sales documents: confirming creates the linked Sales Order
  // (no ledger entry) and converting spawns a draft/approval-gated invoice or
  // DO. Server gates match (quotations.write). Ledger release actions of the
  // children (invoice post, DO confirm) remain accounting.post.
  const canManageQuotation = hasPermission('quotations.write')

  // Issue #687 (UX1): explicit shortage choice before the quotation spawns
  // its silently-backordered Sales Order (#379).
  const [shortageLines, setShortageLines] = useState<ShortageLine[] | null>(null)
  const [preflightPending, setPreflightPending] = useState(false)
  const confirmIntentRef = useRef<'backorder' | 'partial' | null>(null)

  const confirmMutation = useMutation({

    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/confirm`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this quotation.',
        })
        return
      }

      // Issue #687: the explicit choice made in the shortage dialog decides
      // what happens after the quotation releases its Sales Order.
      const intent = confirmIntentRef.current
      confirmIntentRef.current = null
      if (intent === 'partial') {
        const salesOrderId = data?.data?.sales_order_id
        toast({
          title: 'Quotation confirmed',
          description: 'Create the delivery order for the available quantity; short lines stay backordered.',
        })
        if (salesOrderId) {
          navigate(`/delivery-orders/create?sales_order_id=${salesOrderId}`)
        }
        return
      }
      if (intent === 'backorder') {
        toast({
          title: 'Quotation confirmed — Sales Order created',
          description: 'Short lines continue as backorders until stock arrives.',
        })
        return
      }

      toast({ title: 'Quotation confirmed — Sales Order created' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  /**
   * Issue #687: preflight the quotation's product lines against the warehouse
   * the released Sales Order will reserve from. The server resolves it as
   * "default warehouse, else first", so the preflight mirrors that (loaded
   * when the user confirms — the quotation form has no warehouse selector).
   * Advisory only — a failed availability service is surfaced and the confirm
   * still proceeds.
   */
  const beginConfirm = async () => {
    const productLines = ((quotation?.lines ?? []) as any[]).filter(
      (line) =>
        (line.line_type ?? 'product') === 'product' &&
        line.product_id &&
        Number(line.quantity) > 0,
    )

    if (productLines.length === 0) {
      confirmMutation.mutate()
      return
    }

    setPreflightPending(true)
    let shorts: ShortageLine[] | null = null
    try {
      const warehouseResponse = await api.get('/warehouses', { params: { per_page: 100 } })
      const warehouses = warehouseResponse.data?.data || warehouseResponse.data || []
      const warehouse = Array.isArray(warehouses)
        ? warehouses.find((candidate: any) => candidate.is_default) ?? warehouses[0]
        : null

      if (warehouse) {
        const availability = await fetchBulkStockAvailability(
          warehouse.id,
          productLines.map((line: any) => ({
            product_id: line.product_id,
            quantity: Number(line.quantity),
          })),
        )

        const short = (availability.lines ?? []).filter((line) => !line.sufficient)
        if (short.length > 0) {
          shorts = short.map((line) => {
            const quotationLine = productLines.find(
              (candidate: any) => Number(candidate.product_id) === Number(line.product_id),
            )
            return {
              product_id: line.product_id,
              product_name: quotationLine?.product?.name ?? null,
              requested: Number(line.requested),
              available: Number(line.available),
              incoming_quantity: Number(line.incoming_quantity ?? 0),
            }
          })
        }
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

    if (shorts) {
      setShortageLines(shorts)
      return
    }

    confirmMutation.mutate()
  }

  const chooseShortageAction = (intent: 'backorder' | 'partial') => {
    setShortageLines(null)
    confirmIntentRef.current = intent
    confirmMutation.mutate()
  }

  const createInvoiceMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/create-invoice`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this quotation.',
        })
        return
      }
      const invoiceId = data?.data?.id
      const invoiceNeedsApproval = Boolean(data?.data?.requires_approval) && data?.data?.approval_status !== 'approved'
      toast({
        title: 'Invoice created from quotation',
        description: invoiceNeedsApproval
          ? 'The invoice has been sent for approval before it can be posted.'
          : undefined,
      })
      if (invoiceId) {
        navigate(`/invoices/${invoiceId}`)
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create invoice',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const createDeliveryOrderMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/create-delivery-order`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this quotation.',
        })
        return
      }
      const deliveryOrderId = data?.data?.id
      const doNeedsApproval = Boolean(data?.data?.requires_approval) && data?.data?.approval_status !== 'approved'
      toast({
        title: 'Delivery order created from quotation',
        description: doNeedsApproval
          ? 'The delivery order has been sent for approval before it can be confirmed.'
          : undefined,
      })
      if (deliveryOrderId) {
        navigate(`/delivery-orders/${deliveryOrderId}`)
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to create delivery order',
        description: error.response?.data?.message || error.response?.data?.error || 'An error occurred',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete(`/quotations/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast({ title: 'Quotation deleted' })
      navigate('/quotations')
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to delete quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const cancelMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/cancel`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      queryClient.invalidateQueries({
        queryKey: ['chatter', String.raw`App\Models\Quotation`, id],
      })
      toast({ title: 'Quotation cancelled' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to cancel quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const resetToDraftMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/reset-to-draft`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      queryClient.invalidateQueries({
        queryKey: ['chatter', String.raw`App\Models\Quotation`, id],
      })
      toast({ title: 'Quotation reset to draft' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to reset quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleDelete = () => {
    setConfirmAction('delete')
    return
    deleteMutation.mutate()
  }

  // Product Notes starts hidden, matching the previous default.
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(
    () => QUOTATION_LINE_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [showInvoicesPanel, setShowInvoicesPanel] = useState(false)
  const [showDeliveryOrdersDialog, setShowDeliveryOrdersDialog] = useState(false)
  const [confirmAction, setConfirmAction] = useState<'delete' | 'reset' | null>(null)
  const [chatterOpen, setChatterOpen] = useState(false)

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      sent: 'bg-blue-100 text-blue-800',
      confirmed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="py-12 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
          <p className="mt-4 text-sm text-muted-foreground">Loading quotation...</p>
        </div>
      </div>
    )
  }

  if (!quotation) {
    return (
      <div className="p-6">
        <div className="py-12 text-center text-muted-foreground">
          <p>Quotation not found.</p>
          <Button variant="outline" onClick={() => navigate('/quotations')} className="mt-4">
            Back to Quotations
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1 space-y-2">
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 text-muted-foreground hover:text-foreground"
            onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/quotations'))}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div className="min-w-0">
            <div className="flex min-w-0 items-center gap-3">
              <h1 className="truncate text-3xl font-bold">{quotation.quotation_number}</h1>
              <Badge variant="secondary" className={cn('shrink-0 capitalize', getStatusBadge(quotation.status))}>
                {quotation.status}
              </Badge>
              {quotation.is_late && (
                <Badge variant="destructive" className="shrink-0" title="Commitment date has passed and the quotation is not converted">
                  Late
                </Badge>
              )}
            </div>
            <p
              className="mt-1 max-w-lg truncate text-muted-foreground"
              title={quotation.customer ? `Created ${formatDate(quotation.created_at)} for ${quotation.customer.name}` : undefined}
            >
              Created {formatDate(quotation.created_at)} for{' '}
              {quotation.customer ? (
                <Link
                  to={`/customers/${quotation.customer.id}`}
                  className="font-medium text-primary underline-offset-2 hover:underline"
                >
                  {quotation.customer.name}
                </Link>
              ) : (
                'Unknown'
              )}
            </p>
            <TagEditor subjectType="quotation" subjectId={id!} />
          </div>
        </div>

<div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
          {/* Convert straight from the quote (draft, sent or confirmed): the
              server releases the quotation first and the spawned invoice/DO
              follow the tenant's required-approval config. */}
          {['draft', 'sent', 'confirmed'].includes(quotation.status) && canManageQuotation && (
            <>
              <HeaderActionButton
                icon="solar:bill-list-bold-duotone"
                loading={createInvoiceMutation.isPending}
                onClick={() => createInvoiceMutation.mutate()}
                disabled={createInvoiceMutation.isPending}
              >
                Create Invoice
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:box-bold-duotone"
                loading={createDeliveryOrderMutation.isPending}
                onClick={() => createDeliveryOrderMutation.mutate()}
                disabled={createDeliveryOrderMutation.isPending}
              >
                Create Delivery Order
              </HeaderActionButton>
            </>
          )}
          {['draft', 'sent'].includes(quotation.status) && canManageQuotation && (
            <HeaderActionButton
              icon="solar:check-circle-bold-duotone"
              loading={confirmMutation.isPending || preflightPending}
              onClick={() => void beginConfirm()}
              disabled={confirmMutation.isPending || preflightPending}
            >
              Confirm
            </HeaderActionButton>
          )}
          {quotation.status === 'draft' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:pen-bold-duotone"
              onClick={() => navigate(`/quotations/${id}/edit`)}
            >
              Edit
            </HeaderActionButton>
          )}
          {quotation.status === 'cancelled' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:undo-left-round-bold-duotone"
              loading={resetToDraftMutation.isPending}
              onClick={() => setConfirmAction('reset')}
              disabled={resetToDraftMutation.isPending}
            >
              Reset to Draft
            </HeaderActionButton>
          )}
          {['sent', 'confirmed'].includes(quotation.status) && (
            <HeaderActionButton
              variant="outline"
              icon="solar:close-circle-bold-duotone"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              loading={cancelMutation.isPending}
              onClick={() => cancelMutation.mutate()}
              disabled={cancelMutation.isPending}
            >
              Cancel
            </HeaderActionButton>
          )}
          {/* #618: one print/send menu shared with every sales document. */}
          <PrintSendMenu
            documentType="quotation"
            pdfPath={`/quotations/${id}/pdf`}
            filename={`quotation-${quotation.quotation_number}.pdf`}
            title="Quotation"
            resolutionPath={`/documents/quotation/resolution?document_id=${id}`}
          />
          {quotation.status === 'draft' && (
            <HeaderActionButton
              variant="outline"
              icon="solar:trash-bin-trash-bold-duotone"
              className="w-9 px-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              aria-label="Delete quotation"
              title="Delete"
              loading={deleteMutation.isPending}
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            />
          )}
        </div>
      </div>

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
      {/* Linked-document smart buttons */}
      <div className="flex flex-wrap gap-2">
        {quotation.invoices && quotation.invoices.length > 0 && (
          <DocumentLinkChip
            kind="invoice"
            active={showInvoicesPanel}
            label={`${quotation.invoices.length} Invoice${quotation.invoices.length !== 1 ? 's' : ''}`}
            onClick={() => setShowInvoicesPanel(!showInvoicesPanel)}
            title="Toggle linked invoices"
          />
        )}
        {quotation.delivery_orders && quotation.delivery_orders.length > 0 && (
          <DocumentLinkChip
            kind="delivery_order"
            label={`${quotation.delivery_orders.length} Delivery Order${quotation.delivery_orders.length !== 1 ? 's' : ''}`}
            title={quotation.delivery_orders.length === 1 ? 'Open delivery order' : 'Choose a delivery order'}
            onClick={() => (quotation.delivery_orders.length === 1
              ? navigate(`/delivery-orders/${quotation.delivery_orders[0].id}`)
              : setShowDeliveryOrdersDialog(true))}
          />
        )}
      </div>

      {/* Linked delivery orders — choose which one to open */}
      <Dialog open={showDeliveryOrdersDialog} onOpenChange={setShowDeliveryOrdersDialog}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Linked Delivery Orders</DialogTitle>
            <p className="text-sm text-muted-foreground">
              {quotation.delivery_orders?.length} delivery order
              {quotation.delivery_orders?.length === 1 ? '' : 's'} created from this quotation — pick one to open.
            </p>
          </DialogHeader>
          <div className="max-h-80 space-y-2 overflow-y-auto py-2">
            {(quotation.delivery_orders ?? []).map((order: any) => {
              const status = String(order.status ?? 'draft').toLowerCase()
              const statusClass =
                status === 'completed' || status === 'delivered'
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                  : status === 'confirmed'
                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                    : status === 'cancelled'
                      ? 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300'
                      : 'bg-muted text-muted-foreground'

              return (
                <button
                  key={order.id}
                  type="button"
                  onClick={() => {
                    setShowDeliveryOrdersDialog(false)
                    navigate(`/delivery-orders/${order.id}`)
                  }}
                  className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200/80 p-3 text-left transition-colors hover:bg-muted/40 dark:border-slate-800"
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400">
                      <Icon icon="solar:delivery-bold-duotone" className="h-4 w-4" />
                    </div>
                    <div className="min-w-0">
                      <p className="truncate font-medium">{order.delivery_order_number}</p>
                      <p className="text-xs text-muted-foreground">
                        {order.delivery_date ? formatDate(order.delivery_date) : 'No delivery date'}
                      </p>
                    </div>
                  </div>
                  <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize', statusClass)}>
                    {status}
                  </span>
                </button>
              )
            })}
          </div>
          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setShowDeliveryOrdersDialog(false)}>
              Close
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Linked invoices (expandable) */}
      {showInvoicesPanel && quotation.invoices && quotation.invoices.length > 0 && (
        <Card className="overflow-hidden">
          <div className="flex items-center gap-3 border-b bg-muted/20 px-6 py-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
              <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <h3 className="text-base font-semibold leading-none">Linked Invoices</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                Invoices raised from this quotation.
              </p>
            </div>
            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
              {quotation.invoices.length}
            </span>
          </div>

          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice #</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                  <TableHead className="w-[80px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {quotation.invoices.map((inv: any) => {
                  const status = (inv.lifecycle_status || inv.status || '').toLowerCase()
                  const statusClass =
                    status === 'paid'
                      ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                      : status === 'posted' || status === 'sent'
                        ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                        : status === 'overdue'
                          ? 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                          : 'bg-muted text-muted-foreground'

                  return (
                    <TableRow
                      key={inv.id}
                      className="cursor-pointer transition-colors hover:bg-muted/40"
                      onClick={() => navigate(`/invoices/${inv.id}`)}
                    >
                      <TableCell className="font-mono text-sm font-medium">{inv.invoice_number}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">{formatDate(inv.invoice_date)}</TableCell>
                      <TableCell>
                        <span
                          className={cn(
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                            statusClass,
                          )}
                        >
                          {inv.lifecycle_status || inv.status}
                        </span>
                      </TableCell>
                      <TableCell className="text-right text-sm font-medium tabular-nums">
                        {formatCurrency(inv.total, (inv as any).currency_code || undefined)}
                      </TableCell>
                      <TableCell
                        className={cn(
                          'text-right text-sm font-semibold tabular-nums',
                          Number(inv.balance) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400',
                        )}
                      >
                        {formatCurrency(inv.balance, (inv as any).currency_code || undefined)}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="h-7 rounded-full px-2 text-xs"
                          onClick={(event) => {
                            event.stopPropagation()
                            navigate(`/invoices/${inv.id}`)
                          }}
                        >
                          View
                          <Icon icon="solar:alt-arrow-right-bold-duotone" className="ml-1 h-3.5 w-3.5" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Quotation Details</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <div className="text-muted-foreground">Quote Date</div>
                <div className="font-medium">{formatDate(quotation.quotation_date)}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Valid Until</div>
                <div className="font-medium">{quotation.valid_until ? formatDate(quotation.valid_until) : '-'}</div>
              </div>
              {/* Issue #408: promise date + computed expected date + policy. */}
              <div>
                <div className="text-muted-foreground">Commitment Date</div>
                <div className="font-medium">{quotation.commitment_date ? formatDate(quotation.commitment_date) : '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Expected Date</div>
                <div className="font-medium" title="Computed from the product/supplier lead times">
                  {quotation.expected_date ? formatDate(quotation.expected_date) : '-'}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Shipping Policy</div>
                <div className="font-medium">
                  {quotation.shipping_policy === 'when_all_ready'
                    ? 'When all products are ready'
                    : 'As soon as possible'}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Customer Reference</div>
                <div className="font-medium">{quotation.customer_reference || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Internal Reference</div>
                <div className="flex flex-wrap gap-1.5">
                  {quotation.internal_reference ? quotation.internal_reference.split(',').map((reference: string) => reference.trim()).filter(Boolean).map((reference: string) => (
                    <span key={reference} className="rounded-full bg-primary/10 px-2 py-1 font-medium text-primary">{reference}</span>
                  )) : <span className="font-medium">-</span>}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Credit Terms</div>
                <div className="font-medium">{quotation.credit_terms || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Salesperson</div>
                <div className="font-medium">
                  {quotation.salesperson ? (
                    <Link
                      to="/settings?tab=team"
                      className="text-primary underline-offset-2 hover:underline"
                      title="View team members"
                    >
                      {quotation.salesperson}
                    </Link>
                  ) : (
                    '-'
                  )}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Delivery Lead Time</div>
                <div className="font-medium">{quotation.delivery_lead_time || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Shipping Method</div>
                <div className="font-medium">{quotation.shipping_method || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Shipping Terms</div>
                <div className="font-medium">{quotation.shipping_terms || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Incoterm</div>
                <div className="font-medium">
                  {quotation.incoterm_code
                    ? `${quotation.incoterm_code}${quotation.incoterm_place ? ` — ${quotation.incoterm_place}` : ''}`
                    : '-'}
                </div>
              </div>
              <div>
                <div className="text-muted-foreground">Validity</div>
                <div className="font-medium">{quotation.validity || '-'}</div>
              </div>
            </div>

            {(quotation.attention_to || quotation.contact_email || quotation.contact_phone || quotation.billing_address_snapshot || quotation.shipping_address_snapshot) && (
              <div className="mt-6 pt-6 border-t">
                <div className="text-sm font-medium text-muted-foreground uppercase mb-3">Commercial Snapshot</div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                  {quotation.attention_to && (
                    <div>
                      <div className="text-muted-foreground">Attention To</div>
                      <div className="font-medium">{quotation.attention_to}</div>
                    </div>
                  )}
                  {quotation.contact_email && (
                    <div>
                      <div className="text-muted-foreground">Contact Email</div>
                      <div className="font-medium">{quotation.contact_email}</div>
                    </div>
                  )}
                  {quotation.contact_phone && (
                    <div>
                      <div className="text-muted-foreground">Contact Phone</div>
                      <div className="font-medium">{quotation.contact_phone}</div>
                    </div>
                  )}
                </div>
                {(quotation.billing_address_snapshot || quotation.shipping_address_snapshot) && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    {quotation.billing_address_snapshot && (
                      <div className="rounded-lg border p-4 bg-muted/20">
                        <div className="text-xs text-muted-foreground mb-1">Billing Address {quotation.billing_contact ? `(Contact: ${quotation.billing_contact.name || quotation.billing_contact.display_name})` : ''}</div>
                        <div className="text-sm whitespace-pre-wrap">{quotation.billing_address_snapshot}</div>
                      </div>
                    )}
                    {quotation.shipping_address_snapshot && (
                      <div className="rounded-lg border p-4 bg-muted/20">
                        <div className="text-xs text-muted-foreground mb-1">Shipping Address {quotation.shipping_contact ? `(Contact: ${quotation.shipping_contact.name || quotation.shipping_contact.display_name})` : ''}</div>
                        <div className="text-sm whitespace-pre-wrap">{quotation.shipping_address_snapshot}</div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Amounts</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Subtotal</span>
              <span>{money(Number(quotation.subtotal))}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Tax</span>
              <span>{money(Number(quotation.tax_amount))}</span>
            </div>
            {Number(quotation.discount_amount) > 0 && (
              <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">
                Discount{quotation.discount_type === 'percentage' && quotation.discount_value != null ? ` (${Number(quotation.discount_value)}%)` : ''}
              </span>
                <span className="text-green-600">-{money(Number(quotation.discount_amount))}</span>
              </div>
            )}
            <div className="flex justify-between text-lg font-bold pt-2 border-t">
              <span>Total</span>
              <span className="text-primary">{money(Number(quotation.total))}</span>
            </div>
            {showBaseEquivalent && (
              <div className="flex justify-between text-sm text-muted-foreground">
                <span>Base equivalent ({baseCurrency})</span>
                <span>{baseMoney(Number(quotation.total))}</span>
              </div>
            )}
            {showBaseEquivalent && (
              <p className="text-right text-xs text-muted-foreground">
                1 {docCurrency} = {fxRate.toFixed(4)} {baseCurrency}
              </p>
            )}
          </CardContent>
        </Card>
      </div>

      <QuotationCaseInformationDisplay quotation={quotation} />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between space-y-0">
          <CardTitle>Line Items</CardTitle>
          <SalesLineColumnMenu
            columns={QUOTATION_LINE_COLUMNS}
            hidden={hiddenLineColumns}
            setHidden={setHiddenLineColumns}
          />
        </CardHeader>
        <CardContent>
          <SalesLineColumnVisibility
            tableClass="quotation-detail-columns"
            columns={QUOTATION_LINE_COLUMNS}
            hiddenColumns={hiddenLineColumns}
            onHiddenColumnsChange={setHiddenLineColumns}
            showMenu={false}
          >
          <Table>
            <TableHeader>
              <TableRow>
                  <TableHead>#</TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead>Model #</TableHead>
                  <TableHead>Product Notes</TableHead>
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {quotation.lines?.map((line: any, index: number) => (
                  <TableRow key={line.id}>
                    {line.line_type === 'note' || line.line_type === 'section' ? (
                      <TableCell colSpan={10} className={line.line_type === 'section' ? 'bg-muted/60 font-semibold' : 'bg-muted/30 italic text-muted-foreground'}>
                        <div className="flex items-center justify-between gap-4"><span>{line.description}</span>{line.line_type === 'section' && <span className="whitespace-nowrap tabular-nums">{money(getDocumentSectionTotal(quotation.lines || [], index, (item: any) => Number(item.line_total || 0)))}</span>}</div>
                      </TableCell>
                    ) : (<>
                    <TableCell>
                      {(quotation.lines || [])
                        .slice(0, index + 1)
                        .filter((item: any) => (item.line_type ?? 'product') === 'product').length}
                    </TableCell>
                    <TableCell className="font-medium">
                      {line.product ? (
                        <Link
                          to={`/products/${line.product.id}`}
                          className="text-primary underline-offset-2 hover:underline"
                          title={`Open ${line.product.name}`}
                        >
                          {line.product.name}
                        </Link>
                      ) : (
                        line.description || '-'
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                    <TableCell>{line.description || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity}</TableCell>
                  <TableCell className="text-right">{money(Number(line.unit_price))}</TableCell>
                  <TableCell className="text-right">{line.tax_rate}%</TableCell>
                  <TableCell className="text-right font-medium">{money(Number(line.line_total))}</TableCell>
                    </>)}
                </TableRow>
              ))}
            </TableBody>
          </Table>
          </SalesLineColumnVisibility>
        </CardContent>
      </Card>

      {(quotation.notes || quotation.terms) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {quotation.notes && (
            <Card>
              <CardHeader>
                <CardTitle>Notes</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{quotation.notes}</p>
              </CardContent>
            </Card>
          )}
          {quotation.terms && (
            <Card>
              <CardHeader>
                <CardTitle>Terms</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm whitespace-pre-wrap">{quotation.terms}</p>
              </CardContent>
            </Card>
          )}
        </div>
      )}
        </TabsContent>

        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/quotations/${id}/audit`} permission="quotations.view" />
        </TabsContent>

        <TabsContent value="stock-moves" className="mt-6">
          <StockMovesTab endpoint={`/quotations/${id}/stock-moves`} permission="quotations.view" />
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={confirmAction === 'delete'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title="Delete this quotation?"
        description={
          <>
            Quotation{' '}
            <span className="font-medium text-foreground">{quotation.quotation_number}</span> and its lines will be
            permanently removed. This cannot be undone.
          </>
        }
        confirmLabel="Delete"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          setConfirmAction(null)
          deleteMutation.mutate()
        }}
      />

      <ConfirmDialog
        open={confirmAction === 'reset'}
        onOpenChange={(open) => !open && setConfirmAction(null)}
        title="Reset this quotation to draft?"
        description="It becomes editable again and must be re-confirmed."
        confirmLabel="Reset to Draft"
        cancelLabel="Keep as is"
        tone="warning"
        icon="solar:undo-left-round-bold-duotone"
        isPending={resetToDraftMutation.isPending}
        onConfirm={() => {
          setConfirmAction(null)
          resetToDraftMutation.mutate()
        }}
      />

      <StockShortageDialog
        open={shortageLines !== null}
        onOpenChange={(open) => {
          if (!open) setShortageLines(null)
        }}
        lines={shortageLines ?? []}
        pending={confirmMutation.isPending}
        onBackorder={() => chooseShortageAction('backorder')}
        onDeliverPartially={() => chooseShortageAction('partial')}
        onChangeWarehouse={() => {
          setShortageLines(null)
          toast({
            title: 'Not confirmed',
            description: 'Change the default commitment warehouse, then confirm the quotation again.',
          })
        }}
      />

      <ChatterDock
        subjectType={String.raw`App\Models\Quotation`}
        subjectId={id as string}
        documentLabel={quotation.quotation_number}
        open={chatterOpen}
        onOpenChange={setChatterOpen}
      />
    </div>
  )
}

export default QuotationDetailPage
