import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { cn, formatCurrency, resolveImageUrl } from '@/lib/utils'
import api from '@/lib/api'

/** The catalog picker fetches whole product rows; the inline picker maps them. */
export interface CatalogProduct {
  id: number
  name: string
  sku: string
  barcode?: string | null
  short_description?: string | null
  description?: string | null
  stock_quantity?: number
  price?: number | string | null
  selling_price?: number | string | null
  image?: string | null
}

interface ProductCatalogPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Search text typed in the inline picker — retained when the catalog opens. */
  initialSearch?: string
  onSelect: (product: CatalogProduct) => void
  /** #1003: transactional pickers list active products only. */
  activeOnly?: boolean
}

const PAGE_SIZE = 20

const plainText = (value?: string | null) =>
  value ? value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''

/**
 * Issue #1003 — "Show more" catalog selector: the complete, server-paginated
 * result set with brand/category filters, preserving the typed search text.
 */
export function ProductCatalogPickerDialog({
  open,
  onOpenChange,
  initialSearch = '',
  onSelect,
  activeOnly = true,
}: ProductCatalogPickerDialogProps) {
  const [search, setSearch] = useState(initialSearch)
  const [debounced, setDebounced] = useState(initialSearch)
  const [brandId, setBrandId] = useState('all')
  const [categoryId, setCategoryId] = useState('all')
  const [page, setPage] = useState(1)

  // Every open starts from the text typed in the inline picker.
  useEffect(() => {
    if (open) {
      setSearch(initialSearch)
      setDebounced(initialSearch)
      setPage(1)
    }
  }, [open, initialSearch])

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data: brands = [] } = useQuery<any[]>({
    queryKey: ['catalog-brands'],
    enabled: open,
    queryFn: async () => {
      const res = await api.get('/brands', { params: { per_page: 100, is_active: true } })
      return res.data?.data ?? []
    },
  })

  const { data: categories = [] } = useQuery<any[]>({
    queryKey: ['catalog-categories'],
    enabled: open,
    queryFn: async () => {
      const res = await api.get('/product-categories')
      return res.data?.data ?? res.data ?? []
    },
  })

  const { data, isLoading } = useQuery<{ items: CatalogProduct[]; total: number; lastPage: number }>({
    queryKey: ['product-catalog', debounced, brandId, categoryId, page, activeOnly],
    enabled: open,
    queryFn: async () => {
      const params: Record<string, string | number> = { per_page: PAGE_SIZE, page }
      if (debounced) params.search = debounced
      if (brandId !== 'all') params.brand_id = brandId
      if (categoryId !== 'all') params.category_id = categoryId
      if (activeOnly) params.is_active = 'true'

      const res = await api.get('/products', { params })
      const body = res.data ?? {}
      return {
        items: (body.data ?? []) as CatalogProduct[],
        total: Number(body.total ?? 0),
        lastPage: Math.max(1, Number(body.last_page ?? 1)),
      }
    },
  })

  const items = data?.items ?? []
  const total = data?.total ?? 0
  const lastPage = data?.lastPage ?? 1

  const select = (product: CatalogProduct) => {
    onSelect(product)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>Product catalog</DialogTitle>
        </DialogHeader>

        <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_10rem_10rem]">
          <Input
            autoFocus
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search name, short description, SKU, item code, brand, category or metafield..."
          />
          <Select value={brandId} onValueChange={(value) => { setBrandId(value); setPage(1) }}>
            <SelectTrigger><SelectValue placeholder="Brand" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All brands</SelectItem>
              {brands.map((brand) => (
                <SelectItem key={brand.id} value={String(brand.id)}>{brand.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={categoryId} onValueChange={(value) => { setCategoryId(value); setPage(1) }}>
            <SelectTrigger><SelectValue placeholder="Category" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All categories</SelectItem>
              {categories.map((category) => (
                <SelectItem key={category.id} value={String(category.id)}>{category.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="max-h-[55vh] space-y-1 overflow-y-auto pr-1">
          {isLoading ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              <Icon icon="solar:refresh-bold" className="mr-2 inline h-4 w-4 animate-spin" />
              Searching the catalog...
            </div>
          ) : items.length === 0 ? (
            <div className="py-10 text-center text-sm text-muted-foreground">No products found.</div>
          ) : (
            items.map((product) => (
              <div
                key={product.id}
                role="button"
                tabIndex={0}
                onClick={() => select(product)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    select(product)
                  }
                }}
                className={cn('flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-transparent px-3 py-2 text-left transition-colors hover:border-border hover:bg-muted/50 focus:outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/30')}
              >
                <span className="flex min-w-0 items-center gap-3">
                  <span className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                    {product.image ? (
                      <img src={resolveImageUrl(product.image)} alt={product.name} className="h-full w-full object-cover" loading="lazy" />
                    ) : (
                      <Icon icon="solar:gallery-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                    )}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{product.name}</span>
                    {plainText(product.short_description) && (
                      <span className="block truncate text-xs text-muted-foreground">{plainText(product.short_description)}</span>
                    )}
                    <span className="block truncate text-xs text-muted-foreground">SKU: {product.sku}</span>
                  </span>
                </span>
                <span className="flex shrink-0 items-center gap-3">
                  {product.selling_price != null && (
                    <span className="text-sm tabular-nums">{formatCurrency(Number(product.selling_price) || 0)}</span>
                  )}
                  <Button type="button" size="sm" variant="outline" tabIndex={-1}>Select</Button>
                </span>
              </div>
            ))
          )}
        </div>

        <div className="flex items-center justify-between border-t pt-3 text-sm text-muted-foreground">
          <span>
            {total > 0 ? `${(page - 1) * PAGE_SIZE + 1}–${Math.min(page * PAGE_SIZE, total)} of ${total}` : 'No results'}
          </span>
          <span className="flex items-center gap-2">
            <Button type="button" size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
            <span>Page {page} / {lastPage}</span>
            <Button type="button" size="sm" variant="outline" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Next</Button>
          </span>
        </div>
      </DialogContent>
    </Dialog>
  )
}
