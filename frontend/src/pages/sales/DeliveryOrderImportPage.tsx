import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Progress } from '@/components/ui/progress'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import {
  ImportDropzone,
  ImportFieldTable,
  ImportPageHeader,
  ImportResults,
  ImportStepCard,
  type ImportRowIssue,
  type ImportTemplateColumn,
} from '@/components/import/ImportPageKit'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface ImportResult {
  created: number
  errors: Array<{ row: number; message: string; group?: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'delivery_order_number', required: false, description: 'Groups rows into one DO. Blank = auto-generated.' },
  { name: 'customer_code', required: true, description: 'Customer code (preferred). Falls back to customer_name.' },
  { name: 'customer_name', required: false, description: 'Customer name (used if customer_code is blank).' },
  { name: 'delivery_date', required: false, description: 'Delivery date (M/D/YYYY or YYYY-MM-DD). Default: today.' },
  { name: 'expected_date', required: false, description: 'Expected delivery date.' },
  { name: 'carrier', required: false, description: 'Carrier name (e.g. DHL, FedEx).' },
  { name: 'tracking_number', required: false, description: 'Tracking number.' },
  { name: 'shipping_address', required: false, description: 'Shipping address. Auto-filled from customer if blank.' },
  { name: 'attention_to', required: false, description: 'Attention to (e.g. Warehouse Manager).' },
  { name: 'po_number', required: false, description: 'Purchase order number.' },
  { name: 'salesperson', required: false, description: 'Salesperson name.' },
  { name: 'invoice_number', required: false, description: 'Linked invoice number.' },
  { name: 'quotation_number', required: false, description: 'Linked quotation number.' },
  { name: 'case_no', required: false, description: 'Medical case no (e.g. 1st, 2nd, 3rd). Auto-creates a Medical Case.' },
  { name: 'case_tag', required: false, description: 'Case tag: FOC, GEH, HTx 60s, Clinic, CCK, etc. Auto-creates or finds a Bonus Scheme. FOC = Free of Charge.' },
  { name: 'case_date', required: false, description: 'Date of the medical case (M/D/YYYY or YYYY-MM-DD).' },
  { name: 'delivered_to', required: false, description: 'Delivered to (e.g. OT, Clinic, MENH).' },
  { name: 'other_note', required: false, description: 'Other case notes.' },
  { name: 'product_sku', required: false, description: 'Product SKU (links to product).' },
  { name: 'model_number', required: false, description: 'Model / part number. Auto-filled from product if SKU given.' },
  { name: 'description', required: false, description: 'Line item description.' },
  { name: 'product_notes', required: false, description: 'Product notes (free text).' },
  { name: 'quantity', required: true, description: 'Quantity to deliver.' },
  { name: 'unit', required: false, description: 'Unit of measure. Default: pcs.' },
  { name: 'unit_price', required: false, description: 'Unit price per item.' },
  { name: 'patient_name', required: false, description: 'Patient name for this line item.' },
  { name: 'lot_number', required: false, description: 'Lot or batch number for this line item.' },
  { name: 'expiry_date', required: false, description: 'Lot expiry date (M/D/YYYY or YYYY-MM-DD).' },
]

const csvValue = (value: string) => {
  if (/[",\n]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}

export function DeliveryOrderImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      const res = await api.post('/sales/delivery-orders/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: (data) => {
      setImportResult({ created: data.created, errors: data.errors || [] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({
        title: 'Import complete',
        description: `${data.created} delivery order(s) created.`,
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Import failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const downloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name)
    const example = TEMPLATE_COLUMNS.map((column) => {
      switch (column.name) {
        case 'customer_code': return 'CUST-001'
        case 'delivery_date': return '2026-07-14'
        case 'expected_date': return '2026-07-15'
        case 'carrier': return 'DHL'
        case 'quantity': return '10'
        case 'unit': return 'pcs'
        case 'unit_price': return '50.00'
        case 'patient_name': return 'Jane Doe'
        case 'lot_number': return 'LOT-2026-001'
        case 'expiry_date': return '2027-07-15'
        default: return ''
      }
    })
    const csv = [headers.join(','), example.map(csvValue).join(',')].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'delivery-order-import-template.csv'
    a.click()
    URL.revokeObjectURL(url)
    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it in Step 2.' })
  }

  const reset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  const errors: ImportRowIssue[] = (importResult?.errors || []).map((error) => ({
    row: error.row,
    message: error.group ? `${error.group}: ${error.message}` : error.message,
  }))

  return (
    <div className="space-y-6 p-6">
      <ImportPageHeader
        title="Import Delivery Orders"
        subtitle="Upload a CSV file to create delivery orders in bulk. Each row group (by delivery_order_number) becomes one delivery order with line items."
        backTo="/delivery-orders"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Start by downloading the CSV template with all supported delivery order columns."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={downloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} hint="Rows sharing a delivery_order_number are combined into one delivery order." />
        </ImportStepCard>

        <ImportStepCard
          step={2}
          title="Upload CSV File"
          description="Fill in the template and upload it here."
          icon="solar:upload-minimalistic-bold-duotone"
          tone="bg-blue-500/10"
          glyph="text-blue-600 dark:text-blue-400"
        >
          <ImportDropzone
            selectedFile={selectedFile}
            onFile={(file) => { setSelectedFile(file); setImportResult(null) }}
            onClear={reset}
            accept=".csv,.txt,text/csv"
            allowedExtensions={['.csv', '.txt']}
          />

          {selectedFile && !importResult && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={importMutation.isPending}
                disabled={importMutation.isPending}
                onClick={() => importMutation.mutate(selectedFile)}
              >
                Import Delivery Orders
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              errors={errors}
              onReset={reset}
              doneTo="/delivery-orders"
              doneLabel="View Delivery Orders"
              doneIcon="solar:box-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default DeliveryOrderImportPage