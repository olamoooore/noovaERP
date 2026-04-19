import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import api from '@/lib/api'
import { formatCurrency } from '@/lib/utils'

interface StockLevel {
  id: number
  warehouse_id: number
  quantity: number
  reserved_quantity: number
  min_quantity: number
  warehouse: {
    id: number
    name: string
  }
}

function resolveImageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) return path
  if (path.startsWith('/storage/')) return path
  return `/storage/${path.replace(/^\//, '')}`
}

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
                  <div className="text-xs text-muted-foreground">Tax</div>
                  <div className="font-medium">
                    {product.tax_mode === 'none'
                      ? 'No tax'
                      : product.tax_mode === 'custom'
                        ? `Custom (${product.tax_rate_override ?? 0}%) - ${product.tax_included ? 'Inclusive' : 'Exclusive'}`
                        : product.tax?.name
                          ? `${product.tax.name} (${product.tax.rate}%) - ${product.tax.is_inclusive ? 'Inclusive' : 'Exclusive'}`
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
                {product.short_description && (
                  <div className="md:col-span-2">
                    <div className="text-xs text-muted-foreground">Short Description</div>
                    <div
                      className="text-sm [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-1 [&_strong]:font-semibold [&_em]:italic"
                      dangerouslySetInnerHTML={{ __html: product.short_description }}
                    />
                  </div>
                )}
                <div className="md:col-span-2">
                  <div className="text-xs text-muted-foreground">Description</div>
                  {product.description
                    ? <div
                        className="text-sm [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-1 [&_strong]:font-semibold [&_em]:italic"
                        dangerouslySetInnerHTML={{ __html: product.description }}
                      />
                    : <div className="text-sm">-</div>
                  }
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
              <img src={resolveImageUrl(product.image)} alt={product.name} className="w-full h-48 object-cover rounded-md border" />
            ) : (
              <div className="h-48 rounded-md border bg-muted flex items-center justify-center text-muted-foreground">
                <Icon icon="solar:gallery-bold" className="h-10 w-10" />
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Stock Levels by Warehouse */}
      {product && product.type === 'product' && (
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>Stock Levels by Warehouse</CardTitle>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" asChild>
                  <Link to="/inventory/operations">
                    <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                    Stock In
                  </Link>
                </Button>
                <Button variant="outline" size="sm" asChild>
                  <Link to="/inventory/operations">
                    <Icon icon="solar:minus-circle-bold" className="mr-2 h-4 w-4" />
                    Stock Out
                  </Link>
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className="mb-4 p-4 bg-muted rounded-lg">
              <div className="grid grid-cols-3 gap-4 text-center">
                <div>
                  <div className="text-2xl font-bold">{product.stock_quantity ?? 0}</div>
                  <div className="text-xs text-muted-foreground">Total Stock</div>
                </div>
                <div>
                  <div className="text-2xl font-bold">{product.reorder_level ?? 0}</div>
                  <div className="text-xs text-muted-foreground">Reorder Level</div>
                </div>
                <div>
                  <div className="text-2xl font-bold">{formatCurrency((product.stock_quantity ?? 0) * (product.cost ?? 0))}</div>
                  <div className="text-xs text-muted-foreground">Stock Value</div>
                </div>
              </div>
            </div>

            {product.stock_levels && product.stock_levels.length > 0 ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Warehouse</TableHead>
                    <TableHead className="text-right">Quantity</TableHead>
                    <TableHead className="text-right">Reserved</TableHead>
                    <TableHead className="text-right">Available</TableHead>
                    <TableHead className="text-right">Min Qty</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {product.stock_levels.map((level: StockLevel) => {
                    const available = level.quantity - (level.reserved_quantity || 0)
                    const isLow = level.quantity <= level.min_quantity && level.quantity > 0
                    const isOut = level.quantity <= 0
                    return (
                      <TableRow key={level.id}>
                        <TableCell className="font-medium">{level.warehouse?.name}</TableCell>
                        <TableCell className="text-right">{level.quantity}</TableCell>
                        <TableCell className="text-right">{level.reserved_quantity || 0}</TableCell>
                        <TableCell className="text-right">{available}</TableCell>
                        <TableCell className="text-right">{level.min_quantity}</TableCell>
                        <TableCell>
                          {isOut ? (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                              Out of Stock
                            </span>
                          ) : isLow ? (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                              Low Stock
                            </span>
                          ) : (
                            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                              In Stock
                            </span>
                          )}
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            ) : (
              <div className="text-center py-8 text-muted-foreground">
                No warehouse stock levels configured. Stock is tracked at product level only.
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
