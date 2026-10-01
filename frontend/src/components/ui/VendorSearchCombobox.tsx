import { useState, useEffect, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
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

interface Vendor {
  id: number
  name?: string
  company?: string | null
  email?: string | null
  phone?: string | null
  contact_person?: string | null
  code?: string | null
}

interface VendorSearchComboboxProps {
  value: string
  onValueChange: (value: string, vendor?: Vendor) => void
  placeholder?: string
  disabled?: boolean
  /** Compact pill styling (h-8 / rounded-full) to match the header action row. */
  compact?: boolean
}

/**
 * Searchable vendor picker — the AP counterpart of CustomerSearchCombobox.
 * The vendor select used to be a plain dropdown, which is unusable once the
 * vendor list grows (client report 2026-10-01).
 */
export function VendorSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search vendors...',
  compact = false,
  disabled = false,
}: VendorSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const { data: searchResults, isLoading } = useQuery<Vendor[]>({
    queryKey: ['vendors-search', debouncedQuery],
    queryFn: async () => {
      const params: Record<string, string> = { per_page: '50' }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      const res = await api.get('/accounting/vendors', { params })
      return res.data?.data?.data ?? res.data?.data ?? []
    },
    enabled: open,
  })

  const { data: selectedVendor } = useQuery<Vendor>({
    queryKey: ['vendor-search-detail', value],
    queryFn: async () => {
      const res = await api.get(`/accounting/vendors/${value}`)
      return res.data?.data ?? res.data
    },
    enabled: !!value && !searchResults?.find((vendor) => String(vendor.id) === value),
  })

  const getDisplayName = (vendor: Vendor) => vendor.name || vendor.company || 'Unnamed vendor'

  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    const fromResults = searchResults?.find((vendor) => String(vendor.id) === value)
    if (fromResults) return getDisplayName(fromResults)

    if (selectedVendor) return getDisplayName(selectedVendor)

    return 'Loading...'
  }, [value, searchResults, selectedVendor, placeholder])

  const handleSelect = (vendorId: string) => {
    const vendor = searchResults?.find((item) => String(item.id) === vendorId)
    onValueChange(vendorId, vendor)
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
              'w-full justify-between font-normal',
              compact && 'h-8 rounded-full px-3 text-xs',
              value && 'pr-16',
              !value && 'text-muted-foreground'
            )}
          >
            <span className="truncate">{getDisplayValue()}</span>
            <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
          <Command shouldFilter={false}>
            <CommandInput
              placeholder="Type to search..."
              value={searchQuery}
              onValueChange={setSearchQuery}
            />
            <CommandList>
              <CommandEmpty>{isLoading ? 'Searching...' : 'No vendors found.'}</CommandEmpty>
              <CommandGroup>
                {searchResults?.map((vendor) => (
                  <CommandItem key={vendor.id} value={String(vendor.id)} onSelect={handleSelect}>
                    <Icon
                      icon="solar:check-circle-bold"
                      className={cn(
                        'mr-2 h-4 w-4',
                        value === String(vendor.id) ? 'opacity-100' : 'opacity-0'
                      )}
                    />
                    <div className="flex min-w-0 flex-col">
                      <span className="truncate">{getDisplayName(vendor)}</span>
                      {vendor.company && vendor.company !== vendor.name && (
                        <span className="truncate text-xs text-muted-foreground">{vendor.company}</span>
                      )}
                      {vendor.contact_person && (
                        <span className="truncate text-xs text-primary">Contact: {vendor.contact_person}</span>
                      )}
                      {(vendor.code || vendor.email) && (
                        <span className="truncate text-xs text-muted-foreground">
                          {[vendor.code, vendor.email].filter(Boolean).join(' · ')}
                        </span>
                      )}
                    </div>
                  </CommandItem>
                ))}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
      {value && (
        <button
          type="button"
          aria-label="Clear selected vendor"
          disabled={disabled}
          className="absolute right-8 top-1/2 z-10 -translate-y-1/2 rounded-sm p-1 text-muted-foreground hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring disabled:pointer-events-none disabled:opacity-50"
          onClick={handleClear}
        >
          <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
        </button>
      )}
    </div>
  )
}