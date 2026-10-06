import { useState, useEffect, useCallback, useRef, type KeyboardEvent } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { cn, resolveImageUrl } from '@/lib/utils'
import api from '@/lib/api'

interface Product {
  id: number
  name: string
  sku: string
  barcode?: string | null
  stock_quantity: number
  short_description?: string | null
  description?: string | null
  price?: number | string | null
  selling_price?: number | string | null
  cost?: number | string | null
  manufacturer_part_number?: string | null
  image?: string | null
}

interface ProductSearchComboboxProps {
  value: string
  onValueChange: (value: string, product?: Product) => void
  placeholder?: string
  disabled?: boolean
  wrapText?: boolean
  /**
   * #319: keyboard-first entry for line grids. Typing on the closed cell
   * opens the search seeded with the typed keys; Enter looks up an exact
   * SKU/barcode match right away (no debounce wait, so a scanner's trailing
   * Enter works); a search whose only result is an exact SKU/barcode match is
   * picked without Enter.
   */
  keyboardEntry?: boolean
  /**
   * Called after a pick instead of returning focus to the trigger (e.g. move
   * to the line's quantity).
   */
  focusAfterSelect?: () => void
}

const isExactCode = (product: Product, query: string) =>
  [product.sku, product.barcode]
    .filter(Boolean)
    .some((code) => String(code).trim().toLowerCase() === query)

const plainText = (value?: string | null) =>
  value ? value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''

export const getProductLineDescription = (product?: Product) =>
  plainText(product?.short_description) || plainText(product?.description) || product?.name || ''

