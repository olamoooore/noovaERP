import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Pagination } from '@/components/ui/Pagination'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'
import { useToast } from '@/hooks/use-toast'
import { useAuthStore } from '@/store/authStore'
import { getApiErrorMessage } from '@/lib/utils'

type TrackingType = 'batches' | 'serials'

const BATCH_STATUSES = ['available', 'quarantine', 'expired', 'recalled']
const SERIAL_STATUSES = ['available', 'reserved', 'sold', 'returned', 'defective', 'scrapped']

const statusStyles: Record<string, string> = {
  available: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
  reserved: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300',
  quarantine: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
  returned: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
  sold: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200',
  expired: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
  recalled: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
  defective: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
  scrapped: 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300',
}

const fallbackStatusStyle = 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200'

const formatQty = (value: unknown) =>
  Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })

const dateOnly = (value: unknown) => (value ? String(value).split('T')[0] : '')

interface BatchFormState {
  batch_number: string
  lot_number: string
  warehouse_id: string
  status: string
  expiry_date: string
  manufacturing_date: string
  received_date: string
  supplier_batch_number: string
  cost: string
  notes: string
}

interface SerialFormState {
  serial_number: string
  warehouse_id: string
  batch_id: string
  status: string
  cost: string
  mac_address: string
  imei: string
  manufacturing_date: string
  warranty_start_date: string
  warranty_end_date: string
  notes: string
}

const emptyBatchForm: BatchFormState = {
  batch_number: '', lot_number: '', warehouse_id: 'none', status: 'available',
  expiry_date: '', manufacturing_date: '', received_date: '', supplier_batch_number: '',
  cost: '', notes: '',
}

const emptySerialForm: SerialFormState = {
  serial_number: '', warehouse_id: 'none', batch_id: 'none', status: 'available',
  cost: '', mac_address: '', imei: '', manufacturing_date: '',
  warranty_start_date: '', warranty_end_date: '', notes: '',
}

