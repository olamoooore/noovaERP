import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function BonusSchemeCreatePage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [formData, setFormData] = useState({
    name: '',
    description: '',
    start_date: '',
    end_date: '',
    status: 'draft',
  })

  const [products, setProducts] = useState<any[]>([])

  const { data: productList } = useQuery({
    queryKey: ['products-list'],
    queryFn: async () => {
      const res = await api.get('/products', { params: { per_page: 1000 } })
      return res.data.data || []
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const res = await api.post('/bonus-schemes', data)
      return res.data
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['bonus-schemes'] })
      toast({ title: 'Success', description: 'Bonus scheme created successfully' })
      navigate(`/sales/bonus-schemes/${data.data.id}`)
    },
    onError: (error: any) => {
      toast({ title: 'Error', description: error.response?.data?.message || 'Failed to create', variant: 'destructive' })
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const payload = {
      ...formData,
      products: products.filter(p => p.qualifying_product_id && p.scheme_type),
    }
    createMutation.mutate(payload)
  }

  const addProduct = () => {
    setProducts([...products, {
      qualifying_product_id: '',
      scheme_type: 'fixed_discount',
      discount_amount: '',
      discount_percentage: '',
      minimum_purchase_qty: '',
      foc_product_id: '',
      foc_qty: '',
      priority: 0,
    }])
  }

  const removeProduct = (index: number) => {
    setProducts(products.filter((_, i) => i !== index))
  }

  const updateProduct = (index: number, field: string, value: any) => {
    const updated = [...products]
    updated[index] = { ...updated[index], [field]: value }
    setProducts(updated)
  }

  return (
    <div className="container mx-auto py-6 space-y-6">
      <div className="flex items-center justify-between">
        <Button variant="outline" onClick={() => navigate('/sales/bonus-schemes')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="mr-2 h-4 w-4" />
          Back
        </Button>
        <h1 className="text-3xl font-bold">Create Bonus Scheme</h1>
      </div>

      <form onSubmit={handleSubmit}>
        <Card className="mb-6">
          <CardHeader>
            <CardTitle>Scheme Information</CardTitle>
          </CardHeader>
          <CardContent className="p-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="md:col-span-2">
                <label className="text-sm font-medium">Name *</label>
                <input
                  type="text"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                />
              </div>
              <div className="md:col-span-2">
                <label className="text-sm font-medium">Description</label>
                <textarea
                  value={formData.description}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  className="w-full mt-1 flex min-h-[80px] rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Start Date *</label>
                <input
                  type="date"
                  value={formData.start_date}
                  onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                  required
                />
              </div>
              <div>
                <label className="text-sm font-medium">End Date</label>
                <input
                  type="date"
                  value={formData.end_date}
                  onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="text-sm font-medium">Status</label>
                <select
                  value={formData.status}
                  onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                  className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="draft">Draft</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Products */}
        <Card className="mb-6">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Scheme Products</CardTitle>
            <Button type="button" variant="outline" size="sm" onClick={addProduct}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Add Product
            </Button>
          </CardHeader>
          <CardContent>
            {products.length === 0 ? (
              <div className="py-8 text-center text-muted-foreground">
                <p>No products added yet. Click "Add Product" to begin.</p>
              </div>
            ) : (
              <div className="space-y-4">
                {products.map((product, index) => (
                  <div key={index} className="grid grid-cols-1 md:grid-cols-4 gap-3 p-4 border rounded-lg">
                    <div className="md:col-span-2">
                      <label className="text-sm font-medium">Qualifying Product *</label>
                      <select
                        value={product.qualifying_product_id}
                        onChange={(e) => updateProduct(index, 'qualifying_product_id', e.target.value)}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                      >
                        <option value="">Select Product</option>
                        {productList?.map((p: any) => (
                          <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      <label className="text-sm font-medium">Scheme Type *</label>
                      <select
                        value={product.scheme_type}
                        onChange={(e) => updateProduct(index, 'scheme_type', e.target.value)}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        required
                      >
                        <option value="fixed_discount">Fixed Discount</option>
                        <option value="percentage_discount">Percentage Discount</option>
                        <option value="buy_x_get_y">Buy X Get Y</option>
                      </select>
                    </div>
                    <div className="flex items-end">
                      <Button type="button" variant="ghost" size="sm" onClick={() => removeProduct(index)}>
                        <Icon icon="solar:trash-bin-minimalistic-bold-duotone" className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>

                    {product.scheme_type === 'fixed_discount' && (
                      <div>
                        <label className="text-sm font-medium">Discount Amount</label>
                        <input
                          type="number"
                          value={product.discount_amount}
                          onChange={(e) => updateProduct(index, 'discount_amount', e.target.value)}
                          className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                          min="0"
                          step="0.01"
                        />
                      </div>
                    )}

                    {product.scheme_type === 'percentage_discount' && (
                      <div>
                        <label className="text-sm font-medium">Discount %</label>
                        <input
                          type="number"
                          value={product.discount_percentage}
                          onChange={(e) => updateProduct(index, 'discount_percentage', e.target.value)}
                          className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                          min="0"
                          max="100"
                        />
                      </div>
                    )}

                    {product.scheme_type === 'buy_x_get_y' && (
                      <>
                        <div>
                          <label className="text-sm font-medium">Min Purchase Qty</label>
                          <input
                            type="number"
                            value={product.minimum_purchase_qty}
                            onChange={(e) => updateProduct(index, 'minimum_purchase_qty', e.target.value)}
                            className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            min="0"
                          />
                        </div>
                        <div>
                          <label className="text-sm font-medium">FOC Product</label>
                          <select
                            value={product.foc_product_id}
                            onChange={(e) => updateProduct(index, 'foc_product_id', e.target.value)}
                            className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                          >
                            <option value="">Select Product</option>
                            {productList?.map((p: any) => (
                              <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                          </select>
                        </div>
                        <div>
                          <label className="text-sm font-medium">FOC Qty</label>
                          <input
                            type="number"
                            value={product.foc_qty}
                            onChange={(e) => updateProduct(index, 'foc_qty', e.target.value)}
                            className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                            min="0"
                          />
                        </div>
                      </>
                    )}

                    <div>
                      <label className="text-sm font-medium">Priority</label>
                      <input
                        type="number"
                        value={product.priority}
                        onChange={(e) => updateProduct(index, 'priority', Number(e.target.value))}
                        className="w-full mt-1 flex h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
                        min="0"
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="outline" onClick={() => navigate('/sales/bonus-schemes')}>
            Cancel
          </Button>
          <Button type="submit" disabled={createMutation.isPending}>
            {createMutation.isPending ? 'Creating...' : 'Create Bonus Scheme'}
          </Button>
        </div>
      </form>
    </div>
  )
}
