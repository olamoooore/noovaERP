import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Textarea } from '@/components/ui/textarea'
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
  tracking_method?: 'none' | 'quantity' | 'batch' | 'serial'
}

interface Warehouse {
  id: number
  name: string
}

export function StockInPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [productId, setProductId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [quantity, setQuantity] = useState('')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [batchNumber, setBatchNumber] = useState('')
  const [expiryDate, setExpiryDate] = useState('')
  const [serialNumbers, setSerialNumbers] = useState('')

  useEffect(() => {
    const nextProductId = searchParams.get('product_id')
    const nextWarehouseId = searchParams.get('warehouse_id')

    if (nextProductId) {
      setProductId((current) => current || nextProductId)
    }

    if (nextWarehouseId) {
      setWarehouseId((current) => current || nextWarehouseId)
    }
  }, [searchParams])

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

  const mutation = useMutation({
    mutationFn: async () => {
      return api.post('/stock/in', {
        product_id: parseInt(productId),
        warehouse_id: parseInt(warehouseId),
        quantity: parseInt(quantity),
        reference: reference || null,
        notes: notes || null,
        batch_number: batchNumber || null,
        expiry_date: expiryDate || null,
        lot_number: batchNumber || null,
        serial_numbers: serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['products'] })
      toast({ title: 'Stock added successfully' })
      navigate('/inventory/movements')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to add stock',
        variant: 'destructive',
      })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!productId || !warehouseId || !quantity) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
    }
    if (selectedProduct?.tracking_method === 'batch' && !batchNumber.trim()) {
      toast({ title: 'Batch/Lot number is required', variant: 'destructive' })
      return
    }
    if (selectedProduct?.tracking_method === 'serial') {
      const serials = serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean)
      if (serials.length !== parseInt(quantity) || new Set(serials).size !== serials.length) {
        toast({ title: 'Serial count must match quantity and contain no duplicates', variant: 'destructive' })
        return
      }
    }
    mutation.mutate()
  }

  const selectedProduct = products?.find((p) => p.id === parseInt(productId))

  return (
    <div className="space-y-6 max-w-2xl">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
          <Icon icon="solar:arrow-left-bold" className="h-5 w-5" />
        </Button>
        <h1 className="text-3xl font-bold">Stock In</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Add Stock to Inventory</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Product *</label>
              <Select value={productId} onValueChange={(value) => { setProductId(value); setBatchNumber(''); setExpiryDate(''); setSerialNumbers(''); }}>
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
              {selectedProduct && (
                <p className="text-sm text-muted-foreground">
                  Current stock: {selectedProduct.stock_quantity}
                </p>
              )}
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Warehouse *</label>
              <Select value={warehouseId} onValueChange={setWarehouseId}>
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
              <label className="text-sm font-medium">Quantity *</label>
              <Input
                type="number"
                min="1"
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                placeholder="Enter quantity"
              />
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Reference</label>
              <Input
                value={reference}
                onChange={(e) => setReference(e.target.value)}
                placeholder="PO number, invoice, etc."
              />
            </div>

            {selectedProduct?.tracking_method === 'batch' && <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Batch/Lot Number *</label>
                <Input
                  value={batchNumber}
                  onChange={(e) => setBatchNumber(e.target.value)}
                  placeholder="Batch/Lot number"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Expiry Date</label>
                <Input
                  type="date"
                  value={expiryDate}
                  onChange={(e) => setExpiryDate(e.target.value)}
                />
              </div>
            </div>}

            {selectedProduct?.tracking_method === 'serial' && <div className="space-y-2">
              <label className="text-sm font-medium">Serial Numbers *</label>
              <Textarea value={serialNumbers} onChange={(event) => setSerialNumbers(event.target.value)} placeholder="One serial number per line or comma-separated" rows={5} />
              <p className="text-xs text-muted-foreground">Entered {serialNumbers.split(/[\n,]+/).map((value) => value.trim()).filter(Boolean).length} of {parseInt(quantity) || 0}</p>
            </div>}

            <div className="space-y-2">
              <label className="text-sm font-medium">Notes</label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Additional notes..."
                rows={3}
              />
            </div>

            <div className="flex gap-2 pt-4">
              <Button type="button" variant="outline" onClick={() => navigate(-1)}>
                Cancel
              </Button>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? 'Adding...' : 'Add Stock'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
