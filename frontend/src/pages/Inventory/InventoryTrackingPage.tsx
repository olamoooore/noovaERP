import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Pagination } from '@/components/ui/Pagination'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { SortableHead } from '@/components/ui/SortableHead'
import { useSort } from '@/hooks/useSort'

type TrackingType = 'batches' | 'serials'

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

export function InventoryTrackingPage() {
  const navigate = useNavigate()
  const [type, setType] = useState<TrackingType>('batches')
  const [search, setSearch] = useState('')
  const [warehouseId, setWarehouseId] = useState('all')
  const [productId, setProductId] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)

  const { data, isLoading } = useQuery({
    queryKey: ['inventory-tracking', type, search, warehouseId, productId, page, pageSize],
    queryFn: async () => {
      const response = await api.get(`/inventory/tracking/${type}`, { params: { search: search || undefined, warehouse_id: warehouseId === 'all' ? undefined : warehouseId, product_id: productId === 'all' ? undefined : productId, page, per_page: pageSize } })
      return response.data
    },
  })
  const { data: warehouses = [] } = useQuery({ queryKey: ['warehouses'], queryFn: async () => (await api.get('/warehouses')).data || [] })
  const { data: products = [] } = useQuery({ queryKey: ['products-tracking-filter'], queryFn: async () => { const response = await api.get('/products', { params: { per_page: 500 } }); return response.data?.data || response.data || [] } })
  const rows = data?.data || []
  const { sorted: sortedBatches, sortKey: batchSortKey, sortDir: batchSortDir, toggle: toggleBatchSort } = useSort<any>(rows)
  const { sorted: sortedSerials, sortKey: serialSortKey, sortDir: serialSortDir, toggle: toggleSerialSort } = useSort<any>(rows)

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

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-3xl font-bold tracking-tight">Lot & Serial Tracking</h1><p className="text-muted-foreground">Review tracked inventory and import lots or serial numbers by product.</p></div>
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
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoading
                    ? loadingRow(7)
                    : sortedBatches.length === 0
                      ? emptyRow(7, 'solar:box-bold-duotone', 'No lots or batches found.')
                      : sortedBatches.map((row: any) => (
                          <TableRow key={row.id}>
                            <TableCell>
                              <div className="font-medium">{row.product?.name}</div>
                              <div className="text-xs text-muted-foreground">{row.product?.sku}</div>
                            </TableCell>
                            <TableCell className="font-mono text-xs">{row.batch_number || row.lot_number || '-'}</TableCell>
                            <TableCell>{row.warehouse?.name || '-'}</TableCell>
                            <TableCell className="text-right tabular-nums">{formatQty(row.quantity)}</TableCell>
                            <TableCell className="text-right tabular-nums">{formatQty(row.quantity_reserved)}</TableCell>
                            <TableCell>{row.expiry_date ? String(row.expiry_date).split('T')[0] : '-'}</TableCell>
                            <TableCell>
                              <Badge variant="secondary" className={`capitalize ${statusStyles[row.status] || fallbackStatusStyle}`}>{row.status || '-'}</Badge>
                            </TableCell>
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
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoading
                    ? loadingRow(6)
                    : sortedSerials.length === 0
                      ? emptyRow(6, 'solar:qr-code-bold-duotone', 'No serial numbers found.')
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

    </div>
  )
}
