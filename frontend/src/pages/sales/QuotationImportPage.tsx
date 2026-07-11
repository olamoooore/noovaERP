import { useCallback, useRef, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Icon } from '@iconify/react'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function QuotationImportPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<{ created: number; errors: Array<{ row: number; message: string }> } | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      const response = await api.post('/quotations/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data
    },
    onSuccess: (data) => {
      setImportResult(data)
      if (data.errors.length === 0) {
        toast({ title: `Successfully imported ${data.created} quotation(s)` })
      } else {
        toast({
          variant: 'destructive',
          title: `Imported ${data.created} quotation(s) with ${data.errors.length} error(s)`,
        })
      }
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Import failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleFileChange = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (file) {
      setSelectedFile(file)
      setImportResult(null)
    }
  }, [])

  const handleImport = () => {
    if (selectedFile) {
      importMutation.mutate(selectedFile)
    }
  }

  const handleDrop = useCallback((event: React.DragEvent) => {
    event.preventDefault()
    const file = event.dataTransfer.files?.[0]
    if (file && (file.name.endsWith('.csv') || file.name.endsWith('.txt'))) {
      setSelectedFile(file)
      setImportResult(null)
    }
  }, [])

  const handleDragOver = useCallback((event: React.DragEvent) => {
    event.preventDefault()
  }, [])

  const downloadTemplate = () => {
    const headers = [
      'quotation_number',
      'customer_code',
      'customer_name',
      'quotation_date',
      'valid_until',
      'customer_reference',
      'product_sku',
      'description',
      'quantity',
      'unit_price',
      'tax_rate',
      'discount_amount',
      'notes',
      'terms',
      'discount_amount',
    ]
    const sampleRow = [
      'QUO-2026-0001',
      'CUST001',
      'Acme Corp',
      '2026-07-10',
      '2026-08-09',
      'PO-12345',
      'SKU-001',
      'Widget A',
      '10',
      '25.00',
      '10',
      '0',
      'Please deliver by end of month',
      'Net 30',
      '0',
    ]
    const csv = [headers.join(','), sampleRow.join(',')].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'quotation_import_template.csv'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" onClick={() => navigate('/quotations')}>
            <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div>
            <h1 className="text-3xl font-bold">Import Quotations</h1>
            <p className="text-muted-foreground mt-1">
              Import quotations from a CSV file. One row per line item, grouped by quotation_number.
            </p>
          </div>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Upload CSV</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div
            onDrop={handleDrop}
            onDragOver={handleDragOver}
            className="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-12 text-center hover:border-primary transition-colors"
          >
            <Icon icon="solar:import-bold" className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
            <p className="text-lg font-medium">Drag & drop a CSV file here</p>
            <p className="text-sm text-muted-foreground mt-1">or click to browse</p>
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv,.txt"
              onChange={handleFileChange}
              className="hidden"
            />
            <Button
              variant="outline"
              onClick={() => fileInputRef.current?.click()}
              className="mt-4"
            >
              Select File
            </Button>
          </div>

          {selectedFile && (
            <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div className="flex items-center gap-3">
                <Icon icon="solar:file-text-bold" className="h-5 w-5 text-muted-foreground" />
                <div>
                  <div className="text-sm font-medium">{selectedFile.name}</div>
                  <div className="text-xs text-muted-foreground">
                    {(selectedFile.size / 1024).toFixed(1)} KB
                  </div>
                </div>
              </div>
              <Button
                size="sm"
                onClick={handleImport}
                disabled={importMutation.isPending}
              >
                {importMutation.isPending ? 'Importing...' : 'Import'}
              </Button>
            </div>
          )}

          <div className="flex justify-between items-center pt-4 border-t">
            <Button variant="outline" onClick={downloadTemplate}>
              <Icon icon="solar:download-bold" className="mr-2 h-4 w-4" />
              Download Template
            </Button>
            <p className="text-xs text-muted-foreground">
              Template columns: quotation_number, customer_code, customer_name, quotation_date, valid_until, product_sku, description, quantity, unit_price, tax_rate
            </p>
          </div>
        </CardContent>
      </Card>

      {importResult && (
        <Card>
          <CardHeader>
            <CardTitle>Import Results</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3 p-3 bg-green-50 rounded-lg">
              <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-600" />
              <span className="text-sm font-medium text-green-800">
                {importResult.created} quotation(s) imported successfully
              </span>
            </div>

            {importResult.errors.length > 0 && (
              <div className="space-y-2">
                <div className="text-sm font-medium text-destructive">
                  {importResult.errors.length} error(s):
                </div>
                <div className="max-h-60 overflow-y-auto space-y-1">
                  {importResult.errors.map((error, index) => (
                    <div key={index} className="text-xs p-2 bg-red-50 rounded text-red-700">
                      Row {error.row}: {error.message}
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div className="flex gap-2 pt-2">
              <Button variant="outline" onClick={() => navigate('/quotations')}>
                View Quotations
              </Button>
              <Button variant="outline" onClick={() => { setSelectedFile(null); setImportResult(null) }}>
                Import More
              </Button>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

export default QuotationImportPage
