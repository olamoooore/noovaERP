import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
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
import { useToast } from '@/hooks/use-toast'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
import api from '@/lib/api'

interface SearchProduct {
  id: number
  name: string
  sku: string
  stock_quantity: number
  image?: string | null
}

interface Warehouse {
  id: number
  name: string
}

interface TransferItem {
  product_id: string
  product_name: string
  sku: string
  quantity_requested: number
}

export function WarehouseTransferCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [fromWarehouseId, setFromWarehouseId] = useState('')
  const [toWarehouseId, setToWarehouseId] = useState('')
  const [transferDate, setTransferDate] = useState(new Date().toISOString().split('T')[0])
  const [expectedDate, setExpectedDate] = useState('')
  const [notes, setNotes] = useState('')
  const [items, setItems] = useState<TransferItem[]>([])
  
  const [selectedProductId, setSelectedProductId] = useState('')
  const [selectedProduct, setSelectedProduct] = useState<SearchProduct | null>(null)
  const [quantity, setQuantity] = useState('')

  const { data: warehouses } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const res = await api.get('/warehouses')
      return res.data
    },
  })

  const mutation = useMutation({
    mutationFn: async () => {
      return api.post('/warehouse-transfers', {
        from_warehouse_id: parseInt(fromWarehouseId),
        to_warehouse_id: parseInt(toWarehouseId),
        transfer_date: transferDate,
        expected_date: expectedDate || null,
        notes: notes || null,
        items: items.map(item => ({
          product_id: parseInt(item.product_id),
          quantity_requested: item.quantity_requested,
        })),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouse-transfers'] })
      toast({ title: 'Transfer created' })
      navigate('/inventory/transfers')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create transfer',
        variant: 'destructive',
      })
    },
  })

  const addItem = () => {
    if (!selectedProductId || !quantity) {
      toast({ title: 'Please select product and quantity', variant: 'destructive' })
      return
    }

    const product = selectedProduct
    if (!product) return

    const qty = parseInt(quantity, 10)
    if (!Number.isFinite(qty) || qty < 1) {
      toast({ title: 'Quantity must be at least 1', variant: 'destructive' })
      return
    }

    // Selecting a product already on the list tops up its quantity instead of
    // adding a duplicate row.
    const existing = items.find((item) => item.product_id === selectedProductId)
    if (existing) {
      setItems(items.map((item) => item.product_id === selectedProductId
        ? { ...item, quantity_requested: item.quantity_requested + qty }
        : item))
    } else {
      setItems([...items, {
        product_id: selectedProductId,
        product_name: product.name,
        sku: product.sku,
        quantity_requested: qty,
      }])
    }

    setSelectedProductId('')
    setSelectedProduct(null)
    setQuantity('')
  }

  const updateItemQuantity = (index: number, value: number) => {
    if (!Number.isFinite(value) || value < 1) return
    setItems(items.map((item, i) => (i === index ? { ...item, quantity_requested: value } : item)))
  }

  const removeItem = (index: number) => {
    setItems(items.filter((_, i) => i !== index))
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!fromWarehouseId || !toWarehouseId || items.length === 0) {
      toast({ title: 'Please fill all required fields and add at least one item', variant: 'destructive' })
      return
    }
    if (fromWarehouseId === toWarehouseId) {
      toast({ title: 'Source and destination warehouses must be different', variant: 'destructive' })
      return
    }
    mutation.mutate()
  }

  const totalUnits = items.reduce((sum, item) => sum + item.quantity_requested, 0)

  return (
    <div className="max-w-3xl space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/inventory/transfers')}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">New Warehouse Transfer</h1>
          <p className="mt-1 text-muted-foreground">
            Move stock between warehouses with a tracked, auditable transfer.
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2 pt-8">
          <HeaderActionButton variant="outline" onClick={() => navigate(-1)} disabled={mutation.isPending}>
            Cancel
          </HeaderActionButton>
          <HeaderActionButton
            type="submit"
            form="warehouse-transfer-form"
            icon="solar:transfer-vertical-bold-duotone"
            loading={mutation.isPending}
            disabled={mutation.isPending}
          >
            Create Transfer
          </HeaderActionButton>
        </div>
      </div>

      <form id="warehouse-transfer-form" onSubmit={handleSubmit} className="space-y-6">
        {/* Transfer details */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg">Transfer Details</CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="grid gap-4 md:grid-cols-[1fr_auto_1fr] md:items-center">
              <div className="space-y-2">
                <Label>From Warehouse <span className="text-destructive">*</span></Label>
                <Select value={fromWarehouseId} onValueChange={setFromWarehouseId}>
                  <SelectTrigger className="h-9">
                    <SelectValue placeholder="Select source" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses?.map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="hidden justify-center md:flex">
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-muted text-muted-foreground">
                  <Icon icon="solar:arrow-right-bold-duotone" className="h-4 w-4" />
                </div>
              </div>

              <div className="space-y-2">
                <Label>To Warehouse <span className="text-destructive">*</span></Label>
                <Select value={toWarehouseId} onValueChange={setToWarehouseId}>
                  <SelectTrigger className="h-9">
                    <SelectValue placeholder="Select destination" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses?.filter(w => String(w.id) !== fromWarehouseId).map((w) => (
                      <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label>Transfer Date <span className="text-destructive">*</span></Label>
                <Input type="date" className="h-9" value={transferDate} onChange={(e) => setTransferDate(e.target.value)} />
              </div>
              <div className="space-y-2">
                <Label>Expected Date</Label>
                <Input type="date" className="h-9" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} />
              </div>
            </div>

            <div className="space-y-2">
              <Label>Notes</Label>
              <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} placeholder="Optional handling or delivery notes" />
            </div>
          </CardContent>
        </Card>

        {/* Items */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle className="text-lg">Items to Transfer</CardTitle>
            {items.length > 0 && (
              <span className="inline-flex items-center rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">
                {items.length} item{items.length === 1 ? '' : 's'} · {totalUnits} unit{totalUnits === 1 ? '' : 's'}
              </span>
            )}
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap items-end gap-2">
              <div className="min-w-[16rem] flex-1 space-y-2">
                {/* Selection meta sits ABOVE the field: this row is bottom-aligned,
                    so content below the field would shove it up and break the
                    line-up with Quantity/Add. */}
                {selectedProduct && (
                  <p className="truncate text-xs text-muted-foreground" title={`${selectedProduct.name} · SKU ${selectedProduct.sku}`}>
                    {selectedProduct.name} · SKU {selectedProduct.sku} · Stock on hand{' '}
                    <span className="font-medium tabular-nums">{selectedProduct.stock_quantity}</span>
                  </p>
                )}
                <Label>Product</Label>
                <ProductSearchCombobox
                  value={selectedProductId}
                  onValueChange={(value, product) => {
                    setSelectedProductId(value)
                    setSelectedProduct((product as SearchProduct) ?? null)
                  }}
                  placeholder="Search name, SKU, barcode, lot or serial..."
                />
              </div>
              <div className="w-28 space-y-2">
                <Label>Quantity</Label>
                <Input
                  type="number"
                  min="1"
                  className="h-9"
                  placeholder="0"
                  value={quantity}
                  onChange={(e) => setQuantity(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addItem() } }}
                />
              </div>
              <HeaderActionButton
                type="button"
                icon="solar:add-circle-bold"
                onClick={addItem}
                disabled={!selectedProductId || !quantity}
              >
                Add
              </HeaderActionButton>
            </div>

            <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/40 hover:bg-muted/40">
                    <TableHead>Product</TableHead>
                    <TableHead className="w-32 text-right">Quantity</TableHead>
                    <TableHead className="w-14" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.map((item, index) => (
                    <TableRow key={item.product_id}>
                      <TableCell>
                        <div className="font-medium">{item.product_name}</div>
                        <div className="font-mono text-xs text-muted-foreground">{item.sku}</div>
                      </TableCell>
                      <TableCell className="text-right">
                        <Input
                          type="number"
                          min="1"
                          value={item.quantity_requested}
                          onChange={(e) => updateItemQuantity(index, parseInt(e.target.value, 10))}
                          className="ml-auto h-8 w-20 text-right tabular-nums"
                        />
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                          onClick={() => removeItem(index)}
                          title="Remove item"
                          aria-label={`Remove ${item.product_name}`}
                        >
                          <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                  {items.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={3} className="py-10 text-center text-muted-foreground">
                        <Icon icon="solar:box-bold-duotone" className="mx-auto mb-2 h-10 w-10 opacity-40" />
                        <p className="text-sm">No items added yet</p>
                        <p className="mt-0.5 text-xs">Pick a product and quantity above to build the transfer.</p>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      </form>
    </div>
  )
}
