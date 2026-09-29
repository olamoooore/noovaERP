import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Pagination } from '@/components/ui/Pagination'

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

export function ProductCatalogDialog({ products = [], onAdd }: {
  products?: CatalogProduct[]
  onAdd: (items: Array<{ product: CatalogProduct; quantity: number }>) => void
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [category, setCategory] = useState('all')
  const [productType, setProductType] = useState('all')
  const [status, setStatus] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(50)
  const [quantities, setQuantities] = useState<Record<number, number>>({})

  const categories = useMemo(
    () => Array.from(new Set(products.map(categoryName))).sort((a, b) => a.localeCompare(b)),
    [products],
  )
  const productTypes = useMemo(
    () => Array.from(new Set(products.map((product) => product.type || 'product'))).sort((a, b) => a.localeCompare(b)),
    [products],
  )
  const statuses = useMemo(
    () => Array.from(new Set(products.map(productStatus))).sort((a, b) => a.localeCompare(b)),
    [products],
  )
  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase()
    return products.filter((product) => {
      const searchable = `${product.name || ''} ${product.sku || ''} ${product.description || ''}`.toLowerCase()
      return (category === 'all' || categoryName(product) === category)
        && (productType === 'all' || (product.type || 'product') === productType)
        && (status === 'all' || productStatus(product) === status)
        && (!query || searchable.includes(query))
    })
  }, [category, productType, products, search, status])
  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
  const paginatedProducts = filtered.slice((page - 1) * pageSize, page * pageSize)
  const selected = products.filter((product) => (quantities[product.id] || 0) > 0)
  const selectedUnits = selected.reduce((total, product) => total + quantities[product.id], 0)

  const setQuantity = (productId: number, value: number) => {
    setQuantities((current) => ({ ...current, [productId]: Math.max(0, value || 0) }))
  }
  const resetAndClose = () => {
    setQuantities({})
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
  }, [search, category, productType, status])

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
              <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => setQuantity(product.id, quantity - 1)} disabled={quantity === 0} aria-label={`Remove one ${product.name || 'product'}`}><Icon icon="solar:minus-circle-linear" className="h-4 w-4" /></Button>
              <Input className="h-8 w-14 border-0 px-1 text-center shadow-none focus-visible:ring-0" type="number" min={0} value={quantity || ''} placeholder="0" onChange={(event) => setQuantity(product.id, Number(event.target.value))} aria-label={`Quantity for ${product.name || 'product'}`} />
              <Button type="button" variant="ghost" size="sm" className="h-8 w-8 p-0" onClick={() => setQuantity(product.id, quantity + 1)} aria-label={`Add one ${product.name || 'product'}`}><Icon icon="solar:add-circle-linear" className="h-4 w-4" /></Button>
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
                  {['all', ...categories].map((name) => (
                    <button key={name} type="button" onClick={() => setCategory(name)} className={`whitespace-nowrap rounded-md px-3 py-2 text-left text-sm transition ${category === name ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'}`}>{name === 'all' ? 'All Products' : name}</button>
                  ))}
                </div>
              </div>
            </aside>

            <main className="min-w-0 flex-1 overflow-y-auto bg-muted/10 p-5">
              <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-muted-foreground">{filtered.length} of {products.length} products</p>
                {(search || category !== 'all' || productType !== 'all' || status !== 'all') && <Button type="button" variant="ghost" size="sm" onClick={() => { setSearch(''); setCategory('all'); setProductType('all'); setStatus('all') }}>Clear filters</Button>}
              </div>
              {filtered.length === 0 ? (
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
                from={(page - 1) * pageSize + 1}
                to={Math.min(page * pageSize, filtered.length)}
                total={filtered.length}
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
