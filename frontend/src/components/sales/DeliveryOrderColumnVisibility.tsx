import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'

interface ColumnDefinition {
  label: string
  index: number
}

export function DeliveryOrderColumnVisibility({ tableClass, columns, children }: {
  tableClass: string
  columns: ColumnDefinition[]
  children: React.ReactNode
}) {
  const [hidden, setHidden] = useState<number[]>([])
  const css = hidden
    .map((index) => `.${tableClass} th:nth-child(${index}),.${tableClass} td:nth-child(${index}){display:none}`)
    .join('')

  return (
    <div className="space-y-2">
      <div className="flex justify-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button type="button" variant="outline" size="sm">
              <Icon icon="solar:settings-minimalistic-bold" className="mr-2 h-4 w-4" /> Columns
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
            <DropdownMenuLabel>Show columns</DropdownMenuLabel>
            {columns.map((column) => (
              <DropdownMenuItem
                key={column.index}
                onSelect={(event) => {
                  event.preventDefault()
                  setHidden((current) => current.includes(column.index)
                    ? current.filter((index) => index !== column.index)
                    : [...current, column.index])
                }}
              >
                <Icon
                  icon={!hidden.includes(column.index) ? 'solar:check-square-bold' : 'solar:stop-outline'}
                  className="mr-2 h-4 w-4"
                />
                {column.label}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
      <style>{css}</style>
      <div className={tableClass}>{children}</div>
    </div>
  )
}
