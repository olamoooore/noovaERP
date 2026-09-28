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
import { accountingApi } from '@/lib/api'

interface ImportResult {
  created: number
  updated: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'code', required: false, description: 'Vendor code. Leave empty to generate the next VEN code automatically.' },
  { name: 'name', required: true, description: 'Vendor name. Required.' },
  { name: 'email', required: false, description: 'Vendor email address.' },
  { name: 'phone', required: false, description: 'Vendor phone number.' },
  { name: 'company', required: false, description: 'Registered or trading company name.' },
  { name: 'address', required: false, description: 'Legacy general address for compatibility.' },
  { name: 'billing_address', required: false, description: 'Billing address in full-text format.' },
  { name: 'shipping_address', required: false, description: 'Shipping address in full-text format.' },
  { name: 'contact_person', required: false, description: 'Primary vendor contact person.' },
]

export function VendorImportPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const data = new FormData()
      data.append('file', file)
      const response = await accountingApi.post('/accounting/vendors/import', data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      setResult(data)
      queryClient.invalidateQueries({ queryKey: ['vendors'] })
      toast({
        title: 'Vendor import complete',
        description: `${data.created} created, ${data.updated} updated, ${data.errors.length} errors.`,
        variant: data.errors.length > 0 && data.created + data.updated === 0 ? 'destructive' : 'default',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Import failed',
        description: error.response?.data?.message || 'An error occurred while importing vendors.',
        variant: 'destructive',
      })
    },
  })

  const downloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
    const sample = ',Example Vendor,vendor@example.com,+60123456789,Example Sdn Bhd,,123 Billing Street,456 Shipping Street,Jane Doe'
    const url = URL.createObjectURL(new Blob([`${headers}\n${sample}`], { type: 'text/csv;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'vendor_import_template.csv'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it to import vendors.' })
  }

  const reset = () => {
    setSelectedFile(null)
    setResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Vendors"
        subtitle="Bulk import vendors from a CSV file"
        backTo="/accounting/vendors"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Start with the CSV template containing the supported Vendor fields."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={downloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} />
        </ImportStepCard>

        <ImportStepCard
          step={2}
          title="Upload CSV File"
          description="Fill in the template and upload the CSV file."
          icon="solar:upload-minimalistic-bold-duotone"
          tone="bg-blue-500/10"
          glyph="text-blue-600 dark:text-blue-400"
        >
          <ImportDropzone
            selectedFile={selectedFile}
            onFile={(file) => { setSelectedFile(file); setResult(null) }}
            onClear={reset}
          />

          {selectedFile && !result && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={importMutation.isPending}
                disabled={importMutation.isPending}
                onClick={() => importMutation.mutate(selectedFile)}
              >
                Import Vendors
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {result && (
            <ImportResults
              created={result.created}
              updated={result.updated}
              errors={result.errors}
              onReset={reset}
              doneTo="/accounting/vendors"
              doneLabel="View Vendors"
              doneIcon="solar:users-group-rounded-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default VendorImportPage