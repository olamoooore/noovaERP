import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
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
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-3xl font-bold">Lot & Serial Tracking</h1><p className="text-muted-foreground">Review tracked inventory and import lots or serial numbers by product.</p></div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton icon="solar:box-minimalistic-bold-duotone" variant="outline" onClick={() => navigate('/inventory/operations')}>
            Receive Stock
          </HeaderActionButton>
          <HeaderActionButton icon="solar:upload-bold" onClick={() => navigate(`/inventory/tracking/import?type=${type}`)}>
            Bulk Import
          </HeaderActionButton>
        </div>
      </div>

      <Tabs value={type} onValueChange={(value) => { setType(value as TrackingType); setProductId('all'); setPage(1) }}>
        <TabsList><TabsTrigger value="batches">Lots / Batches</TabsTrigger><TabsTrigger value="serials">Serial Numbers</TabsTrigger></TabsList>
        <div className="mt-4 flex flex-wrap gap-3">
          <Input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder={`Search ${type === 'batches' ? 'lot, batch or product' : 'serial or product'}…`} className="max-w-sm" />
          <Select value={productId} onValueChange={(value) => { setProductId(value); setPage(1) }}><SelectTrigger className="w-64"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">All products</SelectItem>{products.filter((product: any) => type === 'batches' ? product.tracking_method === 'batch' : product.tracking_method === 'serial').map((product: any) => <SelectItem key={product.id} value={String(product.id)}>{product.name} ({product.sku})</SelectItem>)}</SelectContent></Select>
          <Select value={warehouseId} onValueChange={(value) => { setWarehouseId(value); setPage(1) }}><SelectTrigger className="w-56"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="all">All warehouses</SelectItem>{warehouses.map((warehouse: any) => <SelectItem key={warehouse.id} value={String(warehouse.id)}>{warehouse.name}</SelectItem>)}</SelectContent></Select>
        </div>

        <TabsContent value="batches">
          <Card><CardHeader><CardTitle>Lots / Batches</CardTitle></CardHeader><CardContent><Table><TableHeader><TableRow><SortableHead label="Product" sortKey="product.name" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} /><SortableHead label="Lot / Batch" sortKey="batch_number" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} /><SortableHead label="Warehouse" sortKey="warehouse.name" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} /><SortableHead label="On Hand" sortKey="quantity" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} align="right" /><SortableHead label="Reserved" sortKey="quantity_reserved" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} align="right" /><SortableHead label="Expiry" sortKey="expiry_date" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} /><SortableHead label="Status" sortKey="status" activeKey={batchSortKey} direction={batchSortDir} onSort={toggleBatchSort} /></TableRow></TableHeader><TableBody>
            {!isLoading && sortedBatches.map((row: any) => <TableRow key={row.id}><TableCell><div className="font-medium">{row.product?.name}</div><div className="text-xs text-muted-foreground">{row.product?.sku}</div></TableCell><TableCell>{row.batch_number || row.lot_number || '-'}</TableCell><TableCell>{row.warehouse?.name || '-'}</TableCell><TableCell className="text-right">{row.quantity}</TableCell><TableCell className="text-right">{row.quantity_reserved || 0}</TableCell><TableCell>{row.expiry_date ? String(row.expiry_date).split('T')[0] : '-'}</TableCell><TableCell><Badge variant="outline">{row.status}</Badge></TableCell></TableRow>)}
            {!isLoading && rows.length === 0 && <TableRow><TableCell colSpan={7} className="py-10 text-center text-muted-foreground">No lots or batches found.</TableCell></TableRow>}
          </TableBody></Table></CardContent></Card>
        </TabsContent>

        <TabsContent value="serials">
          <Card><CardHeader><CardTitle>Serial Numbers</CardTitle></CardHeader><CardContent><Table><TableHeader><TableRow><SortableHead label="Product" sortKey="product.name" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /><SortableHead label="Serial Number" sortKey="serial_number" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /><SortableHead label="Lot" sortKey="batch.lot_number" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /><SortableHead label="Warehouse" sortKey="warehouse.name" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /><SortableHead label="Status" sortKey="status" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /><SortableHead label="Sale Reference" sortKey="sale_reference" activeKey={serialSortKey} direction={serialSortDir} onSort={toggleSerialSort} /></TableRow></TableHeader><TableBody>
            {!isLoading && sortedSerials.map((row: any) => <TableRow key={row.id}><TableCell><div className="font-medium">{row.product?.name}</div><div className="text-xs text-muted-foreground">{row.product?.sku}</div></TableCell><TableCell className="font-mono">{row.serial_number}</TableCell><TableCell>{row.batch?.lot_number || row.batch?.batch_number || '-'}</TableCell><TableCell>{row.warehouse?.name || '-'}</TableCell><TableCell><Badge variant="outline">{row.status}</Badge></TableCell><TableCell>{row.sale_reference || '-'}</TableCell></TableRow>)}
            {!isLoading && rows.length === 0 && <TableRow><TableCell colSpan={6} className="py-10 text-center text-muted-foreground">No serial numbers found.</TableCell></TableRow>}
          </TableBody></Table></CardContent></Card>
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
