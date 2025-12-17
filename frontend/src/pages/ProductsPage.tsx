import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Checkbox } from '@/components/ui/checkbox'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

type TaxMode = 'default' | 'none' | 'custom' | 'tax'

interface Category {
  id: number
  name: string
}

interface Tax {
  id: number
  name: string
  rate: number
  is_active: boolean
}

export function ProductsPage() {
  const [open, setOpen] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    secondary_name: '',
    sku: '',
    barcode: '',
    type: 'product' as 'product' | 'service',
    category_id: '',
    price: '',
    cost: '',
    track_inventory: true,
    stock_quantity: '',
    description: '',

    image: '',
    gallery: '',

    tax_mode: 'default' as TaxMode,
    tax_included: false,
    tax_rate_override: '',
    tax_id: '',
  })

  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: products } = useQuery({
    queryKey: ['products'],
    queryFn: async () => {
      const response = await api.get('/products')
      return response.data.data
    },
  })

  const { data: categories } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: taxes } = useQuery<Tax[]>({
    queryKey: ['taxes'],
    queryFn: async () => {
      const response = await api.get('/taxes')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/products', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      setOpen(false)
      setFormData({
        name: '',
        secondary_name: '',
        sku: '',
        barcode: '',
        type: 'product',
        category_id: '',
        price: '',
        cost: '',
        track_inventory: true,
        stock_quantity: '',
        description: '',
        image: '',
        gallery: '',
        tax_mode: 'default',
        tax_included: false,
        tax_rate_override: '',
        tax_id: '',
      })
      toast({ title: 'Product created successfully' })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to create product' })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const galleryArray = (formData.gallery || '')
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)

    createMutation.mutate({
      ...formData,
      type: formData.type,
      category_id: formData.category_id ? parseInt(formData.category_id) : null,
      track_inventory: !!formData.track_inventory,
      price: parseFloat(formData.price),
      cost: parseFloat(formData.cost),
      stock_quantity: formData.track_inventory ? parseInt(formData.stock_quantity) : null,
      image: formData.image || null,
      gallery: galleryArray.length > 0 ? galleryArray : [],
      tax_mode: formData.tax_mode,
      tax_included: !!formData.tax_included,
      tax_rate_override:
        formData.tax_mode === 'custom' && formData.tax_rate_override !== ''
          ? parseFloat(formData.tax_rate_override)
          : null,
      tax_id:
        formData.tax_mode === 'tax' && formData.tax_id
          ? parseInt(formData.tax_id)
          : null,
      is_active: true,
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Products</h1>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Product
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add New Product</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="name">Product Name</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="secondary_name">Secondary Name (optional)</Label>
                <Input
                  id="secondary_name"
                  value={formData.secondary_name}
                  onChange={(e) => setFormData({ ...formData, secondary_name: e.target.value })}
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="sku">SKU</Label>
                  <Input
                    id="sku"
                    value={formData.sku}
                    onChange={(e) => setFormData({ ...formData, sku: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="barcode">Barcode (optional)</Label>
                  <Input
                    id="barcode"
                    value={formData.barcode}
                    onChange={(e) => setFormData({ ...formData, barcode: e.target.value })}
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Type</Label>
                  <Select
                    value={formData.type}
                    onValueChange={(v) => setFormData({ ...formData, type: v as 'product' | 'service' })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="product">Product</SelectItem>
                      <SelectItem value="service">Service</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label>Category (optional)</Label>
                  <Select
                    value={formData.category_id || 'none'}
                    onValueChange={(v) => setFormData({ ...formData, category_id: v === 'none' ? '' : v })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select category" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {categories?.map((cat) => (
                        <SelectItem key={cat.id} value={String(cat.id)}>
                          {cat.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="cost">Cost</Label>
                  <Input
                    id="cost"
                    type="number"
                    step="0.01"
                    value={formData.cost}
                    onChange={(e) => setFormData({ ...formData, cost: e.target.value })}
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="price">Price</Label>
                  <Input
                    id="price"
                    type="number"
                    step="0.01"
                    value={formData.price}
                    onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                    required
                  />
                </div>
              </div>

              <div className="flex items-center gap-2">
                <Checkbox
                  checked={formData.track_inventory}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, track_inventory: checked === true })
                  }
                />
                <Label className="cursor-pointer" onClick={() => setFormData({ ...formData, track_inventory: !formData.track_inventory })}>
                  Track inventory
                </Label>
              </div>

              {formData.track_inventory && (
                <div className="space-y-2">
                  <Label htmlFor="stock">Stock Quantity</Label>
                  <Input
                    id="stock"
                    type="number"
                    value={formData.stock_quantity}
                    onChange={(e) => setFormData({ ...formData, stock_quantity: e.target.value })}
                    required
                  />
                </div>
              )}
              <div className="space-y-2">
                <Label htmlFor="description">Description</Label>
                <Input
                  id="description"
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="image">Main Image URL (optional)</Label>
                <Input
                  id="image"
                  value={formData.image}
                  onChange={(e) => setFormData({ ...formData, image: e.target.value })}
                  placeholder="https://..."
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="gallery">Gallery URLs (comma-separated)</Label>
                <Input
                  id="gallery"
                  value={formData.gallery}
                  onChange={(e) => setFormData({ ...formData, gallery: e.target.value })}
                  placeholder="https://..., https://..."
                />
              </div>

              <div className="rounded-lg border p-3 space-y-3">
                <div className="font-medium">Tax</div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Tax Mode</Label>
                    <Select
                      value={formData.tax_mode}
                      onValueChange={(v) => setFormData({ ...formData, tax_mode: v as TaxMode })}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Use default tax</SelectItem>
                        <SelectItem value="none">No tax</SelectItem>
                        <SelectItem value="custom">Custom rate</SelectItem>
                        <SelectItem value="tax">Select a tax</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Tax Included</Label>
                    <div className="flex items-center gap-2 h-10">
                      <Checkbox
                        checked={formData.tax_included}
                        onCheckedChange={(checked) =>
                          setFormData({ ...formData, tax_included: checked === true })
                        }
                      />
                      <span className="text-sm text-muted-foreground">Price includes tax</span>
                    </div>
                  </div>
                </div>

                {formData.tax_mode === 'custom' && (
                  <div className="space-y-2">
                    <Label htmlFor="tax_rate_override">Custom Tax Rate (%)</Label>
                    <Input
                      id="tax_rate_override"
                      type="number"
                      step="0.01"
                      value={formData.tax_rate_override}
                      onChange={(e) => setFormData({ ...formData, tax_rate_override: e.target.value })}
                      placeholder="e.g. 9"
                    />
                  </div>
                )}

                {formData.tax_mode === 'tax' && (
                  <div className="space-y-2">
                    <Label>Tax</Label>
                    <Select
                      value={formData.tax_id || 'none'}
                      onValueChange={(v) => setFormData({ ...formData, tax_id: v === 'none' ? '' : v })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select tax" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">Select…</SelectItem>
                        {taxes
                          ?.filter((t) => t.is_active)
                          .map((t) => (
                            <SelectItem key={t.id} value={String(t.id)}>
                              {t.name} ({t.rate}%)
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}

                <div className="text-xs text-muted-foreground">
                  Tax list comes from Settings → Tax.
                </div>
              </div>

              <Button type="submit" className="w-full" disabled={createMutation.isPending}>
                Create Product
              </Button>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>All Products</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>SKU</TableHead>
                <TableHead>Cost</TableHead>
                <TableHead>Price</TableHead>
                <TableHead>Stock</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {products?.map((product: any) => (
                <TableRow key={product.id}>
                  <TableCell className="font-medium">{product.name}</TableCell>
                  <TableCell>{product.sku}</TableCell>
                  <TableCell>{formatCurrency(product.cost)}</TableCell>
                  <TableCell>{formatCurrency(product.price)}</TableCell>
                  <TableCell>{product.stock_quantity}</TableCell>
                  <TableCell>
                    <span
                      className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                        product.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {product.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}
