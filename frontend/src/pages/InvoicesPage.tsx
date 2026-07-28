import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { useNavigate } from 'react-router-dom'

export function InvoicesPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [page, setPage] = useState(1)
  const [perPage] = useState(20)

  const { data: response, isLoading } = useQuery({
    queryKey: ['invoices', page, perPage, statusFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (statusFilter !== 'all') params.status = statusFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      const res = await api.get('/invoices', { params })
      return res.data
    },
  })

  const invoices = response?.data || []
  const meta = {
    current_page: response?.current_page || 1,
    last_page: response?.last_page || 1,
    total: response?.total || 0,
  }

  const sendInvoiceMutation = useMutation({
    mutationFn: async (id: number) => {
      const res = await api.post(`/invoices/${id}/send`)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      toast({ title: 'Invoice posted successfully' })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to post invoice' })
    },
  })

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/invoices/bulk-delete', { ids })
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      setSelectedIds([])
      toast({ title: `${data.deleted} invoice(s) deleted` })
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Bulk delete failed', description: error.response?.data?.message })
    },
  })

  const toggleSelectAll = () => {
    if (selectedIds.length === invoices.length) {
      setSelectedIds([])
    } else {
      setSelectedIds(invoices.map((i: any) => i.id))
    }
  }

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]))
  }

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      posted: 'bg-blue-100 text-blue-800',
      sent: 'bg-blue-100 text-blue-800',
      paid: 'bg-green-100 text-green-800',
      partial: 'bg-yellow-100 text-yellow-800',
      overdue: 'bg-red-100 text-red-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Invoices</h1>
          <p className="text-muted-foreground mt-1">All invoices — created from Sales Orders or directly.</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => navigate('/invoices/import')}>
            <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
            Import
          </Button>
          <Button onClick={() => navigate('/invoices/create')}>
            <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
            Create Invoice
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>All Invoices</span>
            {selectedIds.length > 0 && (
              <Button variant="destructive" size="sm" onClick={() => {
                if (confirm(`Delete ${selectedIds.length} invoice(s)?`)) bulkDeleteMutation.mutate(selectedIds)
              }}>
                <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 mr-1" />
                Delete ({selectedIds.length})
              </Button>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex gap-3 mb-4">
            <input
              type="text"
              placeholder="Search invoices..."
              value={searchQuery}
              onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
              className="px-3 py-2 border rounded-md text-sm w-64"
            />
            <select
              value={statusFilter}
              onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }}
              className="px-3 py-2 border rounded-md text-sm"
            >
              <option value="all">All Status</option>
              <option value="draft">Draft</option>
              <option value="posted">Posted</option>
              <option value="sent">Sent</option>
              <option value="paid">Paid</option>
              <option value="partial">Partial</option>
              <option value="overdue">Overdue</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading invoices...</p>
            </div>
          ) : invoices.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:document-text-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4">No invoices found.</p>
            </div>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[40px]">
                      <input type="checkbox" checked={selectedIds.length === invoices.length && invoices.length > 0} onChange={toggleSelectAll} />
                    </TableHead>
                    <TableHead>Invoice #</TableHead>
                    <TableHead>Customer</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Due Date</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoices.map((invoice: any) => (
                    <TableRow
                      key={invoice.id}
                      className="cursor-pointer hover:bg-muted/50"
                      onClick={() => navigate(`/invoices/${invoice.id}`)}
                    >
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <input type="checkbox" checked={selectedIds.includes(invoice.id)} onChange={() => toggleSelect(invoice.id)} />
                      </TableCell>
                      <TableCell className="font-medium">{invoice.invoice_number}</TableCell>
                      <TableCell>
                        <div>
                          {invoice.customer?.code && (
                            <span className="text-xs font-mono text-muted-foreground">{invoice.customer.code}</span>
                          )}
                          <div>{invoice.customer?.name}</div>
                        </div>
                      </TableCell>
                      <TableCell>{formatDate(invoice.invoice_date || invoice.created_at)}</TableCell>
                      <TableCell>{formatDate(invoice.due_date || invoice.invoice_date || invoice.created_at)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(invoice.total)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(invoice.balance)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary" className={getStatusBadge(invoice.status)}>
                          {invoice.status}
                        </Badge>
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <div className="flex gap-1">
                          <Button size="sm" variant="outline" onClick={() => navigate(`/invoices/${invoice.id}`)} title="View">
                            <Icon icon="solar:eye-bold" className="h-3 w-3" />
                          </Button>
                          {invoice.status === 'draft' && (
                            <>
                              <Button size="sm" variant="outline" onClick={() => navigate(`/invoices/${invoice.id}/edit`)} title="Edit">
                                <Icon icon="solar:pen-bold" className="h-3 w-3" />
                              </Button>
                              <Button size="sm" variant="outline" onClick={() => sendInvoiceMutation.mutate(invoice.id)} title="Post">
                                <Icon icon="solar:paper-plane-bold" className="h-3 w-3" />
                              </Button>
                            </>
                          )}
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={async () => {
                              try {
                                const res = await api.get(`/invoices/${invoice.id}/pdf`, { responseType: 'blob' })
                                const blob = new Blob([res.data], { type: 'application/pdf' })
                                const url = window.URL.createObjectURL(blob)
                                const link = document.createElement('a')
                                link.href = url
                                link.download = `${invoice.invoice_number}.pdf`
                                document.body.appendChild(link)
                                link.click()
                                document.body.removeChild(link)
                                window.URL.revokeObjectURL(url)
                              } catch {
                                toast({ variant: 'destructive', title: 'Failed to download PDF' })
                              }
                            }}
                            title="Download PDF"
                          >
                            <Icon icon="solar:download-bold" className="h-3 w-3" />
                          </Button>
                        </div>
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
                    <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                      Previous
                    </Button>
                    <Button variant="outline" size="sm" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
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

export default InvoicesPage
