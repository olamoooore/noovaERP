import { useState } from 'react'
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
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'

export interface SearchableSelectItem {
  value: string
  label: string
}

/**
 * Lightweight type-to-search select used for document reference fields
 * (invoice no., quotation no., …). Backed by an in-memory item list.
 */
export function SearchableSelect({
  value,
  onValueChange,
  items,
  placeholder = 'Search…',
  emptyLabel = 'None',
  emptyValue = 'none',
}: {
  value: string
  onValueChange: (value: string) => void
  items: SearchableSelectItem[]
  placeholder?: string
  emptyLabel?: string
  emptyValue?: string
}) {
  const [open, setOpen] = useState(false)
  const selected = items.find((i) => i.value === value)
  const display = value === emptyValue || value === '' ? emptyLabel : selected?.label || placeholder

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className={cn(
            'h-9 w-full justify-between font-normal',
            value && value !== emptyValue ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          <span className="truncate">{display}</span>
          <Icon icon="solar:alt-arrow-down-bold" className="h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder={placeholder} />
          <CommandList>
            <CommandEmpty>No results.</CommandEmpty>
            <CommandGroup>
              <CommandItem
                value={emptyLabel}
                onSelect={() => {
                  onValueChange(emptyValue)
                  setOpen(false)
                }}
              >
                {emptyLabel}
              </CommandItem>
              {items.map((item) => (
                <CommandItem
                  key={item.value}
                  value={item.label}
                  onSelect={() => {
                    onValueChange(item.value)
                    setOpen(false)
                  }}
                >
                  {item.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
