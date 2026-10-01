import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { ProductSearchCombobox } from '@/components/inventory/ProductSearchCombobox'
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
      qualifying_product_name: '',
      foc_product_name: '',
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
    <div className="max-w-4xl space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/sales/bonus-schemes')}
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">Create Bonus Scheme</h1>
          <p className="mt-1 text-muted-foreground">
            Define which products qualify and how customers are rewarded.
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2 pt-8">
          <HeaderActionButton variant="outline" onClick={() => navigate('/sales/bonus-schemes')} disabled={createMutation.isPending}>
            Cancel
          </HeaderActionButton>
          <HeaderActionButton
            type="submit"
            form="bonus-scheme-form"
           
            icon="solar:gift-bold-duotone"
            loading={createMutation.isPending}
            disabled={createMutation.isPending}
          >
            Create Bonus Scheme
          </HeaderActionButton>
        </div>
      </div>

      <form id="bonus-scheme-form" onSubmit={handleSubmit} className="space-y-6">
        {/* Scheme information */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader>
            <CardTitle className="text-lg">Scheme Information</CardTitle>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="space-y-2">
              <Label>Name <span className="text-destructive">*</span></Label>
              <Input
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g. Q4 Loyalty Bonus"
                className="h-9"
                required
              />
            </div>

            <div className="space-y-2">
              <Label>Description</Label>
              <Textarea
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="What this scheme rewards and who it applies to"
                rows={2}
              />
            </div>

            <div className="grid gap-4 md:grid-cols-3">
              <div className="space-y-2">
                <Label>Start Date <span className="text-destructive">*</span></Label>
                <Input
                  type="date"
                  value={formData.start_date}
                  onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                  className="h-9"
                  required
                />
              </div>
              <div className="space-y-2">
                <Label>End Date</Label>
                <Input
                  type="date"
                  value={formData.end_date}
                  onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                  className="h-9"
                />
              </div>
              <div className="space-y-2">
                <Label>Status</Label>
                <Select
                  value={formData.status}
                  onValueChange={(value) => setFormData({ ...formData, status: value })}
                >
                  <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="draft">Draft</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Products */}
        <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle className="text-lg">Scheme Products</CardTitle>
            <HeaderActionButton type="button" variant="outline" icon="solar:add-circle-bold" onClick={addProduct}>
              Add Product
            </HeaderActionButton>
          </CardHeader>
          <CardContent className="space-y-4">
            {products.length === 0 ? (
              <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center dark:border-slate-700">
                <Icon icon="solar:gift-bold-duotone" className="mx-auto mb-2 h-10 w-10 text-muted-foreground opacity-40" />
                <p className="text-sm font-medium">No products added yet</p>
                <p className="mt-0.5 text-xs text-muted-foreground">Add a product to define what qualifies for the bonus.</p>
                <div className="mt-4 flex justify-center">
                  <HeaderActionButton type="button" icon="solar:add-circle-bold" onClick={addProduct}>
                    Add Product
                  </HeaderActionButton>
                </div>
              </div>
            ) : (
              products.map((product, index) => (
                <div
                  key={index}
                  className="group relative rounded-xl border border-slate-200/80 p-4 dark:border-slate-800"
                >
                  <div className="absolute right-3 top-3">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                      onClick={() => removeProduct(index)}
                      title="Remove product"
                      aria-label="Remove product"
                    >
                      <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                    </Button>
                  </div>

                  <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-2 md:col-span-1">
                      <Label>Qualifying Product <span className="text-destructive">*</span></Label>
                      <ProductSearchCombobox
                        value={product.qualifying_product_id}
                        onValueChange={(value, selected) => {
                          updateProduct(index, 'qualifying_product_id', value)
                          updateProduct(index, 'qualifying_product_name', selected?.name ?? '')
                        }}
                        placeholder="Search name, SKU, barcode, lot or serial..."
                      />
                    </div>
                    <div className="space-y-2">
                      <Label>Scheme Type <span className="text-destructive">*</span></Label>
                      <Select
                        value={product.scheme_type}
                        onValueChange={(value) => updateProduct(index, 'scheme_type', value)}
                      >
                        <SelectTrigger className="h-9"><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="fixed_discount">Fixed Discount</SelectItem>
                          <SelectItem value="percentage_discount">Percentage Discount</SelectItem>
                          <SelectItem value="buy_x_get_y">Buy X Get Y</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    {product.scheme_type === 'fixed_discount' && (
                      <div className="space-y-2">
                        <Label>Discount Amount</Label>
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          className="h-9"
                          value={product.discount_amount}
                          onChange={(e) => updateProduct(index, 'discount_amount', e.target.value)}
                        />
                      </div>
                    )}

                    {product.scheme_type === 'percentage_discount' && (
                      <div className="space-y-2">
                        <Label>Discount %</Label>
                        <Input
                          type="number"
                          min="0"
                          max="100"
                          className="h-9"
                          value={product.discount_percentage}
                          onChange={(e) => updateProduct(index, 'discount_percentage', e.target.value)}
                        />
                      </div>
                    )}

                    {product.scheme_type === 'buy_x_get_y' && (
                      <>
                        <div className="space-y-2">
                          <Label>Min Purchase Qty</Label>
                          <Input
                            type="number"
                            min="0"
                            className="h-9"
                            value={product.minimum_purchase_qty}
                            onChange={(e) => updateProduct(index, 'minimum_purchase_qty', e.target.value)}
                          />
                        </div>
                        <div className="space-y-2">
                          <Label>FOC Product</Label>
                          <ProductSearchCombobox
                            value={product.foc_product_id}
                            onValueChange={(value, selected) => {
                              updateProduct(index, 'foc_product_id', value)
                              updateProduct(index, 'foc_product_name', selected?.name ?? '')
                            }}
                            placeholder="Search the free product..."
                          />
                        </div>
                        <div className="space-y-2">
                          <Label>FOC Qty</Label>
                          <Input
                            type="number"
                            min="0"
                            className="h-9"
                            value={product.foc_qty}
                            onChange={(e) => updateProduct(index, 'foc_qty', e.target.value)}
                          />
                        </div>
                      </>
                    )}

                    <div className="space-y-2">
                      <Label>Priority</Label>
                      <Input
                        type="number"
                        min="0"
                        className="h-9"
                        value={product.priority}
                        onChange={(e) => updateProduct(index, 'priority', Number(e.target.value))}
                      />
                    </div>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </form>
    </div>
  )
}
