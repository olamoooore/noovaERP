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
          {(quotation.status === 'draft' || quotation.status === 'sent') && (
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
        </div>
      </div>

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
            </div>

            {quotation.sales_order && (
              <div className="mt-4 p-3 rounded-lg bg-green-50 border border-green-200">
                <div className="text-sm font-medium text-green-800">
                  Linked Sales Order: {quotation.sales_order.sales_order_number}
                </div>
                <div className="text-xs text-green-600 mt-1">
                  Status: {quotation.sales_order.status}
                </div>
              </div>
            )}

            {quotation.invoices && quotation.invoices.length > 0 && (
              <div className="mt-4 p-3 rounded-lg bg-blue-50 border border-blue-200">
                <div className="text-sm font-medium text-blue-800">
                  Invoices ({quotation.invoices.length})
                </div>
                {quotation.invoices.map((inv: any) => (
                  <div key={inv.id} className="text-xs text-blue-600 mt-1">
                    {inv.invoice_number} — {formatDate(inv.invoice_date)} — {formatCurrency(inv.total)}
                  </div>
                ))}
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
                <TableHead>Description</TableHead>
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
                  <TableCell>{line.product?.name || '-'}</TableCell>
                  <TableCell>{line.description}</TableCell>
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
