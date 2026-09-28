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
import fixedAssetsApi from '@/lib/fixedAssetsApi'

interface ImportResult {
  created: number
  errors: Array<{ row: number; message: string }>
  warnings?: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'asset_number', required: true, description: 'Unique asset number.' },
  { name: 'name', required: true, description: 'Asset name.' },
  { name: 'category', required: true, description: 'Asset category.' },
  { name: 'purchase_date', required: true, description: 'Purchase date (YYYY-MM-DD, M/D/YYYY, or DD/MM/YYYY).' },
  { name: 'purchase_cost', required: true, description: 'Original purchase cost.' },
  { name: 'depreciation_method', required: true, description: 'straight_line, declining_balance, or units_of_production.' },
  { name: 'useful_life_years', required: true, description: 'Useful life in years (not required for units_of_production).' },
  { name: 'useful_life_units', required: false, description: 'Total production units for units_of_production.' },
  { name: 'salvage_value', required: false, description: 'Residual value; defaults to 0.' },
  { name: 'depreciation_start_date', required: true, description: 'Date depreciation begins.' },
  { name: 'asset_account_code', required: true, description: 'Chart of Accounts code for the asset.' },
  { name: 'depreciation_account_code', required: true, description: 'Chart of Accounts code for depreciation expense.' },
  { name: 'accumulated_depreciation_account_code', required: true, description: 'Chart of Accounts code for accumulated depreciation.' },
  { name: 'location', required: false, description: 'Physical location.' },
  { name: 'department_id', required: false, description: 'Department ID.' },
  { name: 'responsible_person', required: false, description: 'Responsible person.' },
  { name: 'serial_number', required: false, description: 'Serial number.' },
  { name: 'description', required: false, description: 'Asset description.' },
]

const csvValue = (value: string) => /[",\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value

export default function FixedAssetImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: fixedAssetsApi.importAssets,
    onSuccess: (data: ImportResult) => {
      queryClient.invalidateQueries({ queryKey: ['fixed-assets'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      toast({
        title: data.created > 0 && errorCount === 0 ? 'Import successful!' : 'Import completed with errors',
        description: `${data.created} asset(s) created${errorCount ? `, ${errorCount} errors` : '.'}`,
        variant: errorCount && data.created === 0 ? 'destructive' : undefined,
      })
    },
    onError: (error: any) => {
      const response = error.response?.data
      if (Array.isArray(response?.errors)) {
        setImportResult({ created: response.created || 0, warnings: response.warnings || [], errors: response.errors })
      }
      const firstError = Array.isArray(response?.errors) ? response.errors[0]?.message : undefined
      toast({
        title: 'Import failed',
        description: response?.message || firstError || error.message || 'An error occurred during import.',
        variant: 'destructive',
      })
    },
  })

  const handleDownloadTemplate = () => {
    const header = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
    const sample: Record<string, string> = {
      asset_number: 'FA-001',
      name: 'Office Laptop',
      category: 'IT Equipment',
      purchase_date: '2026-01-15',
      purchase_cost: '3600.00',
      depreciation_method: 'straight_line',
      useful_life_years: '3',
      salvage_value: '0',
      depreciation_start_date: '2026-02-01',
      asset_account_code: '1500',
      depreciation_account_code: '6500',
      accumulated_depreciation_account_code: '1550',
    }
    const row = TEMPLATE_COLUMNS.map((column) => csvValue(sample[column.name] || '')).join(',')
    const blob = new Blob([`${header}\n${row}`], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'fixed_asset_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    toast({ title: 'Template downloaded', description: 'Fill in the template and upload the CSV file.' })
  }

  const reset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Fixed Assets"
        subtitle="Bulk import fixed assets from a CSV file. Imported assets are created active without posting journals."
        backTo="/accounting/core/assets"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Use Chart of Accounts codes for all three account columns."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={handleDownloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} hint="Each row creates one fixed asset." />
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
                Import Fixed Assets
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              errors={importResult.errors || []}
              warnings={importResult.warnings || []}
              onReset={reset}
              doneTo="/accounting/core/assets"
              doneLabel="View Assets"
              doneIcon="solar:buildings-2-bold-duotone"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}