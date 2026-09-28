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
import api from '@/lib/api'

interface ImportResult {
  created: number
  updated: number
  errors: Array<{ row: number; message: string }>
}

const TEMPLATE_COLUMNS: ImportTemplateColumn[] = [
  { name: 'name', required: true, description: 'Product name (required)' },
  { name: 'sku', required: false, description: 'Stock Keeping Unit - auto-generated if empty' },
  { name: 'barcode', required: false, description: 'Product barcode (EAN, UPC, etc.)' },
  { name: 'description', required: false, description: 'Product description (also used as short description if not provided)' },
  { name: 'short_description', required: false, description: 'Short description - defaults to description if empty' },
  { name: 'secondary_name', required: false, description: 'Alternative name (e.g., artist name)' },
  { name: 'type', required: false, description: 'product or service (default: product)' },
  { name: 'category_id', required: false, description: 'Category ID (number) or Category Name (text) - new categories will be auto-created' },
  { name: 'cost', required: false, description: 'Purchase/cost price (default: 0)' },
  { name: 'vendor_price', required: false, description: 'Vendor quoted price' },
  { name: 'price', required: false, description: 'Selling price (default: 0)' },
  { name: 'stock_quantity', required: false, description: 'Initial stock quantity' },
  { name: 'track_inventory', required: false, description: 'true/false - whether to track stock' },
  { name: 'is_active', required: false, description: 'true/false - product visibility' },
  { name: 'image_url', required: false, description: 'URL to product image or product page (images will be downloaded)' },
  { name: 'manufacturer', required: false, description: 'Manufacturer or brand name' },
  { name: 'manufacturer_part_number', required: false, description: 'Manufacturer part number (MPN)' },
  { name: 'brand', required: false, description: 'Brand ID or name; missing brands are created automatically' },
  { name: 'unit', required: false, description: 'Unit of measure (e.g., PCS, BOX)' },
  { name: 'lot_number', required: false, description: 'Lot/batch number (for batch-tracked products)' },
  { name: 'expiry_date', required: false, description: 'Expiry date - accepts M/D/YYYY, M/D/YY, or YYYY-MM-DD' },
  { name: 'shelf_life_days', required: false, description: 'Default shelf life in days (for batch-tracked products)' },
]

export function ProductImportPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

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
      const totalProcessed = data.created + (data.updated || 0)
      const parts: string[] = []
      if (data.created > 0) parts.push(`${data.created} created`)
      if (data.updated > 0) parts.push(`${data.updated} updated`)
      if (errorCount > 0) parts.push(`${errorCount} errors`)
      if (totalProcessed > 0 && errorCount === 0) {
        toast({ title: 'Import successful!', description: `${parts.join(', ')}.` })
      } else if (totalProcessed > 0 && errorCount > 0) {
        toast({ title: 'Import completed with errors', description: `${parts.join(', ')}.` })
      } else if (totalProcessed === 0) {
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

  const handleDownloadTemplate = () => {
    const headers = TEMPLATE_COLUMNS.map((column) => column.name).join(',')
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
      '45.00',
      '99.99',
      '100',
      'true',
      'true',
      '',
      '',
      'MPN-1001',
      'Sample Brand',
      'PCS',
      'LOT-001',
      '12/31/2027',
      '365',
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

  const handleReset = () => {
    setSelectedFile(null)
    setImportResult(null)
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <ImportPageHeader
        title="Import Products"
        subtitle="Bulk import products from a CSV file"
        backTo="/products"
      />

      <div className="grid items-start gap-6 lg:grid-cols-2">
        <ImportStepCard
          step={1}
          title="Download Template"
          description="Start by downloading our CSV template. It includes all the fields you need."
          icon="solar:download-minimalistic-bold-duotone"
        >
          <HeaderActionButton variant="outline" icon="solar:document-text-bold-duotone" onClick={handleDownloadTemplate}>
            Download Import Template
          </HeaderActionButton>
          <ImportFieldTable columns={TEMPLATE_COLUMNS} />
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
            onClear={handleReset}
          />

          {selectedFile && !importResult && (
            <div className="space-y-3">
              <HeaderActionButton
                icon="solar:import-bold-duotone"
                loading={importMutation.isPending}
                disabled={importMutation.isPending}
                onClick={() => importMutation.mutate(selectedFile)}
              >
                Import Products
              </HeaderActionButton>
              {importMutation.isPending && <Progress value={undefined} className="w-full" />}
            </div>
          )}

          {importResult && (
            <ImportResults
              created={importResult.created}
              updated={importResult.updated || 0}
              errors={importResult.errors || []}
              onReset={handleReset}
              doneTo="/products"
              doneLabel="View Products"
            />
          )}
        </ImportStepCard>
      </div>
    </div>
  )
}

export default ProductImportPage