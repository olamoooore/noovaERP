import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'

interface Product {
  id: number
  name: string
  sku: string
  stock_quantity: number
  tracking_method?: 'none' | 'quantity' | 'batch' | 'serial'
}

interface Warehouse {
  id: number
  name: string
}

interface AdjustmentReason {
  id: number
  name: string
  type: string
}

interface InventorySettings {
  sme_mode: boolean
}

const isReasonAllowedForOperation = (reason: AdjustmentReason, operation: 'stock-in' | 'stock-out' | 'adjust') => {
  if (operation === 'stock-in') return reason.type === 'increase' || reason.type === 'both'
  if (operation === 'stock-out') return reason.type === 'decrease' || reason.type === 'both'
  return reason.type === 'both'
}

export function StockOperationsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [activeTab, setActiveTab] = useState('stock-in')

  // Stock In state
  const [stockInData, setStockInData] = useState({
    productId: '',
    warehouseId: '',
    quantity: '',
    reasonId: '',
    reference: '',
    notes: '',
    batchNumber: '',
    expiryDate: '',
    serialNumbers: '',
  })

  // Stock Out state
  const [stockOutData, setStockOutData] = useState({
    productId: '',
    warehouseId: '',
    quantity: '',
    reasonId: '',
    reference: '',
    notes: '',
  })

  // Adjust state
  const [adjustData, setAdjustData] = useState({
    productId: '',
    warehouseId: '',
    newQuantity: '',
    reasonId: '',
    notes: '',
  })

  // Selected products for showing stock quantity
  const [selectedStockInProduct, setSelectedStockInProduct] = useState<Product | undefined>()
  const [selectedStockOutProduct, setSelectedStockOutProduct] = useState<Product | undefined>()
  const [selectedAdjustProduct, setSelectedAdjustProduct] = useState<Product | undefined>()

  const { data: warehouses } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const res = await api.get('/warehouses')
      return res.data
    },
  })

  const { data: adjustmentReasons } = useQuery<AdjustmentReason[]>({
    queryKey: ['adjustment-reasons'],
    queryFn: async () => {
      const res = await api.get('/stock/adjustment-reasons')
      return res.data || []
    },
  })

  const { data: inventorySettings } = useQuery<InventorySettings>({
    queryKey: ['inventory-settings'],
    queryFn: async () => {
      const res = await api.get('/inventory/settings')
      return res.data
    },
  })

  const invalidateStockQueries = () => {
    const keysToRefresh = new Set([
      'stock-movements',
      'products',
      'products-stock',
      'products-list',
      'products-search',
      'product-detail',
      'low-stock',
      'out-of-stock',
      'inventory-dashboard',
    ])

    queryClient.invalidateQueries({
      predicate: (query) => keysToRefresh.has(String(query.queryKey[0] ?? '')),
    })
  }

  const getMovementDelta = (movement: any) => {
    if (movement.type === 'adjustment') {
      return movement.balance_after - movement.old_quantity
    }

    if (['out', 'sale', 'transfer_out'].includes(movement.type)) {
      return -Math.abs(movement.quantity)
    }

    return Math.abs(movement.quantity)
  }

  const refreshProductStockInCache = async (productId: number) => {
    const response = await api.get(`/stock/product/${productId}`)
    const stockLevels = Array.isArray(response.data) ? response.data : []
    const totalStock = stockLevels.reduce((sum, stockLevel) => sum + Number(stockLevel.quantity ?? 0), 0)

    queryClient.setQueriesData({
      predicate: (query) => ['products', 'products-stock', 'products-list', 'products-search'].includes(String(query.queryKey[0] ?? '')),
    }, (existing: any) => {
      if (!existing) return existing

      if (Array.isArray(existing)) {
        return existing.map((item) => item?.id === productId ? { ...item, stock_quantity: totalStock, stock: totalStock } : item)
      }

      if (Array.isArray(existing?.data)) {
        return {
          ...existing,
          data: existing.data.map((item: any) => item?.id === productId ? { ...item, stock_quantity: totalStock, stock: totalStock } : item),
        }
      }

      return existing
    })

    queryClient.setQueriesData({
      predicate: (query) => String(query.queryKey[0] ?? '') === 'product-detail' && String(query.queryKey[1] ?? '') === String(productId),
    }, (existing: any) => {
      if (!existing) return existing
      return { ...existing, stock_quantity: totalStock, stock: totalStock }
    })

    return totalStock
  }

  const refetchStockQueries = async () => {
    await Promise.all([
      queryClient.refetchQueries({ queryKey: ['products-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['stock-movements'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['low-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['out-of-stock'], type: 'active' }),
      queryClient.refetchQueries({ queryKey: ['inventory-dashboard'], type: 'active' }),
    ])
  }

  const assertMovementResponse = (payload: any) => {
    if (!payload || typeof payload !== 'object' || typeof payload.id !== 'number') {
      throw new Error('Unexpected stock mutation response from server.')
    }
    return payload
  }

  const syncProductStockInCache = (movement: any) => {
    const productId = movement?.product?.id ?? movement?.product_id
    const movementDelta = getMovementDelta(movement)

    if (typeof productId !== 'number') {
      return
    }

    queryClient.setQueriesData({
      predicate: (query) => ['products', 'products-stock', 'products-list', 'products-search'].includes(String(query.queryKey[0] ?? '')),
    }, (existing: any) => {
      if (!existing) return existing

      if (Array.isArray(existing)) {
        return existing.map((item) => {
          if (item?.id !== productId) return item
          const nextStockQuantity = Math.max(0, Number(item?.stock_quantity ?? item?.stock ?? 0) + movementDelta)
          return { ...item, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
        })
      }

      if (Array.isArray(existing?.data)) {
        return {
          ...existing,
          data: existing.data.map((item: any) => {
            if (item?.id !== productId) return item
            const nextStockQuantity = Math.max(0, Number(item?.stock_quantity ?? item?.stock ?? 0) + movementDelta)
            return { ...item, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
          }),
        }
      }

      return existing
    })

    queryClient.setQueriesData({
      predicate: (query) => String(query.queryKey[0] ?? '') === 'product-detail' && String(query.queryKey[1] ?? '') === String(productId),
    }, (existing: any) => {
      if (!existing) return existing
      const nextStockQuantity = Math.max(0, Number(existing?.stock_quantity ?? existing?.stock ?? 0) + movementDelta)
      return { ...existing, stock_quantity: nextStockQuantity, stock: nextStockQuantity }
    })
  }

  const stockInMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/in', {
        product_id: parseInt(stockInData.productId),
        warehouse_id: parseInt(stockInData.warehouseId),
        quantity: parseInt(stockInData.quantity),
        adjustment_reason_id: parseInt(stockInData.reasonId),
        reference: stockInData.reference || null,
        notes: stockInData.notes || null,
        batch_number: stockInData.batchNumber || null,
        expiry_date: stockInData.expiryDate || null,
        lot_number: stockInData.batchNumber || null,
        serial_numbers: stockInData.serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean),
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      const newStock = movement?.product?.stock_quantity ?? movement?.balance_after
      if (selectedStockInProduct && typeof newStock === 'number') {
        setSelectedStockInProduct({ ...selectedStockInProduct, stock_quantity: newStock })
      }
      toast({ title: 'Stock received successfully' })
      setStockInData({ productId: '', warehouseId: '', quantity: '', reasonId: '', reference: '', notes: '', batchNumber: '', expiryDate: '', serialNumbers: '' })
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to receive stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const stockOutMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/out', {
        product_id: parseInt(stockOutData.productId),
        warehouse_id: parseInt(stockOutData.warehouseId),
        quantity: parseInt(stockOutData.quantity),
        adjustment_reason_id: parseInt(stockOutData.reasonId),
        reference: stockOutData.reference || null,
        notes: stockOutData.notes || null,
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      const newStock = movement?.product?.stock_quantity ?? movement?.balance_after
      if (selectedStockOutProduct && typeof newStock === 'number') {
        setSelectedStockOutProduct({ ...selectedStockOutProduct, stock_quantity: newStock })
      }
      toast({ title: 'Stock removed successfully' })
      setStockOutData({ productId: '', warehouseId: '', quantity: '', reasonId: '', reference: '', notes: '' })
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to remove stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const adjustMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/stock/adjust', {
        product_id: parseInt(adjustData.productId),
        warehouse_id: parseInt(adjustData.warehouseId),
        new_quantity: parseInt(adjustData.newQuantity),
        adjustment_reason_id: adjustData.reasonId ? parseInt(adjustData.reasonId) : undefined,
        notes: adjustData.notes || null,
      })
      return assertMovementResponse(response.data)
    },
    onSuccess: async (movement) => {
      syncProductStockInCache(movement)
      invalidateStockQueries()
      const newStock = movement?.product?.stock_quantity ?? movement?.balance_after
      if (selectedAdjustProduct && typeof newStock === 'number') {
        setSelectedAdjustProduct({ ...selectedAdjustProduct, stock_quantity: newStock })
      }
      toast({ title: 'Stock adjusted successfully' })
      setAdjustData({ productId: '', warehouseId: '', newQuantity: '', reasonId: '', notes: '' })
    },
    onError: (error: any) => {
      const msg = error.response?.data?.message || 'Failed to adjust stock'
      toast({ title: 'Error', description: msg, variant: 'destructive' })
    },
  })

  const handleStockIn = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockInData.productId || !stockInData.warehouseId || !stockInData.quantity || !stockInData.reasonId) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    if (selectedStockInProduct?.tracking_method === 'batch' && !stockInData.batchNumber.trim()) {
      toast({ title: 'Batch/Lot number is required', variant: 'destructive' })
      return
    }
    if (selectedStockInProduct?.tracking_method === 'serial') {
      const serials = stockInData.serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean)
      if (serials.length !== parseInt(stockInData.quantity) || new Set(serials).size !== serials.length) {
        toast({ title: 'Serial count must match quantity and contain no duplicates', variant: 'destructive' })
        return
      }
    }
    stockInMutation.mutate()
  }

  const handleStockOut = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockOutData.productId || !stockOutData.warehouseId || !stockOutData.quantity || !stockOutData.reasonId) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    stockOutMutation.mutate()
  }

  const handleAdjust = (e: React.FormEvent) => {
    e.preventDefault()
    if (!adjustData.productId || !adjustData.warehouseId || adjustData.newQuantity === '' || !adjustData.reasonId) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    adjustMutation.mutate()
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Stock Operations</h1>
          <p className="text-muted-foreground">Record business events that change stock: receipts, removals, and counted balances</p>
        </div>
      </div>

      {inventorySettings && !inventorySettings.sme_mode && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
          <div className="flex items-start gap-2">
            <Icon icon="solar:info-circle-bold" className="mt-0.5 h-4 w-4 shrink-0" />
            <div>
              <span className="font-semibold">SME Mode is disabled.</span> Direct stock entry requires SME Mode to be enabled.
              Go to <a href="/inventory/settings" className="underline font-medium">Inventory Settings</a> and turn on <em>SME Mode</em> to use these operations, or use the{' '}
              <a href="/inventory/purchase-orders" className="underline font-medium">Purchase Order</a> workflow instead.
            </div>
          </div>
        </div>
      )}

      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="grid w-full grid-cols-3 lg:w-[400px]">
          <TabsTrigger value="stock-in" className="flex items-center gap-2">
            <Icon icon="solar:arrow-down-bold" className="h-4 w-4" />
            Receive Stock
          </TabsTrigger>
          <TabsTrigger value="stock-out" className="flex items-center gap-2">
            <Icon icon="solar:arrow-up-bold" className="h-4 w-4" />
            Remove Stock
          </TabsTrigger>
          <TabsTrigger value="adjust" className="flex items-center gap-2">
            <Icon icon="solar:tuning-square-2-bold" className="h-4 w-4" />
            Count Stock
          </TabsTrigger>
        </TabsList>

        {/* Stock In Tab */}
        <TabsContent value="stock-in" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:arrow-down-bold" className="h-5 w-5 text-green-500" />
                Receive Stock
              </CardTitle>
              <CardDescription>
                Record a manual stock receipt such as opening balance, found stock, or a return to stock
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleStockIn} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <ProductSearchCombobox
                      value={stockInData.productId}
                      onValueChange={(value, product) => {
                        setStockInData({ ...stockInData, productId: value, batchNumber: '', expiryDate: '', serialNumbers: '' })
                        setSelectedStockInProduct(product)
                      }}
                      placeholder="Search products..."
                    />
                    {selectedStockInProduct && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{selectedStockInProduct.stock_quantity}</Badge>
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Warehouse *</Label>
                    <Select 
                      value={stockInData.warehouseId} 
                      onValueChange={(v) => setStockInData({ ...stockInData, warehouseId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select warehouse" />
                      </SelectTrigger>
                      <SelectContent>
                        {warehouses?.map((w) => (
                          <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Quantity *</Label>
                    <Input
                      type="number"
                      min="1"
                      value={stockInData.quantity}
                      onChange={(e) => setStockInData({ ...stockInData, quantity: e.target.value })}
                      placeholder="Enter quantity"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Business Reason *</Label>
                    <Select
                      value={stockInData.reasonId}
                      onValueChange={(v) => setStockInData({ ...stockInData, reasonId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Why is stock increasing?" />
                      </SelectTrigger>
                      <SelectContent>
                        {adjustmentReasons?.filter((reason) => isReasonAllowedForOperation(reason, 'stock-in')).map((reason) => (
                          <SelectItem key={reason.id} value={String(reason.id)}>{reason.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Reference</Label>
                    <Input
                      value={stockInData.reference}
                      onChange={(e) => setStockInData({ ...stockInData, reference: e.target.value })}
                      placeholder="PO number, invoice, etc."
                    />
                  </div>

                  {selectedStockInProduct?.tracking_method === 'batch' && <div className="space-y-2">
                    <Label>Batch Number</Label>
                    <Input
                      value={stockInData.batchNumber}
                      onChange={(e) => setStockInData({ ...stockInData, batchNumber: e.target.value })}
                      placeholder="Required batch/lot number"
                    />
                  </div>}

                  {selectedStockInProduct?.tracking_method === 'batch' && <div className="space-y-2">
                    <Label>Expiry Date</Label>
                    <Input
                      type="date"
                      value={stockInData.expiryDate}
                      onChange={(e) => setStockInData({ ...stockInData, expiryDate: e.target.value })}
                    />
                  </div>}

                  {selectedStockInProduct?.tracking_method === 'serial' && <div className="space-y-2 md:col-span-2">
                    <Label>Serial Numbers *</Label>
                    <Textarea value={stockInData.serialNumbers} onChange={(event) => setStockInData({ ...stockInData, serialNumbers: event.target.value })} placeholder="One serial number per line or comma-separated" rows={5} />
                    <p className="text-xs text-muted-foreground">Entered {stockInData.serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean).length} of {parseInt(stockInData.quantity) || 0}</p>
                  </div>}
                </div>

                <div className="space-y-2">
                  <Label>Notes</Label>
                  <Textarea
                    value={stockInData.notes}
                    onChange={(e) => setStockInData({ ...stockInData, notes: e.target.value })}
                    placeholder="Additional notes..."
                    rows={3}
                  />
                </div>

                <Button type="submit" className="w-full md:w-auto" disabled={stockInMutation.isPending}>
                  <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                  {stockInMutation.isPending ? 'Receiving...' : 'Receive Stock'}
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Stock Out Tab */}
        <TabsContent value="stock-out" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:arrow-up-bold" className="h-5 w-5 text-red-500" />
                Remove Stock
              </CardTitle>
              <CardDescription>
                Record a business removal such as damage, loss, expiry, or internal consumption
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleStockOut} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <ProductSearchCombobox
                      value={stockOutData.productId}
                      onValueChange={(value, product) => {
                        setStockOutData({ ...stockOutData, productId: value })
                        setSelectedStockOutProduct(product)
                      }}
                      placeholder="Search products..."
                    />
                    {selectedStockOutProduct && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{selectedStockOutProduct.stock_quantity}</Badge>
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Warehouse *</Label>
                    <Select 
                      value={stockOutData.warehouseId} 
                      onValueChange={(v) => setStockOutData({ ...stockOutData, warehouseId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select warehouse" />
                      </SelectTrigger>
                      <SelectContent>
                        {warehouses?.map((w) => (
                          <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Quantity *</Label>
                    <Input
                      type="number"
                      min="1"
                      value={stockOutData.quantity}
                      onChange={(e) => setStockOutData({ ...stockOutData, quantity: e.target.value })}
                      placeholder="Enter quantity"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Business Reason *</Label>
                    <Select
                      value={stockOutData.reasonId}
                      onValueChange={(v) => setStockOutData({ ...stockOutData, reasonId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Why is stock decreasing?" />
                      </SelectTrigger>
                      <SelectContent>
                        {adjustmentReasons?.filter((reason) => isReasonAllowedForOperation(reason, 'stock-out')).map((reason) => (
                          <SelectItem key={reason.id} value={String(reason.id)}>{reason.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Reference</Label>
                    <Input
                      value={stockOutData.reference}
                      onChange={(e) => setStockOutData({ ...stockOutData, reference: e.target.value })}
                      placeholder="Order number, reason, etc."
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label>Notes</Label>
                  <Textarea
                    value={stockOutData.notes}
                    onChange={(e) => setStockOutData({ ...stockOutData, notes: e.target.value })}
                    placeholder="Additional notes..."
                    rows={3}
                  />
                </div>

                <Button type="submit" variant="destructive" className="w-full md:w-auto" disabled={stockOutMutation.isPending}>
                  <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
                  {stockOutMutation.isPending ? 'Removing...' : 'Remove Stock'}
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Adjust Tab */}
        <TabsContent value="adjust" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:tuning-square-2-bold" className="h-5 w-5 text-blue-500" />
                Reconcile Stock Count
              </CardTitle>
              <CardDescription>
                Reconcile stock to the physically counted quantity after a stock count
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleAdjust} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <ProductSearchCombobox
                      value={adjustData.productId}
                      onValueChange={(value, product) => {
                        setAdjustData({ ...adjustData, productId: value })
                        setSelectedAdjustProduct(product)
                      }}
                      placeholder="Search products..."
                    />
                    {selectedAdjustProduct && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{selectedAdjustProduct.stock_quantity}</Badge>
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Warehouse *</Label>
                    <Select 
                      value={adjustData.warehouseId} 
                      onValueChange={(v) => setAdjustData({ ...adjustData, warehouseId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select warehouse" />
                      </SelectTrigger>
                      <SelectContent>
                        {warehouses?.map((w) => (
                          <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Counted Quantity *</Label>
                    <Input
                      type="number"
                      min="0"
                      value={adjustData.newQuantity}
                      onChange={(e) => setAdjustData({ ...adjustData, newQuantity: e.target.value })}
                      placeholder="Enter physically counted quantity"
                    />
                    {selectedAdjustProduct && adjustData.newQuantity && (
                      <p className="text-sm">
                        {parseInt(adjustData.newQuantity) > (selectedAdjustProduct.stock_quantity || 0) ? (
                          <span className="text-green-600">
                            +{parseInt(adjustData.newQuantity) - (selectedAdjustProduct.stock_quantity || 0)} units
                          </span>
                        ) : parseInt(adjustData.newQuantity) < (selectedAdjustProduct.stock_quantity || 0) ? (
                          <span className="text-red-600">
                            {parseInt(adjustData.newQuantity) - (selectedAdjustProduct.stock_quantity || 0)} units
                          </span>
                        ) : (
                          <span className="text-muted-foreground">No change</span>
                        )}
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Business Reason *</Label>
                    <Select 
                      value={adjustData.reasonId} 
                      onValueChange={(v) => setAdjustData({ ...adjustData, reasonId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select why stock is being reconciled" />
                      </SelectTrigger>
                      <SelectContent>
                        {adjustmentReasons?.filter((reason) => isReasonAllowedForOperation(reason, 'adjust')).map((r) => (
                          <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                <div className="space-y-2">
                  <Label>Notes</Label>
                  <Textarea
                    value={adjustData.notes}
                    onChange={(e) => setAdjustData({ ...adjustData, notes: e.target.value })}
                    placeholder="Explain the reason for adjustment..."
                    rows={3}
                  />
                </div>

                <Button type="submit" className="w-full md:w-auto" disabled={adjustMutation.isPending}>
                  <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                  {adjustMutation.isPending ? 'Posting...' : 'Post Stock Count'}
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}
