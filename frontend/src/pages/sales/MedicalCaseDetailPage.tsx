import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Separator } from '@/components/ui/separator'
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
      <div className="flex items-center justify-between print:hidden">
        <Button variant="outline" onClick={() => navigate('/sales/medical-cases')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <div className="flex gap-2">
          {medicalCase.status === 'draft' && (
            <>
              <Button variant="outline" onClick={() => navigate(`/sales/medical-cases/${id}/edit`)}>
                <Icon icon="solar:pen-bold-duotone" className="mr-2 h-4 w-4" />
                Edit
              </Button>
              <Button variant="destructive" onClick={() => cancelMutation.mutate()}>
                Cancel Case
              </Button>
            </>
          )}
          {medicalCase.status === 'invoiced' && (
            <Button onClick={() => completeMutation.mutate()}>
              <Icon icon="solar:check-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Mark Complete
            </Button>
          )}
        </div>
      </div>

      <div className="flex items-center gap-3">
        <h1 className="text-3xl font-bold">{medicalCase.case_no}</h1>
        <Badge className={STATUS_COLORS[medicalCase.status] || 'bg-gray-100 text-gray-800'}>
          {medicalCase.status}
        </Badge>
      </div>

      {/* Smart Buttons */}
      <div className="flex gap-3 print:hidden">
        {medicalCase.customer && (
          <button
            onClick={() => navigate(`/customers/${medicalCase.customer_id}`)}
            className="inline-flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-muted transition-colors"
          >
            <Icon icon="solar:building-bold-duotone" className="h-4 w-4" />
            {medicalCase.customer.company_name}
          </button>
        )}
        {medicalCase.invoice && (
          <button
            onClick={() => navigate(`/invoices/${medicalCase.invoice_id}`)}
            className="inline-flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-muted transition-colors"
          >
            <Icon icon="solar:file-text-bold-duotone" className="h-4 w-4" />
            {medicalCase.invoice.invoice_number}
          </button>
        )}
        {medicalCase.bonusScheme && (
          <button
            onClick={() => navigate(`/sales/bonus-schemes/${medicalCase.bonus_scheme_id}`)}
            className="inline-flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-muted transition-colors"
          >
            <Icon icon="solar:gift-bold-duotone" className="h-4 w-4" />
            {medicalCase.bonusScheme.name}
          </button>
        )}
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
              <p className="text-sm text-muted-foreground">Customer</p>
              <p className="font-medium">{medicalCase.customer?.company_name || '-'}</p>
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
