import { useCallback, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { useToast } from '@/hooks/use-toast'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

type TrackingType = 'batches' | 'serials'

const FIELDS = {
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
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const initialType = searchParams.get('type') === 'serials' ? 'serials' : 'batches'
  const [type, setType] = useState<TrackingType>(initialType)
  const [dragActive, setDragActive] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [reasonId, setReasonId] = useState('')
  const [result, setResult] = useState<any>(null)

  const { data: reasons = [] } = useQuery({
    queryKey: ['adjustment-reasons'],
    queryFn: async () => (await api.get('/stock/adjustment-reasons')).data || [],
  })

  const importMutation = useMutation({
    mutationFn: async () => {
      const form = new FormData()
      form.append('file', selectedFile as File)
      form.append('adjustment_reason_id', reasonId)
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

  const selectFile = (file?: File) => {
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.csv')) {
      toast({ title: 'Invalid file type', description: 'Please upload a CSV file.', variant: 'destructive' })
      return
    }
    setSelectedFile(file)
    setResult(null)
  }
  const handleDrag = useCallback((event: React.DragEvent) => {
    event.preventDefault()
    event.stopPropagation()
    setDragActive(event.type === 'dragenter' || event.type === 'dragover')
  }, [])

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

  return (
    <div className="mx-auto max-w-5xl space-y-6 p-6">
      <div className="flex items-center gap-4"><Button variant="ghost" size="icon" onClick={() => navigate('/inventory/tracking')}><Icon icon="solar:arrow-left-linear" className="h-5 w-5" /></Button><div><h1 className="text-2xl font-bold">Import Lots / Serial Numbers</h1><p className="text-muted-foreground">Bulk receive tracked inventory from a CSV file.</p></div></div>

      <Card><CardContent className="pt-6"><div className="max-w-sm space-y-2"><label className="text-sm font-medium">Import type</label><Select value={type} onValueChange={(value) => changeType(value as TrackingType)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="batches">Lots / Batches</SelectItem><SelectItem value="serials">Serial Numbers</SelectItem></SelectContent></Select></div></CardContent></Card>

      <div className="grid gap-6 md:grid-cols-2">
        <div className="space-y-6">
          <Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />Step 1: Download Template</CardTitle><CardDescription>{type === 'batches' ? 'One row per product lot/batch.' : 'One row per serial number. Each row receives one inventory unit.'}</CardDescription></CardHeader><CardContent><Button variant="outline" className="w-full" onClick={downloadTemplate}><Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />Download Import Template</Button></CardContent></Card>

          <Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />Template Fields</CardTitle><CardDescription>Required fields are marked with an asterisk (*).</CardDescription></CardHeader><CardContent><Table><TableHeader><TableRow><TableHead>Field</TableHead><TableHead>Description</TableHead></TableRow></TableHeader><TableBody>{FIELDS[type].map((field) => <TableRow key={field.name}><TableCell className="font-medium">{field.name}{field.required && <span className="ml-1 text-destructive">*</span>}</TableCell><TableCell className="text-sm text-muted-foreground">{field.description}</TableCell></TableRow>)}</TableBody></Table></CardContent></Card>
        </div>

        <div className="space-y-6">
          <Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />Step 2: Upload CSV File</CardTitle><CardDescription>Select the accounting reason and upload the completed template.</CardDescription></CardHeader><CardContent className="space-y-4">
            <div className="space-y-2"><label className="text-sm font-medium">Stock-in reason *</label><Select value={reasonId} onValueChange={setReasonId}><SelectTrigger><SelectValue placeholder="Select reason" /></SelectTrigger><SelectContent>{reasons.filter((reason: any) => ['increase', 'both'].includes(reason.type)).map((reason: any) => <SelectItem key={reason.id} value={String(reason.id)}>{reason.name}</SelectItem>)}</SelectContent></Select></div>
            <div className={`rounded-lg border-2 border-dashed p-8 text-center transition-colors ${dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'} ${selectedFile ? 'bg-muted/50' : ''}`} onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={(event) => { handleDrag(event); setDragActive(false); selectFile(event.dataTransfer.files?.[0]) }}>
              {selectedFile ? <div className="space-y-3"><Icon icon="solar:file-check-bold" className="mx-auto h-12 w-12 text-green-500" /><div><p className="font-medium">{selectedFile.name}</p><p className="text-sm text-muted-foreground">{(selectedFile.size / 1024).toFixed(2)} KB</p></div><Button variant="ghost" size="sm" onClick={() => { setSelectedFile(null); setResult(null) }}><Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />Remove</Button></div> : <div className="space-y-3"><Icon icon="solar:cloud-upload-bold-duotone" className="mx-auto h-12 w-12 text-muted-foreground" /><div><p className="font-medium">Drag and drop your CSV file here</p><p className="text-sm text-muted-foreground">or click below to browse</p></div><Button variant="outline" size="sm" asChild><label className="cursor-pointer"><Icon icon="solar:folder-open-bold" className="mr-2 h-4 w-4" />Browse Files<input type="file" accept=".csv,text/csv" className="hidden" onChange={(event) => selectFile(event.target.files?.[0])} /></label></Button></div>}
            </div>
            {selectedFile && !result && <Button className="w-full" disabled={!reasonId || importMutation.isPending} onClick={() => importMutation.mutate()}>{importMutation.isPending ? <><Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />Importing…</> : <><Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />Import {type === 'batches' ? 'Lots / Batches' : 'Serial Numbers'}</>}</Button>}
            {importMutation.isPending && <Progress value={undefined} />}
          </CardContent></Card>

          {result && <Card><CardHeader><CardTitle className="flex items-center gap-2"><Icon icon={result.imported > 0 ? 'solar:check-circle-bold' : 'solar:close-circle-bold'} className={result.imported > 0 ? 'h-5 w-5 text-green-500' : 'h-5 w-5 text-destructive'} />Import Results</CardTitle></CardHeader><CardContent className="space-y-4"><div className="grid grid-cols-2 gap-4"><div className="rounded-lg bg-green-50 p-4 text-center dark:bg-green-950/20"><div className="text-2xl font-bold text-green-600">{result.imported}</div><div className="text-sm text-muted-foreground">Imported</div></div><div className="rounded-lg bg-red-50 p-4 text-center dark:bg-red-950/20"><div className="text-2xl font-bold text-red-600">{result.failed}</div><div className="text-sm text-muted-foreground">Errors</div></div></div>
            {result.failed > 0 && <Alert variant="destructive"><Icon icon="solar:danger-triangle-bold" className="h-4 w-4" /><AlertTitle>Import Errors</AlertTitle><AlertDescription><div className="mt-2 max-h-64 space-y-1 overflow-y-auto text-sm">{result.results.filter((row: any) => row.status === 'failed').map((row: any) => <div key={row.row}><span className="font-medium">Row {row.row}:</span> {row.message}</div>)}</div></AlertDescription></Alert>}
            <div className="flex gap-3"><Button variant="outline" className="flex-1" onClick={() => { setSelectedFile(null); setResult(null) }}><Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />Import Another File</Button><Button className="flex-1" onClick={() => navigate('/inventory/tracking')}><Icon icon="solar:box-bold-duotone" className="mr-2 h-4 w-4" />View Tracking List</Button></div></CardContent></Card>}
        </div>
      </div>
    </div>
  )
}

export default InventoryTrackingImportPage
