import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export interface SalesExportColumn {
  key: string
  label: string
  group: string
  default?: boolean
}

export const INVOICE_EXPORT_COLUMNS = [
  { key: 'invoice_number', label: 'Invoice No', group: 'Invoice', default: true },
  { key: 'invoice_date', label: 'Date', group: 'Invoice', default: true },
  { key: 'customer_name', label: 'Customer', group: 'Invoice', default: true },
  { key: 'customer_code', label: 'Customer Code', group: 'Invoice' },
  { key: 'subtotal', label: 'Subtotal', group: 'Invoice' },
  { key: 'tax_amount', label: 'Tax', group: 'Invoice' },
  { key: 'total', label: 'Amount', group: 'Invoice', default: true },
  { key: 'paid_amount', label: 'Paid Amount', group: 'Invoice' },
  { key: 'balance', label: 'Balance', group: 'Invoice' },
  { key: 'status', label: 'Status', group: 'Invoice', default: true },
  { key: 'due_date', label: 'Due Date', group: 'Invoice', default: true },
  { key: 'product_code', label: 'Product Code', group: 'Product Lines' },
  { key: 'product_name', label: 'Product', group: 'Product Lines' },
  { key: 'line_description', label: 'Description', group: 'Product Lines' },
  { key: 'model_number', label: 'Model Number', group: 'Product Lines' },
  { key: 'lot_number', label: 'Lot Number', group: 'Product Lines' },
  { key: 'expiry_date', label: 'Expiry Date', group: 'Product Lines' },
  { key: 'quantity', label: 'Quantity', group: 'Product Lines' },
  { key: 'unit_price', label: 'Unit Price', group: 'Product Lines' },
  { key: 'line_discount', label: 'Line Discount', group: 'Product Lines' },
  { key: 'tax_rate', label: 'Tax Rate', group: 'Product Lines' },
  { key: 'line_tax_amount', label: 'Line Tax', group: 'Product Lines' },
  { key: 'line_total', label: 'Line Total', group: 'Product Lines' },
] as const satisfies readonly SalesExportColumn[]

interface SalesDocumentExportMenuProps {
  endpoint: string
  filenamePrefix: string
  columns: readonly SalesExportColumn[]
  filters?: Record<string, string | number | undefined>
  selectedIds?: number[]
}

export function SalesDocumentExportMenu({
  endpoint,
  filenamePrefix,
  columns,
  filters = {},
  selectedIds = [],
}: SalesDocumentExportMenuProps) {
  const { toast } = useToast()
  const [selectedColumns, setSelectedColumns] = useState<string[]>(
    columns.filter((column) => column.default).map((column) => column.key),
  )
  const [isExporting, setIsExporting] = useState(false)
  const groups = Array.from(new Set(columns.map((column) => column.group)))

  const download = async () => {
    if (selectedColumns.length === 0) return
    setIsExporting(true)
    try {
      const response = await api.get(endpoint, {
        params: {
          ...filters,
          columns: selectedColumns,
          ...(selectedIds.length > 0 ? { document_ids: selectedIds } : {}),
        },
        responseType: 'blob',
      })
      const url = window.URL.createObjectURL(new Blob([response.data], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      }))
      const link = document.createElement('a')
      link.href = url
      link.download = `${filenamePrefix}_${new Date().toISOString().slice(0, 10)}.xlsx`
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
      toast({ title: 'Excel export downloaded' })
    } catch {
      toast({ variant: 'destructive', title: 'Failed to export Excel file' })
    } finally {
      setIsExporting(false)
    }
  }

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" disabled={isExporting}>
          <Icon icon={isExporting ? 'solar:refresh-bold' : 'solar:file-download-bold'} className={`mr-2 h-4 w-4 ${isExporting ? 'animate-spin' : ''}`} />
          {isExporting ? 'Exporting...' : 'Export Excel'}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-72">
        <p className="font-medium">Select export columns</p>
        <p className="mb-3 text-xs text-muted-foreground">
          {selectedIds.length > 0
            ? `Exporting ${selectedIds.length} selected row${selectedIds.length === 1 ? '' : 's'}.`
            : 'The current filters will be applied.'}
        </p>
        <div className="max-h-72 space-y-2 overflow-y-auto">
          {groups.map((group) => (
            <div key={group}>
              <p className="mb-2 mt-3 text-xs font-semibold uppercase text-muted-foreground first:mt-0">{group}</p>
              <div className="space-y-2">
                {columns.filter((column) => column.group === group).map((column) => (
                  <label key={column.key} className="flex cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                      checked={selectedColumns.includes(column.key)}
                      onCheckedChange={(checked) => setSelectedColumns((current) => checked
                        ? [...current, column.key]
                        : current.filter((key) => key !== column.key))}
                    />
                    {column.label}
                  </label>
                ))}
              </div>
            </div>
          ))}
        </div>
        <Button className="mt-4 w-full" onClick={download} disabled={selectedColumns.length === 0 || isExporting}>
          Download {selectedColumns.length} column{selectedColumns.length === 1 ? '' : 's'}
        </Button>
      </PopoverContent>
    </Popover>
  )
}
