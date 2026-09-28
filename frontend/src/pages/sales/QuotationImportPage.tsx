import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'quotation_number', required: false, description: 'Groups rows into one quotation. Rows sharing this number become line items. Blank = single-line quotation. Auto-generated if empty.' },
  { name: 'customer_code', required: true, description: 'Customer code (preferred). Falls back to customer_name or customer_id.' },
  { name: 'customer_name', required: false, description: 'Customer name (used if customer_code is blank).' },
  { name: 'quotation_date', required: false, description: 'Quote date (M/D/YYYY or YYYY-MM-DD). Default: today.' },
  { name: 'valid_until', required: false, description: 'Expiration / valid-until date.' },
  { name: 'customer_reference', required: false, description: 'Customer PO / reference number.' },
  { name: 'internal_reference', required: false, description: 'Project, field service, or document numbers. Separate multiple references with commas.' },
  { name: 'credit_terms', required: false, description: 'Customer credit terms (e.g. Net 30 days).' },
  { name: 'attention_to', required: false, description: 'Attention To — primary contact name for this quote.' },
  { name: 'contact_email', required: false, description: 'Contact email address.' },
  { name: 'contact_phone', required: false, description: 'Contact phone number.' },
  { name: 'billing_contact', required: false, description: "Billing contact name. Auto-creates the contact if it doesn't exist." },
  { name: 'shipping_contact', required: false, description: 'Shipping / delivery contact name. Auto-creates if not found.' },
  { name: 'billing_address_snapshot', required: false, description: 'Billing address (free text).' },
  { name: 'shipping_address_snapshot', required: false, description: 'Shipping / delivery address (free text).' },
  { name: 'salesperson', required: false, description: 'Salesperson name.' },
  { name: 'delivery_lead_time', required: false, description: 'Delivery lead time (e.g. 2 weeks).' },
  { name: 'shipping_method', required: false, description: 'Shipping method (e.g. Express, Standard).' },
  { name: 'shipping_terms', required: false, description: 'Shipping terms (e.g. FOB, CIF).' },
  { name: 'validity', required: false, description: 'Validity description (e.g. 30 days).' },
  { name: 'invoice_number', required: false, description: 'Link to existing invoice(s). Use comma-separated for multiple: INV-001, INV-002' },
  { name: 'case_no', required: false, description: 'Medical case no (e.g. 1st, 2nd, 3rd). Auto-creates a Medical Case.' },
  { name: 'case_tag', required: false, description: 'Case tag: FOC, GEH, HTx 60s, Clinic, CCK, etc. Auto-creates or finds a Bonus Scheme. FOC = Free of Charge.' },
  { name: 'case_date', required: false, description: 'Date of the medical case (M/D/YYYY or YYYY-MM-DD).' },
  { name: 'patient_name', required: false, description: 'Patient name for the case.' },
  { name: 'delivered_to', required: false, description: 'Delivered to (e.g. OT, Clinic, MENH).' },
  { name: 'other_note', required: false, description: 'Other case notes.' },
  { name: 'notes', required: false, description: 'Quotation notes (shown on document).' },
  { name: 'terms', required: false, description: 'Payment terms (e.g. Net 30).' },
  { name: 'discount_amount', required: false, description: 'Header-level discount amount. Default: 0' },
  { name: 'product_sku', required: false, description: 'Line item: product SKU (links to a product). Optional.' },
  { name: 'model_number', required: false, description: 'Line item: model / part number. Auto-filled from product if SKU given.' },
  { name: 'description', required: true, description: 'Line item: description. Required for each line.' },
  { name: 'product_notes', required: false, description: 'Line item: product notes (free text).' },
  { name: 'quantity', required: true, description: 'Line item: quantity. Default: 1' },
  { name: 'unit_price', required: true, description: 'Line item: unit price. Default: 0 (uses product price if SKU given).' },
  { name: 'tax_rate', required: false, description: 'Line item: tax rate %. Default: 0' },
  { name: 'line_discount_amount', required: false, description: 'Line item: discount amount. Default: 0' },
]

const csvValue = (value: string) => {
  if (/[",\n]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}

export function QuotationImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const { data: customers } = useQuery({
    queryKey: ['customers-list-for-template'],
    queryFn: async () => {
      const response = await api.get('/customers?per_page=5')
      return response.data.data || response.data || []
    },
  })

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/quotations/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['quotations'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      if (data.created > 0 && errorCount === 0) {
        toast({ title: 'Import successful!', description: `${data.created} quotation(s) created.` })
      } else if (data.created > 0 && errorCount > 0) {
        toast({ title: 'Import completed with errors', description: `${data.created} created, ${errorCount} errors.` })
      } else {
        toast({ title: 'Import failed', description: 'No quotations were imported. Check the errors below.', variant: 'destructive' })
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
    const sampleCustomerCode = customers?.[0]?.code || 'YOUR-CUST-CODE'
    const sampleCustomerName = customers?.[0]?.name || 'Your Customer Name'

    const sampleRows = [
      ['QUO-2026-0001', sampleCustomerCode, sampleCustomerName, '7/10/2026', '8/9/2026', 'PO-123', 'PROJECT-001, FST-002', 'Net 30 days', 'Dr. Tan', 'contact@hospital.com', '+65 9123 4567', 'Billing Dept', 'Dr. Lee', '100 Pasir Panjang Rd, Singapore 118530', '50 Science Park Rd, Singapore 117588', 'John Sales', '2 weeks', 'Express', 'FOB', '30 days', 'INV-2026-0001', 'Please deliver by end of month', 'Net 30', '0', 'SKU-001', 'Widget A', '10', '50.00', '10', '0'],
      ['QUO-2026-0001', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'SKU-002', 'Widget B', '5', '20.00', '10', '0'],
    ].map((row) => row.map(csvValue).join(','))

    const csvContent = `${headers}\n${sampleRows.join('\n')}`
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'quotation_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it to import quotations.' })
  }

  const reset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Quotations"
        subtitle="Bulk import quotations from a CSV file"
        backTo="/quotations"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="One row per line item. Rows sharing a quotation_number are grouped into one quotation. Leave it blank for single-line quotations (auto-generated)."
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
                Import Quotations
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              errors={importResult.errors || []}
              onReset={reset}
              doneTo="/quotations"
              doneLabel="View Quotations"
              doneIcon="solar:document-text-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default QuotationImportPage