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
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'

export function InvoicesPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [customerFilter, setCustomerFilter] = useState('')
  const [dateFilter, setDateFilter] = useState<'all' | 'yearly' | 'monthly' | 'custom'>('all')
  const [selectedYear, setSelectedYear] = useState(String(new Date().getFullYear()))
  const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7))
  const [customStartDate, setCustomStartDate] = useState('')
  const [customEndDate, setCustomEndDate] = useState('')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [page, setPage] = useState(1)
  const [perPage] = useState(20)

  const { data: response, isLoading } = useQuery({
    queryKey: ['invoices', page, perPage, statusFilter, searchQuery, customerFilter, dateFilter, selectedYear, selectedMonth, customStartDate, customEndDate],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (statusFilter !== 'all') params.status = statusFilter
      if (searchQuery.trim()) params.search = searchQuery.trim()
      if (customerFilter) params.customer_id = customerFilter
      if (dateFilter === 'yearly') {
        params.start_date = `${selectedYear}-01-01`
        params.end_date = `${selectedYear}-12-31`
      } else if (dateFilter === 'monthly' && selectedMonth) {
        const [year, month] = selectedMonth.split('-').map(Number)
        params.start_date = `${selectedMonth}-01`
        params.end_date = `${selectedMonth}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}`
      } else if (dateFilter === 'custom') {
        if (customStartDate) params.start_date = customStartDate
        if (customEndDate) params.end_date = customEndDate
      }
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

  const bulkPostMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/invoices/bulk-post', { ids })
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      setSelectedIds([])
      const postedCount = data.posted?.length || 0
      const skippedCount = data.skipped?.length || 0
      if (skippedCount > 0) {
        toast({
          title: `Bulk post complete: ${postedCount} posted, ${skippedCount} skipped`,
          description: data.skipped.slice(0, 3).map((s: any) => `#${s.id}: ${s.reason}`).join('; '),
          variant: 'default',
        })
      } else {
        toast({ title: `${postedCount} invoice(s) posted successfully` })
      }
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Bulk post failed', description: error.response?.data?.message })
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
              <div className="flex gap-2">
                <Button
                  size="sm"
                  onClick={() => {
                    if (confirm(`Post ${selectedIds.length} invoice(s)?`)) bulkPostMutation.mutate(selectedIds)
                  }}
                  disabled={bulkPostMutation.isPending}
                >
                  <Icon icon="solar:check-circle-bold" className="h-4 w-4 mr-1" />
                  {bulkPostMutation.isPending ? 'Posting...' : `Bulk Post (${selectedIds.length})`}
                </Button>
                <Button variant="destructive" size="sm" onClick={() => {
                  if (confirm(`Delete ${selectedIds.length} invoice(s)?`)) bulkDeleteMutation.mutate(selectedIds)
                }}>
                  <Icon icon="solar:trash-bin-minimalistic-bold" className="h-4 w-4 mr-1" />
                  Delete ({selectedIds.length})
                </Button>
              </div>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-4 flex flex-wrap gap-3">
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
            <div className="w-48 max-w-full">
              <CustomerSearchCombobox
                value={customerFilter}
                onValueChange={(value) => { setCustomerFilter(value); setPage(1) }}
                placeholder="All Customers"
              />
            </div>
            <select
              value={dateFilter}
              onChange={(e) => { setDateFilter(e.target.value as typeof dateFilter); setPage(1) }}
              className="rounded-md border px-3 py-2 text-sm"
            >
              <option value="all">All Dates</option>
              <option value="yearly">Yearly</option>
              <option value="monthly">Monthly</option>
              <option value="custom">Custom Date</option>
            </select>
            {dateFilter === 'yearly' && (
              <input
                type="number"
                min="2000"
                max="2100"
                value={selectedYear}
                onChange={(e) => { setSelectedYear(e.target.value); setPage(1) }}
                aria-label="Invoice year"
                className="w-28 rounded-md border px-3 py-2 text-sm"
              />
            )}
            {dateFilter === 'monthly' && (
              <input
                type="month"
                value={selectedMonth}
                onChange={(e) => { setSelectedMonth(e.target.value); setPage(1) }}
                aria-label="Invoice month"
                className="rounded-md border px-3 py-2 text-sm"
              />
            )}
            {dateFilter === 'custom' && (
              <>
                <input
                  type="date"
                  value={customStartDate}
                  onChange={(e) => { setCustomStartDate(e.target.value); setPage(1) }}
                  aria-label="Invoice start date"
                  className="rounded-md border px-3 py-2 text-sm"
                />
                <input
                  type="date"
                  min={customStartDate || undefined}
                  value={customEndDate}
                  onChange={(e) => { setCustomEndDate(e.target.value); setPage(1) }}
                  aria-label="Invoice end date"
                  className="rounded-md border px-3 py-2 text-sm"
                />
              </>
            )}
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
                                <Icon icon="solar:check-circle-bold" className="h-3 w-3" />
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
