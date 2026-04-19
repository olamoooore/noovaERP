import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
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

  const showWorkflowToast = (operation: 'stock-in' | 'stock-out' | 'adjust') => {
    const descriptions = {
      'stock-in': 'Direct stock-in is disabled. Use the purchase order and goods receipt workflow to receive inventory.',
      'stock-out': 'Direct stock-out is disabled. Use sales fulfillment or warehouse transfer workflows to reduce inventory.',
      'adjust': 'Direct stock adjustment is disabled. Use an approved inventory adjustment workflow instead.',
    }

    toast({
      title: 'Direct stock mutations are disabled',
      description: descriptions[operation],
      variant: 'destructive',
    })
  }

  const handleStockIn = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockInData.productId || !stockInData.warehouseId || !stockInData.quantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    showWorkflowToast('stock-in')
  }

  const handleStockOut = (e: React.FormEvent) => {
    e.preventDefault()
    if (!stockOutData.productId || !stockOutData.warehouseId || !stockOutData.quantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    showWorkflowToast('stock-out')
  }

  const handleAdjust = (e: React.FormEvent) => {
    e.preventDefault()
    if (!adjustData.productId || !adjustData.warehouseId || !adjustData.newQuantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    showWorkflowToast('adjust')
  }

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
                    <ProductSearchCombobox
                      value={stockInData.productId}
                      onValueChange={(value, product) => {
                        setStockInData({ ...stockInData, productId: value })
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

                <Button type="submit" className="w-full md:w-auto">
                  <>
                    <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                    Use Supported Workflow
                  </>
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

                <Button type="submit" variant="destructive" className="w-full md:w-auto">
                  <>
                    <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
                    Use Supported Workflow
                  </>
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
                    <Label>New Quantity *</Label>
                    <Input
                      type="number"
                      min="0"
                      value={adjustData.newQuantity}
                      onChange={(e) => setAdjustData({ ...adjustData, newQuantity: e.target.value })}
                      placeholder="Enter new quantity"
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

                <Button type="submit" className="w-full md:w-auto">
                  <>
                    <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                    Use Supported Workflow
                  </>
                </Button>
              </form>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  )
}
