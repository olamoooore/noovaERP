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

interface Contact {
  id: string
  display_name?: string
  first_name?: string
  last_name?: string
  company_name?: string
  email?: string
  phone?: string
}

interface ContactSearchComboboxProps {
  value: string
  onValueChange: (value: string, contact?: Contact) => void
  placeholder?: string
  disabled?: boolean
}

export function ContactSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search contacts...',
  disabled = false,
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

  const { data: searchResults, isLoading } = useQuery<Contact[]>({
    queryKey: ['contacts-search', debouncedQuery],
    queryFn: async () => {
      const params: Record<string, string> = { per_page: '50', is_active: 'true' }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      const res = await api.get('/contacts', { params })
      return res.data?.data?.data ?? res.data?.data ?? []
    },
    enabled: open,
  })

  const { data: selectedContact } = useQuery<Contact>({
    queryKey: ['contact-detail', value],
    queryFn: async () => {
      const res = await api.get(`/contacts/${value}`)
      return res.data?.data ?? res.data
    },
    enabled: !!value && !searchResults?.find(c => String(c.id) === value),
  })

  const getDisplayName = (c: Contact) =>
    c.display_name || [c.first_name, c.last_name].filter(Boolean).join(' ') || c.company_name || 'Unnamed'

  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    const fromResults = searchResults?.find(c => String(c.id) === value)
    if (fromResults) return getDisplayName(fromResults)

    if (selectedContact) return getDisplayName(selectedContact)

    return 'Loading...'
  }, [value, searchResults, selectedContact, placeholder])

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
            !value && 'text-muted-foreground'
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
                    <span>{getDisplayName(contact)}</span>
                    {contact.email && (
                      <span className="text-xs text-muted-foreground">{contact.email}</span>
                    )}
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