export function InventoryTrackingPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const hasPermission = useAuthStore((state) => state.hasPermission)
  const canWrite = hasPermission('inventory.write')

  const [type, setType] = useState<TrackingType>('batches')
  const [search, setSearch] = useState('')
  const [warehouseId, setWarehouseId] = useState('all')
  const [productId, setProductId] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)

  const [editingBatch, setEditingBatch] = useState<any | null>(null)
  const [batchForm, setBatchForm] = useState<BatchFormState>(emptyBatchForm)
  const [editingSerial, setEditingSerial] = useState<any | null>(null)
  const [serialForm, setSerialForm] = useState<SerialFormState>(emptySerialForm)
  const [deleteTarget, setDeleteTarget] = useState<{ type: TrackingType; row: any } | null>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['inventory-tracking', type, search, warehouseId, productId, page, pageSize],
    queryFn: async () => {
      const response = await api.get(`/inventory/tracking/${type}`, { params: { search: search || undefined, warehouse_id: warehouseId === 'all' ? undefined : warehouseId, product_id: productId === 'all' ? undefined : productId, page, per_page: pageSize } })
      return response.data
    },
  })
  const { data: warehouses = [] } = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data || [] })
  const { data: products = [] } = useQuery({ queryKey: ['products-tracking-filter'], queryFn: async () => { const response = await api.get('/products', { params: { per_page: 500 } }); return response.data?.data || response.data || [] } })

  // Lots available to link a serial to (only while editing a serial).
  const { data: serialBatchOptions } = useQuery({
    queryKey: ['inventory-tracking', 'batch-options', editingSerial?.product_id],
    queryFn: async () => (await api.get('/inventory/tracking/batches', { params: { product_id: editingSerial?.product_id, per_page: 100 } })).data,
    enabled: Boolean(editingSerial?.product_id),
  })

  const rows = data?.data || []
  const { sorted: sortedBatches, sortKey: batchSortKey, sortDir: batchSortDir, toggle: toggleBatchSort } = useSort<any>(rows)
  const { sorted: sortedSerials, sortKey: serialSortKey, sortDir: serialSortDir, toggle: toggleSerialSort } = useSort<any>(rows)

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['inventory-tracking'] })

  const updateBatchMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.put(`/inventory/tracking/batches/${id}`, payload)).data,
    onSuccess: () => {
      invalidate()
      setEditingBatch(null)
      toast({ title: 'Lot/batch updated' })
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Failed to update lot/batch', description: getApiErrorMessage(error) }),
  })

  const deleteBatchMutation = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/inventory/tracking/batches/${id}`)).data,
    onSuccess: () => {
      invalidate()
      setDeleteTarget(null)
      toast({ title: 'Lot/batch deleted' })
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Failed to delete lot/batch', description: getApiErrorMessage(error) }),
  })

  const updateSerialMutation = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.put(`/inventory/tracking/serials/${id}`, payload)).data,
    onSuccess: () => {
      invalidate()
      setEditingSerial(null)
      toast({ title: 'Serial number updated' })
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Failed to update serial number', description: getApiErrorMessage(error) }),
  })

  const deleteSerialMutation = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/inventory/tracking/serials/${id}`)).data,
    onSuccess: () => {
      invalidate()
      setDeleteTarget(null)
      toast({ title: 'Serial number deleted' })
    },
    onError: (error: any) => toast({ variant: 'destructive', title: 'Failed to delete serial number', description: getApiErrorMessage(error) }),
  })

  const openBatchEdit = (row: any) => {
    setEditingBatch(row)
    setBatchForm({
      batch_number: row.batch_number || '',
      lot_number: row.lot_number || '',
      warehouse_id: row.warehouse_id ? String(row.warehouse_id) : 'none',
      status: row.status || 'available',
      expiry_date: dateOnly(row.expiry_date),
      manufacturing_date: dateOnly(row.manufacturing_date),
      received_date: dateOnly(row.received_date),
      supplier_batch_number: row.supplier_batch_number || '',
      cost: row.cost != null ? String(row.cost) : '',
      notes: row.notes || '',
    })
  }

  const openSerialEdit = (row: any) => {
    setEditingSerial(row)
    setSerialForm({
      serial_number: row.serial_number || '',
      warehouse_id: row.warehouse_id ? String(row.warehouse_id) : 'none',
      batch_id: row.batch_id ? String(row.batch_id) : 'none',
      status: row.status || 'available',
      cost: row.cost != null ? String(row.cost) : '',
      mac_address: row.mac_address || '',
      imei: row.imei || '',
      manufacturing_date: dateOnly(row.manufacturing_date),
      warranty_start_date: dateOnly(row.warranty_start_date),
      warranty_end_date: dateOnly(row.warranty_end_date),
      notes: row.notes || '',
    })
  }

  const submitBatch = () => {
    if (!editingBatch) return
    updateBatchMutation.mutate({
      id: editingBatch.id,
      payload: {
        batch_number: batchForm.batch_number.trim(),
        lot_number: batchForm.lot_number.trim() || null,
        warehouse_id: batchForm.warehouse_id === 'none' ? null : Number(batchForm.warehouse_id),
        status: batchForm.status,
        expiry_date: batchForm.expiry_date || null,
        manufacturing_date: batchForm.manufacturing_date || null,
        received_date: batchForm.received_date || null,
        supplier_batch_number: batchForm.supplier_batch_number.trim() || null,
        cost: batchForm.cost === '' ? null : Number(batchForm.cost),
        notes: batchForm.notes.trim() || null,
      },
    })
  }

  const submitSerial = () => {
    if (!editingSerial) return
    updateSerialMutation.mutate({
      id: editingSerial.id,
      payload: {
        serial_number: serialForm.serial_number.trim(),
        warehouse_id: serialForm.warehouse_id === 'none' ? null : Number(serialForm.warehouse_id),
        batch_id: serialForm.batch_id === 'none' ? null : Number(serialForm.batch_id),
        status: serialForm.status,
        cost: serialForm.cost === '' ? null : Number(serialForm.cost),
        mac_address: serialForm.mac_address.trim() || null,
        imei: serialForm.imei.trim() || null,
        manufacturing_date: serialForm.manufacturing_date || null,
        warranty_start_date: serialForm.warranty_start_date || null,
        warranty_end_date: serialForm.warranty_end_date || null,
        notes: serialForm.notes.trim() || null,
      },
    })
  }

  const loadingRow = (colSpan: number) => (
    <TableRow>
      <TableCell colSpan={colSpan} className="py-12 text-center">
        <div className="mx-auto h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
      </TableCell>
    </TableRow>
  )

  const emptyRow = (colSpan: number, icon: string, message: string) => (
    <TableRow>
      <TableCell colSpan={colSpan} className="py-12 text-center text-muted-foreground">
        <Icon icon={icon} className="mx-auto h-12 w-12 opacity-30" />
        <p className="mt-4">{message}</p>
      </TableCell>
    </TableRow>
  )

  const rowActions = (row: any, onEdit: (row: any) => void) => (
    <div className="flex justify-end gap-1.5">
      <Button
        size="sm"
        variant="outline"
        className="h-7 w-7 p-0"
        onClick={() => onEdit(row)}
        title="Edit"
        aria-label="Edit"
      >
        <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
      </Button>
      <Button
        size="sm"
        variant="outline"
        className="h-7 w-7 border-destructive/40 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
        onClick={() => setDeleteTarget({ type, row })}
        title="Delete"
        aria-label="Delete"
      >
        <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
      </Button>
    </div>
  )

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-3xl font-bold tracking-tight">Lot & Serial Tracking</h1><p className="text-muted-foreground">Review tracked inventory, edit lots or serials, and import them by product.</p></div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton icon="solar:box-minimalistic-bold-duotone" variant="outline" onClick={() => navigate('/inventory/stock?operation=in')}>
            Receive Stock
          </HeaderActionButton>
          <HeaderActionButton icon="solar:upload-bold-duotone" onClick={() => navigate(`/inventory/tracking/import?type=${type}`)}>
            Bulk Import
          </HeaderActionButton>
        </div>
      </div>

      <Tabs value={type} onValueChange={(value) => { setType(value as TrackingType); setProductId('all'); setPage(1) }}>
        <TabsList><TabsTrigger value="batches">Lots / Batches</TabsTrigger><TabsTrigger value="serials">Serial Numbers</TabsTrigger></TabsList>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <div className="relative w-full sm:w-72">
            <Icon icon="solar:magnifer-bold-duotone" className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(event) => { setSearch(event.target.value); setPage(1) }}
              placeholder={`Search ${type === 'batches' ? 'lot, batch or product' : 'serial or product'}…`}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>
          <Select value={productId} onValueChange={(value) => { setProductId(value); setPage(1) }}>
            <SelectTrigger className="h-8 w-64 rounded-full text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All products</SelectItem>
              {products.filter((product: any) => type === 'batches' ? product.tracking_method === 'batch' : product.tracking_method === 'serial').map((product: any) => <SelectItem key={product.id} value={String(product.id)}>{product.name} ({product.sku})</SelectItem>)}
            </SelectContent>
          </Select>
          <Select value={warehouseId} onValueChange={(value) => { setWarehouseId(value); setPage(1) }}>
            <SelectTrigger className="h-8 w-56 rounded-full text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All warehouses</SelectItem>
              {warehouses.map((warehouse: any) => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>

        <TabsContent value="batches">
          <Card>
            <CardHeader><CardTitle>Lots / Batches</CardTitle></CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Product" sortKey="product.name" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} />
                    <SortableHead label="Lot / Batch" sortKey="batch_number" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} />
                    <SortableHead label="Warehouse" sortKey="warehouse.name" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} />
                    <SortableHead label="On Hand" sortKey="quantity" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} align="right" />
                    <SortableHead label="Reserved" sortKey="quantity_reserved" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} align="right" />
                    <SortableHead label="Expiry" sortKey="expiry_date" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} />
                    <SortableHead label="Status" sortKey="status" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} />
                    {canWrite && <TableHead className="w-20" />}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoading
                    ? loadingRow(canWrite ? 8 : 7)
                    : sortedBatches.length === 0
                      ? emptyRow(canWrite ? 8 : 7, 'solar:box-bold-duotone', 'No lots or batches found.')
                      : sortedBatches.map((row: any) => (
                          <TableRow key={row.id}>
                            <TableCell>
                              <div className="font-medium">{row.product?.name}</div>
                              <div className="text-xs text-muted-foreground">{row.product?.sku}</div>
                            </TableCell>
                            <TableCell className="font-mono text-xs">
                              <div>{row.batch_number || '-'}</div>
                              {row.lot_number && row.lot_number !== row.batch_number && (
                                <div className="text-muted-foreground">Lot {row.lot_number}</div>
                              )}
                            </TableCell>
                            <TableCell>{row.warehouse?.name || '-'}</TableCell>
                            <TableCell className="text-right tabular-nums">{formatQty(row.quantity)}</TableCell>
                            <TableCell className="text-right tabular-nums">{formatQty(row.quantity_reserved)}</TableCell>
                            <TableCell>{dateOnly(row.expiry_date) || '-'}</TableCell>
                            <TableCell>
                              <Badge variant="secondary" className={`capitalize ${statusStyles[row.status] || fallbackStatusStyle}`}>{row.status || '-'}</Badge>
                            </TableCell>
                            {canWrite && <TableCell className="text-right">{rowActions(row, openBatchEdit)}</TableCell>}
                          </TableRow>
                        ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="serials">
          <Card>
            <CardHeader><CardTitle>Serial Numbers</CardTitle></CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <SortableHead label="Product" sortKey="product.name" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    <SortableHead label="Serial Number" sortKey="serial_number" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    <SortableHead label="Lot" sortKey="batch.lot_number" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    <SortableHead label="Warehouse" sortKey="warehouse.name" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    <SortableHead label="Status" sortKey="status" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    <SortableHead label="Sale Reference" sortKey="sale_reference" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} />
                    {canWrite && <TableHead className="w-20" />}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoading
                    ? loadingRow(canWrite ? 7 : 6)
                    : sortedSerials.length === 0
                      ? emptyRow(canWrite ? 7 : 6, 'solar:qr-code-bold-duotone', 'No serial numbers found.')
                      : sortedSerials.map((row: any) => (
                          <TableRow key={row.id}>
                            <TableCell>
                              <div className="font-medium">{row.product?.name}</div>
                              <div className="text-xs text-muted-foreground">{row.product?.sku}</div>
                            </TableCell>
                            <TableCell className="font-mono text-xs">{row.serial_number}</TableCell>
                            <TableCell>{row.batch?.lot_number || row.batch?.batch_number || '-'}</TableCell>
                            <TableCell>{row.warehouse?.name || '-'}</TableCell>
                            <TableCell>
                              <Badge variant="secondary" className={`capitalize ${statusStyles[row.status] || fallbackStatusStyle}`}>{row.status || '-'}</Badge>
                            </TableCell>
                            <TableCell>{row.sale_reference || '-'}</TableCell>
                            {canWrite && <TableCell className="text-right">{rowActions(row, openSerialEdit)}</TableCell>}
                          </TableRow>
                        ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Pagination
        page={page}
        totalPages={data?.last_page ?? 1}
        onPageChange={setPage}
        from={(page - 1) * pageSize + 1}
        to={Math.min(page * pageSize, data?.total ?? 0)}
        total={data?.total ?? 0}
        label={type === 'batches' ? 'lots / batches' : 'serial numbers'}
        pageSize={pageSize}
        onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
      />

      {/* Edit Lot / Batch */}
      <Dialog open={Boolean(editingBatch)} onOpenChange={(open) => !open && setEditingBatch(null)}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Edit Lot / Batch</DialogTitle>
          </DialogHeader>
          {editingBatch && (
            <div className="space-y-4 py-2">
              <div className="rounded-xl bg-muted/50 px-4 py-3 text-sm">
                <div className="font-medium">{editingBatch.product?.name} <span className="text-muted-foreground">({editingBatch.product?.sku})</span></div>
                <div className="mt-1 text-xs text-muted-foreground">
                  On hand {formatQty(editingBatch.quantity)} · Reserved {formatQty(editingBatch.quantity_reserved)} — quantity changes are inventory movements; use Receive Stock or a stock adjustment.
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="batch_number">Batch / Lot number</Label>
                  <Input id="batch_number" value={batchForm.batch_number} onChange={(event) => setBatchForm({ ...batchForm, batch_number: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="lot_number">Lot number (optional)</Label>
                  <Input id="lot_number" value={batchForm.lot_number} onChange={(event) => setBatchForm({ ...batchForm, lot_number: event.target.value })} placeholder="Defaults to the batch number" />
                </div>
                <div className="space-y-2">
                  <Label>Warehouse</Label>
                  <Select value={batchForm.warehouse_id} onValueChange={(value) => setBatchForm({ ...batchForm, warehouse_id: value })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No warehouse</SelectItem>
                      {warehouses.map((warehouse: any) => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Status</Label>
                  <Select value={batchForm.status} onValueChange={(value) => setBatchForm({ ...batchForm, status: value })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {BATCH_STATUSES.map((status) => <SelectItem key={status} value={status} className="capitalize">{status}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="expiry_date">Expiry date</Label>
                  <Input id="expiry_date" type="date" value={batchForm.expiry_date} onChange={(event) => setBatchForm({ ...batchForm, expiry_date: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="manufacturing_date">Manufacturing date</Label>
                  <Input id="manufacturing_date" type="date" value={batchForm.manufacturing_date} onChange={(event) => setBatchForm({ ...batchForm, manufacturing_date: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="received_date">Received date</Label>
                  <Input id="received_date" type="date" value={batchForm.received_date} onChange={(event) => setBatchForm({ ...batchForm, received_date: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="supplier_batch_number">Supplier batch number</Label>
                  <Input id="supplier_batch_number" value={batchForm.supplier_batch_number} onChange={(event) => setBatchForm({ ...batchForm, supplier_batch_number: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="cost">Unit cost</Label>
                  <Input id="cost" type="number" min="0" step="0.01" value={batchForm.cost} onChange={(event) => setBatchForm({ ...batchForm, cost: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="batch_notes">Notes</Label>
                  <Input id="batch_notes" value={batchForm.notes} onChange={(event) => setBatchForm({ ...batchForm, notes: event.target.value })} />
                </div>
              </div>
            </div>
          )}
          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setEditingBatch(null)} disabled={updateBatchMutation.isPending}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:check-circle-bold"
              loading={updateBatchMutation.isPending}
              disabled={!batchForm.batch_number.trim() || updateBatchMutation.isPending}
              onClick={submitBatch}
            >
              Save changes
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Serial Number */}
      <Dialog open={Boolean(editingSerial)} onOpenChange={(open) => !open && setEditingSerial(null)}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Edit Serial Number</DialogTitle>
          </DialogHeader>
          {editingSerial && (
            <div className="space-y-4 py-2">
              <div className="rounded-xl bg-muted/50 px-4 py-3 text-sm">
                <div className="font-medium">{editingSerial.product?.name} <span className="text-muted-foreground">({editingSerial.product?.sku})</span></div>
                {editingSerial.sale_reference && (
                  <div className="mt-1 text-xs text-muted-foreground">Sale reference {editingSerial.sale_reference}</div>
                )}
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="serial_number">Serial number</Label>
                  <Input id="serial_number" value={serialForm.serial_number} onChange={(event) => setSerialForm({ ...serialForm, serial_number: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label>Status</Label>
                  <Select value={serialForm.status} onValueChange={(value) => setSerialForm({ ...serialForm, status: value })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {SERIAL_STATUSES.map((status) => <SelectItem key={status} value={status} className="capitalize">{status}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Warehouse</Label>
                  <Select value={serialForm.warehouse_id} onValueChange={(value) => setSerialForm({ ...serialForm, warehouse_id: value })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No warehouse</SelectItem>
                      {warehouses.map((warehouse: any) => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>Lot / Batch</Label>
                  <Select value={serialForm.batch_id} onValueChange={(value) => setSerialForm({ ...serialForm, batch_id: value })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No lot</SelectItem>
                      {(serialBatchOptions?.data || []).map((batch: any) => (
                        <SelectItem key={batch.id} value={String(batch.id)}>{batch.batch_number}{batch.expiry_date ? ` · Exp ${dateOnly(batch.expiry_date)}` : ''}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="serial_cost">Unit cost</Label>
                  <Input id="serial_cost" type="number" min="0" step="0.01" value={serialForm.cost} onChange={(event) => setSerialForm({ ...serialForm, cost: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="mac_address">MAC address</Label>
                  <Input id="mac_address" value={serialForm.mac_address} onChange={(event) => setSerialForm({ ...serialForm, mac_address: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="imei">IMEI</Label>
                  <Input id="imei" value={serialForm.imei} onChange={(event) => setSerialForm({ ...serialForm, imei: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="serial_manufacturing_date">Manufacturing date</Label>
                  <Input id="serial_manufacturing_date" type="date" value={serialForm.manufacturing_date} onChange={(event) => setSerialForm({ ...serialForm, manufacturing_date: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="warranty_start_date">Warranty start</Label>
                  <Input id="warranty_start_date" type="date" value={serialForm.warranty_start_date} onChange={(event) => setSerialForm({ ...serialForm, warranty_start_date: event.target.value })} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="warranty_end_date">Warranty end</Label>
                  <Input id="warranty_end_date" type="date" value={serialForm.warranty_end_date} onChange={(event) => setSerialForm({ ...serialForm, warranty_end_date: event.target.value })} />
                </div>
                <div className="col-span-2 space-y-2">
                  <Label htmlFor="serial_notes">Notes</Label>
                  <Textarea id="serial_notes" rows={2} value={serialForm.notes} onChange={(event) => setSerialForm({ ...serialForm, notes: event.target.value })} />
                </div>
              </div>
            </div>
          )}
          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setEditingSerial(null)} disabled={updateSerialMutation.isPending}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton
              icon="solar:check-circle-bold"
              loading={updateSerialMutation.isPending}
              disabled={!serialForm.serial_number.trim() || updateSerialMutation.isPending}
              onClick={submitSerial}
            >
              Save changes
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete confirmation */}
      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={deleteTarget?.type === 'serials' ? 'Delete this serial number?' : 'Delete this lot/batch?'}
        description={
          deleteTarget?.type === 'serials'
            ? `Serial ${deleteTarget?.row?.serial_number} will be removed. Sold or reserved serials cannot be deleted.`
            : `Lot/batch ${deleteTarget?.row?.batch_number || deleteTarget?.row?.lot_number} will be removed. A lot with stock on hand, reserved stock, serials or consumption history cannot be deleted.`
        }
        confirmLabel="Delete"
        isPending={deleteBatchMutation.isPending || deleteSerialMutation.isPending}
        onConfirm={() => {
          if (!deleteTarget) return
          if (deleteTarget.type === 'batches') deleteBatchMutation.mutate(deleteTarget.row.id)
          else deleteSerialMutation.mutate(deleteTarget.row.id)
        }}
      />

    </div>
  )
}