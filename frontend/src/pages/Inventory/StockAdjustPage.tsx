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
}

interface Warehouse {
  id: number
  name: string
}

interface AdjustmentReason {
  id: number
  name: string
  code: string
  type: string
}

export function StockAdjustPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [productId, setProductId] = useState('')
  const [warehouseId, setWarehouseId] = useState('')
  const [newQuantity, setNewQuantity] = useState('')
  const [reasonId, setReasonId] = useState('')
  const [notes, setNotes] = useState('')

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

  const { data: reasons } = useQuery<AdjustmentReason[]>({
    queryKey: ['adjustment-reasons'],
    queryFn: async () => {
      const res = await api.get('/stock/adjustment-reasons')
      return res.data
    },
  })

  const mutation = useMutation({
    mutationFn: async () => {
      return api.post('/stock/adjust', {
        product_id: parseInt(productId),
        warehouse_id: parseInt(warehouseId),
        new_quantity: parseInt(newQuantity),
        reason_id: parseInt(reasonId),
        notes: notes || null,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stock-movements'] })
      queryClient.invalidateQueries({ queryKey: ['products'] })
      toast({ title: 'Stock adjusted successfully' })
      navigate('/inventory/movements')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to adjust stock',
        variant: 'destructive',
      })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!productId || !warehouseId || newQuantity === '' || !reasonId) {
      toast({ title: 'Please fill all required fields', variant: 'destructive' })
      return
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
        <h1 className="text-3xl font-bold">Stock Adjustment</h1>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Adjust Stock Quantity</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Product *</label>
              <Select value={productId} onValueChange={setProductId}>
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
              <label className="text-sm font-medium">New Quantity *</label>
              <Input
                type="number"
                min="0"
                value={newQuantity}
                onChange={(e) => setNewQuantity(e.target.value)}
                placeholder="Enter new quantity"
              />
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Reason *</label>
              <Select value={reasonId} onValueChange={setReasonId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select reason" />
                </SelectTrigger>
                <SelectContent>
                  {reasons?.map((r) => (
                    <SelectItem key={r.id} value={String(r.id)}>
                      {r.name} ({r.code})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Notes</label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Reason for adjustment..."
                rows={3}
              />
            </div>

            <div className="flex gap-2 pt-4">
              <Button type="button" variant="outline" onClick={() => navigate(-1)}>
                Cancel
              </Button>
              <Button type="submit" disabled={mutation.isPending}>
                {mutation.isPending ? 'Adjusting...' : 'Adjust Stock'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
