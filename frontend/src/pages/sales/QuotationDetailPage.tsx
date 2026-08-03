import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { formatDate } from '@/lib/utils'

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
    if (!window.confirm(`Delete quotation ${quotation?.quotation_number}? This action cannot be undone.`)) return
    deleteMutation.mutate()
  }

  const [isDownloading, setIsDownloading] = useState(false)
  const [showInvoicesPanel, setShowInvoicesPanel] = useState(false)
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

  const formatCurrency = (amount: number | string) =>
    new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(amount || 0))

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
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate('/quotations')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
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
              Created {formatDate(quotation.created_at)} for {quotation.customer?.name || 'Unknown'}
            </p>
          </div>
        </div>

        <div className="flex gap-2">
          <Button variant="outline" onClick={handleDownloadPdf} disabled={isDownloading}>
            <Icon icon="solar:download-minimistic-bold-duotone" className="mr-2 h-4 w-4" />
            {isDownloading ? 'Preparing...' : 'Download PDF'}
          </Button>
          {quotation.status === 'draft' && (
            <>
              <Button
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
              >
                <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                {confirmMutation.isPending ? 'Confirming...' : 'Confirm'}
              </Button>
              <Button
                variant="outline"
                onClick={() => navigate(`/quotations/${id}/edit`)}
              >
                <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                Edit
              </Button>
              <Button
                variant="outline"
                className="text-destructive"
                onClick={handleDelete}
                disabled={deleteMutation.isPending}
              >
                <Icon icon="solar:trash-bin-trash-bold-duotone" className="mr-2 h-4 w-4" />
                {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
              </Button>
            </>
          )}
          {quotation.status === 'sent' && (
            <>
              <Button
                onClick={() => confirmMutation.mutate()}
                disabled={confirmMutation.isPending}
              >
                <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                {confirmMutation.isPending ? 'Confirming...' : 'Confirm'}
              </Button>
              <Button
                variant="outline"
                className="text-destructive"
                onClick={() => cancelMutation.mutate()}
                disabled={cancelMutation.isPending}
              >
                <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                {cancelMutation.isPending ? 'Cancelling...' : 'Cancel'}
              </Button>
            </>
          )}
          {quotation.status === 'confirmed' && (
            <div className="flex gap-2">
              <Button
                onClick={() => createInvoiceMutation.mutate()}
                disabled={createInvoiceMutation.isPending}
              >
                <Icon icon="solar:bill-list-bold-duotone" className="mr-2 h-4 w-4" />
                {createInvoiceMutation.isPending ? 'Creating Invoice...' : 'Create Invoice'}
              </Button>
              {quotation.sales_order && (
                <Button
                  variant="outline"
                  onClick={() => navigate(`/delivery-orders/create?sales_order_id=${quotation.sales_order.id}`)}
                >
                  <Icon icon="solar:package-bold" className="mr-2 h-4 w-4" />
                  Create Delivery Order
                </Button>
              )}
            </div>
          )}
          {quotation.status === 'cancelled' && (
            <Button
              variant="outline"
              onClick={() => {
                if (window.confirm('Reset this quotation to draft? You will be able to edit and re-confirm it.')) {
                  resetToDraftMutation.mutate()
                }
              }}
              disabled={resetToDraftMutation.isPending}
            >
              <Icon icon="solar:undo-left-round-bold" className="mr-2 h-4 w-4" />
              {resetToDraftMutation.isPending ? 'Resetting...' : 'Reset to Draft'}
            </Button>
          )}
        </div>
      </div>

      {/* Odoo-style smart buttons */}
      <div className="flex gap-3">
        {quotation.sales_order && (
          <button
            onClick={() => navigate('/sales/orders')}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:cart-large-2-bold-duotone" className="h-5 w-5 text-green-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{quotation.sales_order.sales_order_number}</div>
              <div className="text-xs text-muted-foreground">Sales Order</div>
            </div>
          </button>
        )}
        {quotation.invoices && quotation.invoices.length > 0 && (
          <button
            onClick={() => setShowInvoicesPanel(!showInvoicesPanel)}
            className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm transition hover:border-primary hover:bg-primary/5 dark:border-gray-700 dark:bg-gray-800"
          >
            <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
            <div className="text-left">
              <div className="font-bold text-foreground">{quotation.invoices.length}</div>
              <div className="text-xs text-muted-foreground">Invoice{quotation.invoices.length !== 1 ? 's' : ''}</div>
            </div>
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

      {/* Invoices panel (expandable) */}
      {showInvoicesPanel && quotation.invoices && quotation.invoices.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Icon icon="solar:bill-list-bold-duotone" className="h-5 w-5 text-blue-600" />
              Linked Invoices
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice #</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                  <TableHead></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {quotation.invoices.map((inv: any) => (
                  <TableRow key={inv.id} className="cursor-pointer hover:bg-muted/50">
                    <TableCell className="font-medium">{inv.invoice_number}</TableCell>
                    <TableCell>{formatDate(inv.invoice_date)}</TableCell>
                    <TableCell>
                      <Badge variant="secondary">{inv.lifecycle_status || inv.status}</Badge>
                    </TableCell>
                    <TableCell className="text-right">{formatCurrency(inv.total)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(inv.balance)}</TableCell>
                    <TableCell>
                      <Button size="sm" variant="outline" onClick={() => navigate(`/invoices/${inv.id}`)}>
                        <Icon icon="solar:eye-bold" className="h-3 w-3" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
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
                <div className="font-medium">{quotation.internal_reference || '-'}</div>
              </div>
              <div>
                <div className="text-muted-foreground">Salesperson</div>
                <div className="font-medium">{quotation.salesperson || '-'}</div>
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
                <span className="text-muted-foreground">Discount</span>
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
                  <TableHead className="text-right">Qty</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Tax</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {quotation.lines?.map((line: any, index: number) => (
                  <TableRow key={line.id}>
                    <TableCell>{index + 1}</TableCell>
                    <TableCell className="font-medium">{line.product?.name || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product?.sku || '-'}</TableCell>
                    <TableCell>{line.description || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.model_number || line.product?.manufacturer_part_number || '-'}</TableCell>
                    <TableCell className="text-muted-foreground">{line.product_notes || '-'}</TableCell>
                  <TableCell className="text-right">{line.quantity}</TableCell>
                  <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                  <TableCell className="text-right">{line.tax_rate}%</TableCell>
                  <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
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
    </div>
  )
}

export default QuotationDetailPage
