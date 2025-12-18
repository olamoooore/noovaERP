import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import api from '@/lib/api'
import { formatCurrency } from '@/lib/utils'

export function ProductViewPage() {
  const { id } = useParams()
  const productId = Number(id)

  const { data: product, isLoading } = useQuery({
    queryKey: ['product', productId],
    enabled: Number.isFinite(productId) && productId > 0,
    queryFn: async () => {
      const res = await api.get(`/products/${productId}`)
      return res.data
    },
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Link to="/products" className="hover:underline">Products</Link>
            <span>/</span>
            <span className="text-foreground">View</span>
          </div>
          <h1 className="text-3xl font-bold mt-1">{isLoading ? 'Loading…' : product?.name || 'Product'}</h1>
        </div>

        <div className="flex gap-2">
          <Button variant="secondary" asChild>
            <Link to="/products">
              <Icon icon="solar:alt-arrow-left-bold" className="mr-2 h-4 w-4" />
              Back
            </Link>
          </Button>
          {product?.id ? (
            <Button asChild>
              <Link to={`/products/${product.id}/edit`}>
                <Icon icon="solar:pen-bold" className="mr-2 h-4 w-4" />
                Edit
              </Link>
            </Button>
          ) : null}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Details</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {isLoading ? (
              <div className="text-sm text-muted-foreground">Loading product details…</div>
            ) : !product ? (
              <div className="text-sm text-muted-foreground">Product not found.</div>
            ) : (
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <div className="text-xs text-muted-foreground">SKU</div>
                  <div className="font-medium">{product.sku || '-'}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Type</div>
                  <div className="font-medium capitalize">{product.type || '-'}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Cost</div>
                  <div className="font-medium">{formatCurrency(product.cost)}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Price</div>
                  <div className="font-medium">{formatCurrency(product.price)}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Tax method</div>
                  <div className="font-medium">{product.tax_included ? 'Inclusive' : 'Exclusive'}</div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Tax</div>
                  <div className="font-medium">
                    {product.tax_mode === 'none'
                      ? 'No tax'
                      : product.tax_mode === 'custom'
                        ? `Custom (${product.tax_rate_override ?? 0}%)`
                        : product.tax?.name
                          ? `${product.tax.name} (${product.tax.rate}%)`
                          : 'Default (from Settings)'}
                  </div>
                </div>
                <div>
                  <div className="text-xs text-muted-foreground">Pre-order</div>
                  <div className="font-medium">
                    {product.is_preorder
                      ? `Enabled${product.preorder_available_at ? ` (available ${String(product.preorder_available_at).slice(0, 10)})` : ''}`
                      : 'Disabled'}
                  </div>
                </div>
                <div className="md:col-span-2">
                  <div className="text-xs text-muted-foreground">Description</div>
                  <div className="text-sm">{product.description || '-'}</div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Media</CardTitle>
          </CardHeader>
          <CardContent>
            {product?.image ? (
              <img src={product.image} alt={product.name} className="w-full h-48 object-cover rounded-md border" />
            ) : (
              <div className="h-48 rounded-md border bg-muted flex items-center justify-center text-muted-foreground">
                <Icon icon="solar:gallery-bold" className="h-10 w-10" />
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
