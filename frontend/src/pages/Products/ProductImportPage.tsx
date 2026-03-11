import { useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { 
  Table, 
  TableBody, 
  TableCell, 
  TableHead, 
  TableHeader, 
  TableRow 
} from '@/components/ui/table'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

interface ImportResult {
  created: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS = [
  { name: 'name', required: true, description: 'Product name (required)' },
  { name: 'sku', required: false, description: 'Stock Keeping Unit - auto-generated if empty' },
  { name: 'barcode', required: false, description: 'Product barcode (EAN, UPC, etc.)' },
  { name: 'description', required: false, description: 'Product description (also used as short description if not provided)' },
  { name: 'short_description', required: false, description: 'Short description - defaults to description if empty' },
  { name: 'secondary_name', required: false, description: 'Alternative name (e.g., artist name)' },
  { name: 'type', required: false, description: 'product or service (default: product)' },
  { name: 'category_id', required: false, description: 'Category ID (number) or Category Name (text) - new categories will be auto-created' },
  { name: 'cost', required: false, description: 'Purchase/cost price (default: 0)' },
  { name: 'price', required: false, description: 'Selling price (default: 0)' },
  { name: 'stock_quantity', required: false, description: 'Initial stock quantity' },
  { name: 'track_inventory', required: false, description: 'true/false - whether to track stock' },
  { name: 'is_active', required: false, description: 'true/false - product visibility' },
  { name: 'image_url', required: false, description: 'URL to product image or product page (images will be downloaded)' },
  { name: 'manufacturer', required: false, description: 'Manufacturer or brand name' },
  { name: 'unit', required: false, description: 'Unit of measure (e.g., PCS, BOX)' },
]

export function ProductImportPage() {
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [dragActive, setDragActive] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)

  const importMutation = useMutation({
    mutationFn: async (file: File) => {
      const fd = new FormData()
      fd.append('file', file)
      const response = await api.post('/products/import', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return response.data as ImportResult
    },
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      setImportResult(data)
      const errorCount = data.errors?.length || 0
      if (data.created > 0 && errorCount === 0) {
        toast({
          title: 'Import successful!',
          description: `${data.created} products have been imported.`,
        })
      } else if (data.created > 0 && errorCount > 0) {
        toast({
          title: 'Import completed with errors',
          description: `${data.created} products imported, ${errorCount} rows had errors.`,
          variant: 'default',
        })
      } else if (data.created === 0) {
        toast({
          title: 'Import failed',
          description: 'No products were imported. Please check the errors below.',
          variant: 'destructive',
        })
      }
    },
    onError: (error: Error) => {
      toast({
        title: 'Import failed',
        description: error.message || 'An error occurred during import.',
        variant: 'destructive',
      })
    },
  })

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)
    
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      const file = e.dataTransfer.files[0]
      if (file.type === 'text/csv' || file.name.endsWith('.csv')) {
        setSelectedFile(file)
        setImportResult(null)
      } else {
        toast({
          title: 'Invalid file type',
          description: 'Please upload a CSV file.',
          variant: 'destructive',
        })
      }
    }
  }, [toast])

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      setSelectedFile(e.target.files[0])
      setImportResult(null)
    }
  }

  const handleDownloadTemplate = () => {
    // Generate CSV template
    const headers = TEMPLATE_COLUMNS.map(col => col.name).join(',')
    const sampleRow = [
      'Sample Product',
      'SAMPLE-001',
      '1234567890123',
      'This is a sample product description',
      'Short description text',
      '',
      'product',
      '',
      '50.00',
      '99.99',
      '100',
      'true',
      'true',
      '',
      '',
      'PCS',
    ].join(',')
    
    const csvContent = `${headers}\n${sampleRow}`
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = 'product_import_template.csv'
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)
    
    toast({
      title: 'Template downloaded',
      description: 'Fill in the template and upload it to import products.',
    })
  }

  const handleImport = () => {
    if (selectedFile) {
      importMutation.mutate(selectedFile)
    }
  }

  const handleReset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate('/products')}>
          <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
        </Button>
        <div>
          <h1 className="text-2xl font-bold">Import Products</h1>
          <p className="text-muted-foreground">Bulk import products from a CSV file</p>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        {/* Left Column - Instructions & Template */}
        <div className="space-y-6">
          {/* Download Template Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:download-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 1: Download Template
              </CardTitle>
              <CardDescription>
                Start by downloading our CSV template. It includes all the fields you need.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button onClick={handleDownloadTemplate} className="w-full" variant="outline">
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Download Import Template
              </Button>
            </CardContent>
          </Card>

          {/* Template Fields Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:list-check-bold" className="h-5 w-5 text-primary" />
                Template Fields
              </CardTitle>
              <CardDescription>
                Required fields are marked with an asterisk (*).
              </CardDescription>
            </CardHeader>
            <CardContent className="max-h-[400px] overflow-y-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[150px]">Field</TableHead>
                    <TableHead>Description</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {TEMPLATE_COLUMNS.map((col) => (
                    <TableRow key={col.name}>
                      <TableCell className="font-medium">
                        {col.name}
                        {col.required && <span className="text-destructive ml-1">*</span>}
                      </TableCell>
                      <TableCell className="text-muted-foreground text-sm">
                        {col.description}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </div>

        {/* Right Column - Upload */}
        <div className="space-y-6">
          {/* Upload Card */}
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:upload-minimalistic-bold" className="h-5 w-5 text-primary" />
                Step 2: Upload CSV File
              </CardTitle>
              <CardDescription>
                Fill in the template and upload it here.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Dropzone */}
              <div
                className={`border-2 border-dashed rounded-lg p-8 text-center transition-colors ${
                  dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25'
                } ${selectedFile ? 'bg-muted/50' : ''}`}
                onDragEnter={handleDrag}
                onDragLeave={handleDrag}
                onDragOver={handleDrag}
                onDrop={handleDrop}
              >
                {selectedFile ? (
                  <div className="space-y-3">
                    <Icon icon="solar:file-check-bold" className="h-12 w-12 mx-auto text-green-500" />
                    <div>
                      <p className="font-medium">{selectedFile.name}</p>
                      <p className="text-sm text-muted-foreground">
                        {(selectedFile.size / 1024).toFixed(2)} KB
                      </p>
                    </div>
                    <Button variant="ghost" size="sm" onClick={handleReset}>
                      <Icon icon="solar:trash-bin-trash-bold" className="mr-2 h-4 w-4" />
                      Remove
                    </Button>
                  </div>
                ) : (
                  <div className="space-y-3">
                    <Icon icon="solar:cloud-upload-bold-duotone" className="h-12 w-12 mx-auto text-muted-foreground" />
                    <div>
                      <p className="font-medium">Drag and drop your CSV file here</p>
                      <p className="text-sm text-muted-foreground">or click the button below to browse</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                      <label className="cursor-pointer">
                        <Icon icon="solar:folder-open-bold" className="mr-2 h-4 w-4" />
                        Browse Files
                        <input
                          type="file"
                          accept=".csv,text/csv"
                          onChange={handleFileSelect}
                          className="hidden"
                        />
                      </label>
                    </Button>
                  </div>
                )}
              </div>

              {/* Import Button */}
              {selectedFile && !importResult && (
                <Button 
                  onClick={handleImport} 
                  className="w-full" 
                  disabled={importMutation.isPending}
                >
                  {importMutation.isPending ? (
                    <>
                      <Icon icon="solar:refresh-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                      Importing...
                    </>
                  ) : (
                    <>
                      <Icon icon="solar:import-bold" className="mr-2 h-4 w-4" />
                      Import Products
                    </>
                  )}
                </Button>
              )}

              {/* Progress */}
              {importMutation.isPending && (
                <Progress value={undefined} className="w-full" />
              )}
            </CardContent>
          </Card>

          {/* Import Results Card */}
          {importResult && (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon 
                    icon={importResult.created > 0 ? "solar:check-circle-bold" : "solar:close-circle-bold"} 
                    className={`h-5 w-5 ${importResult.created > 0 ? 'text-green-500' : 'text-destructive'}`} 
                  />
                  Import Results
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                {/* Summary */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="text-center p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-green-600">{importResult.created}</div>
                    <div className="text-sm text-muted-foreground">Products Imported</div>
                  </div>
                  <div className="text-center p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                    <div className="text-2xl font-bold text-red-600">{importResult.errors.length}</div>
                    <div className="text-sm text-muted-foreground">Errors</div>
                  </div>
                </div>

                {/* Errors List */}
                {importResult.errors.length > 0 && (
                  <Alert variant="destructive">
                    <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
                    <AlertTitle>Import Errors</AlertTitle>
                    <AlertDescription>
                      <div className="mt-2 max-h-[200px] overflow-y-auto space-y-1 text-sm">
                        {importResult.errors.map((err, idx) => (
                          <div key={idx} className="flex gap-2">
                            <span className="font-medium">Row {err.row}:</span>
                            <span>{err.message}</span>
                          </div>
                        ))}
                      </div>
                    </AlertDescription>
                  </Alert>
                )}

                {/* Actions */}
                <div className="flex gap-3">
                  <Button variant="outline" onClick={handleReset} className="flex-1">
                    <Icon icon="solar:upload-bold" className="mr-2 h-4 w-4" />
                    Import Another File
                  </Button>
                  <Button onClick={() => navigate('/products')} className="flex-1">
                    <Icon icon="solar:box-bold" className="mr-2 h-4 w-4" />
                    View Products
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}
