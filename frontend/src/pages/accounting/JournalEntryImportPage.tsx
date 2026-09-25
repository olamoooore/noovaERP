import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface ImportResult {
  created: number
  errors: Array<{ row: number; entry_key?: string; message: string }>
}

const columns = [
  ['entry_key', true, 'Groups lines into one journal entry (for example, JE-001).'],
  ['entry_date', true, 'Entry date in YYYY-MM-DD format.'],
  ['journal_code', false, 'Existing journal code. Leave blank for no journal.'],
  ['reference', false, 'External or internal reference.'],
  ['description', true, 'Journal entry description.'],
  ['remark', false, 'Optional journal entry remark (maximum 255 characters).'],
  ['account_code', true, 'Existing chart-of-accounts code.'],
  ['line_description', false, 'Description for this entry line.'],
  ['debit', true, 'Debit amount; use 0 for a credit line.'],
  ['credit', true, 'Credit amount; use 0 for a debit line.'],
] as const

export default function JournalEntryImportPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [dragActive, setDragActive] = useState(false)
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

  const chooseFile = (selected?: File) => {
    if (!selected) return
    if (!selected.name.toLowerCase().endsWith('.csv')) {
      toast({ title: 'Invalid file type', description: 'Please select a CSV file.', variant: 'destructive' })
      return
    }
    setFile(selected)
    setResult(null)
  }

  const handleDrag = useCallback((event: React.DragEvent) => {
    event.preventDefault()
    event.stopPropagation()
    setDragActive(event.type === 'dragenter' || event.type === 'dragover')
  }, [])

  const downloadTemplate = () => {
    const header = columns.map(([name]) => name).join(',')
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
  }

  const reset = () => {
    setFile(null)
    setResult(null)
  }

  const dropClassName = [
    'border-2 border-dashed rounded-lg p-8 text-center',
    dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25',
    file ? 'bg-muted/50' : '',
  ].join(' ')

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div>
        <Button
          variant="ghost"
          size="sm"
          className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
          onClick={() => navigate('/accounting/core/journal-entries')}
        >
          <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
          Back
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Journal Entries</h1>
          <p className="text-muted-foreground">Bulk create editable draft journal entries from a CSV file</p>
        </div>
      </div>
      <div className="grid gap-6 md:grid-cols-2">
        <div className="space-y-6">
          <Card>
            <CardHeader><CardTitle>Step 1: Download Template</CardTitle><CardDescription>Rows with the same entry_key are combined. Each entry must balance.</CardDescription></CardHeader>
            <CardContent><Button variant="outline" className="w-full" onClick={downloadTemplate}><Icon icon="solar:download-minimalistic-bold" className="h-4 w-4 mr-2" />Download Import Template</Button></CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Template Fields</CardTitle><CardDescription>Required fields are marked with an asterisk (*).</CardDescription></CardHeader>
            <CardContent className="max-h-[430px] overflow-y-auto">
              <Table>
                <TableHeader><TableRow><TableHead>Field</TableHead><TableHead>Description</TableHead></TableRow></TableHeader>
                <TableBody>{columns.map(([name, required, description]) => <TableRow key={name}><TableCell className="font-medium">{name}{required && <span className="text-destructive ml-1">*</span>}</TableCell><TableCell className="text-sm text-muted-foreground">{description}</TableCell></TableRow>)}</TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>
        <div className="space-y-6">
          <Card>
            <CardHeader><CardTitle>Step 2: Upload CSV File</CardTitle><CardDescription>Imported entries are saved as drafts so they can be reviewed and edited before posting.</CardDescription></CardHeader>
            <CardContent className="space-y-4">
              <div className={dropClassName} onDragEnter={handleDrag} onDragLeave={handleDrag} onDragOver={handleDrag} onDrop={(event) => { handleDrag(event); chooseFile(event.dataTransfer.files[0]) }}>
                {file ? <div className="space-y-3"><Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" /><p className="font-medium">{file.name}</p><p className="text-sm text-muted-foreground">{(file.size / 1024).toFixed(2)} KB</p><Button variant="ghost" size="sm" onClick={reset}>Remove</Button></div>
                  : <div className="space-y-3"><Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" /><p className="font-medium">Drag and drop your CSV file here</p><Button variant="outline" size="sm" asChild><label className="cursor-pointer">Browse Files<input className="hidden" type="file" accept=".csv,text/csv" onChange={(event) => chooseFile(event.target.files?.[0])} /></label></Button></div>}
              </div>
              {file && !result && <Button className="w-full" disabled={mutation.isPending} onClick={() => mutation.mutate(file)}>{mutation.isPending ? 'Importing...' : 'Import Journal Entries'}</Button>}
              {mutation.isPending && <Progress value={undefined} />}
            </CardContent>
          </Card>
          {result && <Card><CardHeader><CardTitle>Import Results</CardTitle></CardHeader><CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4"><div className="p-4 text-center rounded-lg bg-green-50 dark:bg-green-950/20"><div className="text-2xl font-bold text-green-600">{result.created}</div><div className="text-sm text-muted-foreground">Drafts created</div></div><div className="p-4 text-center rounded-lg bg-red-50 dark:bg-red-950/20"><div className="text-2xl font-bold text-red-600">{result.errors.length}</div><div className="text-sm text-muted-foreground">Errors</div></div></div>
            {result.errors.length > 0 && <Alert variant="destructive"><AlertTitle>Import Errors</AlertTitle><AlertDescription><div className="mt-2 max-h-52 overflow-y-auto space-y-1">{result.errors.map((error, index) => <p key={index} className="text-sm">{error.entry_key ? error.entry_key + ' — ' : ''}Row {error.row}: {error.message}</p>)}</div></AlertDescription></Alert>}
            <div className="flex gap-3"><Button variant="outline" className="flex-1" onClick={reset}>Import Another File</Button><Button className="flex-1" onClick={() => navigate('/accounting/core/journal-entries')}>View Journal Entries</Button></div>
          </CardContent></Card>}
        </div>
      </div>
    </div>
  )
}
