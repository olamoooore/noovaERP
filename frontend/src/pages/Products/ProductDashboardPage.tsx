import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { StatCard } from '@/components/ui/stat-card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency, resolveImageUrl } from '@/lib/utils'
import api from '@/lib/api'
import type { Product } from '@/types/product'

interface ProductsResponse {
  data: Product[]
  total?: number
}

const AVAILABLE_BADGE =
  'border-transparent bg-emerald-100 font-normal text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
const OUT_OF_STOCK_BADGE =
  'border-transparent bg-red-100 font-normal text-red-700 dark:bg-red-950/60 dark:text-red-300'
const INACTIVE_BADGE = 'border-transparent bg-muted font-normal text-muted-foreground'

/** Services and untracked goods are always sellable; tracked goods need stock. */
const isAvailable = (product: Product) =>
  product.type === 'service' || !product.track_inventory || product.stock_quantity > 0

export function ProductDashboardPage() {
  const [search, setSearch] = useState('')

  const { data, isLoading, isError, refetch } = useQuery<ProductsResponse>({
    queryKey: ['products', 'dashboard'],
    queryFn: async () => {
      const response = await api.get('/products', {
        params: { per_page: 1000, sort_by: 'updated_at', sort_dir: 'desc' },
      })
      return response.data
    },
  })

  const products = useMemo(() => data?.data ?? [], [data])
  const metrics = useMemo(() => {
    const stocked = products.filter((product) => product.type === 'product' && product.track_inventory)
    const lowStock = stocked.filter((product) => {
      const threshold = product.reorder_level ?? product.min_stock_threshold ?? 0
      return product.stock_quantity > 0 && product.stock_quantity <= threshold
    })

    return {
      total: data?.total ?? products.length,
      active: products.filter((product) => product.is_active).length,
      available: products.filter((product) => product.is_active && isAvailable(product)).length,
      lowStock,
      outOfStock: stocked.filter((product) => product.stock_quantity <= 0),
      online: products.filter((product) => product.show_on_website).length,
      categories: new Set(products.map((product) => product.category_id).filter(Boolean)).size,
      inventoryValue: stocked.reduce(
        (total, product) => total + Number(product.cost || 0) * Number(product.stock_quantity || 0),
        0
      ),
    }
  }, [data?.total, products])

  const visibleProducts = useMemo(() => {
    const term = search.trim().toLowerCase()
    return products
      .filter((product) =>
        !term || [product.name, product.sku, product.barcode, product.category?.name]
          .some((value) => value?.toLowerCase().includes(term))
      )
      .slice(0, 10)
  }, [products, search])

  const attention = [...metrics.outOfStock, ...metrics.lowStock]

  const stockCell = (product: Product) => {
    if (product.type === 'service' || !product.track_inventory) {
      return <span className="text-muted-foreground">Not tracked</span>
    }
    const quantity = Number(product.stock_quantity || 0)
    const threshold = product.reorder_level ?? product.min_stock_threshold ?? 0
    if (quantity <= 0) {
      return <span className="font-medium tabular-nums text-red-600 dark:text-red-400">{quantity}</span>
    }
    if (quantity <= threshold) {
      return <span className="font-medium tabular-nums text-amber-600 dark:text-amber-400">{quantity}</span>
    }
    return <span className="tabular-nums">{quantity}</span>
  }

  const statusBadge = (product: Product) => {
    if (!product.is_active) return <Badge variant="outline" className={INACTIVE_BADGE}>Inactive</Badge>
    if (isAvailable(product)) return <Badge variant="outline" className={AVAILABLE_BADGE}>Available</Badge>
    return <Badge variant="outline" className={OUT_OF_STOCK_BADGE}>Out of stock</Badge>
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Product Dashboard</h1>
          <p className="mt-1 text-muted-foreground">
            Maintain a central catalog and monitor the availability of every offering.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton variant="outline" icon="solar:box-bold-duotone" asChild>
            <Link to="/products">View Catalog</Link>
          </HeaderActionButton>
          <HeaderActionButton icon="solar:add-circle-bold-duotone" asChild>
            <Link to="/products/new">Add Product</Link>
          </HeaderActionButton>
        </div>
      </div>

      {/* Primary metrics */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Total offerings"
          value={isLoading ? '—' : metrics.total}
          hint="Products and services"
          icon="solar:box-bold-duotone"
        />
        <StatCard
          label="Available"
          value={isLoading ? '—' : metrics.available}
          hint="Ready to offer"
          icon="solar:check-circle-bold-duotone"
          tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
        />
        <StatCard
          label="Low stock"
          value={isLoading ? '—' : metrics.lowStock.length}
          hint="Needs attention"
          icon="solar:danger-triangle-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
        />
        <StatCard
          label="Out of stock"
          value={isLoading ? '—' : metrics.outOfStock.length}
          hint="Unavailable items"
          icon="solar:close-circle-bold-duotone"
          tone="bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400"
        />
      </div>

      {/* Secondary metrics — one bordered strip instead of four loose cards */}
      <Card className="overflow-hidden rounded-xl">
        <div className="grid grid-cols-2 gap-px bg-border sm:grid-cols-4">
          {[
            { label: 'Active catalog', value: metrics.active, icon: 'solar:box-bold-duotone' },
            { label: 'Published online', value: metrics.online, icon: 'solar:globus-bold-duotone' },
            { label: 'Categories represented', value: metrics.categories, icon: 'solar:layers-minimalistic-bold-duotone' },
            { label: 'Inventory value', value: formatCurrency(metrics.inventoryValue), icon: 'solar:wallet-money-bold-duotone' },
          ].map((tile) => (
            <div key={tile.label} className="flex items-center justify-between gap-3 bg-card px-5 py-4">
              <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{tile.label}</p>
                <p className="mt-1 text-xl font-semibold tabular-nums">{isLoading ? '—' : tile.value}</p>
              </div>
              <Icon icon={tile.icon} className="h-4 w-4 shrink-0 text-muted-foreground/60" />
            </div>
          ))}
        </div>
      </Card>

      <div className="grid gap-6 xl:grid-cols-3">
        {/* Catalog */}
        <Card className="overflow-hidden rounded-xl xl:col-span-2">
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b">
            <div>
              <CardTitle className="text-base">Catalog overview</CardTitle>
              <p className="mt-1 text-sm text-muted-foreground">Recently updated goods and services</p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative w-full sm:w-64">
                <Icon
                  icon="solar:magnifer-bold-duotone"
                  className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Search catalog"
                  aria-label="Search catalog"
                  className="h-8 rounded-full pl-9 pr-3 text-xs"
                />
              </div>
              <HeaderActionButton variant="outline" icon="solar:arrow-right-bold-duotone" asChild>
                <Link to="/products">View all</Link>
              </HeaderActionButton>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {isError ? (
              <div className="py-12 text-center">
                <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto h-8 w-8 text-red-500" />
                <p className="mt-2 text-sm text-muted-foreground">Unable to load the product catalog.</p>
                <HeaderActionButton variant="outline" className="mt-3" onClick={() => refetch()}>
                  Try again
                </HeaderActionButton>
              </div>
            ) : isLoading ? (
              <div className="py-12 text-center text-muted-foreground">
                <Icon icon="eos-icons:loading" className="mx-auto h-6 w-6 animate-spin" />
                <p className="mt-2 text-sm">Loading catalog…</p>
              </div>
            ) : visibleProducts.length === 0 ? (
              <div className="py-12 text-center text-muted-foreground">
                <Icon icon="solar:box-bold-duotone" className="mx-auto h-8 w-8 opacity-40" />
                <p className="mt-2 text-sm">No products found.</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Offering</TableHead>
                    <TableHead>Category</TableHead>
                    <TableHead className="text-right">Stock</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {visibleProducts.map((product) => (
                    <TableRow key={product.id} className="hover:bg-muted/50">
                      <TableCell>
                        <Link
                          to={`/products/${product.id}`}
                          className="flex items-center gap-3 font-medium hover:underline"
                        >
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                            {product.image ? (
                              <img src={resolveImageUrl(product.image)} alt="" className="h-full w-full object-cover" />
                            ) : (
                              <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                            )}
                          </div>
                          <div className="min-w-0">
                            <div className="truncate">{product.name}</div>
                            <div className="text-xs font-normal text-muted-foreground">{product.sku}</div>
                          </div>
                        </Link>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {product.category?.name ?? 'Uncategorized'}
                      </TableCell>
                      <TableCell className="text-right text-sm">{stockCell(product)}</TableCell>
                      <TableCell>{statusBadge(product)}</TableCell>
                      <TableCell className="text-right text-sm tabular-nums">
                        {formatCurrency(Number(product.price || 0))}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>

        {/* Stock attention */}
        <Card className="overflow-hidden rounded-xl">
          <CardHeader className="border-b">
            <div className="flex items-center justify-between gap-3">
              <CardTitle className="text-base">Stock attention</CardTitle>
              {attention.length > 0 && (
                <Badge variant="outline" className={OUT_OF_STOCK_BADGE}>
                  {attention.length}
                </Badge>
              )}
            </div>
            <p className="mt-1 text-sm text-muted-foreground">Items at or below their reorder level</p>
          </CardHeader>
          <CardContent className="p-2">
            {attention.slice(0, 6).map((product) => (
              <Link
                key={product.id}
                to={`/products/${product.id}`}
                className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-muted/50"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{product.name}</p>
                  <p className="text-xs text-muted-foreground">{product.sku}</p>
                </div>
                <Badge
                  variant="outline"
                  className={Number(product.stock_quantity) <= 0 ? OUT_OF_STOCK_BADGE : 'border-transparent bg-amber-100 font-normal text-amber-700 dark:bg-amber-950/60 dark:text-amber-300'}
                >
                  {product.stock_quantity} left
                </Badge>
              </Link>
            ))}
            {!isLoading && attention.length === 0 && (
              <div className="flex flex-col items-center justify-center gap-1 px-4 py-12 text-center">
                <Icon icon="solar:check-circle-bold-duotone" className="h-10 w-10 text-emerald-500/60" />
                <p className="text-sm font-medium">Stock levels look healthy</p>
                <p className="text-xs text-muted-foreground">No items currently need attention.</p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
