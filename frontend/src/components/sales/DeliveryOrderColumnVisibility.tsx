import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'

interface ColumnDefinition {
  label: string
  index: number
}

export const listingColumns = (labels: string[]): ColumnDefinition[] =>
  labels.map((label, index) => ({ label, index: index + 1 }))

export function SalesLineColumnMenu({ columns, hidden, setHidden }: {
  columns: ColumnDefinition[]
  hidden: number[]
  setHidden: React.Dispatch<React.SetStateAction<number[]>>
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="outline" size="sm">
          <Icon icon="solar:settings-minimalistic-bold" className="mr-2 h-4 w-4" /> Show/Hide Columns
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
        <DropdownMenuLabel>Show columns</DropdownMenuLabel>
        {columns.map((column) => (
          <DropdownMenuItem key={column.index} onSelect={(event) => {
            event.preventDefault()
            setHidden((current) => current.includes(column.index)
              ? current.filter((index) => index !== column.index)
              : [...current, column.index])
          }}>
            <Icon icon={!hidden.includes(column.index) ? 'solar:check-square-bold' : 'solar:stop-outline'} className="mr-2 h-4 w-4" />
            {column.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export function SalesLineColumnVisibility({ tableClass, columns, children, hiddenColumns, onHiddenColumnsChange, showMenu = true }: {
  tableClass: string
  columns: ColumnDefinition[]
  children: React.ReactNode
  hiddenColumns?: number[]
  onHiddenColumnsChange?: React.Dispatch<React.SetStateAction<number[]>>
  showMenu?: boolean
}) {
  const [internalHidden, setInternalHidden] = useState<number[]>(() =>
    columns.filter((column) => column.label.toLowerCase() === 'product notes').map((column) => column.index)
  )
  const hidden = hiddenColumns ?? internalHidden
  const setHidden = onHiddenColumnsChange ?? setInternalHidden
  const css = hidden
    .map((index) => `.${tableClass} th:nth-child(${index}),.${tableClass} td:nth-child(${index}){display:none}`)
    .join('')

  return (
    <div className="space-y-2">
      {showMenu && <div className="flex justify-end"><SalesLineColumnMenu columns={columns} hidden={hidden} setHidden={setHidden} /></div>}
      <style>{css}</style>
      <div className={tableClass}>{children}</div>
    </div>
  )
}

export const DeliveryOrderColumnVisibility = SalesLineColumnVisibility
