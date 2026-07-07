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
  updated: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS = [
  { name: 'code', required: false, description: 'Customer ID — any format (e.g., CUST-001, C001, VC0026). Auto-generated if empty' },
  { name: 'name', required: true, description: 'Customer name (required)' },
  { name: 'type', required: false, description: 'individual or company (lowercase)' },
  { name: 'category', required: false, description: 'Customer category name (e.g., VIP, Wholesale). Must already exist' },
  { name: 'contact_name', required: false, description: 'Contact person name (creates a contact record)' },
  { name: 'contact_email', required: false, description: 'Contact person email' },
  { name: 'contact_phone', required: false, description: 'Contact person phone' },
  { name: 'email', required: false, description: 'Email address' },
  { name: 'phone', required: false, description: 'Phone number' },
  { name: 'mobile', required: false, description: 'Mobile number' },
  { name: 'company', required: false, description: 'Company name' },
  { name: 'tax_number', required: false, description: 'Tax or VAT number' },
  { name: 'website', required: false, description: 'Website URL' },
  { name: 'billing_address', required: false, description: 'Billing address' },
  { name: 'shipping_address', required: false, description: 'Shipping address' },
  { name: 'credit_limit', required: false, description: 'Credit limit (number)' },
  { name: 'payment_terms', required: false, description: 'Payment terms in days' },
  { name: 'notes', required: false, description: 'Notes' },
]

export function CustomerImportPage() {
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
      const response = await api.post('/customers/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      const totalProcessed = data.created + (data.updated || 0)
      const parts: string[] = []
      if (data.created > 0) parts.push(`${data.created} created`)
      if (data.updated > 0) parts.push(`${data.updated} updated`)
      if (errorCount > 0) parts.push(`${errorCount} errors`)
      if (totalProcessed > 0 && errorCount === 0) {
        toast({
          title: 'Import successful!',
          description: `${parts.join(', ')}.`,
        })
      } else if (totalProcessed > 0 && errorCount > 0) {
        toast({
          title: 'Import completed with errors',
          description: `${parts.join(', ')}.`,
          variant: 'default',
        })
      } else if (totalProcessed === 0) {
        toast({
          title: 'Import failed',
          description: 'No customers were imported. Please check the errors below.',
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
        toast({
          title: 'Invalid file type',
          description: 'Please upload a CSV file.',
          variant: 'destructive',
        })
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
    const sampleRow = [
      'VC0026',
      'Acme Corp',
      'company',
      'VIP',
      'John Doe',
      'john@acme.com',
      '5551112222',
      'billing@acme.com',
      '5551234567',
      '5559876543',
      'Acme Corporation',
      'TAX-12345',
      'https://acme.com',
      '123 Main St, Singapore 12345',
      '456 Office Rd, Singapore 67890',
      '10000',
      '30',
      'Sample customer',
    ].join(',')

    const csvContent = `${headers}\n${sampleRow}`
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'customer_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    toast({
      title: 'Template downloaded',
      description: 'Fill in the template and upload it to import customers.',
    })
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
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate('/customers')}>
          <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Customers</h1>
          <p className="text-muted-foreground">Bulk import customers from a CSV file</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        {/* Left Column - Instructions & Template */}
        <div className="space-y-6">
          {/* Download Template Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 1: Download Template
              </CardTitle>
              <CardDescription>
                Start by downloading our CSV template. It includes all the fields you need.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button onClick={handleDownloadTemplate} className="w-full" variant="outline">
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Download Import Template
              </Button>
            </CardContent>
          </Card>

          {/* Template Fields Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />
                Template Fields
              </CardTitle>
              <CardDescription>
                Required fields are marked with an asterisk (*). The code field accepts any format.
              </CardDescription>
            </CardHeader>
            <CardContent className="max-h-[400px] overflow-y-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[150px]">Field</TableHead>
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
                      <TableCell className="text-muted-foreground text-sm">
                        {col.description}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        {/* Right Column - Upload */}
        <div className="space-y-6">
          {/* Upload Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 2: Upload CSV File
              </CardTitle>
              <CardDescription>
                Fill in the template and upload it here.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Dropzone */}
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
                      <p className="text-sm text-muted-foreground">
                        {(selectedFile.size / 1024).toFixed(2)} KB
                      </p>
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
                        <input
                          type="file"
                          accept=".csv,text/csv"
                          onChange={handleFileSelect}
                          className="hidden"
                        />
                      </label>
                    </Button>
                  </div>
                )}
              </div>

              {/* Import Button */}
              {selectedFile && !importResult && (
                <Button
                  onClick={handleImport}
                  className="w-full"
                  disabled={importMutation.isPending}
                >
                  {importMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Importing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
                      Import Customers
                    </>
                  )}
                </Button>
              )}

              {/* Progress */}
              {importMutation.isPending && (
                <Progress value={undefined} className="w-full" />
              )}
            </CardContent>
          </Card>

          {/* Import Results Card */}
          {importResult && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon
                    icon={(importResult.created > 0 || (importResult.updated || 0) > 0) ? "solar:check-circle-bold" : "solar:close-circle-bold"}
                    className={`h-5 w-5 ${(importResult.created > 0 || (importResult.updated || 0) > 0) ? 'text-green-500' : 'text-destructive'}`}
                  />
                  Import Results
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                {/* Summary */}
                <div className="grid grid-cols-3 gap-4">
                  <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-green-600">{importResult.created}</div>
                    <div className="text-sm text-muted-foreground">Created</div>
                  </div>
                  <div className="text-center p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-blue-600">{importResult.updated || 0}</div>
                    <div className="text-sm text-muted-foreground">Updated</div>
                  </div>
                  <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-red-600">{importResult.errors.length}</div>
                    <div className="text-sm text-muted-foreground">Errors</div>
                  </div>
                </div>

                {/* Errors List */}
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

                {/* Actions */}
                <div className="flex gap-3">
                  <Button variant="outline" onClick={handleReset} className="flex-1">
                    <Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />
                    Import Another File
                  </Button>
                  <Button onClick={() => navigate('/customers')} className="flex-1">
                    <Icon icon="solar:users-bold" className="mr-2 h-4 w-4" />
                    View Customers
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
