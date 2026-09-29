import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatCurrency } from '@/lib/utils'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { ProductCatalogDialog } from '@/components/sales/ProductCatalogDialog'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'

interface Product {
  id: number
  name: string
  sku: string
  cost: number
}

interface Supplier {
  id: number
  name: string
}

interface Warehouse {
  id: number
  name: string
}

interface OrderItem {
  line_type?: 'product' | 'note' | 'section'
  product_id: string
  product_name: string
  quantity_ordered: number
  unit_cost: number
  tax_rate: number
  line_total: number
}

export function PurchaseOrderCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [supplierId, setSupplierId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [orderDate, setOrderDate] = useState(new Date().toISOString().split('T')[0])
  const [expectedDate, setExpectedDate] = useState('')
  const [notes, setNotes] = useState('')
  const [items, setItems] = useState<OrderItem[]>([])

  // For adding new item
  const [selectedProductId, setSelectedProductId] = useState('')
  const [selectedProduct, setSelectedProduct] = useState<{ name: string; sku: string; stock_quantity: number } | null>(null)
  const [quantity, setQuantity] = useState('')
  const [unitCost, setUnitCost] = useState('')

  const { data: suppliers } = useQuery<Supplier[]>({
    queryKey: ['suppliers-list'],
    queryFn: async () => {
      const res = await api.get('/suppliers', { params: { per_page: 100, is_active: true } })
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

  const { data: products } = useQuery<Product[]>({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 200 } })
      return res.data.data || []
    },
  })

  const mutation = useMutation({
    mutationFn: async () => {
      return api.post('/purchase-orders', {
        supplier_id: parseInt(supplierId),
        warehouse_id: parseInt(warehouseId),
        order_date: orderDate,
        expected_date: expectedDate || null,
        notes: notes || null,
        items: items.map(item => ({
          line_type: item.line_type || 'product',
          sequence: items.indexOf(item),
          product_id: item.product_id ? parseInt(item.product_id) : null,
          description: item.line_type === 'note' ? item.product_name : undefined,
          quantity_ordered: item.quantity_ordered,
          unit_cost: item.unit_cost,
          tax_rate: item.tax_rate,
        })),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      toast({ title: 'Purchase order created' })
      navigate('/inventory/purchase-orders')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create purchase order',
        variant: 'destructive',
      })
    },
  })

  const addItem = () => {
    if (!selectedProductId || !quantity || !unitCost) {
      toast({
        title: 'Missing line details',
        description: 'Pick a product and fill in quantity and unit cost.',
        variant: 'destructive',
      })
      return
    }

    const product = products?.find(p => p.id === parseInt(selectedProductId))
    if (!product) return

    const qty = parseInt(quantity)
    const cost = parseFloat(unitCost)
    const lineTotal = qty * cost

    setItems([...items, {
      product_id: selectedProductId,
      product_name: product.name,
      quantity_ordered: qty,
      unit_cost: cost,
      tax_rate: 0,
      line_total: lineTotal,
    }])

    setSelectedProductId('')
    setSelectedProduct(null)
    setQuantity('')
    setUnitCost('')
  }

  const addNote = () => setItems([...items, { line_type: 'note', product_id: '', product_name: '', quantity_ordered: 0, unit_cost: 0, tax_rate: 0, line_total: 0 }])

  const addCatalogItems = (selected: Array<{ product: any; quantity: number }>) => {
    const next = [...items]
    selected.forEach(({ product, quantity }) => {
      const existing = next.find((item) => item.line_type !== 'note' && item.product_id === String(product.id))
      if (existing) { existing.quantity_ordered += quantity; existing.line_total = existing.quantity_ordered * existing.unit_cost }
      else next.push({ product_id: String(product.id), product_name: product.name || '', quantity_ordered: quantity, unit_cost: Number(product.cost || product.price || 0), tax_rate: 0, line_total: quantity * Number(product.cost || product.price || 0) })
    })
    setItems(next)
  }

  const removeItem = (index: number) => {
    setItems(items.filter((_, i) => i !== index))
  }

  const total = items.reduce((sum, item) => sum + item.line_total, 0)
  const productLines = items.filter((item) => item.line_type !== 'note')
  const supplierName = suppliers?.find((s) => String(s.id) === supplierId)?.name
  const warehouseName = warehouses?.find((w) => String(w.id) === warehouseId)?.name
  const canAddItem = Boolean(selectedProductId && quantity && unitCost)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!supplierId || !warehouseId || items.length === 0) {
      toast({
        title: 'Incomplete purchase order',
        description: 'Select a supplier and warehouse, then add at least one item.',
        variant: 'destructive',
      })
      return
    }
    mutation.mutate()
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <HeaderActionButton
          variant="outline"
          icon="solar:alt-arrow-left-bold-duotone"
          onClick={() => navigate(-1)}
          className="mb-3"
        >
          Back
        </HeaderActionButton>
        <h1 className="text-3xl font-bold tracking-tight">New Purchase Order</h1>
        <p className="mt-1 text-muted-foreground">Order stock from a supplier and track it until it is received.</p>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid items-start gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            {/* Order details */}
            <Card className="overflow-hidden rounded-xl">
              <CardHeader className="border-b">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                    <Icon icon="solar:clipboard-list-bold-duotone" className="h-5 w-5 text-primary" />
                  </div>
                  <div>
                    <CardTitle className="text-base">Order Details</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">Supplier, delivering warehouse and expected dates.</p>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 p-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="po-supplier">
                    Supplier <span className="text-destructive">*</span>
                  </Label>
                  <Select value={supplierId} onValueChange={setSupplierId}>
                    <SelectTrigger id="po-supplier">
                      <SelectValue placeholder="Select supplier" />
                    </SelectTrigger>
                    <SelectContent>
                      {suppliers?.map((s) => (
                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="po-warehouse">
                    Warehouse <span className="text-destructive">*</span>
                  </Label>
                  <Select value={warehouseId} onValueChange={setWarehouseId}>
                    <SelectTrigger id="po-warehouse">
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
                  <Label htmlFor="po-order-date">
                    Order Date <span className="text-destructive">*</span>
                  </Label>
                  <Input id="po-order-date" type="date" value={orderDate} onChange={(e) => setOrderDate(e.target.value)} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="po-expected-date">Expected Date</Label>
                  <Input id="po-expected-date" type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
                </div>
                <div className="space-y-2 sm:col-span-2">
                  <Label htmlFor="po-notes">Notes</Label>
                  <Textarea
                    id="po-notes"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    rows={3}
                    placeholder="Optional internal notes for this purchase order"
                  />
                </div>
              </CardContent>
            </Card>

            {/* Order items */}
            <Card className="overflow-hidden rounded-xl">
              <CardHeader className="border-b">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-500/10">
                      <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                      <CardTitle className="text-base">Order Items</CardTitle>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {productLines.length} {productLines.length === 1 ? 'product' : 'products'} on this order
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <ProductCatalogDialog products={products || []} onAdd={addCatalogItems} />
                    <HeaderActionButton variant="outline" icon="solar:document-add-bold-duotone" onClick={addNote}>
                      Add Note
                    </HeaderActionButton>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4 p-4">
                {/* Add line */}
                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200/80 bg-muted/20 p-3 dark:border-slate-800">
                  <div className="min-w-[220px] flex-1 space-y-2">
                    {/* Selection meta sits ABOVE the field: the add-line row is
                        bottom-aligned, so content below the field would shove it
                        up and distort the line-up with Qty/Unit Cost/Add. */}
                    {selectedProduct && (
                      <p className="truncate text-xs text-muted-foreground" title={`${selectedProduct.name} · SKU ${selectedProduct.sku}`}>
                        {selectedProduct.name} · SKU {selectedProduct.sku} · Stock on hand{' '}
                        <span className="font-medium tabular-nums">{selectedProduct.stock_quantity}</span>
                      </p>
                    )}
                    <Label htmlFor="po-product">Product</Label>
                    <ProductSearchCombobox
                      value={selectedProductId}
                      onValueChange={(value, product) => {
                        setSelectedProductId(value)
                        setSelectedProduct(product ? {
                          name: product.name,
                          sku: product.sku,
                          stock_quantity: product.stock_quantity,
                        } : null)
                        if (product) setUnitCost(String(product.cost ?? product.price ?? 0))
                      }}
                    />
                  </div>
                  <div className="w-24 space-y-2">
                    <Label htmlFor="po-qty">Qty</Label>
                    <Input
                      id="po-qty"
                      type="number"
                      min="1"
                      value={quantity}
                      onChange={(e) => setQuantity(e.target.value)}
                      className="text-right tabular-nums"
                    />
                  </div>
                  <div className="w-32 space-y-2">
                    <Label htmlFor="po-unit-cost">Unit Cost</Label>
                    <Input
                      id="po-unit-cost"
                      type="number"
                      step="0.01"
                      min="0"
                      value={unitCost}
                      onChange={(e) => setUnitCost(e.target.value)}
                      className="text-right tabular-nums"
                    />
                  </div>
                  <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={addItem} disabled={!canAddItem}>
                    Add
                  </HeaderActionButton>
                </div>

                {/* Lines table */}
                <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                  <Table>
                    <TableHeader>
                      <TableRow className="bg-muted/50">
                        <TableHead>Product</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        <TableHead className="text-right">Unit Cost</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                        <TableHead className="w-12" />
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {items.map((item, index) => (
                        <TableRow key={index}>
                          {item.line_type === 'note' ? (
                            <>
                              <TableCell colSpan={4} className="bg-muted/20">
                                <div className="flex items-center gap-2">
                                  <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4 shrink-0 text-muted-foreground" />
                                  <Input
                                    placeholder="Note text (shown on the purchase order)"
                                    value={item.product_name}
                                    onChange={(e) => setItems(items.map((x, i) => i === index ? { ...x, product_name: e.target.value } : x))}
                                  />
                                </div>
                              </TableCell>
                              <TableCell className="text-right">
                                <HeaderActionButton
                                  variant="outline"
                                  icon="solar:trash-bin-trash-bold-duotone"
                                  className="h-7 w-7 border-destructive/40 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                  aria-label="Remove note"
                                  onClick={() => removeItem(index)}
                                />
                              </TableCell>
                            </>
                          ) : (
                            <>
                              <TableCell>
                                <div className="text-sm font-medium">{item.product_name}</div>
                                <div className="text-xs text-muted-foreground">
                                  {products?.find((p) => p.id === Number(item.product_id))?.sku ?? ''}
                                </div>
                              </TableCell>
                              <TableCell className="text-right tabular-nums">{item.quantity_ordered}</TableCell>
                              <TableCell className="text-right tabular-nums">{formatCurrency(item.unit_cost)}</TableCell>
                              <TableCell className="text-right font-medium tabular-nums">{formatCurrency(item.line_total)}</TableCell>
                              <TableCell className="text-right">
                                <HeaderActionButton
                                  variant="outline"
                                  icon="solar:trash-bin-trash-bold-duotone"
                                  className="h-7 w-7 border-destructive/40 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                  aria-label={`Remove ${item.product_name}`}
                                  onClick={() => removeItem(index)}
                                />
                              </TableCell>
                            </>
                          )}
                        </TableRow>
                      ))}
                      {items.length === 0 && (
                        <TableRow>
                          <TableCell colSpan={5} className="py-12 text-center text-muted-foreground">
                            <Icon icon="solar:cart-large-2-bold-duotone" className="mx-auto h-10 w-10 opacity-30" />
                            <p className="mt-3 text-sm font-medium">No items added yet</p>
                            <p className="text-xs">Use Product Catalog or pick a product above to add lines.</p>
                          </TableCell>
                        </TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Summary */}
          <Card className="overflow-hidden rounded-xl lg:sticky lg:top-6">
            <CardHeader className="border-b">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                  <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <CardTitle className="text-base">Summary</CardTitle>
                  <p className="mt-1 text-sm text-muted-foreground">Review before creating.</p>
                </div>
              </div>
            </CardHeader>
            <CardContent className="space-y-4 p-4">
              <div className="space-y-2 text-sm">
                <div className="flex items-center justify-between gap-3">
                  <span className="text-muted-foreground">Supplier</span>
                  <span className="min-w-0 truncate font-medium">{supplierName || '—'}</span>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-muted-foreground">Warehouse</span>
                  <span className="min-w-0 truncate font-medium">{warehouseName || '—'}</span>
                </div>
                <div className="flex items-center justify-between gap-3">
                  <span className="text-muted-foreground">Products</span>
                  <span className="tabular-nums font-medium">{productLines.length}</span>
                </div>
              </div>

              <div className="flex items-center justify-between border-t pt-3">
                <span className="text-sm font-medium">Total</span>
                <span className="text-2xl font-bold tabular-nums">{formatCurrency(total)}</span>
              </div>

              <div className="flex flex-col gap-2">
                <HeaderActionButton
                  type="submit"
                  icon="solar:check-circle-bold-duotone"
                  loading={mutation.isPending}
                  disabled={mutation.isPending}
                  className="w-full justify-center"
                >
                  Create Purchase Order
                </HeaderActionButton>
                <HeaderActionButton
                  type="button"
                  variant="outline"
                  icon="solar:close-circle-bold-duotone"
                  onClick={() => navigate('/inventory/purchase-orders')}
                  className="w-full justify-center"
                >
                  Cancel
                </HeaderActionButton>
              </div>
            </CardContent>
          </Card>
        </div>
      </form>
    </div>
  )
}

export default PurchaseOrderCreatePage