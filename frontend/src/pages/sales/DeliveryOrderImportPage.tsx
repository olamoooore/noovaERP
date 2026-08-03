import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

interface ImportResult {
  created: number
  errors: Array<{ row: number; message: string; group?: string }>
}

const TEMPLATE_COLUMNS = [
  { name: 'delivery_order_number', required: false, description: 'Groups rows into one DO. Blank = auto-generated.' },
  { name: 'customer_code', required: true, description: 'Customer code (preferred). Falls back to customer_name.' },
  { name: 'customer_name', required: false, description: 'Customer name (used if customer_code is blank).' },
  { name: 'delivery_date', required: false, description: 'Delivery date (M/D/YYYY or YYYY-MM-DD). Default: today.' },
  { name: 'expected_date', required: false, description: 'Expected delivery date.' },
  { name: 'carrier', required: false, description: 'Carrier name (e.g. DHL, FedEx).' },
  { name: 'tracking_number', required: false, description: 'Tracking number.' },
  { name: 'shipping_address', required: false, description: 'Shipping address. Auto-filled from customer if blank.' },
  { name: 'attention_to', required: false, description: 'Attention to (e.g. Warehouse Manager).' },
  { name: 'po_number', required: false, description: 'Purchase order number.' },
  { name: 'salesperson', required: false, description: 'Salesperson name.' },
  { name: 'invoice_number', required: false, description: 'Linked invoice number.' },
  { name: 'quotation_number', required: false, description: 'Linked quotation number.' },
  { name: 'product_sku', required: false, description: 'Product SKU (links to product).' },
  { name: 'model_number', required: false, description: 'Model / part number. Auto-filled from product if SKU given.' },
  { name: 'description', required: false, description: 'Line item description.' },
  { name: 'product_notes', required: false, description: 'Product notes (free text).' },
  { name: 'quantity', required: true, description: 'Quantity to deliver.' },
  { name: 'unit', required: false, description: 'Unit of measure. Default: pcs.' },
  { name: 'unit_price', required: false, description: 'Unit price per item.' },
  { name: 'patient_name', required: false, description: 'Patient name for this line item.' },
]

const csvValue = (v: string) => {
  if (/[",\n]/.test(v)) {
    return `"${v.replace(/"/g, '""')}"`
  }
  return v
}

export function DeliveryOrderImportPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [dragActive, setDragActive] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      const res = await api.post('/sales/delivery-orders/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return res.data
    },
    onSuccess: (data) => {
      setImportResult({ created: data.created, errors: data.errors || [] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      toast({
        title: 'Import complete',
        description: `${data.created} delivery order(s) created.`,
      })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Import failed',
        description: error.response?.data?.message || 'An error occurred',
      })
    },
  })

  const handleFile = useCallback((file: File) => {
    if (!file.name.endsWith('.csv') && !file.name.endsWith('.txt')) {
      toast({ title: 'Invalid file type', description: 'Please select a CSV file.', variant: 'destructive' })
      return
    }
    setSelectedFile(file)
    setImportResult(null)
  }, [toast])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    setDragActive(false)
    if (e.dataTransfer.files?.[0]) handleFile(e.dataTransfer.files[0])
  }, [handleFile])

  const handleImport = () => {
    if (!selectedFile) return
    importMutation.mutate(selectedFile)
  }

  const downloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((c) => c.name)
    const example = TEMPLATE_COLUMNS.map((c) => {
      switch (c.name) {
        case 'customer_code': return 'CUST-001'
        case 'delivery_date': return '2026-07-14'
        case 'expected_date': return '2026-07-15'
        case 'carrier': return 'DHL'
        case 'quantity': return '10'
        case 'unit': return 'pcs'
        case 'unit_price': return '50.00'
        case 'patient_name': return 'Jane Doe'
        default: return ''
      }
    })
    const csv = [headers.join(','), example.map(csvValue).join(',')].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'delivery-order-import-template.csv'
    a.click()
    URL.revokeObjectURL(url)
  }

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate('/delivery-orders')}>
          <Icon icon="solar:arrow-left-bold-duotone" className="h-4 w-4 mr-2" />
          Back
        </Button>
        <div>
          <h1 className="text-3xl font-bold">Import Delivery Orders</h1>
          <p className="text-muted-foreground mt-1">Upload a CSV file to create delivery orders in bulk.</p>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Upload CSV</CardTitle>
          <CardDescription>
            Each row group (by delivery_order_number) becomes one delivery order with line items.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div
            className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
              dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'
            }`}
            onDragOver={(e) => { e.preventDefault(); setDragActive(true) }}
            onDragLeave={() => setDragActive(false)}
            onDrop={handleDrop}
          >
            <Icon icon="solar:upload-bold-duotone" className="mx-auto h-12 w-12 text-muted-foreground/50" />
            <p className="mt-4 text-sm text-muted-foreground">
              Drag and drop a CSV file here, or{' '}
              <label className="text-primary cursor-pointer hover:underline">
                browse
                <input type="file" className="hidden" accept=".csv,.txt" onChange={(e) => e.target.files?.[0] && handleFile(e.target.files[0])} />
              </label>
            </p>
            {selectedFile && (
              <p className="mt-2 text-sm font-medium text-green-600">{selectedFile.name}</p>
            )}
          </div>

          <div className="flex gap-3">
            <Button variant="outline" onClick={downloadTemplate}>
              <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
              Download Template
            </Button>
            <Button onClick={handleImport} disabled={!selectedFile || importMutation.isPending}>
              {importMutation.isPending ? 'Importing...' : 'Import'}
            </Button>
          </div>
        </CardContent>
      </Card>

      {importResult && (
        <Card>
          <CardHeader>
            <CardTitle>Import Results</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="p-4 rounded-lg bg-green-50 border border-green-200">
                <div className="text-2xl font-bold text-green-700">{importResult.created}</div>
                <div className="text-sm text-green-600">Delivery Orders Created</div>
              </div>
              <div className="p-4 rounded-lg bg-red-50 border border-red-200">
                <div className="text-2xl font-bold text-red-700">{importResult.errors.length}</div>
                <div className="text-sm text-red-600">Errors</div>
              </div>
            </div>

            {importResult.errors.length > 0 && (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Row / Group</TableHead>
                    <TableHead>Error</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {importResult.errors.map((err, idx) => (
                    <TableRow key={idx}>
                      <TableCell>{err.group || err.row}</TableCell>
                      <TableCell className="text-red-600">{err.message}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}

            <Button variant="outline" onClick={() => navigate('/delivery-orders')}>
              Go to Delivery Orders
            </Button>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>CSV Format Reference</CardTitle>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Column</TableHead>
                <TableHead>Required</TableHead>
                <TableHead>Description</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {TEMPLATE_COLUMNS.map((col) => (
                <TableRow key={col.name}>
                  <TableCell className="font-mono text-sm">{col.name}</TableCell>
                  <TableCell>{col.required ? 'Yes' : 'No'}</TableCell>
                  <TableCell className="text-muted-foreground">{col.description}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
}

export default DeliveryOrderImportPage