export function ProductSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search name, SKU, barcode, lot or serial...',
  disabled = false,
  wrapText = false,
  keyboardEntry = false,
  focusAfterSelect,
}: ProductSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const pickedRef = useRef(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  // Debounce search query
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  // Fetch products with search
  const { data: searchResults, isLoading } = useQuery<Product[]>({
    queryKey: ['products-search', debouncedQuery],
    queryFn: async () => {
      const params: Record<string, string | number> = { per_page: 20 }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      const res = await api.get('/products', { params })
      return res.data.data || []
    },
    enabled: open, // Only fetch when popover is open
  })

  // Fetch selected product details if we have a value but no display name
  const { data: selectedProduct } = useQuery<Product>({
    queryKey: ['product-detail', value],
    queryFn: async () => {
      const res = await api.get(`/products/${value}`)
      return res.data.data || res.data
    },
    enabled: !!value && !searchResults?.find(p => String(p.id) === value),
  })

  // Get display value for selected product
  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    // Check in search results first
    const fromResults = searchResults?.find(p => String(p.id) === value)
    if (fromResults) {
      return `${fromResults.name} (${fromResults.sku})`
    }

    // Check if we have the selected product details
    if (selectedProduct) {
      return `${selectedProduct.name} (${selectedProduct.sku})`
    }

    return 'Loading...'
  }, [value, searchResults, selectedProduct, placeholder])

  const pick = (productId: string, product?: Product) => {
    pickedRef.current = true
    onValueChange(productId, product)
    setOpen(false)
    setSearchQuery('')
  }

  const handleSelect = (productId: string) => {
    pick(productId, searchResults?.find(p => String(p.id) === productId))
  }

  const handleClear = () => {
    onValueChange('', undefined)
    setSearchQuery('')
    setOpen(false)
  }

  // D6(d): typing/scanning an exact SKU or barcode and pressing Enter picks
  // that product directly — no need to click the dropdown row.
  const trimmedQuery = searchQuery.trim().toLowerCase()
  const exactMatch = trimmedQuery
    ? searchResults?.find((product) => isExactCode(product, trimmedQuery)) ?? null
    : null

  // #319: Enter before the debounced results arrive (a scanner) asks the same
  // product search endpoint directly and picks the exact SKU/barcode match.
  const lookupRef = useRef(0)
  const [lookupError, setLookupError] = useState<string | null>(null)
  const selectExactNow = async () => {
    const query = searchQuery.trim()
    if (!query) return
    if (exactMatch) {
      pick(String(exactMatch.id), exactMatch)
      return
    }
    const ticket = ++lookupRef.current
    setLookupError(null)
    let res
    try {
      res = await api.get('/products', { params: { per_page: 20, search: query } })
    } catch {
      if (ticket === lookupRef.current) setLookupError(`Could not look up "${query}". Try again.`)
      return
    }
    if (ticket !== lookupRef.current) return
    const rows: Product[] = res.data?.data || []
    const match = rows.find((product) => isExactCode(product, query.toLowerCase()))
    if (match) pick(String(match.id), match)
  }

  // #319: the settled search for what is typed has one result and it is an
  // exact SKU/barcode match — pick it without waiting for Enter. Several
  // results (e.g. YRN-1 and YRN-10) wait for Enter or a click.
  useEffect(() => {
    if (!keyboardEntry || !open || !trimmedQuery || isLoading) return
    if (debouncedQuery.trim().toLowerCase() !== trimmedQuery) return
    if (searchResults?.length === 1 && isExactCode(searchResults[0], trimmedQuery)) {
      pick(String(searchResults[0].id), searchResults[0])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [keyboardEntry, open, trimmedQuery, debouncedQuery, searchResults, isLoading])

  const handleTriggerKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    if (!keyboardEntry || disabled) return
    if (event.key === 'Enter' && open && searchQuery.trim()) {
      // Keys typed faster than focus moved into the search box.
      event.preventDefault()
      void selectExactNow()
      return
    }
    if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return
    if (event.key === ' ' && !open) return // Space still opens the list.
    event.preventDefault()
    if (open) {
      setSearchQuery((current) => current + event.key)
    } else {
      setSearchQuery(event.key)
      setOpen(true)
    }
  }

  return (
    <div className="relative w-full">
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            onKeyDown={handleTriggerKeyDown}
            className={cn(
              'w-full justify-between gap-2 font-normal',
              wrapText && 'h-auto min-h-10 py-2',
              !value && 'text-muted-foreground'
            )}
          >
            <span className={cn('min-w-0 flex-1', wrapText ? 'whitespace-normal break-words text-left leading-5' : 'truncate')}>
              {getDisplayValue()}
            </span>

            {/* Trailing controls sit inline — no reserved right padding, no
                absolutely-positioned clear button floating in dead space. */}
            <span className="flex shrink-0 items-center gap-0.5">
              {value && (
                <span
                  role="button"
                  tabIndex={-1}
                  aria-label="Clear selected product"
                  title="Clear selection"
                  className="rounded-sm p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                  onPointerDown={(event) => {
                    event.preventDefault()
                    event.stopPropagation()
                  }}
                  onClick={(event) => {
                    event.preventDefault()
                    event.stopPropagation()
                    handleClear()
                  }}
                >
                  <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                </span>
              )}
              <Icon
                icon="solar:alt-arrow-down-linear"
                className={cn('h-4 w-4 shrink-0 transition-transform', open && 'rotate-180')}
              />
            </span>
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[420px] max-w-[calc(100vw-2rem)] p-0"
          align="start"
          onCloseAutoFocus={(event) => {
            if (pickedRef.current && focusAfterSelect) {
              event.preventDefault()
              focusAfterSelect()
            }
            pickedRef.current = false
          }}
        >
        <Command shouldFilter={false}>
          <CommandInput
            placeholder="Search name, SKU, barcode, lot or serial..."
            value={searchQuery}
            onValueChange={setSearchQuery}
            onFocus={(event) => {
              // Keep the keys typed on the trigger: caret after them.
              const end = event.currentTarget.value.length
              event.currentTarget.setSelectionRange(end, end)
            }}
            onKeyDown={(event) => {
              if (event.key !== 'Enter') return
              if (exactMatch) {
                event.preventDefault()
                handleSelect(String(exactMatch.id))
              } else if (keyboardEntry && searchQuery.trim()) {
                event.preventDefault()
                void selectExactNow()
              }
            }}
          />
          <CommandList>
            {lookupError && (
              <div role="alert" className="px-3 py-2 text-xs text-destructive">{lookupError}</div>
            )}
            {isLoading ? (
              <div className="py-6 text-center text-sm text-muted-foreground">
                <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin inline mr-2" />
                Searching...
              </div>
            ) : searchResults && searchResults.length === 0 ? (
              <CommandEmpty>
                {debouncedQuery ? 'No products found.' : 'Search by name, SKU, barcode, lot or serial...'}
              </CommandEmpty>
            ) : (
              <CommandGroup heading="Products">
                {searchResults?.map((product) => (
                  <CommandItem
                    key={product.id}
                    value={String(product.id)}
                    onSelect={handleSelect}
                    className="flex items-center justify-between gap-3 py-3"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                        {product.image ? (
                          <img
                            src={resolveImageUrl(product.image)}
                            alt={product.name}
                            className="h-full w-full object-cover"
                            loading="lazy"
                          />
                        ) : (
                          <Icon icon="solar:gallery-bold-duotone" className="h-5 w-5 text-muted-foreground" />
                        )}
                      </div>
                      <div className="flex min-w-0 flex-1 flex-col">
                        <span className="line-clamp-2 font-medium leading-snug" title={product.name}>{product.name}</span>
                        <span className="truncate text-xs text-muted-foreground" title={product.sku}>SKU: {product.sku}</span>
                        {plainText(product.short_description) && (
                          <span className="mt-1 max-w-[240px] whitespace-normal text-xs text-muted-foreground">
                            {plainText(product.short_description)}
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant="outline" className="text-xs">
                        Stock: {product.stock_quantity}
                      </Badge>
                      {String(product.id) === value && (
                        <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-primary" />
                      )}
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}
