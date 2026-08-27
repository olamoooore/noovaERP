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

export interface InvoiceSearchItem {
  id: number
  invoice_number: string
  customer_id?: number
  customer?: { id: number; name: string }
  total: number
  paid_amount?: number
  balance?: number
  status?: string
  invoice_date?: string
}

interface InvoiceSearchComboboxProps {
  value: string
  onValueChange: (value: string, invoice?: InvoiceSearchItem) => void
  placeholder?: string
  disabled?: boolean
  /** Restrict to specific customer (e.g. for payment recording) */
  customerId?: number | string
  /** Restrict to specific statuses */
  statuses?: string[]
}

export function InvoiceSearchCombobox({
  value,
  onValueChange,
  placeholder = 'Search invoices...',
  disabled = false,
  customerId,
  statuses = ['posted', 'sent', 'overdue', 'partial'],
}: InvoiceSearchComboboxProps) {
  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [debouncedQuery, setDebouncedQuery] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(searchQuery)
    }, 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  const { data: searchResults, isLoading } = useQuery<InvoiceSearchItem[]>({
    queryKey: ['invoices-search', debouncedQuery, customerId, statuses.join(',')],
    queryFn: async () => {
      const params: Record<string, string> = {
        per_page: '50',
        status: statuses.join(','),
      }
      if (debouncedQuery) {
        params.search = debouncedQuery
      }
      if (customerId) {
        params.customer_id = String(customerId)
      }
      const res = await api.get('/invoices', { params })
      return res.data?.data?.data ?? res.data?.data ?? []
    },
    enabled: open,
  })

  const { data: selectedInvoice } = useQuery<InvoiceSearchItem>({
    queryKey: ['invoice-detail', value],
    queryFn: async () => {
      const res = await api.get(`/invoices/${value}`)
      return res.data
    },
    enabled: !!value && !searchResults?.find((inv) => String(inv.id) === value),
  })

  const getDisplayValue = useCallback(() => {
    if (!value) return placeholder

    const fromResults = searchResults?.find((inv) => String(inv.id) === value)
    if (fromResults) {
      const customerName = fromResults.customer?.name || ''
      const balance = fromResults.balance ?? fromResults.total
      return `${fromResults.invoice_number}${customerName ? ` - ${customerName}` : ''} ($${balance})`
    }

    if (selectedInvoice) {
      const customerName = selectedInvoice.customer?.name || ''
      const balance = selectedInvoice.balance ?? selectedInvoice.total
      return `${selectedInvoice.invoice_number}${customerName ? ` - ${customerName}` : ''} ($${balance})`
    }

    return 'Loading...'
  }, [value, searchResults, selectedInvoice, placeholder])

  const handleSelect = (invoiceId: string) => {
    const invoice = searchResults?.find((inv) => String(inv.id) === invoiceId)
    onValueChange(invoiceId, invoice)
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
            placeholder="Type invoice number or customer..."
            value={searchQuery}
            onValueChange={setSearchQuery}
          />
          <CommandList>
            <CommandEmpty>
              {isLoading ? 'Searching...' : 'No invoices found.'}
            </CommandEmpty>
            <CommandGroup>
              {searchResults?.map((invoice) => (
                <CommandItem
                  key={invoice.id}
                  value={String(invoice.id)}
                  onSelect={handleSelect}
                >
                  <Icon
                    icon="solar:check-circle-bold"
                    className={cn(
                      'mr-2 h-4 w-4',
                      value === String(invoice.id) ? 'opacity-100' : 'opacity-0'
                    )}
                  />
                  <div className="flex flex-col">
                    <span>
                      {invoice.invoice_number} - {invoice.customer?.name || 'Unknown'}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      ${invoice.balance ?? invoice.total}
                      {invoice.status ? ` · ${invoice.status}` : ''}
                    </span>
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
