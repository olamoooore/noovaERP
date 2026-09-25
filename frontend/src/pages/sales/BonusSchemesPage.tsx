import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Pagination } from '@/components/ui/Pagination'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { useNavigate } from 'react-router-dom'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

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
  const [perPage, setPerPage] = useState(25)
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
  const { sorted: sortedSchemes, sortKey, sortDir, toggle: toggleSort } = useSort<any>(schemes, 'start_date', 'desc')
  const meta = response?.meta || { current_page: 1, last_page: 1, total: 0 }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Bonus Schemes</h1>
          <p className="text-muted-foreground mt-1">Configure product discounts and FOC rules for customers</p>
        </div>
        <div className="flex gap-2">
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => navigate('/sales/bonus-schemes/create')}>
            Create Scheme
          </HeaderActionButton>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center gap-2">
            <div className="mr-auto">
              <CardTitle>All Bonus Schemes</CardTitle>
              <CardDescription>Search, filter and open a scheme</CardDescription>
            </div>

            <div className="relative w-full sm:w-64">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                placeholder="Search schemes..."
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
                className="h-8 rounded-full pl-9 pr-3 text-xs"
              />
            </div>

            <Select value={statusFilter} onValueChange={(value) => { setStatusFilter(value); setPage(1) }}>
              <SelectTrigger className="h-8 w-40 rounded-full text-xs" aria-label="Filter by status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {STATUS_FILTERS.map((f) => (
                  <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </CardHeader>
        <CardContent>

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
                    <SortableHead label="Scheme Code" sortKey="scheme_code" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Name" sortKey="name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Period" sortKey="start_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead>Products</TableHead>
                    <TableHead>Customers</TableHead>
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedSchemes.map((s: any) => (
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

              <Pagination
                page={page}
                totalPages={meta.last_page}
                onPageChange={setPage}
                from={(page - 1) * perPage + 1}
                to={Math.min(page * perPage, meta.total)}
                total={meta.total}
                label="bonus schemes"
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
