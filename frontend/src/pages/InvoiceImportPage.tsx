import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

interface ImportResult {
  created: number
  updated?: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS = [
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
  { name: 'date_of_case', required: false, description: 'Case date (DD/MM/YYYY, M/D/YYYY, or YYYY-MM-DD).' },
  { name: 'case_location', required: false, description: 'Case / site location.' },
  { name: 'patient_name', required: false, description: 'Patient name.' },
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

// Quote any value containing commas, quotes, or newlines per RFC 4180.
const csvValue = (v: string) => {
  if (/[",\n]/.test(v)) {
    return `"${v.replace(/"/g, '""')}"`
  }
  return v
}

export function InvoiceImportPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [dragActive, setDragActive] = useState(false)
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

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      const file = e.dataTransfer.files[0]
      if (file.type === 'text/csv' || file.name.endsWith('.csv')) {
        setSelectedFile(file)
        setImportResult(null)
      } else {
        toast({ title: 'Invalid file type', description: 'Please upload a CSV file.', variant: 'destructive' })
      }
    }
  }, [toast])

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      setSelectedFile(e.target.files[0])
      setImportResult(null)
    }
  }

  const handleDownloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((col) => col.name).join(',')
    // Two rows sharing invoice_number => one invoice with two line items.
    const samples: Array<Record<string, string>> = [
      {
        invoice_number: 'QUOTE-001', status: 'draft', customer_code: 'CUST-001', customer_name: 'Acme Corp',
        invoice_date: '1/15/2026', due_date: '2/15/2026', po_number: 'PO-456', customer_reference: 'REF-789',
        internal_reference: 'INT-001', case_number: 'CASE-2026-001', date_of_case: '1/14/2026', case_location: 'Main Site',
        patient_name: 'John Smith', cost_center_id: 'CC-001', delivery_date: '1/20/2026', doctor: 'Dr. Sarah Lee',
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

  const handleImport = () => {
    if (selectedFile) {
      importMutation.mutate(selectedFile)
    }
  }

  const handleReset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate('/invoices')}>
          <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Invoices / Quotations</h1>
          <p className="text-muted-foreground">Bulk import invoices or quotations (draft invoices) from a CSV file</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 1: Download Template
              </CardTitle>
              <CardDescription>
                One row per line item. Rows sharing an <strong>invoice_number</strong> are grouped into one invoice.
                Leave <strong>status</strong> as <code>draft</code> to create quotations; set to <code>sent</code> to post invoices.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button onClick={handleDownloadTemplate} className="w-full" variant="outline">
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Download Import Template
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />
                Template Fields
              </CardTitle>
              <CardDescription>Required fields are marked with an asterisk (*).</CardDescription>
            </CardHeader>
            <CardContent className="max-h-[400px] overflow-y-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[170px]">Field</TableHead>
                    <TableHead>Description</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {TEMPLATE_COLUMNS.map((col) => (
                    <TableRow key={col.name}>
                      <TableCell className="font-medium">
                        {col.name}
                        {col.required && <span className="text-destructive ml-1">*</span>}
                      </TableCell>
                      <TableCell className="text-muted-foreground text-sm">{col.description}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 2: Upload CSV File
              </CardTitle>
              <CardDescription>Fill in the template and upload it here.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div
                className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                  dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'
                } ${selectedFile ? 'bg-muted/50' : ''}`}
                onDragEnter={handleDrag}
                onDragLeave={handleDrag}
                onDragOver={handleDrag}
                onDrop={handleDrop}
              >
                {selectedFile ? (
                  <div className="space-y-3">
                    <Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" />
                    <div>
                      <p className="font-medium">{selectedFile.name}</p>
                      <p className="text-sm text-muted-foreground">{(selectedFile.size / 1024).toFixed(2)} KB</p>
                    </div>
                    <Button variant="ghost" size="sm" onClick={handleReset}>
                      <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                      Remove
                    </Button>
                  </div>
                ) : (
                  <div className="space-y-3">
                    <Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" />
                    <div>
                      <p className="font-medium">Drag and drop your CSV file here</p>
                      <p className="text-sm text-muted-foreground">or click the button below to browse</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                      <label className="cursor-pointer">
                        <Icon icon="solar:folder-open-bold" className="mr-2 h-4 w-4" />
                        Browse Files
                        <input type="file" accept=".csv,text/csv" onChange={handleFileSelect} className="hidden" />
                      </label>
                    </Button>
                  </div>
                )}
              </div>

              {selectedFile && !importResult && (
                <Button onClick={handleImport} className="w-full" disabled={importMutation.isPending}>
                  {importMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Importing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
                      Import Invoices
                    </>
                  )}
                </Button>
              )}

              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </CardContent>
          </Card>

          {importResult && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon
                    icon={importResult.created > 0 ? 'solar:check-circle-bold' : 'solar:close-circle-bold'}
                    className={`h-5 w-5 ${importResult.created > 0 ? 'text-green-500' : 'text-destructive'}`}
                  />
                  Import Results
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-green-600">{importResult.created}</div>
                    <div className="text-sm text-muted-foreground">Created</div>
                  </div>
                  <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-red-600">{importResult.errors.length}</div>
                    <div className="text-sm text-muted-foreground">Errors</div>
                  </div>
                </div>

                {importResult.errors.length > 0 && (
                  <Alert variant="destructive">
                    <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
                    <AlertTitle>Import Errors</AlertTitle>
                    <AlertDescription>
                      <div className="mt-2 max-h-[200px] overflow-y-auto space-y-1 text-sm">
                        {importResult.errors.map((err, idx) => (
                          <div key={idx} className="flex gap-2">
                            <span className="font-medium">Row {err.row}:</span>
                            <span>{err.message}</span>
                          </div>
                        ))}
                      </div>
                    </AlertDescription>
                  </Alert>
                )}

                <div className="flex gap-3">
                  <Button variant="outline" onClick={handleReset} className="flex-1">
                    <Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />
                    Import Another File
                  </Button>
                  <Button onClick={() => navigate('/invoices')} className="flex-1">
                    <Icon icon="solar:bill-list-bold-duotone" className="mr-2 h-4 w-4" />
                    View Invoices
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

export default InvoiceImportPage
