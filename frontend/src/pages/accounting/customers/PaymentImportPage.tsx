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

interface PaymentImportError {
  row: number
  message: string
}

interface PaymentImportWarning {
  row: number
  message: string
}

interface PaymentImportResult {
  created: number
  warnings: PaymentImportWarning[]
  errors: PaymentImportError[]
}

const TEMPLATE_COLUMNS: Array<{ name: string; required: boolean; description: string }> = [
  { name: 'payment_no', required: false, description: 'Your own payment number (e.g. RCV-00123). Leave blank to auto-generate PAY/YYYY/MM/SEQ. Numbers from deleted payments can be reused.' },
  { name: 'payment_date', required: true, description: 'Date the payment was received (YYYY-MM-DD, M/D/YYYY, or DD/MM/YYYY).' },
  { name: 'customer_code', required: true, description: 'Customer code. The customer is matched on this column first.' },
  { name: 'customer_name', required: false, description: 'Fallback when customer_code is blank. Matched by name.' },
  { name: 'customer_id', required: false, description: 'Numeric customer id. Used when both code and name are blank.' },
  { name: 'invoice_no', required: false, description: 'Invoice number to allocate this payment against. Leave blank for a standalone (unallocated) payment.' },
  { name: 'amount', required: true, description: 'Payment amount. Must be greater than zero and not exceed the invoice balance.' },
  { name: 'method', required: true, description: 'cash, bank_transfer, card, check, or other (bank/transfer also accepted).' },
  { name: 'reference', required: false, description: 'Bank transaction id, cheque number, or any external reference. Used to detect duplicate imports.' },
  { name: 'currency', required: false, description: 'Three-letter ISO currency code. Defaults to the tenant base currency.' },
  { name: 'notes', required: false, description: 'Free-text notes shown on the payment record (also accepts note / remarks).' },
]

const TEMPLATE_SAMPLES: Array<Record<string, string>> = [
  {
    payment_no: '',
    payment_date: '2026-08-20',
    customer_code: 'CUST-001',
    customer_name: 'Acme Corp',
    customer_id: '',
    invoice_no: 'INV-2026-0001',
    amount: '500.00',
    method: 'bank_transfer',
    reference: 'TXN-2026-08-001',
    currency: 'USD',
    notes: 'August wire receipt (number auto-generated)',
  },
  {
    payment_no: 'RCV-00123',
    payment_date: '2026-08-21',
    customer_code: '',
    customer_name: 'Globex Sdn Bhd',
    customer_id: '',
    invoice_no: '',
    amount: '1,250.50',
    method: 'cash',
    reference: 'CHQ-0098',
    currency: 'USD',
    notes: 'Custom number kept as-is; unallocated payment',
  },
]

const csvValue = (v: string) => {
  if (/[",\n]/.test(v)) {
    return `"${v.replace(/"/g, '""')}"`
  }
  return v
}

export function PaymentImportPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [dragActive, setDragActive] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<PaymentImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/payments/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as PaymentImportResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['customer-payments'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      const warningCount = data.warnings?.length || 0
      if (data.created > 0 && errorCount === 0) {
        toast({
          title: 'Import successful!',
          description: warningCount > 0
            ? `${data.created} payment(s) created, ${warningCount} duplicate(s) skipped.`
            : `${data.created} payment(s) created.`,
        })
      } else if (data.created > 0 && errorCount > 0) {
        toast({
          title: 'Import completed with errors',
          description: `${data.created} created, ${errorCount} errors.`,
          variant: 'destructive',
        })
      } else {
        toast({
          title: 'Import failed',
          description: 'No payments were imported. Check the errors below.',
          variant: 'destructive',
        })
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
    const sampleRows = TEMPLATE_SAMPLES.map(
      (sample) => TEMPLATE_COLUMNS.map((column) => csvValue(sample[column.name] ?? '')).join(','),
    )
    const csvContent = `${headers}\n${sampleRows.join('\n')}`
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'payment_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it to import payments.' })
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
        <Button variant="ghost" size="icon" onClick={() => navigate('/accounting/customers/payments')}>
          <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Customer Payments</h1>
          <p className="text-muted-foreground">
            Bulk import payment records from a CSV file. New payments are created in <code>draft</code> status and can be reviewed before posting.
          </p>
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
                One row per payment. Leave <strong>payment_no</strong> blank to auto-generate <code>PAY/YYYY/MM/SEQ</code>,
                or fill it with your own number — numbers from previously deleted payments can be reused.
                Leave <strong>invoice_no</strong> blank for a standalone (unallocated) payment.
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
                      Import Payments
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
                <div className="grid grid-cols-3 gap-3">
                  <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-green-600">{importResult.created}</div>
                    <div className="text-sm text-muted-foreground">Created</div>
                  </div>
                  <div className="text-center p-4 bg-yellow-50 dark:bg-yellow-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-yellow-600">{importResult.warnings?.length || 0}</div>
                    <div className="text-sm text-muted-foreground">Duplicates</div>
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

                {importResult.warnings && importResult.warnings.length > 0 && (
                  <Alert>
                    <Icon icon="solar:info-circle-bold" className="h-4 w-4" />
                    <AlertTitle>Skipped (Possible Duplicates)</AlertTitle>
                    <AlertDescription>
                      <div className="mt-2 max-h-[160px] overflow-y-auto space-y-1 text-sm">
                        {importResult.warnings.map((w, idx) => (
                          <div key={idx} className="flex gap-2">
                            <span className="font-medium">Row {w.row}:</span>
                            <span>{w.message}</span>
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
                  <Button onClick={() => navigate('/accounting/customers/payments')} className="flex-1">
                    <Icon icon="solar:wallet-money-bold-duotone" className="mr-2 h-4 w-4" />
                    View Payments
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

export default PaymentImportPage
