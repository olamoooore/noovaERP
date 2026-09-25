import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
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
import { formatDate, formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  allocated: 'bg-blue-100 text-blue-800',
  delivered: 'bg-yellow-100 text-yellow-800',
  invoiced: 'bg-purple-100 text-purple-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

export function MedicalCaseDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: medicalCase, isLoading } = useQuery({
    queryKey: ['medical-case', id],
    queryFn: async () => {
      const res = await api.get(`/medical-cases/${id}`)
      return res.data
    },
    enabled: !!id,
  })

  const cancelMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/medical-cases/${id}/cancel`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['medical-case', id] })
      queryClient.invalidateQueries({ queryKey: ['medical-cases'] })
      toast({ title: 'Success', description: 'Medical case cancelled successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to cancel', variant: 'destructive' })
    },
  })

  const completeMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/medical-cases/${id}/complete`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['medical-case', id] })
      queryClient.invalidateQueries({ queryKey: ['medical-cases'] })
      toast({ title: 'Success', description: 'Medical case completed successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to complete', variant: 'destructive' })
    },
  })

  const [lifecycleAction, setLifecycleAction] = useState<'allocate' | 'deliver' | 'invoice' | null>(null)
  const [qtyByItem, setQtyByItem] = useState<Record<number, string>>({})
  const [invoiceId, setInvoiceId] = useState('')

  const { data: customerInvoices } = useQuery({
    queryKey: ['case-customer-invoices', medicalCase?.customer_id],
    queryFn: async () => {
      const res = await api.get('/invoices', {
        params: { customer_id: medicalCase.customer_id, per_page: 50 },
      })
      return res.data.data || []
    },
    enabled: lifecycleAction === 'invoice' && !!medicalCase?.customer_id,
  })

  const openLifecycleDialog = (action: 'allocate' | 'deliver' | 'invoice') => {
    const items = medicalCase?.items || []
    const seed: Record<number, string> = {}
    items.forEach((item: any) => {
      if (action === 'allocate') {
        seed[item.id] = String(item.allocated_qty ?? 0)
      } else if (action === 'deliver') {
        seed[item.id] = String(item.allocated_qty ?? 0)
      } else {
        seed[item.id] = String(item.delivered_qty ?? 0)
      }
    })
    setQtyByItem(seed)
    setInvoiceId(medicalCase?.invoice_id ? String(medicalCase.invoice_id) : '')
    setLifecycleAction(action)
  }

  const lifecycleMutation = useMutation({
    mutationFn: async () => {
      const items = medicalCase?.items || []
      if (lifecycleAction === 'allocate') {
        return api.post(`/medical-cases/${id}/allocate`, {
          items: items.map((item: any) => ({ id: item.id, allocated_qty: Number(qtyByItem[item.id] ?? 0) })),
        })
      }
      if (lifecycleAction === 'deliver') {
        return api.post(`/medical-cases/${id}/deliver`, {
          items: items.map((item: any) => ({ id: item.id, delivered_qty: Number(qtyByItem[item.id] ?? 0) })),
        })
      }
      return api.post(`/medical-cases/${id}/invoice`, {
        invoice_id: Number(invoiceId),
        items: items.map((item: any) => ({ id: item.id, invoiced_qty: Number(qtyByItem[item.id] ?? 0) })),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['medical-case', id] })
      queryClient.invalidateQueries({ queryKey: ['medical-cases'] })
      toast({ title: 'Success', description: `Case ${lifecycleAction === 'allocate' ? 'allocated' : lifecycleAction === 'deliver' ? 'delivered' : 'invoiced'} successfully` })
      setLifecycleAction(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || error.response?.data?.error || 'Failed to update case',
        variant: 'destructive',
      })
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    )
  }

  if (!medicalCase) {
    return (
      <div className="text-center py-12">
        <Icon icon="solar:folder-open-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
        <p className="text-muted-foreground">Medical case not found</p>
        <Button variant="outline" className="mt-4" onClick={() => navigate('/sales/medical-cases')}>
          Back to Cases
        </Button>
      </div>
    )
  }

  const items = medicalCase.items || []

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="print:hidden">
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/sales/medical-cases')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold">{medicalCase.case_no}</h1>
            <Badge className={STATUS_COLORS[medicalCase.status] || 'bg-gray-100 text-gray-800'}>
              {medicalCase.status}
            </Badge>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {medicalCase.status === 'draft' && (
              <>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:pen-bold-duotone"
                  onClick={() => navigate(`/sales/medical-cases/${id}/edit`)}
                >
                  Edit
                </HeaderActionButton>
                <HeaderActionButton icon="solar:box-bold-duotone" onClick={() => openLifecycleDialog('allocate')}>
                  Allocate
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:close-circle-bold-duotone"
                  className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                  onClick={() => cancelMutation.mutate()}
                >
                  Cancel Case
                </HeaderActionButton>
              </>
            )}
            {medicalCase.status === 'allocated' && (
              <>
                <HeaderActionButton icon="solar:delivery-bold-duotone" onClick={() => openLifecycleDialog('deliver')}>
                  Record Delivery
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:file-text-bold-duotone"
                  onClick={() => openLifecycleDialog('invoice')}
                >
                  Invoice
                </HeaderActionButton>
              </>
            )}
            {medicalCase.status === 'delivered' && (
              <HeaderActionButton icon="solar:file-text-bold-duotone" onClick={() => openLifecycleDialog('invoice')}>
                Invoice
              </HeaderActionButton>
            )}
            {medicalCase.status === 'invoiced' && (
              <HeaderActionButton icon="solar:check-circle-bold-duotone" onClick={() => completeMutation.mutate()}>
                Mark Complete
              </HeaderActionButton>
            )}
          </div>
        </div>
      </div>

      {/* Smart Buttons */}
      <div className="flex flex-wrap gap-2 print:hidden">
        {medicalCase.patient_name && (
          <span className="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/5 px-3 text-xs font-medium text-primary">
            <Icon icon="solar:user-bold-duotone" className="h-3.5 w-3.5" />
            {medicalCase.patient_name}
          </span>
        )}
        {medicalCase.invoice && (
          <button
            type="button"
            onClick={() => navigate(`/invoices/${medicalCase.invoice_id}`)}
            className="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/5 px-3 text-xs font-medium text-primary transition-colors hover:border-primary/50 hover:bg-primary/10"
          >
            <Icon icon="solar:file-text-bold-duotone" className="h-3.5 w-3.5" />
            {medicalCase.invoice.invoice_number}
          </button>
        )}
        <button
          type="button"
          onClick={() => navigate('/sales/bonus-schemes')}
          className="inline-flex h-8 items-center gap-1.5 rounded-full border border-primary/30 bg-primary/5 px-3 text-xs font-medium text-primary transition-colors hover:border-primary/50 hover:bg-primary/10"
        >
          <Icon icon="solar:gift-bold-duotone" className="h-3.5 w-3.5" />
          Bonus Schemes
        </button>
      </div>

      {/* Case Details */}
      <Card className="print:shadow-none">
        <CardHeader>
          <CardTitle>Case Details</CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div>
              <p className="text-sm text-muted-foreground">Case No</p>
              <p className="font-medium">{medicalCase.case_no}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Patient Name</p>
              <p className="font-medium">{medicalCase.patient_name || '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Case Date</p>
              <p className="font-medium">{medicalCase.case_date ? formatDate(medicalCase.case_date) : '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Procedure Date</p>
              <p className="font-medium">{medicalCase.procedure_date ? formatDate(medicalCase.procedure_date) : '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Location</p>
              <p className="font-medium">{medicalCase.location || '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Patient Reference</p>
              <p className="font-medium">{medicalCase.patient_reference || '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Doctor</p>
              <p className="font-medium">{medicalCase.doctor_contact?.name || '-'}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Requestor</p>
              <p className="font-medium">{medicalCase.requestor_contact?.name || '-'}</p>
            </div>
          </div>

          {medicalCase.notes && (
            <div className="mt-6">
              <p className="text-sm text-muted-foreground">Notes</p>
              <p className="mt-1">{medicalCase.notes}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Line Items */}
      <Card className="print:shadow-none">
        <CardHeader>
          <CardTitle>Case Items</CardTitle>
        </CardHeader>
        <CardContent>
          {items.length === 0 ? (
            <div className="py-8 text-center text-muted-foreground">
              <p>No items in this case</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead>Allocated Qty</TableHead>
                  <TableHead>Delivered Qty</TableHead>
                  <TableHead>Invoiced Qty</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead>FOC</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((item: any) => (
                  <TableRow key={item.id}>
                    <TableCell className="font-medium">{item.product?.name || '-'}</TableCell>
                    <TableCell>{item.allocated_qty}</TableCell>
                    <TableCell>{item.delivered_qty}</TableCell>
                    <TableCell>{item.invoiced_qty}</TableCell>
                    <TableCell className="text-right">{formatCurrency(item.unit_price)}</TableCell>
                    <TableCell className="text-right">{formatCurrency(item.allocated_qty * item.unit_price)}</TableCell>
                    <TableCell>
                      {item.is_foc && (
                        <Badge className="bg-green-100 text-green-800">FOC</Badge>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Dialog open={lifecycleAction !== null} onOpenChange={(open) => !open && setLifecycleAction(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {lifecycleAction === 'allocate' ? 'Allocate Case Items' : lifecycleAction === 'deliver' ? 'Record Delivery' : 'Invoice Case Items'}
            </DialogTitle>
            <DialogDescription>
              {lifecycleAction === 'invoice'
                ? 'Select the invoice and confirm the invoiced quantity per item.'
                : 'Confirm the quantity per item.'}
            </DialogDescription>
          </DialogHeader>

          {lifecycleAction === 'invoice' && (
            <div className="space-y-2">
              <Label htmlFor="case-invoice">Invoice</Label>
              <select
                id="case-invoice"
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                value={invoiceId}
                onChange={(event) => setInvoiceId(event.target.value)}
              >
                <option value="">Select an invoice...</option>
                {(customerInvoices || []).map((invoice: any) => (
                  <option key={invoice.id} value={invoice.id}>
                    {invoice.invoice_number} — {formatCurrency(invoice.total)}
                  </option>
                ))}
              </select>
            </div>
          )}

          <div className="space-y-3">
            {items.map((item: any) => (
              <div key={item.id} className="flex items-center justify-between gap-4">
                <span className="text-sm">{item.product?.name || `Item #${item.id}`}</span>
                <Input
                  type="number"
                  min="0"
                  step="any"
                  className="w-28"
                  value={qtyByItem[item.id] ?? ''}
                  onChange={(event) =>
                    setQtyByItem((previous) => ({ ...previous, [item.id]: event.target.value }))
                  }
                />
              </div>
            ))}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setLifecycleAction(null)}>
              Cancel
            </Button>
            <Button
              onClick={() => lifecycleMutation.mutate()}
              disabled={
                lifecycleMutation.isPending ||
                (lifecycleAction === 'invoice' && !invoiceId)
              }
            >
              {lifecycleMutation.isPending ? 'Saving...' : 'Confirm'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delivery Address */}
      {medicalCase.delivery_address && (
        <Card className="print:shadow-none">
          <CardHeader>
            <CardTitle>Delivery Address</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="font-medium">{medicalCase.delivery_address.site_name || '-'}</p>
            <p className="text-sm text-muted-foreground mt-1">
              {medicalCase.delivery_address.address_line_1 || ''}
              {medicalCase.delivery_address.address_line_2 ? `, ${medicalCase.delivery_address.address_line_2}` : ''}
              {medicalCase.delivery_address.Build_name ? `, ${medicalCase.delivery_address.Build_name}` : ''}
              {medicalCase.delivery_address.postal_code ? ` ${medicalCase.delivery_address.postal_code}` : ''}
            </p>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
