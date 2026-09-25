import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import fixedAssetsApi from '@/lib/fixedAssetsApi'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

interface ImportResult { created: number; errors: Array<{ row: number; message: string }>; warnings?: Array<{ row: number; message: string }> }
const TEMPLATE_COLUMNS = [
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
const csvValue = (v: string) => /[",\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v

export default function FixedAssetImportPage() {
  const navigate = useNavigate(); const { toast } = useToast(); const queryClient = useQueryClient()
  const [dragActive, setDragActive] = useState(false); const [selectedFile, setSelectedFile] = useState<File | null>(null); const [importResult, setImportResult] = useState<ImportResult | null>(null)
  const importMutation = useMutation({ mutationFn: fixedAssetsApi.importAssets, onSuccess: (data: ImportResult) => { queryClient.invalidateQueries({ queryKey: ['fixed-assets'] }); setImportResult(data); const n = data.errors?.length || 0; toast({ title: data.created > 0 && n === 0 ? 'Import successful!' : 'Import completed with errors', description: `${data.created} asset(s) created${n ? `, ${n} errors` : '.'}`, variant: n && data.created === 0 ? 'destructive' : undefined }) }, onError: (error: any) => { const response = error.response?.data; if (Array.isArray(response?.errors)) setImportResult({ created: response.created || 0, warnings: response.warnings || [], errors: response.errors }); const firstError = Array.isArray(response?.errors) ? response.errors[0]?.message : undefined; toast({ title: 'Import failed', description: response?.message || firstError || error.message || 'An error occurred during import.', variant: 'destructive' }) } })
  const handleDrag = useCallback((e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); if (e.type === 'dragenter' || e.type === 'dragover') setDragActive(true); else if (e.type === 'dragleave') setDragActive(false) }, [])
  const handleDrop = useCallback((e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragActive(false); const file = e.dataTransfer.files?.[0]; if (file && (file.type === 'text/csv' || file.name.endsWith('.csv'))) { setSelectedFile(file); setImportResult(null) } else if (file) toast({ title: 'Invalid file type', description: 'Please upload a CSV file.', variant: 'destructive' }) }, [toast])
  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => { const file = e.target.files?.[0]; if (file) { setSelectedFile(file); setImportResult(null) } }
  const handleDownloadTemplate = () => { const header = TEMPLATE_COLUMNS.map(c => c.name).join(','); const sample: Record<string, string> = { asset_number: 'FA-001', name: 'Office Laptop', category: 'IT Equipment', purchase_date: '2026-01-15', purchase_cost: '3600.00', depreciation_method: 'straight_line', useful_life_years: '3', salvage_value: '0', depreciation_start_date: '2026-02-01', asset_account_code: '1500', depreciation_account_code: '6500', accumulated_depreciation_account_code: '1550' }; const row = TEMPLATE_COLUMNS.map(c => csvValue(sample[c.name] || '')).join(','); const blob = new Blob([`${header}\n${row}`], { type: 'text/csv' }); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'fixed_asset_import_template.csv'; document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url); toast({ title: 'Template downloaded', description: 'Fill in the template and upload the CSV file.' }) }
  const reset = () => { setSelectedFile(null); setImportResult(null) }
  return <div className="p-6 max-w-5xl mx-auto space-y-6">
    <div>
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/accounting/core/assets')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div><h1 className="text-2xl font-bold">Import Fixed Assets</h1><p className="text-muted-foreground">Bulk import fixed assets from a CSV file. Imported assets are created active without posting journals.</p></div></div>
    <div className="grid gap-6 md:grid-cols-2"><div className="space-y-6"><Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />Step 1: Download Template</CardTitle><CardDescription>Use Chart of Accounts codes for all three account columns. Required fields are marked with an asterisk.</CardDescription></CardHeader><CardContent><Button onClick={handleDownloadTemplate} className="w-full" variant="outline"><Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />Download Import Template</Button></CardContent></Card><Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />Template Fields</CardTitle><CardDescription>Each row creates one fixed asset.</CardDescription></CardHeader><CardContent className="max-h-[400px] overflow-y-auto"><Table><TableHeader><TableRow><TableHead className="w-[190px]">Field</TableHead><TableHead>Description</TableHead></TableRow></TableHeader><TableBody>{TEMPLATE_COLUMNS.map(col => <TableRow key={col.name}><TableCell className="font-medium">{col.name}{col.required && <span className="text-destructive ml-1">*</span>}</TableCell><TableCell className="text-muted-foreground text-sm">{col.description}</TableCell></TableRow>)}</TableBody></Table></CardContent></Card></div>
      <div className="space-y-6"><Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />Step 2: Upload CSV File</CardTitle><CardDescription>Fill in the template and upload it here.</CardDescription></CardHeader><CardContent className="space-y-4"><div className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'} ${selectedFile ? 'bg-muted/50' : ''}`} onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={handleDrop}>{selectedFile ? <div className="space-y-3"><Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" /><p className="font-medium">{selectedFile.name}</p><p className="text-sm text-muted-foreground">{(selectedFile.size / 1024).toFixed(2)} KB</p><Button variant="ghost" size="sm" onClick={reset}><Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />Remove</Button></div> : <div className="space-y-3"><Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" /><p className="font-medium">Drag and drop your CSV file here</p><p className="text-sm text-muted-foreground">or click the button below to browse</p><Button variant="outline" size="sm" asChild><label className="cursor-pointer"><Icon icon="solar:folder-open-bold" className="mr-2 h-4 w-4" />Browse Files<input type="file" accept=".csv,text/csv" onChange={handleFileSelect} className="hidden" /></label></Button></div>}</div>{selectedFile && !importResult && <Button onClick={() => importMutation.mutate(selectedFile)} className="w-full" disabled={importMutation.isPending}>{importMutation.isPending ? <><Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />Importing...</> : <><Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />Import Fixed Assets</>}</Button>}{importMutation.isPending && <Progress value={undefined} className="w-full" />}</CardContent></Card>
        {importResult && <Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon={importResult.errors?.length ? 'solar:danger-triangle-bold' : 'solar:check-circle-bold'} className="h-5 w-5" />Import Results</CardTitle></CardHeader><CardContent className="space-y-4"><Alert variant={importResult.errors?.length ? 'destructive' : 'default'}><AlertTitle>{importResult.created} asset(s) created</AlertTitle><AlertDescription>{importResult.errors?.length || 0} row(s) failed validation.</AlertDescription></Alert>{importResult.errors?.length > 0 && <div className="max-h-64 overflow-y-auto space-y-2">{importResult.errors.map((error, index) => <div key={`${error.row}-${index}`} className="text-sm"><strong>Row {error.row}:</strong> {error.message}</div>)}</div>}<Button variant="outline" onClick={reset} className="w-full"><Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />Import Another File</Button></CardContent></Card>}</div></div>
  </div>
}
