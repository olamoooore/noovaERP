import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Progress } from '@/components/ui/progress'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import {
  ImportDropzone,
  ImportErrorList,
  ImportFieldTable,
  ImportPageHeader,
  ImportResultTiles,
  ImportStepCard,
  type ImportRowIssue,
  type ImportTemplateColumn,
} from '@/components/import/ImportPageKit'
import { Icon } from '@iconify/react'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

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

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
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
  const { toast } = useToast()
  const queryClient = useQueryClient()

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

  const handleDownloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
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

  const handleReset = () => {
    setSelectedFile(null)
    setStageResult(null)
    setCommitResult(null)
  }

  const commitReadyRows = (stageResult?.counts.valid || 0) + (stageResult?.counts.duplicate || 0)
  const canCommit = Boolean(stageResult) && commitReadyRows > 0 && !commitResult

  const codeConflicts: ImportRowIssue[] = (stageResult?.code_conflicts || []).map((conflict) => ({
    row: conflict.row,
    message: `Code "${conflict.code}" already exists`,
  }))

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Customers"
        subtitle="Bulk import customers from a CSV file (staged validation)"
        backTo="/customers"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Start by downloading our CSV template. It includes all the fields you need."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={handleDownloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} hint="Required fields are marked with an asterisk (*). The code field accepts any format." />
        </ImportStepCard>

        <ImportStepCard
          step={stageResult ? 3 : 2}
          title={!stageResult ? 'Upload & Validate' : !commitResult ? 'Review & Import' : 'Complete'}
          description={
            !stageResult
              ? 'Fill in the template and upload it here for validation.'
              : !commitResult
                ? 'Review the validation results and import the valid rows.'
                : 'Import completed successfully.'
          }
          icon="solar:upload-minimalistic-bold-duotone"
          tone="bg-blue-500/10"
          glyph="text-blue-600 dark:text-blue-400"
        >
          {!stageResult && (
            <>
              <ImportDropzone
                selectedFile={selectedFile}
                onFile={(file) => { setSelectedFile(file); setStageResult(null); setCommitResult(null) }}
                onClear={handleReset}
              />

              {selectedFile && (
                <div className="space-y-3">
                  <HeaderActionButton
                    icon="solar:import-bold-duotone"
                    loading={stageMutation.isPending}
                    disabled={stageMutation.isPending}
                    onClick={() => stageMutation.mutate(selectedFile)}
                  >
                    Stage &amp; Validate
                  </HeaderActionButton>
                  {stageMutation.isPending && <Progress value={undefined} className="w-full" />}
                </div>
              )}
            </>
          )}

          {/* Staging results — review before commit */}
          {stageResult && !commitResult && (
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                  <Icon icon="solar:clipboard-check-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm font-semibold">Validation complete</p>
                  <p className="text-xs text-muted-foreground">
                    {commitReadyRows} rows valid and ready to import
                    {stageResult.counts.warning > 0 ? `, ${stageResult.counts.warning} with warnings` : ''}
                    {stageResult.counts.error > 0 ? `, ${stageResult.counts.error} with errors` : ''}
                    {stageResult.counts.candidate > 0 ? `, ${stageResult.counts.candidate} candidates for review` : ''}.
                  </p>
                </div>
              </div>

              <ImportResultTiles
                tiles={[
                  { label: 'Valid', value: commitReadyRows, tone: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40', glyph: 'text-emerald-600 dark:text-emerald-400' },
                  { label: 'Warnings', value: stageResult.counts.warning, tone: 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40', glyph: 'text-amber-600 dark:text-amber-400' },
                  { label: 'Errors', value: stageResult.counts.error, tone: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', glyph: 'text-red-600 dark:text-red-400' },
                ]}
              />

              <ImportErrorList errors={codeConflicts} errorsTitle="Code Conflicts" />

              {stageResult.issues?.length > 0 && (
                <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                  <div className="bg-muted/50 px-4 py-2.5 text-sm font-medium">Validation details</div>
                  <div className="max-h-[260px] overflow-auto">
                    <Table>
                      <TableHeader>
                        <TableRow className="bg-muted/50">
                          <TableHead>Row</TableHead>
                          <TableHead>Column</TableHead>
                          <TableHead>Value</TableHead>
                          <TableHead>Issue</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {stageResult.issues.map((issue, idx) => (
                          <TableRow key={`${issue.row}-${issue.column}-${idx}`}>
                            <TableCell className="font-medium tabular-nums">{issue.row}</TableCell>
                            <TableCell>{issue.column || 'Row'}</TableCell>
                            <TableCell className="max-w-[140px] truncate" title={issue.value || ''}>
                              {issue.value || '—'}
                            </TableCell>
                            <TableCell className={issue.severity === 'error' ? 'text-destructive' : 'text-amber-700 dark:text-amber-400'}>
                              {issue.message}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </div>
              )}

              <div className="flex flex-wrap items-center gap-2">
                <HeaderActionButton
                  icon="solar:check-circle-bold-duotone"
                  loading={commitMutation.isPending}
                  disabled={!canCommit || commitMutation.isPending}
                  onClick={() => stageResult?.batch_id && commitMutation.mutate(stageResult.batch_id)}
                >
                  Import {commitReadyRows} Rows
                </HeaderActionButton>
                <HeaderActionButton variant="outline" icon="solar:upload-bold-duotone" onClick={handleReset}>
                  Import Another File
                </HeaderActionButton>
              </div>
            </div>
          )}

          {/* Commit results */}
          {commitResult && (
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                  <Icon icon="solar:check-circle-bold-duotone" className="h-5 w-5" />
                </div>
                <div>
                  <p className="text-sm font-semibold">Import complete</p>
                  <p className="text-xs text-muted-foreground">
                    {commitResult.imported} customers imported successfully
                    {commitResult.rejected > 0 ? `, ${commitResult.rejected} rejected` : ''}.
                  </p>
                </div>
              </div>

              <ImportResultTiles
                tiles={[
                  { label: 'Imported', value: commitResult.imported, tone: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40', glyph: 'text-emerald-600 dark:text-emerald-400' },
                  { label: 'Rejected', value: commitResult.rejected, tone: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', glyph: 'text-red-600 dark:text-red-400' },
                ]}
              />

              <div className="flex flex-wrap items-center gap-2">
                <HeaderActionButton variant="outline" icon="solar:upload-bold-duotone" onClick={handleReset}>
                  Import Another File
                </HeaderActionButton>
                <HeaderActionButton icon="solar:users-group-rounded-bold-duotone" asChild>
                  <Link to="/customers">View Customers</Link>
                </HeaderActionButton>
              </div>
            </div>
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default CustomerImportPage