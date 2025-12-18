import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

interface Category {
  id: number
  name: string
  parent_id?: number | null
}

type ViewMode = 'list' | 'grid'
type SortBy = 'name' | 'price' | 'cost' | 'stock_quantity' | 'created_at' | 'updated_at'
type SortDir = 'asc' | 'desc'
type StatusFilter = 'all' | 'active' | 'inactive' | 'new_arrival' | 'preorder' | 'out_of_stock'
type TypeFilter = 'all' | 'product' | 'service'

export function ProductsPage() {
  const queryClient = useQueryClient()
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [search, setSearch] = useState('')
  const [type, setType] = useState<TypeFilter>('all')
  const [status, setStatus] = useState<StatusFilter>('all')
  const [categoryId, setCategoryId] = useState<string>('all')
  const [sortBy, setSortBy] = useState<SortBy>('name')
  const [sortDir, setSortDir] = useState<SortDir>('asc')

  const clearFilters = () => {
    setSearch('')
    setType('all')
    setStatus('all')
    setCategoryId('all')
    setSortBy('name')
    setSortDir('asc')
  }

  const { data: categories } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: products } = useQuery({
    queryKey: ['products', search, type, status, categoryId, sortBy, sortDir],
    queryFn: async () => {
      const params: Record<string, any> = {
        per_page: 50,
        sort_by: sortBy,
        sort_dir: sortDir,
      }
      if (search.trim()) params.search = search.trim()
      if (type !== 'all') params.type = type
      if (status !== 'all') {
        if (status === 'active') params.is_active = true
        else if (status === 'inactive') params.is_active = false
        else if (status === 'new_arrival') params.is_new_arrival = true
        else if (status === 'preorder') params.is_preorder = true
        else if (status === 'out_of_stock') params.out_of_stock = true
      }
      if (categoryId !== 'all') params.category_id = parseInt(categoryId)

      const response = await api.get('/products', { params })
      return response.data.data
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      const res = await api.delete(`/products/${id}`)
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
    },
  })

  const handleDelete = async (product: any) => {
    const ok = confirm(`Delete “${product?.name || 'product'}”? This cannot be undone.`)
    if (!ok) return
    await deleteMutation.mutateAsync(product.id)
  }

  const sortedCategories = useMemo(() => {
    const list = [...(categories || [])]
    list.sort((a, b) => {
      if (!!a.parent_id !== !!b.parent_id) return a.parent_id ? 1 : -1
      return a.name.localeCompare(b.name)
    })
    return list
  }, [categories])

  const categoryLabelForProduct = (product: any): string => {
    const categoryId = product?.category_id ?? product?.category?.id
    const category = product?.category ?? (categories || []).find((c) => c.id === categoryId)
    if (!category) return '-'

    const parentId = category.parent_id ?? (categories || []).find((c) => c.id === category.id)?.parent_id
    if (!parentId) return category.name

    const parent = (categories || []).find((c) => c.id === parentId)
    return parent ? `${parent.name} / ${category.name}` : category.name
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Products</h1>
        <div className="flex items-center gap-2">
          <Button variant="secondary" asChild>
            <Link to="/products/new#import">
              <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
              Import
            </Link>
          </Button>
          <Button asChild>
            <Link to="/products/new">
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Add Product
            </Link>
          </Button>
        </div>
      </div>

      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-5 lg:flex-1">
              <div className="space-y-1">
                <div className="text-xs text-muted-foreground">Search</div>
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Name, SKU, barcode…"
                />
              </div>

              <div className="space-y-1">
                <div className="text-xs text-muted-foreground">Type</div>
                <Select value={type} onValueChange={(v) => setType(v as TypeFilter)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="product">Product</SelectItem>
                    <SelectItem value="service">Service</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <div className="text-xs text-muted-foreground">Status</div>
                <Select value={status} onValueChange={(v) => setStatus(v as StatusFilter)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                    <SelectItem value="new_arrival">New Arrival</SelectItem>
                    <SelectItem value="preorder">Pre-order</SelectItem>
                    <SelectItem value="out_of_stock">Out of Stock</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <div className="text-xs text-muted-foreground">Category</div>
                <Select value={categoryId} onValueChange={(v) => setCategoryId(v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All</SelectItem>
                    {sortedCategories.map((cat) => (
                      <SelectItem key={cat.id} value={String(cat.id)}>
                        {cat.parent_id ? `— ${cat.name}` : cat.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <div className="text-xs text-muted-foreground">Sort</div>
                <div className="flex gap-2">
                  <Select value={sortBy} onValueChange={(v) => setSortBy(v as SortBy)}>
                    <SelectTrigger className="flex-1">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="name">Name</SelectItem>
                      <SelectItem value="price">Price</SelectItem>
                      <SelectItem value="cost">Cost</SelectItem>
                      <SelectItem value="stock_quantity">Stock</SelectItem>
                      <SelectItem value="created_at">Created</SelectItem>
                      <SelectItem value="updated_at">Updated</SelectItem>
                    </SelectContent>
                  </Select>
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))}
                    title="Toggle sort direction"
                  >
                    <Icon
                      icon={
                        sortDir === 'asc'
                          ? 'solar:sort-from-top-to-bottom-bold'
                          : 'solar:sort-from-bottom-to-top-bold'
                      }
                      className="h-4 w-4"
                    />
                  </Button>
                </div>
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Button type="button" variant="ghost" onClick={clearFilters}>
                Clear
              </Button>

              <div className="flex rounded-md border overflow-hidden">
                <Button
                  type="button"
                  variant={viewMode === 'list' ? 'default' : 'ghost'}
                  onClick={() => setViewMode('list')}
                >
                  <Icon icon="solar:list-bold" className="h-4 w-4" />
                </Button>
                <Button
                  type="button"
                  variant={viewMode === 'grid' ? 'default' : 'ghost'}
                  onClick={() => setViewMode('grid')}
                >
                  <Icon icon="solar:widget-2-bold" className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>All Products</CardTitle>
        </CardHeader>
        <CardContent>
          {viewMode === 'list' ? (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Product</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead>Price</TableHead>
                  <TableHead>Stock</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {products?.map((product: any) => (
                  <TableRow key={product.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-md border bg-muted overflow-hidden flex items-center justify-center">
                          {product.image ? (
                            <img
                              src={product.image}
                              alt={product.name}
                              className="h-full w-full object-cover"
                            />
                          ) : (
                            <Icon
                              icon="solar:gallery-bold"
                              className="h-5 w-5 text-muted-foreground"
                            />
                          )}
                        </div>
                        <div className="min-w-0">
                          <div className="font-medium truncate">{product.name}</div>
                          <div className="flex flex-wrap gap-1 mt-0.5">
                            {product.secondary_name && (
                              <span className="text-xs text-muted-foreground truncate mr-1">
                                {product.secondary_name}
                              </span>
                            )}
                            {product.is_new_arrival && (
                              <span className="inline-flex items-center rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-800">
                                New
                              </span>
                            )}
                            {product.is_preorder && (
                              <span className="inline-flex items-center rounded-full bg-purple-100 px-1.5 py-0.5 text-[10px] font-medium text-purple-800">
                                Pre-order
                              </span>
                            )}
                            {(product.stock_quantity !== null && product.stock_quantity <= 0) && (
                              <span className="inline-flex items-center rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-800">
                                Out of Stock
                              </span>
                            )}
                          </div>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="capitalize">{product.type || '-'}</TableCell>
                    <TableCell>{categoryLabelForProduct(product)}</TableCell>
                    <TableCell>{product.sku}</TableCell>
                    <TableCell>{formatCurrency(product.cost)}</TableCell>
                    <TableCell>{formatCurrency(product.price)}</TableCell>
                    <TableCell>{product.stock_quantity ?? '-'}</TableCell>
                    <TableCell>
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                          product.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {product.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="inline-flex items-center gap-1">
                        <Button asChild variant="ghost" size="icon" title="Edit">
                          <Link to={`/products/${product.id}/edit`}>
                            <Icon icon="solar:pen-bold" className="h-4 w-4" />
                          </Link>
                        </Button>
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          title="Delete"
                          onClick={() => handleDelete(product)}
                          disabled={deleteMutation.isPending}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
                {(!products || products.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={9} className="text-center py-10 text-muted-foreground">
                      No products found
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {products?.map((product: any) => (
                <Card key={product.id} className="overflow-hidden relative group">
                  <div className="h-40 w-full bg-muted border-b overflow-hidden relative">
                    {product.image ? (
                      <img
                        src={product.image}
                        alt={product.name}
                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                      />
                    ) : (
                      <div className="h-full w-full flex items-center justify-center text-muted-foreground">
                        <Icon icon="solar:gallery-bold" className="h-10 w-10" />
                      </div>
                    )}

                    <div className="absolute top-2 left-2 flex flex-col gap-1">
                      {product.is_new_arrival && (
                        <span className="inline-flex items-center rounded-full bg-blue-500 px-2 py-0.5 text-xs font-medium text-white shadow-sm">
                          New
                        </span>
                      )}
                      {product.is_preorder && (
                        <span className="inline-flex items-center rounded-full bg-purple-500 px-2 py-0.5 text-xs font-medium text-white shadow-sm">
                          Pre-order
                        </span>
                      )}
                      {(product.stock_quantity !== null && product.stock_quantity <= 0) && (
                        <span className="inline-flex items-center rounded-full bg-red-500 px-2 py-0.5 text-xs font-medium text-white shadow-sm">
                          Out of Stock
                        </span>
                      )}
                    </div>

                    <div className="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                      <Button asChild variant="secondary" size="icon" title="Edit" className="h-8 w-8 shadow-sm">
                        <Link to={`/products/${product.id}/edit`}>
                          <Icon icon="solar:pen-bold" className="h-4 w-4" />
                        </Link>
                      </Button>
                      <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        title="Delete"
                        className="h-8 w-8 shadow-sm"
                        onClick={() => handleDelete(product)}
                        disabled={deleteMutation.isPending}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                      </Button>
                    </div>
                  </div>
                  <CardContent className="pt-4 space-y-2">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="font-semibold truncate">{product.name}</div>
                        {product.secondary_name ? (
                          <div className="text-xs text-muted-foreground truncate">
                            {product.secondary_name}
                          </div>
                        ) : null}
                      </div>
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                          product.is_active
                            ? 'bg-green-100 text-green-800'
                            : 'bg-gray-100 text-gray-800'
                        }`}
                      >
                        {product.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </div>

                    <div className="text-sm text-muted-foreground">
                      {categoryLabelForProduct(product)}
                    </div>

                    <div className="flex items-center justify-between">
                      <div className="text-sm">
                        <div className="text-muted-foreground text-xs">Price</div>
                        <div className="font-semibold">{formatCurrency(product.price)}</div>
                      </div>
                      <div className="text-sm text-right">
                        <div className="text-muted-foreground text-xs">Stock</div>
                        <div className="font-semibold">{product.stock_quantity ?? '-'}</div>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
              {(!products || products.length === 0) && (
                <div className="col-span-full text-center py-10 text-muted-foreground">
                  No products found
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
