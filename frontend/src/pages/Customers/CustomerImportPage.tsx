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

interface StageResult {
  batch_id: string
  total_rows: number
  counts: {
    valid: number
    warning: number
    duplicate: number
    candidate: number
    error: number
  }
  code_conflicts: Array<{ row: number; code: string }>
  issues: Array<{
    row: number
    severity: 'error' | 'warning' | 'candidate'
    column: string | null
    value: string | null
    message: string
  }>
  status: string
}

interface CommitResult {
  imported: number
  rejected: number
  batch_id: string
}

const TEMPLATE_COLUMNS = [
  { name: 'code', required: false, description: 'Customer ID — matching an existing code updates that customer; an unknown or blank code creates one' },
  { name: 'name', required: true, description: 'Customer name (required)' },
  { name: 'type', required: false, description: 'Customer: individual/company; Address: billing/shipping/other. Use address_type when importing both' },
  { name: 'category', required: false, description: 'Customer category name (e.g., VIP, Wholesale). Must already exist' },
  { name: 'contact_name', required: false, description: 'Contact person name (creates a contact record)' },
  { name: 'contact_category', required: false, description: 'Contact category, e.g. Clinical or Finance' },
  { name: 'contact_type', required: false, description: 'Contact type, e.g. Primary or Billing' },
  { name: 'contact_email', required: false, description: 'Contact person email' },
  { name: 'contact_phone', required: false, description: 'Contact person phone' },
  { name: 'contact_mobile', required: false, description: 'Contact person mobile' },
  { name: 'contact_street', required: false, description: 'Contact street address' },
  { name: 'contact_street2', required: false, description: 'Contact address line 2' },
  { name: 'contact_city', required: false, description: 'Contact city' },
  { name: 'contact_state', required: false, description: 'Contact state / province' },
  { name: 'contact_zip', required: false, description: 'Contact ZIP / postal code' },
  { name: 'contact_country', required: false, description: 'Contact country' },
  { name: 'email', required: false, description: 'Email address' },
  { name: 'phone', required: false, description: 'Phone number' },
  { name: 'mobile', required: false, description: 'Mobile number' },
  { name: 'company', required: false, description: 'Company name' },
  { name: 'tax_number', required: false, description: 'Tax or VAT number' },
  { name: 'website', required: false, description: 'Website URL' },
  { name: 'billing_address', required: false, description: 'Billing address' },
  { name: 'shipping_address', required: false, description: 'Shipping address' },
  { name: 'address_type', required: false, description: 'Customer address type: billing, shipping, or other' },
  { name: 'label', required: false, description: 'Customer address label, e.g. Main Office' },
  { name: 'street', required: false, description: 'Customer address street' },
  { name: 'street2', required: false, description: 'Customer address line 2' },
  { name: 'city', required: false, description: 'Customer address city' },
  { name: 'state', required: false, description: 'Customer address state / province' },
  { name: 'zip', required: false, description: 'Customer address ZIP / postal code' },
  { name: 'country', required: false, description: 'Customer address country' },
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
  const [stageResult, setStageResult] = useState<StageResult | null>(null)
  const [commitResult, setCommitResult] = useState<CommitResult | null>(null)

  const stageMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/accounting/customers/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as StageResult
    },
    onSuccess: (data) => {
      setStageResult(data)
      const hasErrors = data.counts.error > 0
      const hasWarnings = data.counts.warning > 0
      const commitReady = data.counts.valid + data.counts.duplicate

      if (hasErrors && commitReady === 0) {
        toast({
          title: 'Import validation failed',
          description: `${data.counts.error} rows have errors. Fix the CSV and try again.`,
          variant: 'destructive',
        })
      } else if (hasWarnings) {
        toast({
          title: 'Import staged with warnings',
          description: `${commitReady} rows ready, ${data.counts.warning} rows have unknown values (will be rejected at commit).`,
        })
      } else {
        toast({
          title: 'Import staged',
          description: `${commitReady} rows valid and ready to import.`,
        })
      }
    },
    onError: (error: Error) => {
      toast({
        title: 'Import failed',
        description: error.message || 'An error occurred during staging.',
        variant: 'destructive',
      })
    },
  })

  const commitMutation = useMutation({
    mutationFn: async (batchId: string) => {
      const response = await api.post(`/accounting/customers/import/${batchId}/commit`)
      return response.data as CommitResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      setCommitResult(data)
      toast({
        title: 'Import complete',
        description: `${data.imported} customers imported${data.rejected > 0 ? `, ${data.rejected} rejected` : ''}.`,
      })
    },
    onError: (error: Error) => {
      toast({
        title: 'Import failed',
        description: error.message || 'An error occurred during commit.',
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
        setStageResult(null)
        setCommitResult(null)
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
      setStageResult(null)
      setCommitResult(null)
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
      'Clinical',
      'Primary',
      'john@acme.com',
      '5551112222',
      '5553334444',
      '123 Main Street',
      '#12-34',
      'Singapore',
      'Singapore',
      '018956',
      'Singapore',
      'billing@acme.com',
      '5551234567',
      '5559876543',
      'Acme Corporation',
      'TAX-12345',
      'https://acme.com',
      '123 Main St, Singapore 12345',
      '456 Office Rd, Singapore 67890',
      'shipping',
      'Main Office',
      '456 Office Road',
      '#08-01',
      'Singapore',
      'Singapore',
      '67890',
      'Singapore',
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

  const handleStage = () => {
    if (selectedFile) {
      stageMutation.mutate(selectedFile)
    }
  }

  const handleCommit = () => {
    if (stageResult?.batch_id) {
      commitMutation.mutate(stageResult.batch_id)
    }
  }

  const handleReset = () => {
    setSelectedFile(null)
    setStageResult(null)
    setCommitResult(null)
  }

  const commitReadyRows = (stageResult?.counts.valid || 0) + (stageResult?.counts.duplicate || 0)
  const canCommit = stageResult && commitReadyRows > 0 && !commitResult

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/customers')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Customers</h1>
          <p className="text-muted-foreground">Bulk import customers from a CSV file (staged validation)</p>
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

        {/* Right Column - Upload & Results */}
        <div className="space-y-6">
          {/* Upload Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />
                {!stageResult ? 'Step 2: Upload & Validate' : !commitResult ? 'Step 3: Review & Import' : 'Complete'}
              </CardTitle>
              <CardDescription>
                {!stageResult
                  ? 'Fill in the template and upload it here for validation.'
                  : !commitResult
                    ? 'Review the validation results and import the valid rows.'
                    : 'Import completed successfully.'}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Dropzone */}
              {!stageResult && (
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
              )}

              {/* Stage Button */}
              {selectedFile && !stageResult && (
                <Button
                  onClick={handleStage}
                  className="w-full"
                  disabled={stageMutation.isPending}
                >
                  {stageMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Validating...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
                      Stage & Validate
                    </>
                  )}
                </Button>
              )}

              {/* Progress */}
              {stageMutation.isPending && (
                <Progress value={undefined} className="w-full" />
              )}

              {/* Staging Results */}
              {stageResult && !commitResult && (
                <div className="space-y-4">
                  <Alert>
                    <Icon icon="solar:info-circle-bold" className="h-4 w-4" />
                    <AlertTitle>Validation Complete</AlertTitle>
                    <AlertDescription>
                      {commitReadyRows} rows valid and ready to import
                      {stageResult.counts.warning > 0 && `, ${stageResult.counts.warning} with warnings`}
                      {stageResult.counts.error > 0 && `, ${stageResult.counts.error} with errors`}
                      {stageResult.counts.candidate > 0 && `, ${stageResult.counts.candidate} candidates for review`}.
                    </AlertDescription>
                  </Alert>

                  <div className="grid grid-cols-3 gap-4">
                    <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                      <div className="text-2xl font-bold text-green-600">{commitReadyRows}</div>
                      <div className="text-sm text-muted-foreground">Valid</div>
                    </div>
                    <div className="text-center p-4 bg-yellow-50 dark:bg-yellow-950/20 rounded-lg">
                      <div className="text-2xl font-bold text-yellow-600">{stageResult.counts.warning}</div>
                      <div className="text-sm text-muted-foreground">Warnings</div>
                    </div>
                    <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                      <div className="text-2xl font-bold text-red-600">{stageResult.counts.error}</div>
                      <div className="text-sm text-muted-foreground">Errors</div>
                    </div>
                  </div>

                  {stageResult.code_conflicts.length > 0 && (
                    <Alert variant="destructive">
                      <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
                      <AlertTitle>Code Conflicts</AlertTitle>
                      <AlertDescription>
                        <div className="mt-2 max-h-[150px] overflow-y-auto space-y-1 text-sm">
                          {stageResult.code_conflicts.map((conflict, idx) => (
                            <div key={idx} className="flex gap-2">
                              <span className="font-medium">Row {conflict.row}:</span>
                              <span>Code "{conflict.code}" already exists</span>
                            </div>
                          ))}
                        </div>
                      </AlertDescription>
                    </Alert>
                  )}

                  {stageResult.issues?.length > 0 && (
                    <div className="rounded-lg border overflow-hidden">
                      <div className="bg-muted px-4 py-3 font-medium">Validation details</div>
                      <div className="max-h-[260px] overflow-auto">
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Row</TableHead>
                              <TableHead>Column</TableHead>
                              <TableHead>Value</TableHead>
                              <TableHead>Issue</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {stageResult.issues.map((issue, idx) => (
                              <TableRow key={`${issue.row}-${issue.column}-${idx}`}>
                                <TableCell className="font-medium">{issue.row}</TableCell>
                                <TableCell>{issue.column || 'Row'}</TableCell>
                                <TableCell className="max-w-[140px] truncate" title={issue.value || ''}>
                                  {issue.value || '—'}
                                </TableCell>
                                <TableCell className={issue.severity === 'error' ? 'text-destructive' : 'text-yellow-700'}>
                                  {issue.message}
                                </TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>
                      </div>
                    </div>
                  )}

                  <Button
                    onClick={handleCommit}
                    className="w-full"
                    disabled={!canCommit || commitMutation.isPending}
                  >
                    {commitMutation.isPending ? (
                      <>
                        <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                        Importing...
                      </>
                    ) : (
                      <>
                        <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                        Import {commitReadyRows} Rows
                      </>
                    )}
                  </Button>

                  <Button variant="outline" onClick={handleReset} className="w-full">
                    <Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />
                    Import Another File
                  </Button>
                </div>
              )}

              {/* Commit Results */}
              {commitResult && (
                <div className="space-y-4">
                  <Alert>
                    <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-600" />
                    <AlertTitle>Import Complete</AlertTitle>
                    <AlertDescription>
                      {commitResult.imported} customers imported successfully
                      {commitResult.rejected > 0 && `, ${commitResult.rejected} rejected`}.
                    </AlertDescription>
                  </Alert>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                      <div className="text-2xl font-bold text-green-600">{commitResult.imported}</div>
                      <div className="text-sm text-muted-foreground">Imported</div>
                    </div>
                    <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                      <div className="text-2xl font-bold text-red-600">{commitResult.rejected}</div>
                      <div className="text-sm text-muted-foreground">Rejected</div>
                    </div>
                  </div>

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
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
