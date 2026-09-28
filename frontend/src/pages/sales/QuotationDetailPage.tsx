import { useState } from 'react'
import { useAuthStore } from '@/store/authStore'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { TagEditor } from '@/components/tags/TagEditor'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { QuotationCaseInformationDisplay } from '@/components/sales/QuotationCaseInformation'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { ChatterDock } from '@/components/chatter'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatCurrency, formatDate } from '@/lib/utils'
import { SalesLineColumnVisibility, SalesLineColumnMenu } from '@/components/sales/DeliveryOrderColumnVisibility'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { DocumentTemplateDialog } from '@/components/documents/DocumentTemplateDialog'

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

  const { hasPermission } = useAuthStore()
  // Quotations are sales documents: confirming creates the linked Sales Order
  // (no ledger entry) and converting spawns a draft/approval-gated invoice or
  // DO. Server gates match (quotations.write). Ledger release actions of the
  // children (invoice post, DO confirm) remain accounting.post.
  const canManageQuotation = hasPermission('quotations.write')

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

  const [templateDialogOpen, setTemplateDialogOpen] = useState(false)

  // Product Notes starts hidden, matching the previous default.
  const [hiddenLineColumns, setHiddenLineColumns] = useState<number[]>(
    () => QUOTATION_LINE_COLUMNS.filter((c) => c.label.toLowerCase() === 'product notes').map((c) => c.index)
  )
  const [showInvoicesPanel, setShowInvoicesPanel] = useState(false)
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
              loading={confirmMutation.isPending}
              onClick={() => confirmMutation.mutate()}
              disabled={confirmMutation.isPending}
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
          <HeaderActionButton
            variant="outline"
            icon="solar:download-minimalistic-bold-duotone"
            title="Download PDF"
            onClick={() => setTemplateDialogOpen(true)}
          >
            PDF
          </HeaderActionButton>
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

      {/* Linked-document smart buttons */}
      <div className="flex flex-wrap gap-2">
        {quotation.invoices && quotation.invoices.length > 0 && (
          <button
            type="button"
            onClick={() => setShowInvoicesPanel(!showInvoicesPanel)}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors',
              showInvoicesPanel
                ? 'border-primary/60 bg-primary/15 text-primary'
                : 'border-primary/30 bg-primary/5 text-primary hover:border-primary/50 hover:bg-primary/10',
            )}
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-3.5 w-3.5" />
            {quotation.invoices.length} Invoice{quotation.invoices.length !== 1 ? 's' : ''}
          </button>
        )}
        {quotation.delivery_orders && quotation.delivery_orders.length > 0 && (
          <button
            type="button"
            onClick={() => (quotation.delivery_orders.length === 1
              ? navigate(`/delivery-orders/${quotation.delivery_orders[0].id}`)
              : navigate('/delivery-orders'))}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors',
              'border-amber-300/60 bg-amber-50 text-amber-700 hover:border-amber-400 hover:bg-amber-100',
              'dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60',
            )}
          >
            <Icon icon="solar:delivery-bold-duotone" className="h-3.5 w-3.5" />
            {quotation.delivery_orders.length} Delivery Order{quotation.delivery_orders.length !== 1 ? 's' : ''}
          </button>
        )}
      </div>

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
                        {formatCurrency(inv.total)}
                      </TableCell>
                      <TableCell
                        className={cn(
                          'text-right text-sm font-semibold tabular-nums',
                          Number(inv.balance) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400',
                        )}
                      >
                        {formatCurrency(inv.balance)}
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
              <span>{formatCurrency(quotation.subtotal)}</span>
            </div>
            <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">Tax</span>
              <span>{formatCurrency(quotation.tax_amount)}</span>
            </div>
            {Number(quotation.discount_amount) > 0 && (
              <div className="flex justify-between text-sm">
              <span className="text-muted-foreground">
                Discount{quotation.discount_type === 'percentage' && quotation.discount_value != null ? ` (${Number(quotation.discount_value)}%)` : ''}
              </span>
                <span className="text-green-600">-{formatCurrency(quotation.discount_amount)}</span>
              </div>
            )}
            <div className="flex justify-between text-lg font-bold pt-2 border-t">
              <span>Total</span>
              <span className="text-primary">{formatCurrency(quotation.total)}</span>
            </div>
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
                        <div className="flex items-center justify-between gap-4"><span>{line.description}</span>{line.line_type === 'section' && <span className="whitespace-nowrap tabular-nums">{formatCurrency(getDocumentSectionTotal(quotation.lines || [], index, (item: any) => Number(item.line_total || 0)))}</span>}</div>
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
                  <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                  <TableCell className="text-right">{line.tax_rate}%</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
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

      <DocumentTemplateDialog
        open={templateDialogOpen}
        onOpenChange={setTemplateDialogOpen}
        documentType="quotation"
        pdfPath={`/quotations/${id}/pdf`}
        filename={`quotation-${quotation.quotation_number}.pdf`}
        title="Quotation"
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
