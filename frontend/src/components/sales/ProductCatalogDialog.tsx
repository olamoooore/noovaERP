import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/Pagination'
import api from '@/lib/api'

export type CatalogProduct = {
  id: number
  name?: string
  sku?: string | null
  description?: string | null
  price?: number | string | null
  unit?: string | null
  category?: { name?: string } | string | null
  image_url?: string | null
  type?: string | null
  status?: string | null
  is_active?: boolean
}

const categoryName = (product: CatalogProduct) => {
  if (typeof product.category === 'object') return product.category?.name || 'Uncategorized'
  return product.category || 'Uncategorized'
}

const productStatus = (product: CatalogProduct) => {
  if (product.status) return product.status
  if (typeof product.is_active === 'boolean') return product.is_active ? 'active' : 'inactive'
  return 'active'
}

type ServerPage = {
  data: CatalogProduct[]
  current_page?: number
  last_page?: number
  total?: number
  from?: number | null
  to?: number | null
}

/**
 * Product picker dialog.
 *
 * #301: when no `products` array is passed, the catalogue searches the server
 * itself — one paginated `GET /products` page at a time (debounced search,
 * category/type/status filters), fetched only while the dialog is open — so a
 * sales form no longer has to preload 1,000 products on open. Passing
 * `products` keeps the legacy in-memory mode for callers not yet migrated.
 */
