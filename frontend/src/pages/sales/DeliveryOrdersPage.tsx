import { useState } from 'react'
import { useAuthStore } from '@/store/authStore'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import pricingApi from '@/lib/pricingApi'
import { Icon } from '@iconify/react'
import { useNavigate } from 'react-router-dom'
import { formatDate } from '@/lib/utils'
import { SalesDocumentExportMenu } from '@/components/sales/SalesDocumentExportMenu'
import { SalesLineColumnMenu, SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useBulkSelection } from '@/hooks/use-bulk-selection'
import { BulkActionBar } from '@/components/ui/bulk-action-bar'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { TagFilterSelect } from '@/components/tags/TagFilterSelect'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Pagination } from '@/components/ui/Pagination'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

const DELIVERY_ORDER_EXPORT_COLUMNS = [
  { key: 'delivery_order_number', label: 'Delivery Order No', group: 'Delivery Order', default: true },
  { key: 'delivery_date', label: 'Delivery Date', group: 'Delivery Order', default: true },
  { key: 'expected_date', label: 'Due Date', group: 'Delivery Order' },
  { key: 'customer_name', label: 'Customer', group: 'Delivery Order', default: true },
  { key: 'customer_code', label: 'Customer Code', group: 'Delivery Order' },
  { key: 'warehouse_name', label: 'Warehouse', group: 'Delivery Details' },
  { key: 'sales_order_number', label: 'Sales Order No', group: 'Delivery Order' },
  { key: 'invoice_number', label: 'Invoice No', group: 'Delivery Order' },
  { key: 'quotation_number', label: 'Quotation No', group: 'Delivery Order' },
  { key: 'po_number', label: 'PO Number', group: 'Delivery Order' },
  { key: 'salesperson', label: 'Salesperson', group: 'Delivery Details' },
  { key: 'attention_to', label: 'Attention To', group: 'Delivery Details' },
  { key: 'carrier', label: 'Carrier', group: 'Delivery Details' },
  { key: 'tracking_number', label: 'Tracking No', group: 'Delivery Details' },
  { key: 'created_by_name', label: 'Created By', group: 'Delivery Details' },
  { key: 'confirmed_by_name', label: 'Confirmed By', group: 'Delivery Details' },
  { key: 'shipping_address', label: 'Shipping Address', group: 'Delivery Details' },
  { key: 'notes', label: 'Notes', group: 'Delivery Details' },
  { key: 'payment_terms', label: 'Payment Terms', group: 'Delivery Order' },
  { key: 'total', label: 'Total', group: 'Delivery Order' },
  { key: 'status', label: 'Status', group: 'Delivery Order', default: true },
  { key: 'product_code', label: 'Product Code', group: 'Product Lines' },
  { key: 'product_name', label: 'Product', group: 'Product Lines' },
  { key: 'line_description', label: 'Description', group: 'Product Lines' },
  { key: 'model_number', label: 'Model Number', group: 'Product Lines' },
  { key: 'patient_name', label: 'Patient Name', group: 'Product Lines' },
  { key: 'lot_number', label: 'Lot Number', group: 'Product Lines' },
  { key: 'batch_number', label: 'Batch Number', group: 'Product Lines' },
  { key: 'expiry_date', label: 'Expiry Date', group: 'Product Lines' },
  { key: 'quantity_ordered', label: 'Quantity Ordered', group: 'Product Lines' },
  { key: 'quantity_delivered', label: 'Quantity Delivered', group: 'Product Lines' },
  { key: 'unit', label: 'Unit', group: 'Product Lines' },
  { key: 'unit_price', label: 'Unit Price', group: 'Product Lines' },
  { key: 'line_total', label: 'Line Total', group: 'Product Lines' },
] as const

