import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { formatCurrency, formatDate } from '@/lib/utils'
import { SalesDocumentExportMenu } from '@/components/sales/SalesDocumentExportMenu'
import { SalesLineColumnMenu, SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

import { useBulkSelection } from '@/hooks/use-bulk-selection'
import { BulkSelectionNotice } from '@/components/ui/bulk-selection-notice'
import { TagFilterSelect } from '@/components/tags/TagFilterSelect'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Pagination } from '@/components/ui/Pagination'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

const QUOTATION_EXPORT_COLUMNS = [
  { key: 'quotation_number', label: 'Quotation No', group: 'Quotation', default: true },
  { key: 'quotation_date', label: 'Date', group: 'Quotation', default: true },
  { key: 'valid_until', label: 'Valid Until', group: 'Quotation', default: true },
  { key: 'customer_name', label: 'Customer', group: 'Quotation', default: true },
  { key: 'customer_code', label: 'Customer Code', group: 'Quotation' },
  { key: 'customer_reference', label: 'Customer Reference', group: 'Quotation' },
  { key: 'internal_reference', label: 'Internal Reference', group: 'Quotation Details' },
  { key: 'credit_terms', label: 'Credit Terms', group: 'Quotation Details' },
  { key: 'salesperson', label: 'Salesperson', group: 'Quotation Details' },
  { key: 'delivery_lead_time', label: 'Delivery Lead Time', group: 'Quotation Details' },
  { key: 'shipping_method', label: 'Shipping Method', group: 'Quotation Details' },
  { key: 'shipping_terms', label: 'Shipping Terms', group: 'Quotation Details' },
  { key: 'validity', label: 'Validity', group: 'Quotation Details' },
  { key: 'attention_to', label: 'Attention To', group: 'Commercial Snapshot' },
  { key: 'contact_email', label: 'Contact Email', group: 'Commercial Snapshot' },
  { key: 'contact_phone', label: 'Contact Phone', group: 'Commercial Snapshot' },
  { key: 'billing_address', label: 'Billing Address', group: 'Commercial Snapshot' },
  { key: 'shipping_address', label: 'Shipping Address', group: 'Commercial Snapshot' },
  { key: 'notes', label: 'Notes', group: 'Quotation Details' },
  { key: 'terms', label: 'Terms & Conditions', group: 'Quotation Details' },
  { key: 'invoice_numbers', label: 'Invoice No(s)', group: 'Linked Documents' },
  { key: 'subtotal', label: 'Subtotal', group: 'Quotation' },
  { key: 'tax_amount', label: 'Tax', group: 'Quotation' },
  { key: 'discount_amount', label: 'Discount', group: 'Quotation' },
  { key: 'total', label: 'Total', group: 'Quotation', default: true },
  { key: 'status', label: 'Status', group: 'Quotation', default: true },
  { key: 'product_code', label: 'Product Code', group: 'Product Lines' },
  { key: 'product_name', label: 'Product', group: 'Product Lines' },
  { key: 'line_description', label: 'Description', group: 'Product Lines' },
  { key: 'model_number', label: 'Model Number', group: 'Product Lines' },
  { key: 'quantity', label: 'Quantity', group: 'Product Lines' },
  { key: 'unit_price', label: 'Unit Price', group: 'Product Lines' },
  { key: 'line_discount', label: 'Line Discount', group: 'Product Lines' },
  { key: 'tax_rate', label: 'Tax Rate', group: 'Product Lines' },
  { key: 'line_tax_amount', label: 'Line Tax', group: 'Product Lines' },
  { key: 'line_total', label: 'Line Total', group: 'Product Lines' },
] as const

