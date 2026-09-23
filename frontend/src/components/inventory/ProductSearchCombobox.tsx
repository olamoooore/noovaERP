import { useState, useEffect, useCallback } from 'react'
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
import { cn } from '@/lib/utils'
import api from '@/lib/api'

interface Product {
  id: number
  name: string
  sku: string
  stock_quantity: number
  short_description?: string | null
  description?: string | null
  price?: number | string | null
  selling_price?: number | string | null
  manufacturer_part_number?: string | null
}

interface ProductSearchComboboxProps {
  value: string
  onValueChange: (value: string, product?: Product) => void
  placeholder?: string
  disabled?: boolean
  wrapText?: boolean
}

const plainText = (value?: string | null) =>
  value ? value.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() : ''

export const getProductLineDescription = (product?: Product) =>
  plainText(product?.short_description) || plainText(product?.description) || product?.name || ''

export function ProductSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search products...',
  disabled = false,
  wrapText = false,
}: ProductSearchComboboxProps) {
  const [open, setOpen] = useState(false)
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

  const handleSelect = (productId: string) => {
    const product = searchResults?.find(p => String(p.id) === productId)
    onValueChange(productId, product)
    setOpen(false)
    setSearchQuery('')
  }

  const handleClear = () => {
    onValueChange('', undefined)
    setSearchQuery('')
    setOpen(false)
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
        <PopoverContent className="w-[400px] p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput
            placeholder="Type to search products..."
            value={searchQuery}
            onValueChange={setSearchQuery}
          />
          <CommandList>
            {isLoading ? (
              <div className="py-6 text-center text-sm text-muted-foreground">
                <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin inline mr-2" />
                Searching...
              </div>
            ) : searchResults && searchResults.length === 0 ? (
              <CommandEmpty>
                {debouncedQuery ? 'No products found.' : 'Type to search products...'}
              </CommandEmpty>
            ) : (
              <CommandGroup heading="Products">
                {searchResults?.map((product) => (
                  <CommandItem
                    key={product.id}
                    value={String(product.id)}
                    onSelect={handleSelect}
                    className="flex items-center justify-between py-3"
                  >
                    <div className="flex flex-col">
                      <span className="font-medium">{product.name}</span>
                      <span className="text-xs text-muted-foreground">SKU: {product.sku}</span>
                      {plainText(product.short_description) && (
                        <span className="mt-1 max-w-[260px] whitespace-normal text-xs text-muted-foreground">
                          {plainText(product.short_description)}
                        </span>
                      )}
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