export function DeliveryOrdersPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [searchQuery, setSearchQuery] = useState('')
  const [typeFilter, setTypeFilter] = useState<string>('all')
  // MRD FR-8.1: currency filter + currency sort.
  const [currencyFilter, setCurrencyFilter] = useState('all')
  const [sortBy, setSortBy] = useState<'date' | 'currency'>('date')
  const [tagFilter, setTagFilter] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [hiddenDoColumns, setHiddenDoColumns] = useState<number[]>([])
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false)

  const { data: filterCurrencies } = useQuery({
    queryKey: ['currencies', 'sellable'],
    queryFn: () => pricingApi.sellableCurrencies(),
  })
  const filterCurrencyRows: any[] = (filterCurrencies as any)?.data ?? filterCurrencies ?? []

  const { data: response, isLoading } = useQuery({
    queryKey: ['delivery-orders', page, perPage, typeFilter, currencyFilter, sortBy, tagFilter, searchQuery],
    queryFn: async () => {
      const params: any = { page, per_page: perPage }
      if (typeFilter !== 'all') params.type = typeFilter
      if (currencyFilter !== 'all') params.currency_code = currencyFilter
      if (sortBy === 'currency') params.sort_by = 'currency'
      if (searchQuery.trim()) params.search = searchQuery.trim()
      if (tagFilter) params.tag = tagFilter
      const res = await api.get('/sales/delivery-orders', { params })
      return res.data
    },
  })

  const deliveryOrders = response?.data || []
  const { sorted: sortedDeliveryOrders, sortKey, sortDir, toggle: toggleSort } = useSort<any>(deliveryOrders, 'created_at', 'desc')
  const meta = {
    current_page: response?.current_page || 1,
    last_page: response?.last_page || 1,
    total: response?.total || 0,
  }

  const selectionFilters = {
    ...(typeFilter !== 'all' ? { type: typeFilter } : {}),
    ...(searchQuery.trim() ? { search: searchQuery.trim() } : {}),
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
    pageIds: deliveryOrders.map((deliveryOrder: any) => deliveryOrder.id),
    scopeKey: JSON.stringify(selectionFilters),
    fetchAllIds: async () => {
      const res = await api.get('/sales/delivery-orders', { params: { ...selectionFilters, ids_only: 1 } })
      return res.data.data
    },
    onSelectAllError: () => toast({ variant: 'destructive', title: 'Failed to select all delivery orders' }),
  })

  const { hasPermission } = useAuthStore()
  // Posting/confirming sell-side documents is accounting-only (server enforces accounting.post).
  const canPost = hasPermission('accounting.post')

  const confirmMutation = useMutation({

    mutationFn: async (id: number) => {
      const response = await api.post(`/sales/delivery-orders/${id}/confirm`)
      return response.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      if (data?.requires_approval) {
        toast({
          title: 'Approval required',
          description: data?.message || 'An approval request has been submitted for this document.',
        })
        return
      }
      toast({ title: 'Delivery order confirmed' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to confirm delivery',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/sales/delivery-orders/bulk-delete', { ids })
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      clearSelection()
      toast({ title: `${data.deleted} delivery order(s) deleted` })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Bulk delete failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const getStatusBadge = (status: string) => {
    const styles: Record<string, string> = {
      draft: 'bg-gray-100 text-gray-800',
      confirmed: 'bg-blue-100 text-blue-800',
      done: 'bg-green-100 text-green-800',
      cancelled: 'bg-red-100 text-red-800',
    }
    return styles[status] || 'bg-gray-100 text-gray-800'
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Delivery Orders</h1>
          <p className="text-muted-foreground">Track shipments dispatched from sales orders or standalone.</p>
        </div>

        <div className="flex items-center justify-end gap-2">
          <SalesDocumentExportMenu
            compact
            endpoint="/sales/delivery-orders/export"
            filenamePrefix="delivery_orders"
            columns={DELIVERY_ORDER_EXPORT_COLUMNS}
            filters={{
              search: searchQuery.trim() || undefined,
              type: typeFilter !== 'all' ? typeFilter : undefined,
            }}
            selectedIds={selectedIds}
          />
          <Button
            variant="outline"
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/delivery-orders/import')}
          >
            <Icon icon="solar:upload-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Import
          </Button>
          <Button
            size="sm"
            className="h-8 rounded-full px-3 text-xs"
            onClick={() => navigate('/delivery-orders/create')}
          >
            <Icon icon="solar:add-circle-bold-duotone" className="mr-1.5 h-3.5 w-3.5" />
            Create Delivery Order
          </Button>
        </div>
      </div>

      <BulkActionBar
        selectedCount={selectedIds.length}
        pageSize={deliveryOrders.length}
        allPageSelected={allPageSelected}
        allMatchingSelected={allMatchingSelected}
        isSelectingAll={isSelectingAll}
        onSelectAllMatching={selectAllMatching}
        onClearSelection={clearSelection}
        noun="delivery orders"
        destructiveAction={
          <HeaderActionButton
            variant="destructive"
            icon="solar:trash-bin-minimalistic-bold"
            onClick={() => setBulkDeleteOpen(true)}
          >
            Delete ({selectedIds.length})
          </HeaderActionButton>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Delivery Orders</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="mb-4 flex flex-wrap items-center gap-1.5 sm:flex-nowrap sm:overflow-x-auto">
            <div className="relative w-full sm:w-64">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                placeholder="Search deliveries..."
                value={searchQuery}
                onChange={(e) => { setSearchQuery(e.target.value); setPage(1) }}
                className="h-8 rounded-full pl-9 pr-3 text-xs"
              />
            </div>

            <Select value={typeFilter} onValueChange={(v) => { setTypeFilter(v); setPage(1) }}>
              <SelectTrigger className="h-8 w-32 rounded-full px-2.5 text-xs">
                <SelectValue placeholder="All Types" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="standalone">Standalone</SelectItem>
                <SelectItem value="linked">From Sales Order</SelectItem>
              </SelectContent>
            </Select>

            <Select value={currencyFilter} onValueChange={(v) => { setCurrencyFilter(v); setPage(1) }}>
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

            <Select value={sortBy} onValueChange={(v) => { setSortBy(v as 'date' | 'currency'); setPage(1) }}>
              <SelectTrigger className="h-8 w-28 rounded-full px-2.5 text-xs">
                <SelectValue placeholder="Sort" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="date">Newest</SelectItem>
                <SelectItem value="currency">Currency</SelectItem>
              </SelectContent>
            </Select>

            <TagFilterSelect
              className="h-8 w-24 rounded-full text-xs"
              placeholder="Tags"
              value={tagFilter}
              onChange={(value) => { setTagFilter(value); setPage(1) }}
            />

            <div className="ml-auto">
              <SalesLineColumnMenu
                columns={listingColumns(['Select', 'Delivery #', 'Type', 'Customer', 'Delivery Date', 'Created Date', 'Lines', 'Status', 'Actions'])}
                hidden={hiddenDoColumns}
                setHidden={setHiddenDoColumns}
              />
            </div>
          </div>

          {isLoading ? (
            <div className="py-12 text-center">
              <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
              <p className="mt-4 text-sm text-muted-foreground">Loading deliveries...</p>
            </div>
          ) : deliveryOrders.length === 0 ? (
            <div className="py-12 text-center text-muted-foreground">
              <Icon icon="solar:box-bold-duotone" className="mx-auto h-12 w-12 opacity-30" />
              <p className="mt-4">No delivery orders found.</p>
            </div>
          ) : (
            <>
              <SalesLineColumnVisibility
                tableClass="delivery-orders-listing-columns"
                columns={listingColumns(['Select', 'Delivery #', 'Type', 'Customer', 'Delivery Date', 'Created Date', 'Lines', 'Status', 'Actions'])}
                hiddenColumns={hiddenDoColumns}
                onHiddenColumnsChange={setHiddenDoColumns}
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
                        aria-label="Select delivery orders on this page"
                      />
                    </TableHead>
                    <SortableHead label="Delivery #" sortKey="delivery_order_number" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Type" sortKey="sales_order_id" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Customer" sortKey="customer.name" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Delivery Date" sortKey="delivery_date" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <SortableHead label="Created Date" sortKey="created_at" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead>Lines</TableHead>
                    <SortableHead label="Status" sortKey="status" activeKey={sortKey} direction={sortDir} onSort={toggleSort} />
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedDeliveryOrders.map((do_: any) => (
                    <TableRow
                      key={do_.id}
                      className="cursor-pointer"
                      onClick={() => navigate(`/delivery-orders/${do_.id}`)}
                    >
                      <TableCell onClick={(event) => event.stopPropagation()}>
                        <input type="checkbox" checked={selectedIds.includes(do_.id)} onChange={() => toggleSelect(do_.id)} />
                      </TableCell>
                      <TableCell className="font-medium">{do_.delivery_order_number}</TableCell>
                      <TableCell>
                        <Badge variant="outline" className={do_.sales_order_id ? '' : 'border-blue-300 text-blue-700'}>
                          {do_.sales_order_id ? 'Linked' : 'Standalone'}
                        </Badge>
                      </TableCell>
                      <TableCell>{do_.customer?.name || '-'}</TableCell>
                      <TableCell>{do_.delivery_date ? formatDate(do_.delivery_date) : '-'}</TableCell>
                      <TableCell>{formatDate(do_.created_at || do_.delivery_date)}</TableCell>
                      <TableCell>{do_.lines?.length || 0}</TableCell>
                      <TableCell>
                        {/* A draft that is sitting in approval should not read as
                            a plain "draft" — mirror the invoice list's contextual
                            badge so both lists read the same way. */}
                        {do_.status === 'draft' && do_.approval_status === 'pending' ? (
                          do_.approval_view === 'to_approve' ? (
                            <Badge variant="secondary" className="whitespace-nowrap bg-amber-100 text-amber-800 animate-pulse">
                              Requires Approval
                            </Badge>
                          ) : (
                            <Badge variant="secondary" className="whitespace-nowrap bg-amber-100 text-amber-800">
                              Pending Approval
                            </Badge>
                          )
                        ) : do_.status === 'draft' && do_.approval_status === 'approved' ? (
                          <Badge variant="secondary" className="whitespace-nowrap bg-emerald-100 text-emerald-800">
                            Approved
                          </Badge>
                        ) : do_.status === 'draft' && do_.approval_status === 'rejected' ? (
                          <Badge variant="secondary" className="whitespace-nowrap bg-red-100 text-red-800">
                            Approval Rejected
                          </Badge>
                        ) : (
                          <Badge variant="secondary" className={getStatusBadge(do_.status)}>
                            {do_.status}
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell onClick={(event) => event.stopPropagation()}>
                        <div className="flex justify-end gap-1.5">
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 w-7 p-0"
                            onClick={() => navigate(`/delivery-orders/${do_.id}`)}
                            title="View"
                            aria-label="View delivery order"
                          >
                            <Icon icon="solar:eye-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                          {do_.status === 'draft' && canPost && (
                            <Button
                              size="sm"
                              className="h-7 w-7 bg-emerald-600 p-0 text-white hover:bg-emerald-700"
                              onClick={() => confirmMutation.mutate(do_.id)}
                              disabled={confirmMutation.isPending}
                              title="Confirm"
                              aria-label="Confirm delivery order"
                            >
                              <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4" />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
              </SalesLineColumnVisibility>

              {/* Pagination */}
              <Pagination
                page={page}
                totalPages={meta.last_page}
                onPageChange={setPage}
                from={(page - 1) * perPage + 1}
                to={Math.min(page * perPage, meta.total)}
                total={meta.total}
                label="delivery orders"
                pageSize={perPage}
                onPageSizeChange={(size) => { setPerPage(size); setPage(1) }}
              />
            </>
          )}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={bulkDeleteOpen}
        onOpenChange={setBulkDeleteOpen}
        title={`Delete ${selectedIds.length} delivery order(s)?`}
        description="Confirmed and completed delivery orders are skipped; only drafts and cancellations are removed. This cannot be undone."
        confirmLabel="Delete orders"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={bulkDeleteMutation.isPending}
        onConfirm={() => {
          const ids = selectedIds
          setBulkDeleteOpen(false)
          bulkDeleteMutation.mutate(ids)
        }}
      />

    </div>
  )
}

export default DeliveryOrdersPage
