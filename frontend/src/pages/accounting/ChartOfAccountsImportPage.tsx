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
  updated: number
  errors: Array<{ row: number; field?: string; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'code', required: true, description: 'Unique account code. Existing codes are updated.' },
  { name: 'name', required: true, description: 'Account name.' },
  { name: 'type', required: true, description: 'asset, liability, equity, revenue, or expense.' },
  { name: 'subtype', required: false, description: 'Optional reporting subtype.' },
  { name: 'parent_code', required: false, description: 'Code of the parent account. It may appear later in the same file.' },
  { name: 'description', required: false, description: 'Optional account description.' },
  { name: 'is_active', required: false, description: 'true or false; defaults to true.' },
  { name: 'opening_balance', required: false, description: 'Opening balance; defaults to 0.' },
]

const BACK_PATH = '/accounting/configuration/company?tab=chart-of-accounts'

export function ChartOfAccountsImportPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)

  const mutation = useMutation({
    mutationFn: async (file: File) => {
      const data = new FormData()
      data.append('file', file)
      const response = await api.post('/accounting/chart-of-accounts/import', data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      setResult({ ...data, updated: data.updated ?? 0, errors: data.errors ?? [] })
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts'] })
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts-parent-options'] })
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts-tree'] })
      toast({
        title: data.errors?.length ? 'Import completed with errors' : 'Import successful',
        description: `${data.created} created and ${data.updated ?? 0} updated.`,
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Import failed',
        description: error?.response?.data?.message || error?.message || 'The CSV file could not be imported.',
        variant: 'destructive',
      })
    },
  })

  const downloadTemplate = async () => {
    try {
      const response = await api.get('/accounting/chart-of-accounts/import-template', { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = 'chart-of-accounts-import-template.csv'
      link.click()
      URL.revokeObjectURL(url)
      toast({ title: 'Template downloaded', description: 'Fill in the template and upload it in Step 2.' })
    } catch {
      toast({ title: 'Download failed', description: 'Could not download the import template.', variant: 'destructive' })
    }
  }

  const reset = () => {
    setSelectedFile(null)
    setResult(null)
    mutation.reset()
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Chart of Accounts"
        subtitle="Create or update accounts from a CSV file"
        backTo={BACK_PATH}
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Use the standard column names so parent accounts and updates can be resolved correctly."
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
          description="Imported codes update existing accounts; new codes create accounts."
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
                loading={mutation.isPending}
                disabled={mutation.isPending}
                onClick={() => mutation.mutate(selectedFile)}
              >
                Import Accounts
              </HeaderActionButton>
              {mutation.isPending && <Progress value={undefined} />}
            </div>
          )}

          {result && (
            <ImportResults
              created={result.created}
              updated={result.updated}
              errors={result.errors}
              onReset={reset}
              doneTo={BACK_PATH}
              doneLabel="View Accounts"
              doneIcon="solar:document-text-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default ChartOfAccountsImportPage