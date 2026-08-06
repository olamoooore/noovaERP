import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { useNavigate } from 'react-router-dom'

const STATUS_FILTERS = [
  { value: 'all', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'expired', label: 'Expired' },
]

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  active: 'bg-green-100 text-green-800',
  inactive: 'bg-yellow-100 text-yellow-800',
  expired: 'bg-red-100 text-red-800',
}

export function BonusSchemesPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [page, setPage] = useState(1)
  const perPage = 25
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: response, isLoading } = useQuery({
    queryKey: ['bonus-schemes', page, perPage, statusFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (statusFilter !== 'all') params.status = statusFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      const res = await api.get('/bonus-schemes', { params })
      return res.data
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/bonus-schemes/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bonus-schemes'] })
      toast({ title: 'Success', description: 'Bonus scheme deleted successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to delete', variant: 'destructive' })
    },
  })

  const schemes = response?.data || []
  const meta = response?.meta || { current_page: 1, last_page: 1, total: 0 }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Bonus Schemes</h1>
          <p className="text-muted-foreground mt-1">Configure product discounts and FOC rules for customers</p>
        </div>
        <div className="flex gap-2">
          <Button onClick={() => navigate('/sales/bonus-schemes/create')}>
            <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
            Create Scheme
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>All Bonus Schemes</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3 mb-4">
            <input
              type="text"
              placeholder="Search schemes..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            />
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
              className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            >
              {STATUS_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>{f.label}</option>
              ))}
            </select>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto" />
              <p className="mt-2 text-muted-foreground">Loading bonus schemes...</p>
            </div>
          ) : schemes.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:gift-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
              <p>No bonus schemes found.</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Scheme Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead>Products</TableHead>
                    <TableHead>Customers</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {schemes.map((s: any) => (
                    <TableRow key={s.id} className="cursor-pointer" onClick={() => navigate(`/sales/bonus-schemes/${s.id}`)}>
                      <TableCell className="font-medium">{s.scheme_code}</TableCell>
                      <TableCell>{s.name}</TableCell>
                      <TableCell>{formatDate(s.start_date)} - {s.end_date ? formatDate(s.end_date) : 'Ongoing'}</TableCell>
                      <TableCell>{s.products?.length || 0}</TableCell>
                      <TableCell>{s.customer_assignments?.length || 0}</TableCell>
                      <TableCell>
                        <Badge className={STATUS_COLORS[s.status] || 'bg-gray-100 text-gray-800'}>
                          {s.status}
                        </Badge>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {meta.last_page > 1 && (
                <div className="flex items-center justify-between mt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {meta.current_page} of {meta.last_page} ({meta.total} total)
                  </p>
                  <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => setPage(p => Math.max(1, p - 1))} disabled={page === 1}>
                      Previous
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setPage(p => Math.min(meta.last_page, p + 1))} disabled={page === meta.last_page}>
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
