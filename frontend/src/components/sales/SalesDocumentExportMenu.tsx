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
  { key: 'id', label: 'Invoice ID', group: 'Invoice' },
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
  { key: 'case_number', label: 'Case Number', group: 'Case Information' },
  { key: 'date_of_case', label: 'Case Date', group: 'Case Information' },
  { key: 'case_location', label: 'Case Location', group: 'Case Information' },
  { key: 'patient_name', label: 'Patient Name', group: 'Case Information' },
  { key: 'cost_center_id', label: 'Cost Center ID', group: 'Case Information' },
  { key: 'delivery_date', label: 'Delivery Date', group: 'Case Information' },
  { key: 'po_number', label: 'PO Number', group: 'References' },
  { key: 'customer_reference', label: 'Customer Reference', group: 'References' },
  { key: 'internal_reference', label: 'Internal Reference', group: 'References' },
  { key: 'pr_no', label: 'PR No', group: 'References' },
  { key: 'contract_no', label: 'Contract No', group: 'References' },
  { key: 'buyer_name', label: 'Buyer', group: 'Case Information' },
  { key: 'plant_code', label: 'Plant Code', group: 'Case Information' },
  { key: 'company_code', label: 'Company Code', group: 'Case Information' },
  { key: 'doctor', label: 'Doctor', group: 'Case Information' },
  { key: 'requestor', label: 'Requestor', group: 'Case Information' },
  { key: 'billing_contact', label: 'Billing Contact', group: 'Case Information' },
  { key: 'shipping_contact', label: 'Shipping Contact', group: 'Case Information' },
  { key: 'billing_label', label: 'Billing Label', group: 'Billing Address' },
  { key: 'billing_street', label: 'Billing Street', group: 'Billing Address' },
  { key: 'billing_street2', label: 'Billing Street 2', group: 'Billing Address' },
  { key: 'billing_city', label: 'Billing City', group: 'Billing Address' },
  { key: 'billing_state', label: 'Billing State', group: 'Billing Address' },
  { key: 'billing_zip', label: 'Billing ZIP', group: 'Billing Address' },
  { key: 'billing_country', label: 'Billing Country', group: 'Billing Address' },
  { key: 'shipping_label', label: 'Shipping Label', group: 'Shipping Address' },
  { key: 'shipping_street', label: 'Shipping Street', group: 'Shipping Address' },
  { key: 'shipping_street2', label: 'Shipping Street 2', group: 'Shipping Address' },
  { key: 'shipping_city', label: 'Shipping City', group: 'Shipping Address' },
  { key: 'shipping_state', label: 'Shipping State', group: 'Shipping Address' },
  { key: 'shipping_zip', label: 'Shipping ZIP', group: 'Shipping Address' },
  { key: 'shipping_country', label: 'Shipping Country', group: 'Shipping Address' },
  { key: 'attention_to', label: 'Attention To', group: 'Contacts' },
  { key: 'contact_email', label: 'Contact Email', group: 'Contacts' },
  { key: 'contact_phone', label: 'Contact Phone', group: 'Contacts' },
  { key: 'payment_terms_snapshot', label: 'Payment Terms', group: 'Terms & Notes' },
  { key: 'notes', label: 'Notes', group: 'Terms & Notes' },
  { key: 'terms', label: 'Terms', group: 'Terms & Notes' },
  { key: 'company_registration_number', label: 'Company Registration Number', group: 'Tax & Registration' },
  { key: 'tax_registration_number', label: 'Tax Registration Number', group: 'Tax & Registration' },
  { key: 'tax_jurisdiction', label: 'Tax Jurisdiction', group: 'Tax & Registration' },
  { key: 'tax_regime', label: 'Tax Regime', group: 'Tax & Registration' },
  { key: 'tax_treatment', label: 'Tax Treatment', group: 'Tax & Registration' },
  { key: 'discount_amount', label: 'Discount Amount', group: 'Financial' },
  { key: 'shipping_cost', label: 'Shipping Cost', group: 'Financial' },
  { key: 'shipping_method', label: 'Shipping Method', group: 'Delivery' },
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
  /** Compact pill styling for inline toolbars. */
  compact?: boolean
}

export function SalesDocumentExportMenu({
  endpoint,
  filenamePrefix,
  columns,
  filters = {},
  selectedIds = [],
  compact = false,
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
        <Button
          variant="outline"
          disabled={isExporting}
          className={compact ? 'h-8 rounded-full px-3 text-xs' : undefined}
        >
          <Icon
            icon={isExporting ? 'solar:refresh-bold' : 'solar:file-download-bold'}
            className={
              compact
                ? `mr-1.5 h-3.5 w-3.5 ${isExporting ? 'animate-spin' : ''}`
                : `mr-2 h-4 w-4 ${isExporting ? 'animate-spin' : ''}`
            }
          />
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
