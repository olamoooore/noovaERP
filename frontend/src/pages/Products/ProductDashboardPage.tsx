import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'
import type { Product } from '@/types/product'

interface ProductsResponse {
  data: Product[]
  total?: number
}

const imageUrl = (path?: string | null) => {
  if (!path) return ''
  if (/^(https?:|data:|blob:)/.test(path) || path.startsWith('/storage/')) return path
  return `/storage/${path.replace(/^\//, '')}`
}

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

  const products = data?.data ?? []
  const metrics = useMemo(() => {
    const stocked = products.filter((product) => product.type === 'product' && product.track_inventory)
    const lowStock = stocked.filter((product) => {
      const threshold = product.reorder_level ?? product.min_stock_threshold ?? 0
      return product.stock_quantity > 0 && product.stock_quantity <= threshold
    })

    return {
      total: data?.total ?? products.length,
      active: products.filter((product) => product.is_active).length,
      available: products.filter(
        (product) => product.is_active && (product.type === 'service' || !product.track_inventory || product.stock_quantity > 0)
      ).length,
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

  const statCards = [
    { label: 'Total offerings', value: metrics.total, hint: 'Products and services', icon: 'solar:box-bold-duotone', color: 'text-blue-600 bg-blue-50 dark:bg-blue-950/40' },
    { label: 'Available', value: metrics.available, hint: 'Ready to offer', icon: 'solar:check-circle-bold-duotone', color: 'text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40' },
    { label: 'Low stock', value: metrics.lowStock.length, hint: 'Needs attention', icon: 'solar:danger-triangle-bold-duotone', color: 'text-amber-600 bg-amber-50 dark:bg-amber-950/40' },
    { label: 'Out of stock', value: metrics.outOfStock.length, hint: 'Unavailable items', icon: 'solar:close-circle-bold-duotone', color: 'text-red-600 bg-red-50 dark:bg-red-950/40' },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-3xl font-bold">Product Dashboard</h1>
          <p className="mt-1 text-muted-foreground">
            Maintain a central catalog and monitor the availability of every offering.
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link to="/products">View Catalog</Link>
          </Button>
          <Button asChild>
            <Link to="/products/new">
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Product
            </Link>
          </Button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {statCards.map((stat) => (
          <Card key={stat.label}>
            <CardContent className="flex items-center justify-between p-5">
              <div>
                <p className="text-sm text-muted-foreground">{stat.label}</p>
                <p className="mt-1 text-3xl font-bold">{isLoading ? '—' : stat.value}</p>
                <p className="mt-1 text-xs text-muted-foreground">{stat.hint}</p>
              </div>
              <div className={`rounded-xl p-3 ${stat.color}`}>
                <Icon icon={stat.icon} className="h-6 w-6" />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card><CardContent className="p-5"><p className="text-sm text-muted-foreground">Active catalog</p><p className="mt-2 text-2xl font-semibold">{metrics.active}</p></CardContent></Card>
        <Card><CardContent className="p-5"><p className="text-sm text-muted-foreground">Published online</p><p className="mt-2 text-2xl font-semibold">{metrics.online}</p></CardContent></Card>
        <Card><CardContent className="p-5"><p className="text-sm text-muted-foreground">Categories represented</p><p className="mt-2 text-2xl font-semibold">{metrics.categories}</p></CardContent></Card>
        <Card><CardContent className="p-5"><p className="text-sm text-muted-foreground">Inventory value</p><p className="mt-2 text-2xl font-semibold">{formatCurrency(metrics.inventoryValue)}</p></CardContent></Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <CardTitle>Catalog overview</CardTitle>
              <p className="mt-1 text-sm text-muted-foreground">Recently updated goods and services</p>
            </div>
            <div className="relative w-full sm:w-72">
              <Icon icon="solar:magnifer-linear" className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search catalog" className="pl-9" />
            </div>
          </CardHeader>
          <CardContent>
            {isError ? (
              <div className="py-10 text-center">
                <p className="text-sm text-muted-foreground">Unable to load the product catalog.</p>
                <Button variant="outline" className="mt-3" onClick={() => refetch()}>Try again</Button>
              </div>
            ) : (
              <Table>
                <TableHeader><TableRow><TableHead>Offering</TableHead><TableHead>Category</TableHead><TableHead>Stock</TableHead><TableHead>Status</TableHead><TableHead className="text-right">Price</TableHead></TableRow></TableHeader>
                <TableBody>
                  {!isLoading && visibleProducts.length === 0 && <TableRow><TableCell colSpan={5} className="h-24 text-center text-muted-foreground">No products found.</TableCell></TableRow>}
                  {visibleProducts.map((product) => {
                    const available = product.type === 'service' || !product.track_inventory || product.stock_quantity > 0
                    return (
                      <TableRow key={product.id}>
                        <TableCell><Link to={`/products/${product.id}`} className="flex items-center gap-3 font-medium hover:underline"><div className="flex h-9 w-9 items-center justify-center overflow-hidden rounded-md bg-muted">{product.image ? <img src={imageUrl(product.image)} alt="" className="h-full w-full object-cover" /> : <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-muted-foreground" />}</div><div><div>{product.name}</div><div className="text-xs font-normal text-muted-foreground">{product.sku}</div></div></Link></TableCell>
                        <TableCell>{product.category?.name ?? 'Uncategorized'}</TableCell>
                        <TableCell>{product.type === 'service' || !product.track_inventory ? 'Not tracked' : product.stock_quantity}</TableCell>
                        <TableCell><Badge variant={product.is_active && available ? 'default' : 'secondary'}>{!product.is_active ? 'Inactive' : available ? 'Available' : 'Out of stock'}</Badge></TableCell>
                        <TableCell className="text-right">{formatCurrency(Number(product.price || 0))}</TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            )}
            <div className="mt-4 flex justify-end"><Button variant="ghost" asChild><Link to="/products">View all products <Icon icon="solar:arrow-right-linear" className="ml-2 h-4 w-4" /></Link></Button></div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Stock attention</CardTitle><p className="text-sm text-muted-foreground">Items at or below their reorder level</p></CardHeader>
          <CardContent className="space-y-3">
            {[...metrics.outOfStock, ...metrics.lowStock].slice(0, 6).map((product) => (
              <Link key={product.id} to={`/products/${product.id}`} className="flex items-center justify-between rounded-lg border p-3 transition-colors hover:bg-muted/50">
                <div className="min-w-0"><p className="truncate text-sm font-medium">{product.name}</p><p className="text-xs text-muted-foreground">{product.sku}</p></div>
                <Badge variant={product.stock_quantity <= 0 ? 'destructive' : 'secondary'}>{product.stock_quantity} left</Badge>
              </Link>
            ))}
            {!isLoading && metrics.outOfStock.length + metrics.lowStock.length === 0 && (
              <div className="py-10 text-center"><Icon icon="solar:check-circle-bold-duotone" className="mx-auto h-10 w-10 text-emerald-500" /><p className="mt-2 font-medium">Stock levels look healthy</p><p className="text-sm text-muted-foreground">No items currently need attention.</p></div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
