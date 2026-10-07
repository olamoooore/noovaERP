import { useState, useEffect, useCallback, useMemo } from 'react'
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

interface Contact {
  id: string
  display_name?: string
  first_name?: string
  last_name?: string
  company_name?: string
  email?: string
  phone?: string
  // Contact Master link (customer contact records) and address fields.
  core_contact_uuid?: string | null
  type?: string
  label?: string
  street?: string
  street2?: string
  city?: string
  state?: string
  zip?: string
  country?: string
  is_default?: boolean
}

interface ContactSearchComboboxProps {
  value: string
  onValueChange: (value: string, contact?: Contact) => void
  placeholder?: string
  disabled?: boolean
  customerId?: string | number
  source?: 'contacts' | 'customer_contacts' | 'addresses'
  addressType?: 'billing' | 'shipping'
  /** Caller-owned trigger classes (e.g. the #323 sales-form control tokens). */
  className?: string
}

const formatRecordName = (c: Contact, source: ContactSearchComboboxProps['source']) => {
  // Only address selectors should render structured address values. Customer
  // contacts can also carry address fields but must still display their name.
  if (source === 'addresses' && (c.street || c.city || c.country)) {
    const parts = [c.street, c.street2, c.city, c.state, c.zip, c.country].filter(Boolean)
    return parts.join(', ') || c.label || c.type || 'Address'
  }
  return c.display_name || (c as any).name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.company_name || c.label || 'Unnamed'
}

export function ContactSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search contacts...',
  disabled = false,
  customerId,
  source = customerId ? 'customer_contacts' : 'contacts',
  addressType,
  className,
}: ContactSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const usesCustomerRecords = Boolean(customerId) && (source === 'customer_contacts' || source === 'addresses')

  // #301: a customer's contacts/addresses come from one GET /customers/{id},
  // fetched once per customer and filtered locally. The search text is NOT in
  // this key — typing must never refetch the whole customer.
  const { data: customerRecord, isLoading: isCustomerLoading } = useQuery<Record<string, any> | null>({
    queryKey: ['customer-contact-records', customerId],
    queryFn: async () => {
      const res = await api.get(`/customers/${customerId}`)
      return res.data?.data ?? res.data ?? null
    },
    enabled: usesCustomerRecords && (open || !!value),
  })

  const getDisplayName = (c: Contact) => formatRecordName(c, source)

  const customerRecords = useMemo<Contact[]>(() => {
    if (!usesCustomerRecords || !customerRecord) return []
    return source === 'addresses'
      ? (customerRecord.addresses ?? []).filter((address: Contact) => !addressType || address.type === addressType)
      : (customerRecord.customer_contacts ?? customerRecord.customerContacts ?? [])
  }, [usesCustomerRecords, customerRecord, source, addressType])

  // Free contact search (no customer) stays server-side and debounced.
  const { data: contactResults, isLoading: isContactsLoading } = useQuery<Contact[]>({
    queryKey: ['contacts-search', debouncedQuery],
    queryFn: async () => {
      const params: Record<string, string> = { per_page: '50', is_active: 'true' }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      const res = await api.get('/contacts', { params })
      return res.data?.data?.data ?? res.data?.data ?? []
    },
    enabled: open && !usesCustomerRecords && source === 'contacts',
  })

  const searchResults = useMemo<Contact[] | undefined>(() => {
    if (!usesCustomerRecords) return contactResults
    if (!debouncedQuery) return customerRecords
    const q = debouncedQuery.toLowerCase()
    return customerRecords.filter((record) => formatRecordName(record, source).toLowerCase().includes(q))
  }, [usesCustomerRecords, contactResults, customerRecords, debouncedQuery, source])
  const isLoading = usesCustomerRecords ? isCustomerLoading : isContactsLoading

  const { data: selectedContact } = useQuery<Contact>({
    queryKey: ['contact-detail', value],
    queryFn: async () => {
      const res = await api.get(`/contacts/${value}`)
      return res.data?.data ?? res.data
    },
    // #1007: a saved invoice contact is a core contact UUID that may not be in
    // the customer's current contact list — resolve its name instead of
    // showing "Loading..." forever.
    enabled: !!value
      && (!usesCustomerRecords || (source === 'customer_contacts' && value.includes('-') && !!customerRecord))
      && !searchResults?.find(c => String(c.id) === value),
  })

  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    const fromResults = searchResults?.find(c => String(c.id) === value)
    if (fromResults) return getDisplayName(fromResults)

    if (usesCustomerRecords) {
      // The selected record may be filtered out by the current search text.
      const pool: Contact[] = source === 'addresses'
        ? customerRecord?.addresses ?? []
        : customerRecords
      const fromCustomer = pool.find(c => String(c.id) === value)
      if (fromCustomer) return getDisplayName(fromCustomer)
    }

    if (selectedContact) return getDisplayName(selectedContact)

    return 'Loading...'
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, searchResults, selectedContact, placeholder, usesCustomerRecords, customerRecord, customerRecords, source])

  const handleSelect = (contactId: string) => {
    const contact = searchResults?.find(c => String(c.id) === contactId)
    onValueChange(contactId, contact)
    setOpen(false)
    setSearchQuery('')
  }

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation()
    onValueChange('', undefined)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            'w-full justify-between font-normal',
            !value && 'text-muted-foreground',
            className
          )}
        >
          <span className="truncate">{getDisplayValue()}</span>
          <div className="flex items-center gap-1 ml-2 shrink-0">
            {value && (
              <Icon
                icon="solar:close-circle-bold"
                className="h-4 w-4 opacity-50 hover:opacity-100 cursor-pointer"
                onClick={handleClear}
              />
            )}
            <Icon icon="solar:alt-arrow-down-linear" className="h-4 w-4 opacity-50" />
          </div>
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
              {isLoading ? 'Searching...' : 'No contacts found.'}
            </CommandEmpty>
            <CommandGroup>
              {searchResults?.map((contact) => (
                <CommandItem
                  key={contact.id}
                  value={String(contact.id)}
                  onSelect={handleSelect}
                >
                  <Icon
                    icon="solar:check-circle-bold"
                    className={cn(
                      'mr-2 h-4 w-4',
                      value === String(contact.id) ? 'opacity-100' : 'opacity-0'
                    )}
                  />
                  <div className="flex flex-col">
                    <span className="text-xs font-medium text-muted-foreground capitalize">{contact.type || 'Contact'}</span>
                    <span>{getDisplayName(contact)}</span>
                    {contact.email && <span className="text-xs text-muted-foreground">{contact.email}</span>}
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
