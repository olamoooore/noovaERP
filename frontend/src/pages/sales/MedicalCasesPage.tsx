import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Pagination } from '@/components/ui/Pagination'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { useNavigate } from 'react-router-dom'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

const STATUS_FILTERS = [
  { value: 'all', label: 'All Statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'allocated', label: 'Allocated' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

const STATUS_COLORS: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  allocated: 'bg-blue-100 text-blue-800',
  delivered: 'bg-yellow-100 text-yellow-800',
  invoiced: 'bg-purple-100 text-purple-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

export function MedicalCasesPage() {
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: response, isLoading } = useQuery({
    queryKey: ['medical-cases', page, perPage, statusFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (statusFilter !== 'all') params.status = statusFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      const res = await api.get('/medical-cases', { params })
      return res.data
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/medical-cases/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['medical-cases'] })
      toast({ title: 'Success', description: 'Medical case deleted successfully' })
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to delete', variant: 'destructive' })
    },
  })

  const cases = response?.data || []
  const { sorted: sortedCases, sortKey, sortDir, toggle: toggleSort } = useSort<any>(cases, 'case_date', 'desc')
  const meta = response?.meta || { current_page: 1, last_page: 1, total: 0 }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Medical Cases</h1>
          <p className="text-muted-foreground mt-1">Manage medical cases linked to customers and invoices</p>
        </div>
        <div className="flex gap-2">
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => navigate('/sales/medical-cases/create')}>
            Create Case
          </HeaderActionButton>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center gap-2">
            <div className="mr-auto">
              <CardTitle>All Medical Cases</CardTitle>
              <CardDescription>Search, filter and open a case</CardDescription>
            </div>

            <div className="relative w-full sm:w-64">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                placeholder="Search cases..."
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
              <p className="mt-2 text-muted-foreground">Loading medical cases...</p>
            </div>
          ) : cases.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:folder-open-bold-duotone" className="h-12 w-12 mx-auto mb-3 opacity-50" />
              <p>No medical cases found.</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Case No" sortKey="case_no" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Patient Name" sortKey="patient_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Case Date" sortKey="case_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Location" sortKey="location" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Invoice" sortKey="invoice.invoice_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedCases.map((c: any) => (
                    <TableRow key={c.id} className="cursor-pointer" onClick={() => navigate(`/sales/medical-cases/${c.id}`)}>
                      <TableCell className="font-medium">{c.case_no}</TableCell>
                      <TableCell>{c.patient_name || '-'}</TableCell>
                      <TableCell>{c.case_date ? formatDate(c.case_date) : '-'}</TableCell>
                      <TableCell>{c.location || '-'}</TableCell>
                      <TableCell>
                        <Badge className={STATUS_COLORS[c.status] || 'bg-gray-100 text-gray-800'}>
                          {c.status}
                        </Badge>
                      </TableCell>
                      <TableCell>{c.invoice?.invoice_number || '-'}</TableCell>
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
                label="medical cases"
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
