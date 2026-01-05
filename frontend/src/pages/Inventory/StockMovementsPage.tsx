import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import api from '@/lib/api'

interface Warehouse {
  id: number
  name: string
}

export function StockMovementsPage() {
  const [search, setSearch] = useState('')
  const [type, setType] = useState('all')
  const [warehouseId, setWarehouseId] = useState('all')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')

  const { data: warehouses } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const res = await api.get('/warehouses')
      return res.data
    },
  })

  const { data: movementsData, isLoading } = useQuery({
    queryKey: ['stock-movements', search, type, warehouseId, fromDate, toDate],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      if (type !== 'all') params.type = type
      if (warehouseId !== 'all') params.warehouse_id = warehouseId
      if (fromDate) params.from_date = fromDate
      if (toDate) params.to_date = toDate
      const res = await api.get('/stock/movements', { params })
      return res.data
    },
  })

  const movements = movementsData?.data || []

  const getTypeColor = (movementType: string) => {
    switch (movementType) {
      case 'in':
      case 'purchase':
      case 'transfer_in':
      case 'return':
        return 'bg-green-100 text-green-800'
      case 'out':
      case 'sale':
      case 'transfer_out':
        return 'bg-red-100 text-red-800'
      case 'adjustment':
        return 'bg-blue-100 text-blue-800'
      default:
        return 'bg-gray-100 text-gray-800'
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Stock Movements</h1>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link to="/inventory/stock/out">
              <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
              Stock Out
            </Link>
          </Button>
          <Button asChild>
            <Link to="/inventory/stock/in">
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Stock In
            </Link>
          </Button>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
            <Input
              placeholder="Search product, reference..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <Select value={type} onValueChange={setType}>
              <SelectTrigger>
                <SelectValue placeholder="Movement Type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="in">Stock In</SelectItem>
                <SelectItem value="out">Stock Out</SelectItem>
                <SelectItem value="adjustment">Adjustment</SelectItem>
                <SelectItem value="purchase">Purchase</SelectItem>
                <SelectItem value="sale">Sale</SelectItem>
                <SelectItem value="transfer_in">Transfer In</SelectItem>
                <SelectItem value="transfer_out">Transfer Out</SelectItem>
              </SelectContent>
            </Select>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger>
                <SelectValue placeholder="Warehouse" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Warehouses</SelectItem>
                {warehouses?.map((w) => (
                  <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Input
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              placeholder="From Date"
            />
            <Input
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              placeholder="To Date"
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Quantity</TableHead>
                <TableHead>Before</TableHead>
                <TableHead>After</TableHead>
                <TableHead>Reference</TableHead>
                <TableHead>Notes</TableHead>
                <TableHead>By</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={9} className="text-center py-8">Loading...</TableCell>
                </TableRow>
              ) : movements.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={9} className="text-center py-8 text-muted-foreground">
                    No movements found
                  </TableCell>
                </TableRow>
              ) : (
                movements.map((movement: any) => (
                  <TableRow key={movement.id}>
                    <TableCell>{new Date(movement.created_at).toLocaleString()}</TableCell>
                    <TableCell>
                      <div>
                        <div className="font-medium">{movement.product?.name}</div>
                        <div className="text-xs text-muted-foreground">{movement.product?.sku}</div>
                      </div>
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeColor(movement.type)}`}>
                        {movement.type.replace('_', ' ')}
                      </span>
                    </TableCell>
                    <TableCell className="font-medium">
                      {movement.type.includes('out') || movement.type === 'sale' ? '-' : '+'}
                      {movement.quantity}
                    </TableCell>
                    <TableCell>{movement.old_quantity ?? '-'}</TableCell>
                    <TableCell>{movement.balance_after}</TableCell>
                    <TableCell>{movement.reference || '-'}</TableCell>
                    <TableCell className="max-w-[200px] truncate">{movement.notes || '-'}</TableCell>
                    <TableCell>{movement.created_by?.name || '-'}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}