export function QuotesPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [tagFilter, setTagFilter] = useState('')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [showBulkDeleteDialog, setShowBulkDeleteDialog] = useState(false)

  const queryParams = new URLSearchParams()
  queryParams.set('per_page', String(pageSize))
  queryParams.set('page', String(page))
  if (searchQuery.trim()) queryParams.set('search', searchQuery.trim())
  if (statusFilter !== 'all') queryParams.set('status', statusFilter)
  if (tagFilter) queryParams.set('tag', tagFilter)

  const { data: quotesResponse, isLoading } = useQuery({
    queryKey: ['quotations', searchQuery, statusFilter, tagFilter, page, pageSize],
    queryFn: async () => {
      const response = await api.get(`/quotations?${queryParams.toString()}`)
      return response.data
    },
  })

  const quotes = quotesResponse?.data || []
  const { sorted: sortedQuotes, sortKey, sortDir, toggle: toggleSort } = useSort<any>(quotes, 'created_at', 'desc')
  const lastPage = quotesResponse?.last_page || 1
  const totalRecords = quotesResponse?.total || 0

  const selectionFilters = {
    ...(searchQuery.trim() ? { search: searchQuery.trim() } : {}),
    ...(statusFilter !== 'all' ? { status: statusFilter } : {}),
    ...(tagFilter ? { tag: tagFilter } : {}),
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
    pageIds: quotes.filter((quote: any) => ['draft', 'sent', 'confirmed'].includes(quote.status)).map((quote: any) => quote.id),
    scopeKey: JSON.stringify(selectionFilters),
    fetchAllIds: async () => {
      const response = await api.get('/quotations', { params: { ...selectionFilters, ids_only: 1 } })
      return response.data.data
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all quotations' }),
  })

  const confirmMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/quotations/${id}/confirm`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast({ title: 'Quotation confirmed and Sales Order created' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/quotations/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      toast({ title: 'Quotation deleted' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to delete quotation',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleDelete = (quoteId: number, quoteNumber: string) => {
    setDeleteTarget({ id: quoteId, number: quoteNumber })
  }

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post('/quotations/bulk-delete', { ids })
      return response.data
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      clearSelection()
      toast({
        title: `${data.deleted?.length || 0} quotation(s) deleted`,
        description: data.skipped?.length ? `${data.skipped.length} skipped (confirmed/cancelled)` : undefined,
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk delete failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleBulkDelete = () => {
    if (selectedIds.length === 0) return
    setShowBulkDeleteDialog(true)
  }

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      sent: 'bg-blue-100 text-blue-800',
      confirmed: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  const handleSearchChange = (value: string) => {
    setSearchQuery(value)
    setPage(1)
  }


  const [hiddenQuoteColumns, setHiddenQuoteColumns] = useState<number[]>([])
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; number: string } | null>(null)

  const handleStatusChange = (value: string) => {
    setStatusFilter(value)
    setPage(1)
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Quotations</h1>
          <p className="text-muted-foreground">Create and manage quotations. Confirm to create Sales Orders, then invoice from orders.</p>
        </div>

        <div className="flex items-center justify-end gap-2">
          <SalesDocumentExportMenu
            compact
            endpoint="/quotations/export"
            filenamePrefix="quotations"
            columns={QUOTATION_EXPORT_COLUMNS}
            filters={{
              search: searchQuery.trim() || undefined,
              status: statusFilter !== 'all' ? statusFilter : undefined,
            }}
            selectedIds={selectedIds}
          />
          <Button
            variant="outline"
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/quotations/import')}
          >
            <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Import
          </Button>
          <Button
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/quotations/create')}
          >
            <Icon icon="solar:add-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Create Quotation
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <span>All Quotations</span>
            <span className="text-sm font-normal text-muted-foreground">{totalRecords} quotation(s)</span>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative w-full sm:w-64">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                value={searchQuery}
                onChange={(event) => handleSearchChange(event.target.value)}
                placeholder="Search quotation number or customer"
                className="h-9 pl-9"
              />
            </div>

            <Select value={statusFilter} onValueChange={handleStatusChange}>
              <SelectTrigger className="h-9 w-[150px]">
                <SelectValue placeholder="All Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="sent">Sent</SelectItem>
                <SelectItem value="confirmed">Confirmed</SelectItem>
                <SelectItem value="cancelled">Cancelled</SelectItem>
              </SelectContent>
            </Select>

            <TagFilterSelect
              value={tagFilter}
              onChange={(value) => { setTagFilter(value); setPage(1) }}
            />

            <div className="ml-auto">
              <SalesLineColumnMenu
                columns={listingColumns(['Select', 'Quote #', 'Customer', 'Quote Date', 'Created Date', 'Valid Until', 'Reference', 'Total', 'Status', 'Actions'])}
                hidden={hiddenQuoteColumns}
                setHidden={setHiddenQuoteColumns}
              />
            </div>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading quotations...</p>
            </div>
          ) : quotes.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:file-text-bold-duotone" className="mx-auto mb-4 h-16 w-16 opacity-50" />
              <p className="mb-2 text-lg font-medium text-foreground">No quotations found</p>
              <p className="mb-4 text-sm">Create a quotation to start a sales proposal for a customer.</p>
              <Button onClick={() => navigate('/quotations/create')}>
                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                Create Quotation
              </Button>
            </div>
          ) : (
            <>
              {selectedIds.length > 0 && (
                <div className="flex items-center justify-between rounded-lg border border-primary/20 bg-primary/5 px-4 py-2">
                  <BulkSelectionNotice pageSize={quotes.filter((quote: any) => ['draft', 'sent', 'confirmed'].includes(quote.status)).length} allPageSelected={allPageSelected} selectedCount={selectedIds.length} allMatchingSelected={allMatchingSelected} isSelectingAll={isSelectingAll} onSelectAllMatching={selectAllMatching} noun="quotations" />
                  <Button size="sm" variant="destructive" onClick={handleBulkDelete} disabled={bulkDeleteMutation.isPending}>
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                    Delete Selected
                  </Button>
                </div>
              )}
              <div className="overflow-x-auto">
                <SalesLineColumnVisibility
                  tableClass="quotes-listing-columns"
                  columns={listingColumns(['Select', 'Quote #', 'Customer', 'Quote Date', 'Created Date', 'Valid Until', 'Reference', 'Total', 'Status', 'Actions'])}
                  hiddenColumns={hiddenQuoteColumns}
                  onHiddenColumnsChange={setHiddenQuoteColumns}
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
                          aria-label="Select quotations on this page"
                          className="h-4 w-4 rounded border-gray-300"
                        />
                      </TableHead>
                      <SortableHead label="Quote #" sortKey="quote_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Customer" sortKey="customer_name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Quote Date" sortKey="quote_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Created Date" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Valid Until" sortKey="valid_until" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Reference" sortKey="reference" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <SortableHead label="Total" sortKey="total" activeKey={sortKey} direction={sortDir} onSort={toggleSort} align="right" />
                      <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {sortedQuotes.map((quote: any) => (
                      <TableRow
                        key={quote.id}
                        className="hover:bg-accent/50 cursor-pointer"
                        onClick={() => navigate(`/quotations/${quote.id}`)}
                      >
                        <TableCell onClick={(event) => event.stopPropagation()}>
                          {['draft', 'sent', 'confirmed'].includes(quote.status) && (
                            <input
                              type="checkbox"
                              checked={selectedIds.includes(quote.id)}
                              onChange={() => toggleSelect(quote.id)}
                              className="h-4 w-4 rounded border-gray-300"
                            />
                          )}
                        </TableCell>
                        <TableCell className="font-medium">{quote.quotation_number}</TableCell>
                        <TableCell>
                          <div>
                            {quote.customer?.code && (
                              <span className="text-xs font-mono text-muted-foreground">{quote.customer.code}</span>
                            )}
                            <div>{quote.customer?.name || 'Unknown'}</div>
                          </div>
                        </TableCell>
                        <TableCell>{formatDate(quote.quotation_date || quote.created_at)}</TableCell>
                        <TableCell>{formatDate(quote.created_at)}</TableCell>
                        <TableCell>{quote.valid_until ? formatDate(quote.valid_until) : '-'}</TableCell>
                        <TableCell>{quote.customer_reference || quote.internal_reference || '-'}</TableCell>
                        <TableCell className="text-right">{formatCurrency(quote.total)}</TableCell>
                        <TableCell>
                          <Badge variant="secondary" className={getStatusBadge(quote.status)}>
                            {quote.status}
                          </Badge>
                        </TableCell>
                        <TableCell onClick={(event) => event.stopPropagation()}>
                          <div className="flex justify-end gap-1.5">
                            <Button size="sm" variant="outline" className="h-7 w-7 p-0" onClick={() => navigate(`/quotations/${quote.id}`)} title="View">
                              <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                            </Button>
                            {(quote.status === 'draft' || quote.status === 'sent') && (
                              <>
                                <Button size="sm" variant="outline" className="h-7 w-7 p-0" onClick={() => navigate(`/quotations/${quote.id}/edit`)} title="Edit">
                                  <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="h-7 w-7 border-destructive/40 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                  onClick={() => handleDelete(quote.id, quote.quotation_number)}
                                  title="Delete"
                                  disabled={deleteMutation.isPending}
                                >
                                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                  size="sm"
                                  className="h-7 w-7 bg-emerald-600 p-0 text-white hover:bg-emerald-700"
                                  onClick={() => confirmMutation.mutate(quote.id)}
                                  disabled={confirmMutation.isPending}
                                  title="Confirm"
                                >
                                  <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
                                </Button>
                              </>
                            )}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                </SalesLineColumnVisibility>
              </div>

              <Pagination
                page={page}
                totalPages={lastPage}
                onPageChange={setPage}
                from={(page - 1) * pageSize + 1}
                to={Math.min(page * pageSize, totalRecords)}
                total={totalRecords}
                label="quotes"
                pageSize={pageSize}
                onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>

      <AlertDialog open={showBulkDeleteDialog} onOpenChange={setShowBulkDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {selectedIds.length} quotation(s)?</AlertDialogTitle>
            <AlertDialogDescription>
              Only draft, sent, and confirmed quotations will be deleted. Quotations that have already been converted to invoices cannot be deleted and will be skipped.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Go Back</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                bulkDeleteMutation.mutate(selectedIds)
                setShowBulkDeleteDialog(false)
              }}
              disabled={bulkDeleteMutation.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {bulkDeleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <ConfirmDialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title="Delete this quotation?"
        description={
          <>
            Quotation{' '}
            <span className="font-medium text-foreground">{deleteTarget?.number}</span> and its lines will be
            permanently removed. This cannot be undone.
          </>
        }
        confirmLabel="Delete"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          const target = deleteTarget
          setDeleteTarget(null)
          if (target) deleteMutation.mutate(target.id)
        }}
      />
    </div>
  )
}

export default QuotesPage