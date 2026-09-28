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
  type ImportTemplateColumn,
} from '@/components/import/ImportPageKit'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface ImportResult {
  created: number
  updated?: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'invoice_number', required: false, description: 'Groups rows into one invoice. Rows sharing this number become line items. Blank = single-line invoice. Auto-generated if empty.' },
  { name: 'status', required: false, description: 'draft (quotation) or sent (posts the invoice). Default: draft' },
  { name: 'customer_code', required: true, description: 'Customer code (preferred). Falls back to customer_name or customer_id.' },
  { name: 'customer_name', required: false, description: 'Customer name (used if customer_code is blank).' },
  { name: 'invoice_date', required: false, description: 'Issue date (DD/MM/YYYY, M/D/YYYY, or YYYY-MM-DD). Default: today.' },
  { name: 'due_date', required: false, description: 'Due / valid-until date.' },
  { name: 'po_number', required: false, description: 'Customer PO reference.' },
  { name: 'customer_reference', required: false, description: 'Customer reference number.' },
  { name: 'internal_reference', required: false, description: 'Internal reference note.' },
  { name: 'case_number', required: false, description: 'Case number / case reference.' },
  { name: 'pr_no', required: false, description: 'Purchase requisition number.' },
  { name: 'contract_no', required: false, description: 'Contract number.' },
  { name: 'buyer', required: false, description: 'Buyer name (matched to contacts).' },
  { name: 'plant_code', required: false, description: 'Plant code.' },
  { name: 'company_code', required: false, description: 'Company code.' },
  { name: 'date_of_case', required: false, description: 'Case date (DD/MM/YYYY, M/D/YYYY, or YYYY-MM-DD).' },
  { name: 'case_location', required: false, description: 'Case / site location.' },
  { name: 'patient_name', required: false, description: 'Patient name.' },
  { name: 'case_no', required: false, description: 'Medical case no (e.g. 1st, 2nd, 3rd). Auto-creates a Medical Case linked to this invoice.' },
  { name: 'case_tag', required: false, description: 'Case tag: FOC, GEH, HTx 60s, Clinic, CCK, etc. Auto-creates or finds a Bonus Scheme. FOC = Free of Charge.' },
  { name: 'case_date', required: false, description: 'Date of the medical case (M/D/YYYY or YYYY-MM-DD).' },
  { name: 'delivered_to', required: false, description: 'Delivered to (e.g. OT, Clinic, MENH).' },
  { name: 'other_note', required: false, description: 'Other case notes.' },
  { name: 'cost_center_id', required: false, description: 'Cost center reference.' },
  { name: 'delivery_date', required: false, description: 'Delivery date (DD/MM/YYYY, M/D/YYYY, or YYYY-MM-DD).' },
  { name: 'doctor', required: false, description: 'Doctor name (matched to contacts).' },
  { name: 'requestor', required: false, description: 'Requestor name (matched to contacts).' },
  { name: 'billing_contact', required: false, description: 'Billing address contact name (matched to contacts).' },
  { name: 'shipping_contact', required: false, description: 'Delivery address contact name (matched to contacts).' },
  { name: 'attention_to', required: false, description: 'Contact person at customer.' },
  { name: 'contact_email', required: false, description: 'Contact email.' },
  { name: 'contact_phone', required: false, description: 'Contact phone.' },
  { name: 'billing_address_snapshot', required: false, description: 'Billing address (free text).' },
  { name: 'shipping_address_snapshot', required: false, description: 'Shipping address (free text).' },
  { name: 'billing_label', required: false, description: 'Billing address: label.' },
  { name: 'billing_street', required: false, description: 'Billing address: street.' },
  { name: 'billing_street2', required: false, description: 'Billing address: second street line.' },
  { name: 'billing_city', required: false, description: 'Billing address: city.' },
  { name: 'billing_state', required: false, description: 'Billing address: state.' },
  { name: 'billing_zip', required: false, description: 'Billing address: ZIP or postal code.' },
  { name: 'billing_country', required: false, description: 'Billing address: country.' },
  { name: 'shipping_label', required: false, description: 'Shipping address: label.' },
  { name: 'shipping_street', required: false, description: 'Shipping address: street.' },
  { name: 'shipping_street2', required: false, description: 'Shipping address: second street line.' },
  { name: 'shipping_city', required: false, description: 'Shipping address: city.' },
  { name: 'shipping_state', required: false, description: 'Shipping address: state.' },
  { name: 'shipping_zip', required: false, description: 'Shipping address: ZIP or postal code.' },
  { name: 'shipping_country', required: false, description: 'Shipping address: country.' },
  { name: 'company_registration_number', required: false, description: 'Customer company reg. no.' },
  { name: 'tax_registration_number', required: false, description: 'Customer tax reg. no.' },
  { name: 'notes', required: false, description: 'Invoice notes (shown on document).' },
  { name: 'terms', required: false, description: 'Payment terms (e.g. Net 30).' },
  { name: 'discount_amount', required: false, description: 'Header-level discount amount. Default: 0' },
  { name: 'product_sku', required: false, description: 'Line item: product SKU (links to a product). Optional.' },
  { name: 'model_number', required: false, description: 'Line item: model / part number. Auto-filled from product if SKU given.' },
  { name: 'description', required: true, description: 'Line item: description. Required for each line.' },
  { name: 'product_notes', required: false, description: 'Line item: product notes (free text).' },
  { name: 'lot_number', required: false, description: 'Line item: lot or batch number.' },
  { name: 'expiry_date', required: false, description: 'Line item: expiry date (DD/MM/YYYY, M/D/YYYY, or YYYY-MM-DD).' },
  { name: 'quantity', required: true, description: 'Line item: quantity. Default: 1' },
  { name: 'unit_price', required: true, description: 'Line item: unit price. Explicit 0 is preserved; blank uses the product price when an SKU is provided.' },
  { name: 'tax_rate', required: false, description: 'Line item: tax rate %. Default: 0' },
  { name: 'line_discount_amount', required: false, description: 'Line item: discount amount. Default: 0' },
]

