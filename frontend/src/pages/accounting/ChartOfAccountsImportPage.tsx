import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface ImportResult {
  created: number
  updated: number
  errors: Array<{ row: number; field?: string; message: string }>
}

const fields = [
  ['code', true, 'Unique account code. Existing codes are updated.'],
  ['name', true, 'Account name.'],
  ['type', true, 'asset, liability, equity, revenue, or expense.'],
  ['subtype', false, 'Optional reporting subtype.'],
  ['parent_code', false, 'Code of the parent account. It may appear later in the same file.'],
  ['description', false, 'Optional account description.'],
  ['is_active', false, 'true or false; defaults to true.'],
  ['opening_balance', false, 'Opening balance; defaults to 0.'],
] as const

const backPath = '/accounting/configuration/company?tab=chart-of-accounts'

export function ChartOfAccountsImportPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()
  const [dragActive, setDragActive] = useState(false)
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

  const acceptFile = (file?: File) => {
    if (!file) return
    if (!file.name.toLowerCase().endsWith('.csv')) {
      toast({ title: 'Invalid file', description: 'Please select a CSV file.', variant: 'destructive' })
      return
    }
    setSelectedFile(file)
    setResult(null)
  }

  const handleDrop = useCallback((event: React.DragEvent) => {
    event.preventDefault()
    setDragActive(false)
    acceptFile(event.dataTransfer.files[0])
  }, [])

  const downloadTemplate = async () => {
    try {
      const response = await api.get('/accounting/chart-of-accounts/import-template', { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = 'chart-of-accounts-import-template.csv'
      link.click()
      URL.revokeObjectURL(url)
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
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(backPath)}>
          <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Chart of Accounts</h1>
          <p className="text-muted-foreground">Create or update accounts from a CSV file</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Step 1: Download Template</CardTitle>
              <CardDescription>Use the standard column names so parent accounts and updates can be resolved correctly.</CardDescription>
            </CardHeader>
            <CardContent>
              <Button variant="outline" className="w-full" onClick={downloadTemplate}>
                <Icon icon="solar:download-minimalistic-bold" className="mr-2 h-4 w-4" /> Download Import Template
              </Button>
            </CardContent>
          </Card>
          <Card>
            <CardHeader><CardTitle>Template Fields</CardTitle></CardHeader>
            <CardContent className="max-h-[420px] overflow-y-auto">
              <Table>
                <TableHeader><TableRow><TableHead>Field</TableHead><TableHead>Description</TableHead></TableRow></TableHeader>
                <TableBody>{fields.map(([name, required, description]) => (
                  <TableRow key={name}><TableCell className="font-medium">{name}{required && <span className="text-destructive"> *</span>}</TableCell><TableCell className="text-sm text-muted-foreground">{description}</TableCell></TableRow>
                ))}</TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader><CardTitle>Step 2: Upload CSV File</CardTitle><CardDescription>Imported codes update existing accounts; new codes create accounts.</CardDescription></CardHeader>
            <CardContent className="space-y-4">
              <div
                className={`border-2 border-dashed rounded-lg p-8 text-center ${dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'}`}
                onDragEnter={(e) => { e.preventDefault(); setDragActive(true) }}
                onDragOver={(e) => e.preventDefault()}
                onDragLeave={(e) => { e.preventDefault(); setDragActive(false) }}
                onDrop={handleDrop}
              >
                {selectedFile ? <div className="space-y-2"><Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" /><p className="font-medium">{selectedFile.name}</p><Button variant="ghost" size="sm" onClick={reset}>Remove</Button></div> :
                  <div className="space-y-3"><Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" /><p>Drag and drop your CSV file here</p><Button variant="outline" size="sm" asChild><label className="cursor-pointer">Browse Files<input className="hidden" type="file" accept=".csv,text/csv" onChange={(e) => acceptFile(e.target.files?.[0])} /></label></Button></div>}
              </div>
              {selectedFile && !result && <Button className="w-full" disabled={mutation.isPending} onClick={() => mutation.mutate(selectedFile)}>{mutation.isPending ? 'Importing...' : 'Import Accounts'}</Button>}
              {mutation.isPending && <Progress value={undefined} />}
            </CardContent>
          </Card>

          {result && <Card><CardHeader><CardTitle>Import Results</CardTitle></CardHeader><CardContent className="space-y-4">
            <div className="grid grid-cols-3 gap-3 text-center"><div className="rounded-lg bg-green-50 p-3"><strong className="text-xl text-green-600">{result.created}</strong><div className="text-sm">Created</div></div><div className="rounded-lg bg-blue-50 p-3"><strong className="text-xl text-blue-600">{result.updated}</strong><div className="text-sm">Updated</div></div><div className="rounded-lg bg-red-50 p-3"><strong className="text-xl text-red-600">{result.errors.length}</strong><div className="text-sm">Errors</div></div></div>
            {result.errors.length > 0 && <Alert variant="destructive"><AlertTitle>Import Errors</AlertTitle><AlertDescription><div className="mt-2 max-h-52 overflow-y-auto">{result.errors.map((error, index) => <div key={index}>Row {error.row}: {error.message}</div>)}</div></AlertDescription></Alert>}
            <div className="flex gap-3"><Button variant="outline" className="flex-1" onClick={reset}>Import Another File</Button><Button className="flex-1" onClick={() => navigate(backPath)}>View Accounts</Button></div>
          </CardContent></Card>}
        </div>
      </div>
    </div>
  )
}

export default ChartOfAccountsImportPage
