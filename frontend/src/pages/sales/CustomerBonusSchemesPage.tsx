import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Pagination } from '@/components/ui/Pagination'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { useNavigate } from 'react-router-dom'

const STATUS_COLORS: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  suspended: 'bg-yellow-100 text-yellow-800',
  expired: 'bg-red-100 text-red-800',
}

export function CustomerBonusSchemesPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: response, isLoading } = useQuery({
    queryKey: ['customer-bonus-schemes', page, perPage, statusFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (statusFilter !== 'all') params.status = statusFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      const res = await api.get('/customer-bonus-schemes', { params })
      return res.data
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/customer-bonus-schemes/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer-bonus-schemes'] })
      toast({ title: 'Success', description: 'Assignment deleted successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to delete', variant: 'destructive' })
    },
  })

  const assignments = response?.data || []
  const meta = response?.meta || { current_page: 1, last_page: 1, total: 0 }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Customer Bonus Schemes</h1>
          <p className="text-muted-foreground mt-1">Assign bonus schemes to eligible customers</p>
        </div>
        <div className="flex gap-2">
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => navigate('/sales/customer-bonus-schemes/create')}>
            Assign Scheme
          </HeaderActionButton>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>All Assignments</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3 mb-4">
            <input
              type="text"
              placeholder="Search assignments..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            />
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
              className="flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
            >
              <option value="all">All Statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="expired">Expired</option>
            </select>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary mx-auto" />
              <p className="mt-2 text-muted-foreground">Loading assignments...</p>
            </div>
          ) : assignments.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:users-group-rounded-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
              <p>No assignments found.</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Customer</TableHead>
                    <TableHead>Bonus Scheme</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Remarks</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {assignments.map((a: any) => (
                    <TableRow key={a.id} className="cursor-pointer" onClick={() => navigate(`/sales/customer-bonus-schemes/${a.id}`)}>
                      <TableCell className="font-medium">{a.customer?.company_name || '-'}</TableCell>
                      <TableCell>{a.bonus_scheme?.name || '-'}</TableCell>
                      <TableCell>{formatDate(a.start_date)} - {a.end_date ? formatDate(a.end_date) : 'Ongoing'}</TableCell>
                      <TableCell>
                        <Badge className={STATUS_COLORS[a.status] || 'bg-gray-100 text-gray-800'}>
                          {a.status}
                        </Badge>
                      </TableCell>
                      <TableCell>{a.remarks || '-'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              <Pagination
                page={page}
                totalPages={meta.last_page}
                onPageChange={setPage}
                from={(page - 1) * perPage + 1}
                to={Math.min(page * perPage, meta.total)}
                total={meta.total}
                label="assignments"
                pageSize={perPage}
                onPageSizeChange={(size) => { setPerPage(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
