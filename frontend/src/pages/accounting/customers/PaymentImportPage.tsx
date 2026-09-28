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

interface PaymentImportWarning {
  row: number
  message: string
}

interface PaymentImportResult {
  created: number
  warnings: PaymentImportWarning[]
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
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

const csvValue = (value: string) => {
  if (/[",\n]/.test(value)) {
    return `"${value.replace(/"/g, '""')}"`
  }
  return value
}

export function PaymentImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

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

  const handleDownloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
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

  const handleReset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Customer Payments"
        subtitle="Bulk import payment records from a CSV file. New payments are created in draft status and can be reviewed before posting."
        backTo="/accounting/customers/payments"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="One row per payment. Leave payment_no blank to auto-generate PAY/YYYY/MM/SEQ."
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
            onClear={handleReset}
          />

          {selectedFile && !importResult && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={importMutation.isPending}
                disabled={importMutation.isPending}
                onClick={() => importMutation.mutate(selectedFile)}
              >
                Import Payments
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              errors={importResult.errors || []}
              warnings={importResult.warnings || []}
              onReset={handleReset}
              doneTo="/accounting/customers/payments"
              doneLabel="View Payments"
              doneIcon="solar:card-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default PaymentImportPage