const csvValue = (value: string) => {
  if (/[",\n]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}

export function InvoiceImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/invoices/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      queryClient.invalidateQueries({ queryKey: ['sales-quotes'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      if (data.created > 0 && errorCount === 0) {
        toast({ title: 'Import successful!', description: `${data.created} invoice(s) created.` })
      } else if (data.created > 0 && errorCount > 0) {
        toast({ title: 'Import completed with errors', description: `${data.created} created, ${errorCount} errors.` })
      } else {
        toast({ title: 'Import failed', description: 'No invoices were imported. Check the errors below.', variant: 'destructive' })
      }
    },
    onError: (error: Error) => {
      toast({
        title: 'Import failed',
        description: error.message || 'An error occurred during import.',
        variant: 'destructive',
      })
    },
  })

  const handleDownloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
    const samples: Array<Record<string, string>> = [
      {
        invoice_number: 'QUOTE-001', status: 'draft', customer_code: 'CUST-001', customer_name: 'Acme Corp',
        invoice_date: '1/15/2026', due_date: '2/15/2026', po_number: 'PO-456', customer_reference: 'REF-789',
        internal_reference: 'INT-001', case_number: 'CASE-2026-001', date_of_case: '1/14/2026', case_location: 'Main Site',
        patient_name: 'John Smith', case_no: '1st', case_tag: 'FOC', case_date: '1/14/2026', delivered_to: 'OT', other_note: 'Clinic visit',
        cost_center_id: 'CC-001', delivery_date: '1/20/2026', doctor: 'Dr. Sarah Lee',
        requestor: 'Jane Doe', billing_contact: 'Acme Billing', shipping_contact: 'Acme Delivery', attention_to: 'John Doe',
        contact_email: 'ap@acme.com', terms: 'Net 30', discount_amount: '0', product_sku: 'SKU-001',
        billing_label: 'Head Office', billing_street: '10 Billing Road', billing_city: 'Singapore', billing_state: 'Singapore',
        billing_zip: '123456', billing_country: 'Singapore', shipping_label: 'Warehouse', shipping_street: '20 Delivery Road',
        shipping_city: 'Singapore', shipping_state: 'Singapore', shipping_zip: '654321', shipping_country: 'Singapore',
        model_number: 'MODEL-001', description: 'Widget A', product_notes: 'Handle with care', lot_number: 'LOT-001',
        expiry_date: '12/31/2027', quantity: '10', unit_price: '50.00', tax_rate: '0', line_discount_amount: '0',
      },
      {
        invoice_number: 'QUOTE-001', product_sku: 'SKU-002', description: 'Widget B', lot_number: 'LOT-002',
        expiry_date: '12/31/2028', quantity: '5', unit_price: '20.00', tax_rate: '0', line_discount_amount: '0',
      },
    ]
    const sampleRows = samples.map((sample) => TEMPLATE_COLUMNS.map((column) => csvValue(sample[column.name] || '')).join(','))

    const csvContent = `${headers}\n${sampleRows.join('\n')}`
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'invoice_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it to import invoices/quotes.' })
  }

  const reset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Invoices / Quotations"
        subtitle="Bulk import invoices or quotations (draft invoices) from a CSV file"
        backTo="/invoices"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="One row per line item. Rows sharing an invoice_number are grouped into one invoice. Leave it blank for single-line invoices (auto-generated)."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={handleDownloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} />
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
          />

          {selectedFile && !importResult && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={importMutation.isPending}
                disabled={importMutation.isPending}
                onClick={() => importMutation.mutate(selectedFile)}
              >
                Import Invoices / Quotations
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              errors={importResult.errors || []}
              onReset={reset}
              doneTo="/invoices"
              doneLabel="View Invoices"
              doneIcon="solar:document-text-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default InvoiceImportPage