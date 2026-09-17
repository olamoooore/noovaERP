import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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
import { Checkbox } from '@/components/ui/checkbox'
import { formatCurrency, formatDate } from '@/lib/utils'
import api from '@/lib/api'
import { SalesLineColumnVisibility, listingColumns } from '@/components/sales/DeliveryOrderColumnVisibility'
import { SalesDocumentExportMenu, type SalesExportColumn } from '@/components/sales/SalesDocumentExportMenu'
import { useBulkSelection } from '@/hooks/use-bulk-selection'
import { BulkSelectionNotice } from '@/components/ui/bulk-selection-notice'

const PRODUCT_EXPORT_COLUMNS = [
  { key: 'sku', label: 'SKU', group: 'Product', default: true }, { key: 'name', label: 'Product Name', group: 'Product', default: true },
  { key: 'secondary_name', label: 'Secondary Name', group: 'Product' }, { key: 'short_description', label: 'Short Description', group: 'Product', default: true },
  { key: 'description', label: 'Full Description', group: 'Product', default: true }, { key: 'type', label: 'Type', group: 'Product', default: true },
  { key: 'category_name', label: 'Category', group: 'Classification', default: true }, { key: 'brand_name', label: 'Brand', group: 'Classification' },
  { key: 'barcode', label: 'Barcode', group: 'Product' }, { key: 'manufacturer', label: 'Manufacturer', group: 'Product', default: true }, { key: 'manufacturer_part_number', label: 'Manufacturer Part No', group: 'Product' },
  { key: 'cost', label: 'Cost', group: 'Pricing', default: true }, { key: 'vendor_price', label: 'Vendor Price', group: 'Pricing' }, { key: 'price', label: 'Price', group: 'Pricing', default: true },
  { key: 'wholesale_price', label: 'Wholesale Price', group: 'Pricing' }, { key: 'msrp', label: 'MSRP', group: 'Pricing' }, { key: 'min_price', label: 'Minimum Price', group: 'Pricing' }, { key: 'max_price', label: 'Maximum Price', group: 'Pricing' },
  { key: 'tax_rate', label: 'Tax Rate', group: 'Pricing' }, { key: 'tax_mode', label: 'Tax Mode', group: 'Pricing' }, { key: 'tax_included', label: 'Tax Included', group: 'Pricing' },
  { key: 'stock_quantity', label: 'Stock Quantity', group: 'Inventory', default: true }, { key: 'unit', label: 'Unit', group: 'Inventory' },
  { key: 'track_inventory', label: 'Track Inventory', group: 'Inventory' }, { key: 'tracking_method', label: 'Tracking Method', group: 'Inventory' }, { key: 'reorder_level', label: 'Reorder Level', group: 'Inventory' },
  { key: 'safety_stock', label: 'Safety Stock', group: 'Inventory' }, { key: 'max_stock', label: 'Maximum Stock', group: 'Inventory' }, { key: 'reorder_quantity', label: 'Reorder Quantity', group: 'Inventory' },
  { key: 'weight', label: 'Weight', group: 'Physical' }, { key: 'length', label: 'Length', group: 'Physical' }, { key: 'width', label: 'Width', group: 'Physical' }, { key: 'height', label: 'Height', group: 'Physical' },
  { key: 'is_hazmat', label: 'Hazardous Material', group: 'Handling' }, { key: 'hazmat_class', label: 'Hazmat Class', group: 'Handling' }, { key: 'storage_conditions', label: 'Storage Conditions', group: 'Handling' }, { key: 'is_fragile', label: 'Fragile', group: 'Handling' },
  { key: 'shelf_life_days', label: 'Shelf Life', group: 'Handling' }, { key: 'lot_number', label: 'Lot Number', group: 'Handling' }, { key: 'expiry_date', label: 'Expiry Date', group: 'Handling' },
  { key: 'hs_code', label: 'HS Code', group: 'Compliance' }, { key: 'country_of_origin', label: 'Country of Origin', group: 'Compliance' }, { key: 'warranty_months', label: 'Warranty Months', group: 'Compliance' }, { key: 'warranty_type', label: 'Warranty Type', group: 'Compliance' },
  { key: 'income_account_code', label: 'Income Account', group: 'Accounting' }, { key: 'expense_account_code', label: 'Expense Account', group: 'Accounting' },
  { key: 'status', label: 'Status', group: 'Product', default: true }, { key: 'is_active', label: 'Active', group: 'Product' }, { key: 'is_new_arrival', label: 'New Arrival', group: 'Product' }, { key: 'is_preorder', label: 'Preorder', group: 'Product' }, { key: 'show_on_website', label: 'Show on Website', group: 'Product' },
] as const satisfies readonly SalesExportColumn[]

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

