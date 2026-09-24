import { useState } from 'react'
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
import { SalesLineColumnVisibility } from '@/components/sales/DeliveryOrderColumnVisibility'
import { getDocumentSectionTotal } from '@/components/sales/DocumentTextLineRow'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'

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

  const confirmMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/quotations/${id}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotation', id] })
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
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
      const invoiceId = data?.data?.id
      if (invoiceId) {
        toast({ title: 'Invoice created from quotation' })
        navigate(`/invoices/${invoiceId}`)
      } else {
        toast({ title: 'Invoice created from quotation' })
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

  const [isDownloading, setIsDownloading] = useState(false)
  const [showInvoicesPanel, setShowInvoicesPanel] = useState(false)
  const [confirmAction, setConfirmAction] = useState<'delete' | 'reset' | null>(null)
  const [chatterOpen, setChatterOpen] = useState(false)
  const handleDownloadPdf = async () => {
    setIsDownloading(true)
    try {
      const response = await api.get(`/quotations/${id}/pdf`, { responseType: 'blob' })
      const url = window.URL.createObjectURL(new Blob([response.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `quotation-${quotation?.quotation_number}.pdf`)
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Download failed',
        description: error.response?.data?.message || 'Could not download the PDF. Please try again.',
      })
    } finally {
      setIsDownloading(false)
    }
  }

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
      <div className="flex items-center justify-between">
        <div className="space-y-2">
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 text-muted-foreground hover:text-foreground"
            onClick={() => (window.history.length > 1 ? navigate(-1) : navigate('/quotations'))}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-3xl font-bold">{quotation.quotation_number}</h1>
              <Badge variant="secondary" className={getStatusBadge(quotation.status)}>
                {quotation.status}
              </Badge>
            </div>
            <p className="text-muted-foreground mt-1">
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

        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton
            variant="outline"
            icon="solar:download-minimalistic-bold-duotone"
            loading={isDownloading}
            onClick={handleDownloadPdf}
            disabled={isDownloading}
          >
            Download PDF
          </HeaderActionButton>

          {quotation.status === 'draft' && (
            <>
              <HeaderActionButton
                icon="solar:check-circle-bold-duotone"
                loading={confirmMutation.isPending}
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
              >
                Confirm
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:pen-bold-duotone"
                onClick={() => navigate(`/quotations/${id}/edit`)}
              >
                Edit
              </HeaderActionButton>
              <HeaderActionButton
                variant="outline"
                icon="solar:trash-bin-trash-bold-duotone"
                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                loading={deleteMutation.isPending}
                onClick={handleDelete}
                disabled={deleteMutation.isPending}
              >
                Delete
              </HeaderActionButton>
            </>
          )}

          {quotation.status === 'sent' && (
            <>
              <HeaderActionButton
                icon="solar:check-circle-bold-duotone"
                loading={confirmMutation.isPending}
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
              >
                Confirm
              </HeaderActionButton>
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
            </>
          )}

          {quotation.status === 'confirmed' && (
            <>
              <HeaderActionButton
                icon="solar:bill-list-bold-duotone"
                loading={createInvoiceMutation.isPending}
                onClick={() => createInvoiceMutation.mutate()}
                disabled={createInvoiceMutation.isPending}
              >
                Create Invoice
              </HeaderActionButton>
              {quotation.sales_order && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:box-bold-duotone"
                  onClick={() => navigate(`/delivery-orders/create?sales_order_id=${quotation.sales_order.id}&quotation_id=${quotation.id}`)}
                >
                  Create Delivery Order
                </HeaderActionButton>
              )}
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
            </>
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
        </div>
      </div>

      {/* Linked-document smart buttons */}
      <div className="flex flex-wrap gap-2">
        {quotation.invoices && quotation.invoices.length > 0 && (
          <button
            type="button"
            onClick={() => setShowInvoicesPanel(!showInvoicesPanel)}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full border bg-background px-3 text-xs font-medium transition-colors',
              'hover:bg-accent hover:text-accent-foreground',
              showInvoicesPanel
                ? 'border-primary/40 bg-primary/10 text-primary'
                : 'border-input text-muted-foreground',
            )}
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-3.5 w-3.5" />
            {quotation.invoices.length} Invoice{quotation.invoices.length !== 1 ? 's' : ''}
          </button>
        )}
        {quotation.delivery_orders && quotation.delivery_orders.length > 0 && (
          <button
            onClick={() => navigate('/delivery-orders')}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:package-bold" className="h-5 w-5 text-orange-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{quotation.delivery_orders.length}</div>
              <div className="text-xs text-muted-foreground">Delivery Order{quotation.delivery_orders.length !== 1 ? 's' : ''}</div>
            </div>
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
        <CardHeader>
          <CardTitle>Line Items</CardTitle>
        </CardHeader>
        <CardContent>
          <SalesLineColumnVisibility tableClass="quotation-detail-columns" columns={[
            '#', 'Product', 'SKU', 'Description', 'Model #', 'Product Notes', 'Qty', 'Unit Price', 'Tax', 'Total',
          ].map((label, index) => ({ label, index: index + 1 }))}>
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
                    <TableCell>{index + 1}</TableCell>
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
