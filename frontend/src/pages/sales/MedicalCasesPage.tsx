import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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
  const perPage = 25
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
          <CardTitle>All Medical Cases</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3 mb-4">
            <input
              type="text"
              placeholder="Search cases..."
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
                    <TableHead>Case No</TableHead>
                    <TableHead>Patient Name</TableHead>
                    <TableHead>Case Date</TableHead>
                    <TableHead>Location</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Invoice</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {cases.map((c: any) => (
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
