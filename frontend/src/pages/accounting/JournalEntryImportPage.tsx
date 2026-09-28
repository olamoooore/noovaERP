import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Progress } from '@/components/ui/progress'
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

interface ImportResult {
  created: number
  errors: Array<{ row: number; entry_key?: string; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'entry_key', required: true, description: 'Groups lines into one journal entry (for example, JE-001).' },
  { name: 'entry_date', required: true, description: 'Entry date in YYYY-MM-DD format.' },
  { name: 'journal_code', required: false, description: 'Existing journal code. Leave blank for no journal.' },
  { name: 'reference', required: false, description: 'External or internal reference.' },
  { name: 'description', required: true, description: 'Journal entry description.' },
  { name: 'remark', required: false, description: 'Optional journal entry remark (maximum 255 characters).' },
  { name: 'account_code', required: true, description: 'Existing chart-of-accounts code.' },
  { name: 'line_description', required: false, description: 'Description for this entry line.' },
  { name: 'debit', required: true, description: 'Debit amount; use 0 for a credit line.' },
  { name: 'credit', required: true, description: 'Credit amount; use 0 for a debit line.' },
]

export default function JournalEntryImportPage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)

  const mutation = useMutation({
    mutationFn: async (selected: File) => {
      const body = new FormData()
      body.append('file', selected)
      const response = await api.post('/accounting/journal-entries/import', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      setResult(data)
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] })
      const noun = data.created === 1 ? 'entry' : 'entries'
      const suffix = data.errors.length ? ', ' + data.errors.length + ' error(s)' : ''
      toast({
        title: data.created ? 'Import complete' : 'Import failed',
        description: data.created + ' draft journal ' + noun + ' created' + suffix + '.',
        variant: data.created === 0 ? 'destructive' : undefined,
      })
    },
    onError: (error: any) => toast({
      title: 'Import failed',
      description: error.response?.data?.message || error.message || 'Unable to import the CSV file.',
      variant: 'destructive',
    }),
  })

  const downloadTemplate = () => {
    const header = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
    const rows = [
      'JE-001,2026-09-03,GJ,MONTH-END,September accrual,Review before posting,6100,Accrued expense,1000.00,0',
      'JE-001,2026-09-03,GJ,MONTH-END,September accrual,Review before posting,2100,Accrued liability,0,1000.00',
    ]
    const url = URL.createObjectURL(new Blob([[header, ...rows].join('\n')], { type: 'text/csv' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = 'journal_entries_import_template.csv'
    anchor.click()
    URL.revokeObjectURL(url)
    toast({ title: 'Template downloaded', description: 'Fill in the template and upload it in Step 2.' })
  }

  const reset = () => {
    setFile(null)
    setResult(null)
  }

  const errors: ImportRowIssue[] = (result?.errors || []).map((error) => ({
    row: error.row,
    message: error.entry_key ? `${error.entry_key} — ${error.message}` : error.message,
  }))

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Journal Entries"
        subtitle="Bulk create editable draft journal entries from a CSV file"
        backTo="/accounting/core/journal-entries"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Rows with the same entry_key are combined. Each entry must balance."
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
          description="Imported entries are saved as drafts so they can be reviewed and edited before posting."
          icon="solar:upload-minimalistic-bold-duotone"
          tone="bg-blue-500/10"
          glyph="text-blue-600 dark:text-blue-400"
        >
          <ImportDropzone
            selectedFile={file}
            onFile={(selected) => { setFile(selected); setResult(null) }}
            onClear={reset}
          />

          {file && !result && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={mutation.isPending}
                disabled={mutation.isPending}
                onClick={() => mutation.mutate(file)}
              >
                Import Journal Entries
              </HeaderActionButton>
              {mutation.isPending && <Progress value={undefined} />}
            </div>
          )}

          {result && (
            <div className="space-y-4 border-t pt-4">
              <div className="flex items-center gap-3">
                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                  result.created > 0
                    ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
                    : 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'
                }`}>
                  <Icon
                    icon={result.created > 0 ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'}
                    className="h-5 w-5"
                  />
                </div>
                <div>
                  <p className="text-sm font-semibold">Import results</p>
                  <p className="text-xs text-muted-foreground">
                    {result.created} draft journal {result.created === 1 ? 'entry' : 'entries'} created
                    {result.errors.length ? `, ${result.errors.length} errors` : ''}
                  </p>
                </div>
              </div>

              <ImportResultTiles
                tiles={[
                  { label: 'Drafts created', value: result.created, tone: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40', glyph: 'text-emerald-600 dark:text-emerald-400' },
                  { label: 'Errors', value: result.errors.length, tone: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', glyph: 'text-red-600 dark:text-red-400' },
                ]}
              />

              <ImportErrorList errors={errors} />

              <div className="flex flex-wrap items-center gap-2">
                <HeaderActionButton variant="outline" icon="solar:upload-bold-duotone" onClick={reset}>
                  Import Another File
                </HeaderActionButton>
                <HeaderActionButton icon="solar:document-text-bold-duotone" asChild>
                  <Link to="/accounting/core/journal-entries">View Journal Entries</Link>
                </HeaderActionButton>
              </div>
            </div>
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}