import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'
import pricingApi from '@/lib/pricingApi'
import { useAuthStore } from '@/store/authStore'
import { useNavigate } from 'react-router-dom'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { INVOICE_EXPORT_COLUMNS, SalesDocumentExportMenu } from '@/components/sales/SalesDocumentExportMenu'
import { SalesLineColumnMenu, SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { useBulkSelection } from '@/hooks/use-bulk-selection'
import { BulkActionBar } from '@/components/ui/bulk-action-bar'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { TagFilterSelect } from '@/components/tags/TagFilterSelect'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Pagination } from '@/components/ui/Pagination'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

export function InvoicesPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('')
  const [hiddenInvoiceColumns, setHiddenInvoiceColumns] = useState<number[]>([])
  const [bulkAction, setBulkAction] = useState<'post' | 'delete' | null>(null)
  const [statusFilter, setStatusFilter] = useState('all')
  // MRD FR-8.1: document-currency filter + currency sort on the list.
  const [currencyFilter, setCurrencyFilter] = useState('all')
  const [sortBy, setSortBy] = useState<'date' | 'currency'>('date')
  const [customerFilter, setCustomerFilter] = useState('')
  const [tagFilter, setTagFilter] = useState('')
  const [dateFilter, setDateFilter] = useState<'all' | 'yearly' | 'monthly' | 'custom'>('all')
  const [selectedYear, setSelectedYear] = useState(String(new Date().getFullYear()))
  const [selectedMonth, setSelectedMonth] = useState(new Date().toISOString().slice(0, 7))
  const [customStartDate, setCustomStartDate] = useState('')
  const [customEndDate, setCustomEndDate] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)

  const { data: filterCurrencies } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  })
  const filterCurrencyRows: any[] = (filterCurrencies as any)?.data ?? filterCurrencies ?? []

  // Wide search hits the DB per term; debounce typing like the pickers do.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearchQuery(searchQuery)
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const activeFilterParams = () => {
    const params: Record<string, string> = {}
    if (statusFilter !== 'all') params.status = statusFilter
    if (currencyFilter !== 'all') params.currency_code = currencyFilter
    if (sortBy === 'currency') params.sort_by = 'currency'
    if (debouncedSearchQuery.trim()) params.search = debouncedSearchQuery.trim()
    if (customerFilter) params.customer_id = customerFilter
    if (tagFilter) params.tag = tagFilter
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
    return params
  }

  const { data: response, isLoading } = useQuery({
    queryKey: ['invoices', page, perPage, statusFilter, currencyFilter, sortBy, debouncedSearchQuery, customerFilter, tagFilter, dateFilter, selectedYear, selectedMonth, customStartDate, customEndDate],
    queryFn: async () => {
      const params = { page, per_page: perPage, ...activeFilterParams() }
      const res = await api.get('/invoices', { params })
      return res.data
    },
  })

  const invoices = response?.data || []
  const { sorted: sortedInvoices, sortKey, sortDir, toggle: toggleSort } = useSort<any>(invoices, 'created_at', 'desc')
  const meta = {
    current_page: response?.current_page || 1,
    last_page: response?.last_page || 1,
    total: response?.total || 0,
  }

  const {
    selectedIds,
    clearSelection,
    toggleSelect,
    togglePageSelection,
    selectAllMatching,
    allPageSelected,
    allMatchingSelected,
    isSelectingAll,
  } = useBulkSelection({
    pageIds: invoices.map((invoice: any) => invoice.id),
    scopeKey: JSON.stringify(activeFilterParams()),
    fetchAllIds: async () => {
      const res = await api.get('/invoices', { params: { ...activeFilterParams(), ids_only: 1 } })
      return res.data.data
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all invoices' }),
  })

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
      clearSelection()
      toast({ title: `${data.deleted} invoice(s) deleted` })
    },
    onError: (error: any) => {
      toast({ variant: 'destructive', title: 'Bulk delete failed', description: error.response?.data?.message })
    },
  })

  const { hasPermission } = useAuthStore()
  // Bulk posting is accounting-only (server enforces accounting.post).
  const canPost = hasPermission('accounting.post')

  const bulkPostMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/invoices/bulk-post', { ids })
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      clearSelection()
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
        <div className="flex items-center justify-end gap-2">
          <SalesDocumentExportMenu
            compact
            endpoint="/invoices/export"
            filenamePrefix="invoices"
            columns={INVOICE_EXPORT_COLUMNS}
            filters={activeFilterParams()}
            selectedIds={selectedIds}
          />
          <Button
            variant="outline"
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/invoices/import')}
          >
            <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Import
          </Button>
          <Button
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/invoices/create')}
          >
            <Icon icon="solar:add-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Create Invoice
          </Button>
        </div>
      </div>

      <BulkActionBar
        selectedCount={selectedIds.length}
        pageSize={invoices.length}
        allPageSelected={allPageSelected}
        allMatchingSelected={allMatchingSelected}
        isSelectingAll={isSelectingAll}
        onSelectAllMatching={selectAllMatching}
        onClearSelection={clearSelection}
        noun="invoices"
        destructiveAction={
          <HeaderActionButton
            variant="destructive"
            icon="solar:trash-bin-minimalistic-bold"
            disabled={bulkDeleteMutation.isPending}
            onClick={() => setBulkAction('delete')}
          >
            Delete ({selectedIds.length})
          </HeaderActionButton>
        }
      >
        {canPost && (
          <HeaderActionButton
            icon="solar:check-circle-bold"
            loading={bulkPostMutation.isPending}
            disabled={bulkPostMutation.isPending}
            onClick={() => setBulkAction('post')}
          >
            Bulk Post ({selectedIds.length})
          </HeaderActionButton>
        )}
      </BulkActionBar>

      <Card>
        <CardHeader>
          <CardTitle>All Invoices</CardTitle>
        </CardHeader>
        <CardContent>
          {/* Compact single-row filter bar — filters left, column picker right */}
          <div className="mb-4 flex flex-wrap items-center gap-2">
            <div className="flex flex-wrap items-center gap-1.5 sm:flex-nowrap sm:overflow-x-auto">
            <div className="relative w-full sm:w-56">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                type="text"
                placeholder="Search invoice, customer, contact…"
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
                className="h-8 rounded-full pl-8 pr-2 text-xs"
              />
            </div>

            <Select
              value={statusFilter}
              onValueChange={(value) => { setStatusFilter(value); setPage(1) }}
            >
              <SelectTrigger className="h-8 w-28 rounded-full px-2.5 text-xs">
                <SelectValue placeholder="All Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="posted">Posted</SelectItem>
                <SelectItem value="sent">Sent</SelectItem>
                <SelectItem value="paid">Paid</SelectItem>
                <SelectItem value="partial">Partial</SelectItem>
                <SelectItem value="overdue">Overdue</SelectItem>
                <SelectItem value="cancelled">Cancelled</SelectItem>
              </SelectContent>
            </Select>

            {/* FR-8.1: currency dimension on the document list. */}
            <Select
              value={currencyFilter}
              onValueChange={(value) => { setCurrencyFilter(value); setPage(1) }}
            >
              <SelectTrigger className="h-8 w-28 rounded-full px-2.5 text-xs">
                <SelectValue placeholder="All Currencies" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Currencies</SelectItem>
                {filterCurrencyRows.map((currency: any) => (
                  <SelectItem key={currency.code} value={currency.code}>{currency.code}</SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select
              value={sortBy}
              onValueChange={(value) => { setSortBy(value as 'date' | 'currency'); setPage(1) }}
            >
              <SelectTrigger className="h-8 w-28 rounded-full px-2.5 text-xs">
                <SelectValue placeholder="Sort" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="date">Newest</SelectItem>
                <SelectItem value="currency">Currency</SelectItem>
              </SelectContent>
            </Select>

            <div className="w-full sm:w-40">
              <CustomerSearchCombobox
                compact
                value={customerFilter}
                onValueChange={(value) => { setCustomerFilter(value); setPage(1) }}
                placeholder="All Customers"
              />
            </div>

            <TagFilterSelect
              className="h-8 w-24 rounded-full text-xs"
              placeholder="Tags"
              value={tagFilter}
              onChange={(value) => { setTagFilter(value); setPage(1) }}
            />

            <Select
              value={dateFilter}
              onValueChange={(value) => { setDateFilter(value as typeof dateFilter); setPage(1) }}
            >
              <SelectTrigger className="h-8 w-28 rounded-full text-xs">
                <SelectValue placeholder="All Dates" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Dates</SelectItem>
                <SelectItem value="yearly">Yearly</SelectItem>
                <SelectItem value="monthly">Monthly</SelectItem>
                <SelectItem value="custom">Custom Date</SelectItem>
              </SelectContent>
            </Select>

            {dateFilter === 'yearly' && (
              <Input
                type="number"
                min="2000"
                max="2100"
                value={selectedYear}
                onChange={(e) => { setSelectedYear(e.target.value); setPage(1) }}
                aria-label="Invoice year"
                className="h-8 w-24 rounded-full px-3 text-xs"
              />
            )}
            {dateFilter === 'monthly' && (
              <Input
                type="month"
                value={selectedMonth}
                onChange={(e) => { setSelectedMonth(e.target.value); setPage(1) }}
                aria-label="Invoice month"
                className="h-8 w-36 rounded-full px-3 text-xs"
              />
            )}
            {dateFilter === 'custom' && (
              <>
                <Input
                  type="date"
                  value={customStartDate}
                  onChange={(e) => { setCustomStartDate(e.target.value); setPage(1) }}
                  aria-label="Invoice start date"
                  className="h-8 w-36 rounded-full px-3 text-xs"
                />
                <Input
                  type="date"
                  min={customStartDate || undefined}
                  value={customEndDate}
                  onChange={(e) => { setCustomEndDate(e.target.value); setPage(1) }}
                  aria-label="Invoice end date"
                  className="h-8 w-36 rounded-full px-3 text-xs"
                />
              </>
            )}
            </div>

            <div className="ml-auto">
              <SalesLineColumnMenu
                columns={listingColumns(['Select', 'Invoice #', 'Customer', 'Date', 'Created Date', 'Due Date', 'Total', 'Balance', 'Status', 'Actions'])}
                hidden={hiddenInvoiceColumns}
                setHidden={setHiddenInvoiceColumns}
              />
            </div>
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
              <SalesLineColumnVisibility
                tableClass="invoices-listing-columns"
                columns={listingColumns(['Select', 'Invoice #', 'Customer', 'Date', 'Created Date', 'Due Date', 'Total', 'Balance', 'Status', 'Actions'])}
                hiddenColumns={hiddenInvoiceColumns}
                onHiddenColumnsChange={setHiddenInvoiceColumns}
                showMenu={false}
              >
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[40px]">
                      <input
                        type="checkbox"
                        checked={allPageSelected}
                        onChange={togglePageSelection}
                        aria-label="Select invoices on this page"
                      />
                    </TableHead>
                    <SortableHead label="Invoice #" sortKey="invoice_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Customer" sortKey="customer.name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Date" sortKey="invoice_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Created Date" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Due Date" sortKey="due_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Total" sortKey="total" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Balance" sortKey="balance" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedInvoices.map((invoice: any) => (
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
                          {/* Wide search can match a contact person, address, line or tag
                              on the invoice's customer — show which so the row is explicable. */}
                          {(invoice.matched_contacts?.length ?? 0) > 0 && (
                            <div className="truncate text-xs text-primary" title={invoice.matched_contacts.join(', ')}>
                              Contact: {invoice.matched_contacts.join(', ')}
                            </div>
                          )}
                          {(invoice.matched_tags?.length ?? 0) > 0 && (
                            <div className="truncate text-xs text-amber-600" title={invoice.matched_tags.join(', ')}>
                              Tag: {invoice.matched_tags.join(', ')}
                            </div>
                          )}
                          {(invoice.matched_lines?.length ?? 0) > 0 && (
                            <div className="truncate text-xs text-muted-foreground" title={invoice.matched_lines.join(' · ')}>
                              Line: {invoice.matched_lines.join(' · ')}
                            </div>
                          )}
                          {(invoice.matched_addresses?.length ?? 0) > 0 && (
                            <div className="truncate text-xs text-muted-foreground" title={invoice.matched_addresses.join(' · ')}>
                              Address: {invoice.matched_addresses.join(' · ')}
                            </div>
                          )}
                        </div>
                      </TableCell>
                      <TableCell>{formatDate(invoice.invoice_date || invoice.created_at)}</TableCell>
                      <TableCell>{formatDate(invoice.created_at)}</TableCell>
                      <TableCell>{formatDate(invoice.due_date || invoice.invoice_date || invoice.created_at)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(invoice.total)}</TableCell>
                      <TableCell className="text-right">{formatCurrency(invoice.balance)}</TableCell>
                      <TableCell>
                        {invoice.status === 'draft' && invoice.approval_status === 'pending' ? (
                          invoice.approval_view === 'to_approve' ? (
                            <Badge variant="secondary" className="whitespace-nowrap bg-amber-100 text-amber-800 animate-pulse">
                              Requires Approval
                            </Badge>
                          ) : (
                            <Badge variant="secondary" className="whitespace-nowrap bg-amber-100 text-amber-800">
                              Pending
                            </Badge>
                          )
                        ) : invoice.status === 'draft' && invoice.approval_status === 'approved' ? (
                          <Badge variant="secondary" className="whitespace-nowrap bg-emerald-100 text-emerald-800">
                            Approved
                          </Badge>
                        ) : invoice.status === 'draft' && invoice.approval_status === 'rejected' ? (
                          <Badge variant="secondary" className="whitespace-nowrap bg-red-100 text-red-800">
                            Approval Rejected
                          </Badge>
                        ) : invoice.status === 'draft' && invoice.requires_approval ? (
                          <Badge variant="secondary" className="whitespace-nowrap bg-amber-100 text-amber-800">
                            Requires Approval
                          </Badge>
                        ) : (
                          <Badge variant="secondary" className={getStatusBadge(invoice.status)}>
                            {invoice.status}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <div className="flex justify-end gap-1.5">
                          <Button size="sm" variant="outline" className="h-7 w-7 p-0" onClick={() => navigate(`/invoices/${invoice.id}`)} title="View">
                            <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                          {invoice.status === 'draft' && (
                            <>
                              <Button size="sm" variant="outline" className="h-7 w-7 p-0" onClick={() => navigate(`/invoices/${invoice.id}/edit`)} title="Edit">
                                <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                              </Button>
                              {canPost && (
                                <Button
                                  size="sm"
                                  className="h-7 w-7 bg-emerald-600 p-0 text-white hover:bg-emerald-700"
                                  onClick={() => sendInvoiceMutation.mutate(invoice.id)}
                                  disabled={sendInvoiceMutation.isPending}
                                  title="Post"
                                >
                                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
                                </Button>
                              )}
                            </>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              </SalesLineColumnVisibility>

              <Pagination
                page={page}
                totalPages={meta.last_page}
                onPageChange={setPage}
                from={(page - 1) * perPage + 1}
                to={Math.min(page * perPage, meta.total)}
                total={meta.total}
                label="invoices"
                pageSize={perPage}
                onPageSizeChange={(size) => { setPerPage(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={bulkAction === 'post'}
        onOpenChange={(open) => !open && setBulkAction(null)}
        title={`Post ${selectedIds.length} invoice(s)?`}
        description="Each invoice is posted to the general ledger and becomes non-editable. Invoices requiring approval are skipped unless already approved."
        confirmLabel="Post invoices"
        tone="warning"
        icon="solar:check-circle-bold-duotone"
        isPending={bulkPostMutation.isPending}
        onConfirm={() => {
          const ids = selectedIds
          setBulkAction(null)
          bulkPostMutation.mutate(ids)
        }}
      />

      <ConfirmDialog
        open={bulkAction === 'delete'}
        onOpenChange={(open) => !open && setBulkAction(null)}
        title={`Delete ${selectedIds.length} invoice(s)?`}
        description="Only draft invoices can be deleted; posted ones are skipped. This cannot be undone."
        confirmLabel="Delete invoices"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => {
          const ids = selectedIds
          setBulkAction(null)
          bulkDeleteMutation.mutate(ids)
        }}
      />

    </div>
  )
}

export default InvoicesPage