export function ProductCatalogDialog({ products: providedProducts, onAdd }: {
  products?: CatalogProduct[]
  onAdd: (items: Array<{ product: CatalogProduct; quantity: number }>) => void
}) {
  const serverMode = providedProducts === undefined
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [category, setCategory] = useState('all')
  const [productType, setProductType] = useState('all')
  const [status, setStatus] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(50)
  const [quantities, setQuantities] = useState<Record<number, number>>({})
  // Picked products keyed by id, so a pick survives paging away from it.
  const [picked, setPicked] = useState<Record<number, CatalogProduct>>({})

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data: serverCategories = [] } = useQuery<Array<{ id: number; name: string }>>({
    // Own key: other screens cache /product-categories in different shapes.
    queryKey: ['product-catalog-categories'],
    queryFn: async () => {
      const response = await api.get('/product-categories')
      return Array.isArray(response.data) ? response.data : (response.data?.data || [])
    },
    enabled: serverMode && open,
    staleTime: 60_000,
  })

  const { data: serverPage, isFetching: serverLoading } = useQuery<ServerPage>({
    queryKey: ['product-catalog', debouncedSearch, category, productType, status, page, pageSize],
    queryFn: async () => {
      const params: Record<string, string | number | boolean> = { page, per_page: pageSize, sort_by: 'name', sort_dir: 'asc' }
      if (debouncedSearch) params.search = debouncedSearch
      if (category !== 'all') params.category_id = category
      if (productType !== 'all') params.type = productType
      if (status === 'active') params.is_active = true
      if (status === 'inactive') params.is_active = false
      const response = await api.get('/products', { params })
      const body = response.data ?? {}
      return Array.isArray(body) ? { data: body } : { ...body, data: body.data || [] }
    },
    enabled: serverMode && open,
    placeholderData: (previous) => previous,
  })

  const products = useMemo(() => providedProducts ?? [], [providedProducts])

  const categoryOptions = useMemo<Array<{ value: string; label: string }>>(
    () => serverMode
      ? serverCategories.map((item) => ({ value: String(item.id), label: item.name }))
      : Array.from(new Set(products.map(categoryName))).sort((a, b) => a.localeCompare(b)).map((name) => ({ value: name, label: name })),
    [serverMode, serverCategories, products],
  )
  const productTypes = useMemo(
    () => serverMode
      ? ['product', 'service']
      : Array.from(new Set(products.map((product) => product.type || 'product'))).sort((a, b) => a.localeCompare(b)),
    [serverMode, products],
  )
  const statuses = useMemo(
    () => serverMode
      ? ['active', 'inactive']
      : Array.from(new Set(products.map(productStatus))).sort((a, b) => a.localeCompare(b)),
    [serverMode, products],
  )
  const filtered = useMemo(() => {
    if (serverMode) return serverPage?.data ?? []
    const query = search.trim().toLowerCase()
    return products.filter((product) => {
      const searchable = `${product.name || ''} ${product.sku || ''} ${product.description || ''}`.toLowerCase()
      return (category === 'all' || categoryName(product) === category)
        && (productType === 'all' || (product.type || 'product') === productType)
        && (status === 'all' || productStatus(product) === status)
        && (!query || searchable.includes(query))
    })
  }, [serverMode, serverPage, category, productType, products, search, status])
  const matchingCount = serverMode ? (serverPage?.total ?? filtered.length) : filtered.length
  const pageCount = serverMode
    ? Math.max(1, serverPage?.last_page ?? 1)
    : Math.max(1, Math.ceil(filtered.length / pageSize))
  const paginatedProducts = serverMode ? filtered : filtered.slice((page - 1) * pageSize, page * pageSize)
  const rangeFrom = serverMode ? (serverPage?.from ?? (filtered.length ? 1 : 0)) : (page - 1) * pageSize + 1
  const rangeTo = serverMode ? (serverPage?.to ?? filtered.length) : Math.min(page * pageSize, filtered.length)
  const selected = Object.values(picked).filter((product) => (quantities[product.id] || 0) > 0)
  const selectedUnits = selected.reduce((total, product) => total + quantities[product.id], 0)

  const setQuantity = (product: CatalogProduct, value: number) => {
    setPicked((current) => ({ ...current, [product.id]: product }))
    setQuantities((current) => ({ ...current, [product.id]: Math.max(0, value || 0) }))
  }
  const resetAndClose = () => {
    setQuantities({})
    setPicked({})
    setSearch('')
    setCategory('all')
    setProductType('all')
    setStatus('all')
    setPage(1)
    setOpen(false)
  }
  const confirm = () => {
    onAdd(selected.map((product) => ({ product, quantity: quantities[product.id] })))
    resetAndClose()
  }

  useEffect(() => {
    if (!open) return
    const previousOverflow = document.body.style.overflow
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', closeOnEscape)
    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])

  useEffect(() => {
    setPage(1)
  }, [search, debouncedSearch, category, productType, status])

  const renderProductCard = (product: CatalogProduct) => {
    const quantity = quantities[product.id] || 0
    return (
      <article key={product.id} className={`group overflow-hidden rounded-lg border bg-card shadow-sm transition hover:border-primary/50 hover:shadow-md ${quantity > 0 ? 'border-primary ring-1 ring-primary' : ''}`}>
        <div className="relative aspect-[4/3] bg-muted">
          {product.image_url ? (
            <img src={product.image_url} alt={product.name || 'Product'} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full items-center justify-center"><Icon icon="solar:box-minimalistic-bold-duotone" className="h-16 w-16 text-muted-foreground/35" /></div>
          )}
          <span className="absolute left-2 top-2 rounded bg-background/90 px-2 py-1 text-xs font-medium shadow-sm">{categoryName(product)}</span>
        </div>
        <div className="space-y-3 p-4">
          <div className="min-w-0">
            <h3 className="truncate font-semibold" title={product.name || 'Unnamed product'}>{product.name || 'Unnamed product'}</h3>
            <p className="mt-1 text-xs text-muted-foreground">{product.sku || 'No SKU'} · {product.unit || 'Unit'}</p>
            <p className="mt-2 line-clamp-2 min-h-10 text-sm text-muted-foreground">{product.description || 'No description'}</p>
          </div>
          <div className="flex items-center justify-between gap-3 border-t pt-3">
            <span className="font-semibold">{product.price ?? '—'}</span>
            <div className="flex items-center rounded-md border bg-background">
              <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => setQuantity(product, quantity - 1)} disabled={quantity === 0} aria-label={`Remove one ${product.name || 'product'}`}><Icon icon="solar:minus-circle-linear" className="h-4 w-4" /></Button>
              <Input className="h-8 w-14 border-0 px-1 text-center shadow-none focus-visible:ring-0" type="number" min={0} value={quantity || ''} placeholder="0" onChange={(event) => setQuantity(product, Number(event.target.value))} aria-label={`Quantity for ${product.name || 'product'}`} />
              <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => setQuantity(product, quantity + 1)} aria-label={`Add one ${product.name || 'product'}`}><Icon icon="solar:add-circle-linear" className="h-4 w-4" /></Button>
            </div>
          </div>
        </div>
      </article>
    )
  }

  return (
    <>
      <HeaderActionButton type="button" variant="outline" icon="solar:widget-5-bold-duotone" onClick={() => setOpen(true)}>Product Catalog</HeaderActionButton>
      {open && createPortal(
        <div className="fixed inset-0 z-[100] flex h-dvh w-screen flex-col bg-background" role="dialog" aria-modal="true" aria-labelledby="product-catalog-title">
          <header className="flex flex-wrap items-center gap-4 border-b bg-background px-5 py-4 pr-14">
            <div className="mr-auto">
              <h1 id="product-catalog-title" className="text-xl font-semibold">Product Catalog</h1>
              <p className="mt-1 text-sm text-muted-foreground">Choose products and quantities to add to this document.</p>
            </div>
            <Button type="button" variant="ghost" size="sm" className="h-9 w-9 p-0" onClick={() => setOpen(false)} aria-label="Close product catalog">
              <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
            </Button>
          </header>

          <div className="flex min-h-0 flex-1 flex-col md:flex-row">
            <aside className="w-full shrink-0 border-b bg-muted/20 p-4 md:w-64 md:border-b-0 md:border-r">
              <div className="relative">
                <Icon icon="solar:magnifer-linear" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search products..." className="pl-9" autoFocus />
              </div>
              <div className="mt-5">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filters</p>
                <div className="space-y-2">
                  <select value={productType} onChange={(event) => setProductType(event.target.value)} className="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="all">All product types</option>
                    {productTypes.map((type) => <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>)}
                  </select>
                  <select value={status} onChange={(event) => setStatus(event.target.value)} className="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="all">All statuses</option>
                    {statuses.map((itemStatus) => <option key={itemStatus} value={itemStatus}>{itemStatus.replace(/_/g, ' ')}</option>)}
                  </select>
                </div>
              </div>
              <div className="mt-5">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Categories</p>
                <div className="flex gap-2 overflow-x-auto md:flex-col md:overflow-visible">
                  {[{ value: 'all', label: 'All Products' }, ...categoryOptions].map((option) => (
                    <button key={option.value} type="button" onClick={() => setCategory(option.value)} className={`whitespace-nowrap rounded-md px-3 py-2 text-left text-sm transition ${category === option.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}>{option.label}</button>
                  ))}
                </div>
              </div>
            </aside>

            <main className="min-w-0 flex-1 overflow-y-auto bg-muted/10 p-5">
              <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">{serverMode ? `${matchingCount} products` : `${filtered.length} of ${products.length} products`}</p>
                {(search || category !== 'all' || productType !== 'all' || status !== 'all') && <Button type="button" variant="ghost" size="sm" onClick={() => { setSearch(''); setCategory('all'); setProductType('all'); setStatus('all') }}>Clear filters</Button>}
              </div>
              {serverMode && serverLoading && !serverPage ? (
                <div className="flex min-h-80 items-center justify-center text-sm text-muted-foreground">Loading products...</div>
              ) : filtered.length === 0 ? (
                <div className="flex min-h-80 flex-col items-center justify-center rounded-lg border border-dashed bg-background text-center">
                  <Icon icon="solar:box-minimalistic-linear" className="mb-3 h-12 w-12 text-muted-foreground/50" />
                  <p className="font-medium">No products found</p><p className="mt-1 text-sm text-muted-foreground">Try another search or category.</p>
                </div>
              ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">{paginatedProducts.map(renderProductCard)}</div>
              )}
              <Pagination
                page={page}
                totalPages={pageCount}
                onPageChange={setPage}
                from={rangeFrom}
                to={rangeTo}
                total={matchingCount}
                label="products"
                pageSize={pageSize}
                onPageSizeChange={(size) => { setPageSize(size); setPage(1) }}
              />
            </main>
          </div>

          <footer className="flex flex-wrap items-center gap-3 border-t bg-background px-5 py-4">
            <div className="mr-auto"><span className="font-medium">{selected.length} product{selected.length === 1 ? '' : 's'} selected</span><span className="ml-2 text-sm text-muted-foreground">{selectedUnits} total units</span></div>
            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
            <Button type="button" onClick={confirm} disabled={!selected.length}>Add Selected Products</Button>
          </footer>
        </div>,
        document.body,
      )}
    </>
  )
}
