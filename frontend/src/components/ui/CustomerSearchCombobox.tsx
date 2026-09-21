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

interface Customer {
  id: string
  name?: string
  company?: string
  email?: string
  phone?: string
  code?: string
}

interface CustomerSearchComboboxProps {
  value: string
  onValueChange: (value: string, customer?: Customer) => void
  placeholder?: string
  disabled?: boolean
}

export function CustomerSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search customers...',
  disabled = false,
}: CustomerSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const { data: searchResults, isLoading } = useQuery<Customer[]>({
    queryKey: ['customers-search', debouncedQuery],
    queryFn: async () => {
      const params: Record<string, string> = { per_page: '50' }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      const res = await api.get('/customers', { params })
      return res.data?.data?.data ?? res.data?.data ?? []
    },
    enabled: open,
  })

  const { data: selectedCustomer } = useQuery<Customer>({
    queryKey: ['customer-detail', value],
    queryFn: async () => {
      const res = await api.get(`/customers/${value}`)
      return res.data?.data ?? res.data
    },
    enabled: !!value && !searchResults?.find(c => String(c.id) === value),
  })

  const getDisplayName = (c: Customer) =>
    c.name || c.company || 'Unnamed'

  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    const fromResults = searchResults?.find(c => String(c.id) === value)
    if (fromResults) return getDisplayName(fromResults)

    if (selectedCustomer) return getDisplayName(selectedCustomer)

    return 'Loading...'
  }, [value, searchResults, selectedCustomer, placeholder])

  const handleSelect = (customerId: string) => {
    const customer = searchResults?.find(c => String(c.id) === customerId)
    onValueChange(customerId, customer)
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
            <CommandEmpty>
              {isLoading ? 'Searching...' : 'No customers found.'}
            </CommandEmpty>
            <CommandGroup>
              {searchResults?.map((customer) => (
                <CommandItem
                  key={customer.id}
                  value={String(customer.id)}
                  onSelect={handleSelect}
                >
                  <Icon
                    icon="solar:check-circle-bold"
                    className={cn(
                      'mr-2 h-4 w-4',
                      value === String(customer.id) ? 'opacity-100' : 'opacity-0'
                    )}
                  />
                  <div className="flex flex-col">
                    <span>{getDisplayName(customer)}</span>
                    {customer.code && (
                      <span className="text-xs text-muted-foreground">{customer.code}</span>
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
          aria-label="Clear selected customer"
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
