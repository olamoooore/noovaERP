import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Progress } from '@/components/ui/progress'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import {
  ImportDropzone,
  ImportErrorList,
  ImportFieldTable,
  ImportPageHeader,
  ImportResultTiles,
  ImportStepCard,
  type ImportTemplateColumn,
} from '@/components/import/ImportPageKit'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'

type TrackingType = 'batches' | 'serials'

const FIELDS: Record<TrackingType, ImportTemplateColumn[]> = {
  batches: [
    { name: 'product_sku', required: true, description: 'Existing product SKU. Product must use Batch/Lot tracking.' },
    { name: 'warehouse_code', required: true, description: 'Existing warehouse code receiving the stock.' },
    { name: 'batch_number', required: true, description: 'Lot/Batch number. Existing matching batches are increased.' },
    { name: 'quantity', required: true, description: 'Positive quantity received into this lot/batch.' },
    { name: 'expiry_date', required: false, description: 'Expiry date in YYYY-MM-DD format.' },
    { name: 'reference', required: false, description: 'Purchase order, opening balance, or receipt reference.' },
    { name: 'notes', required: false, description: 'Additional receipt notes.' },
  ],
  serials: [
    { name: 'product_sku', required: true, description: 'Existing product SKU. Product must use Serial tracking.' },
    { name: 'warehouse_code', required: true, description: 'Existing warehouse code receiving the unit.' },
    { name: 'serial_number', required: true, description: 'Unique serial number. One CSV row represents one unit.' },
    { name: 'reference', required: false, description: 'Purchase order, opening balance, or receipt reference.' },
    { name: 'notes', required: false, description: 'Additional receipt notes.' },
  ],
}

export function InventoryTrackingImportPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const initialType = searchParams.get('type') === 'serials' ? 'serials' : 'batches'
  const [type, setType] = useState<TrackingType>(initialType)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [result, setResult] = useState<any>(null)

  const importMutation = useMutation({
    mutationFn: async () => {
      const form = new FormData()
      form.append('file', selectedFile as File)
      return (await api.post(`/inventory/tracking/${type}/import`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data
    },
    onSuccess: (data) => {
      setResult(data)
      queryClient.invalidateQueries({ queryKey: ['inventory-tracking'] })
      queryClient.invalidateQueries({ queryKey: ['products-stock'] })
      const failed = Number(data.failed || 0)
      toast({
        title: failed ? 'Import completed with errors' : 'Import successful!',
        description: `${data.imported} row(s) imported${failed ? `, ${failed} failed` : ''}.`,
        variant: data.imported === 0 && failed ? 'destructive' : 'default',
      })
    },
    onError: (error: any) => toast({ title: 'Import failed', description: error.response?.data?.message || 'Unable to import file.', variant: 'destructive' }),
  })

  const downloadTemplate = async () => {
    const response = await api.get(`/inventory/tracking/${type}/template`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${type}-import-template.csv`
    anchor.click()
    URL.revokeObjectURL(url)
    toast({ title: 'Template downloaded', description: `Fill in the ${type} template and upload it in Step 2.` })
  }

  const changeType = (next: TrackingType) => {
    setType(next)
    setSearchParams({ type: next })
    setSelectedFile(null)
    setResult(null)
  }

  const reset = () => {
    setSelectedFile(null)
    setResult(null)
  }

  const failedRows: Array<{ row: number; message: string }> = result?.results
    ? result.results.filter((row: any) => row.status === 'failed').map((row: any) => ({ row: row.row, message: row.message }))
    : []

  return (
    <div className="mx-auto max-w-5xl space-y-6 p-6">
      <ImportPageHeader
        title="Import Lots / Serial Numbers"
        subtitle="Bulk receive tracked inventory from a CSV file."
        backTo="/inventory/tracking"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description={type === 'batches' ? 'One row per product lot/batch.' : 'One row per serial number. Each row receives one inventory unit.'}
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={downloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={FIELDS[type]} />
        </ImportStepCard>

        <ImportStepCard
          step={2}
          title="Upload CSV File"
          description="Choose the import type, then upload the completed template."
          icon="solar:upload-minimalistic-bold-duotone"
          tone="bg-blue-500/10"
          glyph="text-blue-600 dark:text-blue-400"
        >
          <div className="space-y-2">
            <label className="text-xs font-medium">Import type</label>
            <Select value={type} onValueChange={(value) => changeType(value as TrackingType)}>
              <SelectTrigger className="h-8 w-full rounded-full text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="batches">Lots / Batches</SelectItem>
                <SelectItem value="serials">Serial Numbers</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {type === 'batches'
                ? 'One row per product lot/batch. Existing matching lots are increased.'
                : 'One row per serial number — each row receives one inventory unit.'}
            </p>
          </div>

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
                onClick={() => importMutation.mutate()}
              >
                Import {type === 'batches' ? 'Lots / Batches' : 'Serial Numbers'}
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} />}
            </div>
          )}

          {result && (
            <div className="space-y-4 border-t pt-4">
              <div className="flex items-center gap-3">
                <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${
                  Number(result.imported) > 0
                    ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400'
                    : 'bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400'
                }`}>
                  <Icon
                    icon={Number(result.imported) > 0 ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'}
                    className="h-5 w-5"
                  />
                </div>
                <div>
                  <p className="text-sm font-semibold">Import results</p>
                  <p className="text-xs text-muted-foreground">
                    {Number(result.imported)} imported{Number(result.failed) ? `, ${Number(result.failed)} failed` : ''}
                  </p>
                </div>
              </div>

              <ImportResultTiles
                tiles={[
                  { label: 'Imported', value: Number(result.imported || 0), tone: 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40', glyph: 'text-emerald-600 dark:text-emerald-400' },
                  { label: 'Errors', value: Number(result.failed || 0), tone: 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-950/40', glyph: 'text-red-600 dark:text-red-400' },
                ]}
              />

              <ImportErrorList errors={failedRows} />

              <div className="flex flex-wrap items-center gap-2">
                <HeaderActionButton variant="outline" icon="solar:upload-bold-duotone" onClick={reset}>
                  Import Another File
                </HeaderActionButton>
                <HeaderActionButton icon="solar:box-bold-duotone" asChild>
                  <Link to="/inventory/tracking">View Tracking List</Link>
                </HeaderActionButton>
              </div>
            </div>
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default InventoryTrackingImportPage