function resolveImageUrl(path: string | null | undefined): string {
  if (!path) return ''
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:') || path.startsWith('blob:')) return path
  if (path.startsWith('/storage/')) return path
  return `/storage/${path.replace(/^\//, '')}`
}

export function ProductsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [search, setSearch] = useState('')
  const [type, setType] = useState<TypeFilter>('all')
  const [status, setStatus] = useState<StatusFilter>('all')
  const [categoryId, setCategoryId] = useState<string>('all')
  const [sortBy, setSortBy] = useState<SortBy>('created_at')
  const [sortDir, setSortDir] = useState<SortDir>('desc')
  const [page, setPage] = useState(1)

  const clearFilters = () => {
    setSearch('')
    setType('all')
    setStatus('all')
    setCategoryId('all')
    setSortBy('created_at')
    setSortDir('desc')
    setPage(1)
  }

  const { data: categories } = useQuery<Category[]>({
    queryKey: ['product-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data.data || [])
    },
  })

  const { data: productsResponse, isLoading: productsLoading } = useQuery({
    queryKey: ['products', search, type, status, categoryId, sortBy, sortDir, page],
    queryFn: async () => {
      const params: Record<string, any> = {
        per_page: 50,
        page,
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
      return response.data
    },
    placeholderData: (prev) => prev,
  })

  const products = productsResponse?.data || []
  const totalPages = productsResponse?.last_page || 1
  const totalProducts = productsResponse?.total || 0
  const productFilters = { search: search.trim() || undefined, type: type === 'all' ? undefined : type, category_id: categoryId === 'all' ? undefined : Number(categoryId), is_active: status === 'active' ? 1 : status === 'inactive' ? 0 : undefined, is_new_arrival: status === 'new_arrival' ? 1 : undefined, is_preorder: status === 'preorder' ? 1 : undefined, out_of_stock: status === 'out_of_stock' ? 1 : undefined, sort_by: sortBy, sort_dir: sortDir }
  const {
    selectedIds, clearSelection, toggleSelect, togglePageSelection,
    selectAllMatching, allPageSelected, allMatchingSelected, isSelectingAll,
  } = useBulkSelection({
    pageIds: products.map((product: any) => product.id),
    scopeKey: JSON.stringify(productFilters),
    fetchAllIds: async () => {
      const response = await api.get('/products', { params: { ...productFilters, ids_only: 1 } })
      return response.data.data
    },
  })
  const selectedIdSet = new Set(selectedIds)

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
  const bulkDeleteMutation = useMutation({
    mutationFn: async (ids: number[]) => {
      const res = await api.post('/products/bulk-delete', { ids })
      return res.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      clearSelection()
    },
  })

  const handleBulkDelete = async () => {
    const count = selectedIds.length
    if (count === 0) return
    const ok = confirm(`Delete ${count} selected product(s)? This cannot be undone.`)
    if (!ok) return
    await bulkDeleteMutation.mutateAsync(selectedIds)
  }

  const someSelected = selectedIds.length > 0
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
          <SalesDocumentExportMenu endpoint="/products/export" filenamePrefix="products" columns={PRODUCT_EXPORT_COLUMNS} filters={productFilters} selectedIds={selectedIds} />
          <Button variant="secondary" asChild>
            <Link to="/products/import">
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

      {someSelected && (
        <div className="flex items-center gap-3 rounded-lg border bg-muted/50 px-4 py-3">
          <Checkbox
            checked={allPageSelected}
            onCheckedChange={togglePageSelection}
          />
          <span className="text-sm font-medium">
            <BulkSelectionNotice pageSize={products.length} allPageSelected={allPageSelected} selectedCount={selectedIds.length} allMatchingSelected={allMatchingSelected} isSelectingAll={isSelectingAll} onSelectAllMatching={selectAllMatching} noun="products" />
          </span>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            onClick={handleBulkDelete}
            disabled={bulkDeleteMutation.isPending}
          >
            <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
            {bulkDeleteMutation.isPending ? 'Deleting...' : `Delete ${selectedIds.length} Selected`}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={clearSelection}
          >
            Clear Selection
          </Button>
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle>All Products {totalProducts > 0 && <span className="text-muted-foreground font-normal text-base">({totalProducts})</span>}</CardTitle>
        </CardHeader>
        <CardContent>
          {viewMode === 'list' ? (
                <SalesLineColumnVisibility tableClass="products-listing-columns" columns={listingColumns(['Select', 'Product', 'Type', 'Category', 'SKU', 'Created Date', 'Cost', 'Price', 'Stock', 'Status', 'Actions'])}>
                <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-10">
                    <Checkbox
                      checked={allPageSelected}
                      onCheckedChange={togglePageSelection}
                      aria-label="Select products on this page"
                    />
                  </TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Category</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Created Date</TableHead>
                  <TableHead>Cost</TableHead>
                  <TableHead>Price</TableHead>
                  <TableHead>Stock</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {products?.map((product: any) => (
                  <TableRow key={product.id} className={`cursor-pointer ${selectedIdSet.has(product.id) ? 'bg-muted/50' : ''}`} onClick={() => navigate(`/products/${product.id}`)}>
                    <TableCell onClick={(event) => event.stopPropagation()}>
                      <Checkbox
                        checked={selectedIdSet.has(product.id)}
                        onCheckedChange={() => toggleSelect(product.id)}
                      />
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-md border bg-muted overflow-hidden flex items-center justify-center">
                          {product.image ? (
                            <img
                              src={resolveImageUrl(product.image)}
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
                    <TableCell>{formatDate(product.created_at)}</TableCell>
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
                    <TableCell className="text-right" onClick={(event) => event.stopPropagation()}>
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
                    <TableCell colSpan={11} className="text-center py-10 text-muted-foreground">
                      {productsLoading ? (
                        <div className="flex items-center justify-center gap-2">
                          <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-primary"></div>
                          Loading products…
                        </div>
                      ) : (
                        'No products found'
                      )}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
                </Table>
                </SalesLineColumnVisibility>
          ) : (
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {products?.map((product: any) => (
                <Card key={product.id} onClick={() => navigate(`/products/${product.id}`)} className={`cursor-pointer overflow-hidden relative group ${selectedIdSet.has(product.id) ? 'ring-2 ring-primary' : ''}`}>
                  <div className="absolute top-2 left-2 z-10" onClick={(event) => event.stopPropagation()}>
                    <Checkbox
                      checked={selectedIdSet.has(product.id)}
                      onCheckedChange={() => toggleSelect(product.id)}
                      className="bg-white/80 backdrop-blur-sm"
                    />
                  </div>
                  <div className="h-40 w-full bg-muted border-b overflow-hidden relative">
                    {product.image ? (
                      <img
                        src={resolveImageUrl(product.image)}
                        alt={product.name}
                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                      />
                    ) : (
                      <div className="h-full w-full flex items-center justify-center text-muted-foreground">
                        <Icon icon="solar:gallery-bold" className="h-10 w-10" />
                      </div>
                    )}

                    <div className="absolute top-2 left-8 flex flex-col gap-1">
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

                    <div className="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity" onClick={(event) => event.stopPropagation()}>
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
                  {productsLoading ? (
                    <div className="flex items-center justify-center gap-2">
                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-primary"></div>
                      Loading products…
                    </div>
                  ) : (
                    'No products found'
                  )}
                </div>
              )}
            </div>
          )}

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between pt-4 border-t mt-4">
              <div className="text-sm text-muted-foreground">
                Showing {((page - 1) * 50) + 1}–{Math.min(page * 50, totalProducts)} of {totalProducts} products
              </div>
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  <Icon icon="solar:arrow-left-linear" className="h-4 w-4 mr-1" />
                  Previous
                </Button>
                <span className="text-sm text-muted-foreground">
                  Page {page} of {totalPages}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                >
                  Next
                  <Icon icon="solar:arrow-right-linear" className="h-4 w-4 ml-1" />
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
