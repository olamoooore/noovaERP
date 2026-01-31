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

interface Product {
  id: number
  name: string
  sku: string
  stock_quantity: number
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

export function StockOperationsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [activeTab, setActiveTab] = useState('stock-in')

  // Stock In state
  const [stockInData, setStockInData] = useState({
    productId: '',
    warehouseId: '',
    quantity: '',
    reference: '',
    notes: '',
    batchNumber: '',
    expiryDate: '',
  })

  // Stock Out state
  const [stockOutData, setStockOutData] = useState({
    productId: '',
    warehouseId: '',
    quantity: '',
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

  const { data: products } = useQuery<Product[]>({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 200 } })
      return res.data.data || []
    },
  })

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

  // Stock In mutation
  const stockInMutation = useMutation({
    mutationFn: async () => {
      return api.post('/stock/in', {
        product_id: parseInt(stockInData.productId),
        warehouse_id: parseInt(stockInData.warehouseId),
        quantity: parseInt(stockInData.quantity),
        reference: stockInData.reference || null,
        notes: stockInData.notes || null,
        batch_number: stockInData.batchNumber || null,
        expiry_date: stockInData.expiryDate || null,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['products'] })
      queryClient.invalidateQueries({ queryKey: ['products-list'] })
      toast({ title: 'Stock added successfully' })
      setStockInData({
        productId: '',
        warehouseId: '',
        quantity: '',
        reference: '',
        notes: '',
        batchNumber: '',
        expiryDate: '',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to add stock',
        variant: 'destructive',
      })
    },
  })

  // Stock Out mutation
  const stockOutMutation = useMutation({
    mutationFn: async () => {
      return api.post('/stock/out', {
        product_id: parseInt(stockOutData.productId),
        warehouse_id: parseInt(stockOutData.warehouseId),
        quantity: parseInt(stockOutData.quantity),
        reference: stockOutData.reference || null,
        notes: stockOutData.notes || null,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['products'] })
      queryClient.invalidateQueries({ queryKey: ['products-list'] })
      toast({ title: 'Stock removed successfully' })
      setStockOutData({
        productId: '',
        warehouseId: '',
        quantity: '',
        reference: '',
        notes: '',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to remove stock',
        variant: 'destructive',
      })
    },
  })

  // Adjust mutation
  const adjustMutation = useMutation({
    mutationFn: async () => {
      return api.post('/stock/adjust', {
        product_id: parseInt(adjustData.productId),
        warehouse_id: parseInt(adjustData.warehouseId),
        new_quantity: parseInt(adjustData.newQuantity),
        reason_id: adjustData.reasonId ? parseInt(adjustData.reasonId) : null,
        notes: adjustData.notes || null,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['products'] })
      queryClient.invalidateQueries({ queryKey: ['products-list'] })
      toast({ title: 'Stock adjusted successfully' })
      setAdjustData({
        productId: '',
        warehouseId: '',
        newQuantity: '',
        reasonId: '',
        notes: '',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to adjust stock',
        variant: 'destructive',
      })
    },
  })

  const handleStockIn = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockInData.productId || !stockInData.warehouseId || !stockInData.quantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    stockInMutation.mutate()
  }

  const handleStockOut = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockOutData.productId || !stockOutData.warehouseId || !stockOutData.quantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    stockOutMutation.mutate()
  }

  const handleAdjust = (e: React.FormEvent) => {
    e.preventDefault()
    if (!adjustData.productId || !adjustData.warehouseId || !adjustData.newQuantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    adjustMutation.mutate()
  }

  const getSelectedProduct = (productId: string) => 
    products?.find((p) => p.id === parseInt(productId))

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Stock Operations</h1>
          <p className="text-muted-foreground">Manage stock movements - add, remove, or adjust inventory</p>
        </div>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="grid w-full grid-cols-3 lg:w-[400px]">
          <TabsTrigger value="stock-in" className="flex items-center gap-2">
            <Icon icon="solar:arrow-down-bold" className="h-4 w-4" />
            Stock In
          </TabsTrigger>
          <TabsTrigger value="stock-out" className="flex items-center gap-2">
            <Icon icon="solar:arrow-up-bold" className="h-4 w-4" />
            Stock Out
          </TabsTrigger>
          <TabsTrigger value="adjust" className="flex items-center gap-2">
            <Icon icon="solar:tuning-square-2-bold" className="h-4 w-4" />
            Adjust
          </TabsTrigger>
        </TabsList>

        {/* Stock In Tab */}
        <TabsContent value="stock-in" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:arrow-down-bold" className="h-5 w-5 text-green-500" />
                Add Stock to Inventory
              </CardTitle>
              <CardDescription>
                Record incoming stock from purchases, returns, or transfers
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleStockIn} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <Select 
                      value={stockInData.productId} 
                      onValueChange={(v) => setStockInData({ ...stockInData, productId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select product" />
                      </SelectTrigger>
                      <SelectContent>
                        {products?.map((p) => (
                          <SelectItem key={p.id} value={String(p.id)}>
                            {p.name} ({p.sku})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {getSelectedProduct(stockInData.productId) && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{getSelectedProduct(stockInData.productId)?.stock_quantity}</Badge>
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
                    <Label>Reference</Label>
                    <Input
                      value={stockInData.reference}
                      onChange={(e) => setStockInData({ ...stockInData, reference: e.target.value })}
                      placeholder="PO number, invoice, etc."
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Batch Number</Label>
                    <Input
                      value={stockInData.batchNumber}
                      onChange={(e) => setStockInData({ ...stockInData, batchNumber: e.target.value })}
                      placeholder="Optional batch number"
                    />
                  </div>

                  <div className="space-y-2">
                    <Label>Expiry Date</Label>
                    <Input
                      type="date"
                      value={stockInData.expiryDate}
                      onChange={(e) => setStockInData({ ...stockInData, expiryDate: e.target.value })}
                    />
                  </div>
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

                <Button type="submit" disabled={stockInMutation.isPending} className="w-full md:w-auto">
                  {stockInMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Processing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                      Add Stock
                    </>
                  )}
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
                Remove Stock from Inventory
              </CardTitle>
              <CardDescription>
                Record outgoing stock for sales, damages, or other reasons
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleStockOut} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <Select 
                      value={stockOutData.productId} 
                      onValueChange={(v) => setStockOutData({ ...stockOutData, productId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select product" />
                      </SelectTrigger>
                      <SelectContent>
                        {products?.map((p) => (
                          <SelectItem key={p.id} value={String(p.id)}>
                            {p.name} ({p.sku})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {getSelectedProduct(stockOutData.productId) && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{getSelectedProduct(stockOutData.productId)?.stock_quantity}</Badge>
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

                <Button type="submit" variant="destructive" disabled={stockOutMutation.isPending} className="w-full md:w-auto">
                  {stockOutMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Processing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
                      Remove Stock
                    </>
                  )}
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
                Adjust Stock Quantity
              </CardTitle>
              <CardDescription>
                Correct inventory counts after physical stock checks or for discrepancies
              </CardDescription>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleAdjust} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label>Product *</Label>
                    <Select 
                      value={adjustData.productId} 
                      onValueChange={(v) => setAdjustData({ ...adjustData, productId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select product" />
                      </SelectTrigger>
                      <SelectContent>
                        {products?.map((p) => (
                          <SelectItem key={p.id} value={String(p.id)}>
                            {p.name} ({p.sku})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {getSelectedProduct(adjustData.productId) && (
                      <p className="text-sm text-muted-foreground">
                        Current stock: <Badge variant="outline">{getSelectedProduct(adjustData.productId)?.stock_quantity}</Badge>
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
                    <Label>New Quantity *</Label>
                    <Input
                      type="number"
                      min="0"
                      value={adjustData.newQuantity}
                      onChange={(e) => setAdjustData({ ...adjustData, newQuantity: e.target.value })}
                      placeholder="Enter new quantity"
                    />
                    {getSelectedProduct(adjustData.productId) && adjustData.newQuantity && (
                      <p className="text-sm">
                        {parseInt(adjustData.newQuantity) > (getSelectedProduct(adjustData.productId)?.stock_quantity || 0) ? (
                          <span className="text-green-600">
                            +{parseInt(adjustData.newQuantity) - (getSelectedProduct(adjustData.productId)?.stock_quantity || 0)} units
                          </span>
                        ) : parseInt(adjustData.newQuantity) < (getSelectedProduct(adjustData.productId)?.stock_quantity || 0) ? (
                          <span className="text-red-600">
                            {parseInt(adjustData.newQuantity) - (getSelectedProduct(adjustData.productId)?.stock_quantity || 0)} units
                          </span>
                        ) : (
                          <span className="text-muted-foreground">No change</span>
                        )}
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Reason</Label>
                    <Select 
                      value={adjustData.reasonId} 
                      onValueChange={(v) => setAdjustData({ ...adjustData, reasonId: v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select reason" />
                      </SelectTrigger>
                      <SelectContent>
                        {adjustmentReasons?.map((r) => (
                          <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>
                        ))}
                        <SelectItem value="other">Other</SelectItem>
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

                <Button type="submit" disabled={adjustMutation.isPending} className="w-full md:w-auto">
                  {adjustMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Processing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                      Adjust Stock
                    </>
                  )}
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}
