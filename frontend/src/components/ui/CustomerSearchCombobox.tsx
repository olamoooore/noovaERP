import { Fragment, useState, useEffect, useCallback, useRef } from 'react'
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
  /** Populated by the API when the search matched one of the customer's contacts. */
  matched_contacts?: string[]
  /** #1007: customer_contacts ids aligned with matched_contacts. */
  matched_contact_ids?: Array<string | number>
  /** Tag names that matched the search (e.g. "VIP"). */
  matched_tags?: string[]
  /** Address/building labels that matched the search. */
  matched_addresses?: string[]
  /** #339: customer_addresses ids aligned with matched_addresses. */
  matched_address_ids?: Array<string | number>
  /**
   * #339 (D1 Smart Customer Pick): one row per matched contact/address —
   * ids and display labels only (no contact email/phone, #444/#567).
   */
  matched_picks?: CustomerMatchPick[]
}

type CustomerMatchPick =
  | { kind: 'contact'; contact_id: string | number; contact_name?: string | null; job_title?: string | null; role?: string | null }
  | { kind: 'address'; address_id: string | number; address_type?: string | null; label?: string | null; city?: string | null; text?: string | null }

/**
 * #339: what a smart-pick row selected besides the customer, so the caller can
 * fill that contact or address on the header in the same pick.
 */
export type CustomerSmartPick =
  | { kind: 'contact'; contactId: string }
  | { kind: 'address'; addressId: string; addressType: string }

const titleCase = (value?: string | null) =>
  value ? value.charAt(0).toUpperCase() + value.slice(1).toLowerCase() : ''

/** "ACME › John Tan · Billing" / "ACME › KL Warehouse · Shipping · Kuala Lumpur" */
const pickLabel = (customerName: string, pick: CustomerMatchPick): string => {
  if (pick.kind === 'contact') {
    const parts = [pick.contact_name || 'Contact', titleCase(pick.role) || pick.job_title || '']
    return `${customerName} › ${parts.filter(Boolean).join(' · ')}`
  }
  const head = pick.label || pick.text || 'Address'
  const parts = [head, titleCase(pick.address_type), pick.label ? pick.city ?? '' : '']
  return `${customerName} › ${parts.filter(Boolean).join(' · ')}`
}

const pickValue = (customerId: string | number, pick: CustomerMatchPick) =>
  pick.kind === 'contact'
    ? `${customerId}::contact::${pick.contact_id}`
    : `${customerId}::address::${pick.address_id}`

interface CustomerSearchComboboxProps {
  value: string
  /**
   * #1007: `matchedContactId` is set when the pick matched through exactly one
   * of the customer's contact persons, so the caller can pre-select it.
   */
  onValueChange: (value: string, customer?: Customer, matchedContactId?: string, smartPick?: CustomerSmartPick) => void
  placeholder?: string
  disabled?: boolean
  /** Compact pill styling (h-8 / rounded-full) to match the header action row. */
  compact?: boolean
  /** Caller-owned trigger classes (e.g. the #323 sales-form control tokens). */
  className?: string
  /**
   * #319: called after a pick instead of returning focus to the trigger
   * (e.g. move to the first line's product).
   */
  focusAfterSelect?: () => void
}

export function CustomerSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search customers...',
  compact = false,
  disabled = false,
  className,
  focusAfterSelect,
}: CustomerSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const pickedRef = useRef(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const { data: searchResults, isLoading, isError, error } = useQuery<Customer[]>({
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

  // #1002: a refused/failed search (401/403/500) must not look like an empty
  // result — "No customers found." sent users hunting for a missing customer
  // when the real problem was access or a server error.
  const errorStatus = (error as { response?: { status?: number } } | null)?.response?.status

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
    pickedRef.current = true
    const matchedIds = customer?.matched_contact_ids ?? []
    const matchedContactId = matchedIds.length === 1 ? String(matchedIds[0]) : undefined
    onValueChange(customerId, customer, matchedContactId)
    setOpen(false)
    setSearchQuery('')
  }

  // #339: a contact/address pick row selects the customer AND that record.
  const handleSmartPick = (customer: Customer, pick: CustomerMatchPick) => {
    pickedRef.current = true
    const customerId = String(customer.id)
    if (pick.kind === 'contact') {
      const contactId = String(pick.contact_id)
      onValueChange(customerId, customer, contactId, { kind: 'contact', contactId })
    } else {
      onValueChange(customerId, customer, undefined, {
        kind: 'address',
        addressId: String(pick.address_id),
        addressType: pick.address_type ?? '',
      })
    }
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
              !value && 'text-muted-foreground',
              className
            )}
          >
            <span className="truncate">{getDisplayValue()}</span>
            <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="w-[--radix-popover-trigger-width] p-0"
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
            placeholder="Type to search..."
            value={searchQuery}
            onValueChange={setSearchQuery}
          />
          <CommandList>
            {isError ? (
              <div role="alert" className="px-3 py-6 text-center text-sm text-destructive">
                Couldn't load customers — check your access or try again.
                {errorStatus ? ` (HTTP ${errorStatus})` : ''}
              </div>
            ) : (
              <CommandEmpty>
                {isLoading ? 'Searching...' : 'No customers found.'}
              </CommandEmpty>
            )}
            <CommandGroup>
              {searchResults?.map((customer) => (
                <Fragment key={customer.id}>
                <CommandItem
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
                  <div className="flex min-w-0 flex-col">
                    <span className="truncate">{getDisplayName(customer)}</span>
                    {(customer.matched_contacts?.length ?? 0) > 0 && (
                      <span className="truncate text-xs text-primary">
                        Contact: {customer.matched_contacts!.join(', ')}
                      </span>
                    )}
                    {(customer.matched_tags?.length ?? 0) > 0 && (
                      <span className="truncate text-xs text-amber-600">
                        Tag: {customer.matched_tags!.join(', ')}
                      </span>
                    )}
                    {(customer.matched_addresses?.length ?? 0) > 0 && (
                      <span className="truncate text-xs text-muted-foreground">
                        Address: {customer.matched_addresses!.join(' · ')}
                      </span>
                    )}
                    {customer.code && (
                      <span className="text-xs text-muted-foreground">{customer.code}</span>
                    )}
                  </div>
                </CommandItem>
                {(customer.matched_picks ?? []).map((pick) => (
                  <CommandItem
                    key={pickValue(customer.id, pick)}
                    value={pickValue(customer.id, pick)}
                    onSelect={() => handleSmartPick(customer, pick)}
                    className="pl-8"
                  >
                    <Icon
                      icon={pick.kind === 'contact' ? 'solar:user-linear' : 'solar:map-point-linear'}
                      className="mr-2 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                    />
                    <span className="truncate text-xs">{pickLabel(getDisplayName(customer), pick)}</span>
                  </CommandItem>
                ))}
                </Fragment>
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
