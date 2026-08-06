import { useParams, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  active: 'bg-green-100 text-green-800',
  inactive: 'bg-yellow-100 text-yellow-800',
  expired: 'bg-red-100 text-red-800',
}

const SCHEME_TYPE_LABELS: Record<string, string> = {
  fixed_discount: 'Fixed Discount',
  percentage_discount: 'Percentage Discount',
  buy_x_get_y: 'Buy X Get Y',
}

export function BonusSchemeDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: scheme, isLoading } = useQuery({
    queryKey: ['bonus-scheme', id],
    queryFn: async () => {
      const res = await api.get(`/bonus-schemes/${id}`)
      return res.data
    },
    enabled: !!id,
  })

  const activateMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/bonus-schemes/${id}/activate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bonus-scheme', id] })
      queryClient.invalidateQueries({ queryKey: ['bonus-schemes'] })
      toast({ title: 'Success', description: 'Scheme activated successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to activate', variant: 'destructive' })
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async () => {
      await api.post(`/bonus-schemes/${id}/deactivate`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bonus-scheme', id] })
      queryClient.invalidateQueries({ queryKey: ['bonus-schemes'] })
      toast({ title: 'Success', description: 'Scheme deactivated successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to deactivate', variant: 'destructive' })
    },
  })

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary" />
      </div>
    )
  }

  if (!scheme) {
    return (
      <div className="text-center py-12">
        <Icon icon="solar:gift-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
        <p className="text-muted-foreground">Bonus scheme not found</p>
        <Button variant="outline" className="mt-4" onClick={() => navigate('/sales/bonus-schemes')}>
          Back to Schemes
        </Button>
      </div>
    )
  }

  const products = scheme.products || []
  const customers = scheme.customer_assignments || []

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between print:hidden">
        <Button variant="outline" onClick={() => navigate('/sales/bonus-schemes')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <div className="flex gap-2">
          {scheme.status === 'draft' && (
            <>
              <Button variant="outline" onClick={() => navigate(`/sales/bonus-schemes/${id}/edit`)}>
                <Icon icon="solar:pen-bold-duotone" className="mr-2 h-4 w-4" />
                Edit
              </Button>
              <Button onClick={() => activateMutation.mutate()}>
                <Icon icon="solar:play-circle-bold-duotone" className="mr-2 h-4 w-4" />
                Activate
              </Button>
            </>
          )}
          {scheme.status === 'active' && (
            <Button variant="destructive" onClick={() => deactivateMutation.mutate()}>
              <Icon icon="solar:stop-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Deactivate
            </Button>
          )}
        </div>
      </div>

      <div className="flex items-center gap-3">
        <h1 className="text-3xl font-bold">{scheme.name}</h1>
        <Badge className={STATUS_COLORS[scheme.status] || 'bg-gray-100 text-gray-800'}>
          {scheme.status}
        </Badge>
      </div>

      {/* Scheme Details */}
      <Card className="print:shadow-none">
        <CardHeader>
          <CardTitle>Scheme Details</CardTitle>
        </CardHeader>
        <CardContent className="p-6">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
            <div>
              <p className="text-sm text-muted-foreground">Scheme Code</p>
              <p className="font-medium">{scheme.scheme_code}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Name</p>
              <p className="font-medium">{scheme.name}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">Start Date</p>
              <p className="font-medium">{formatDate(scheme.start_date)}</p>
            </div>
            <div>
              <p className="text-sm text-muted-foreground">End Date</p>
              <p className="font-medium">{scheme.end_date ? formatDate(scheme.end_date) : 'Ongoing'}</p>
            </div>
          </div>
          {scheme.description && (
            <div className="mt-4">
              <p className="text-sm text-muted-foreground">Description</p>
              <p className="mt-1">{scheme.description}</p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Products */}
      <Card className="print:shadow-none">
        <CardHeader>
          <CardTitle>Scheme Products ({products.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {products.length === 0 ? (
            <div className="py-8 text-center text-muted-foreground">
              <p>No products configured for this scheme</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Discount</TableHead>
                  <TableHead>Min Qty</TableHead>
                  <TableHead>FOC Product</TableHead>
                  <TableHead>FOC Qty</TableHead>
                  <TableHead>Priority</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {products.map((p: any) => (
                  <TableRow key={p.id}>
                    <TableCell className="font-medium">{p.qualifying_product?.name || '-'}</TableCell>
                    <TableCell>
                      <Badge variant="outline">{SCHEME_TYPE_LABELS[p.scheme_type] || p.scheme_type}</Badge>
                    </TableCell>
                    <TableCell>
                      {p.scheme_type === 'fixed_discount' && `- $${p.discount_amount}`}
                      {p.scheme_type === 'percentage_discount' && `- ${p.discount_percentage}%`}
                      {p.scheme_type === 'buy_x_get_y' && '-'}
                    </TableCell>
                    <TableCell>{p.minimum_purchase_qty || '-'}</TableCell>
                    <TableCell>{p.foc_product?.name || '-'}</TableCell>
                    <TableCell>{p.foc_qty || '-'}</TableCell>
                    <TableCell>{p.priority}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Assigned Customers */}
      <Card className="print:shadow-none">
        <CardHeader>
          <CardTitle>Assigned Customers ({customers.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {customers.length === 0 ? (
            <div className="py-8 text-center text-muted-foreground">
              <p>No customers assigned to this scheme</p>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Customer</TableHead>
                  <TableHead>Start Date</TableHead>
                  <TableHead>End Date</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Remarks</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {customers.map((c: any) => (
                  <TableRow key={c.id}>
                    <TableCell className="font-medium">{c.customer?.company_name || '-'}</TableCell>
                    <TableCell>{formatDate(c.start_date)}</TableCell>
                    <TableCell>{c.end_date ? formatDate(c.end_date) : 'Ongoing'}</TableCell>
                    <TableCell>
                      <Badge className={c.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}>
                        {c.status}
                      </Badge>
                    </TableCell>
                    <TableCell>{c.remarks || '-'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
