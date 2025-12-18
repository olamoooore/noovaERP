import { useState, useRef } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Checkbox } from '@/components/ui/checkbox'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'
import Barcode from 'react-barcode'

interface Product {
  id: number
  name: string
  sku: string
  barcode?: string
  price: number
  stock: number
}

interface BarcodeSettings {
  barcode_type: string
  barcode_format: string
  show_price: boolean
  show_name: boolean
  show_sku: boolean
  bar_width: number
  bar_height: number
}

export function POSBarcodePage() {
  const { toast } = useToast()
  const printRef = useRef<HTMLDivElement>(null)
  const [search, setSearch] = useState('')
  const [selectedProducts, setSelectedProducts] = useState<number[]>([])
  const [printQuantity, setPrintQuantity] = useState(1)
  const [showPrintDialog, setShowPrintDialog] = useState(false)
  const [showSettingsDialog, setShowSettingsDialog] = useState(false)

  // Fetch products with barcodes
  const { data: productsData, isLoading } = useQuery({
    queryKey: ['pos-barcodes', search],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 100 }
      if (search) params.search = search
      const response = await api.get('/pos/barcodes', { params })
      return response.data
    },
  })

  // Fetch barcode settings
  const { data: settings } = useQuery<BarcodeSettings>({
    queryKey: ['barcode-settings'],
    queryFn: async () => {
      const response = await api.get('/pos/barcodes/settings')
      return response.data
    },
  })

  // Generate print data
  const printMutation = useMutation({
    mutationFn: async (data: { product_ids?: number[]; quantity: number; all?: boolean; search?: string }) => {
      const response = await api.post('/pos/barcodes/print', data)
      return response.data
    },
    onSuccess: () => {
      setShowPrintDialog(true)
    },
    onError: () => {
      toast({
        title: "Error",
        description: "Failed to generate barcodes",
        variant: "destructive",
      })
    },
  })

  const products = productsData?.data || productsData || []

  const toggleProduct = (productId: number) => {
    setSelectedProducts(prev => 
      prev.includes(productId) 
        ? prev.filter(id => id !== productId)
        : [...prev, productId]
    )
  }

  const toggleAll = () => {
    if (selectedProducts.length === products.length) {
      setSelectedProducts([])
    } else {
      setSelectedProducts(products.map((p: Product) => p.id))
    }
  }

  const handlePrint = () => {
    if (selectedProducts.length === 0) {
      toast({
        title: "No products selected",
        description: "Please select at least one product to print barcodes",
        variant: "destructive",
      })
      return
    }
    printMutation.mutate({
      product_ids: selectedProducts,
      quantity: printQuantity,
    })
  }

  const handlePrintAll = () => {
    printMutation.mutate({
      all: true,
      search: search || undefined,
      quantity: printQuantity,
    })
  }

  const doPrint = () => {
    if (printRef.current) {
      const printContent = printRef.current.innerHTML
      const printWindow = window.open('', '_blank')
      if (printWindow) {
        printWindow.document.write(`
          <!DOCTYPE html>
          <html>
          <head>
            <title>Print Barcodes</title>
            <style>
              body { font-family: sans-serif; margin: 20px; }
              .barcode-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
              .barcode-item { text-align: center; padding: 10px; border: 1px dashed #ccc; }
              .barcode-name { font-size: 12px; font-weight: bold; margin-bottom: 5px; }
              .barcode-sku { font-size: 10px; color: #666; margin-bottom: 5px; }
              .barcode-price { font-size: 14px; font-weight: bold; margin-top: 5px; }
              @media print {
                .barcode-item { border: none; page-break-inside: avoid; }
              }
            </style>
          </head>
          <body>
            ${printContent}
          </body>
          </html>
        `)
        printWindow.document.close()
        printWindow.print()
      }
    }
  }

  const selectedProductsData = products.filter((p: Product) => selectedProducts.includes(p.id))

  return (
    <div className="flex h-full flex-col gap-4 p-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Product Barcodes</h1>
          <p className="text-muted-foreground">Search products and print barcodes</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handlePrintAll} disabled={printMutation.isPending}>
            <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
            Generate All
          </Button>
          <Button variant="outline" onClick={() => setShowSettingsDialog(true)}>
            <Icon icon="solar:settings-bold" className="h-4 w-4 mr-2" />
            Settings
          </Button>
          <Button onClick={handlePrint} disabled={selectedProducts.length === 0}>
            <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
            Print Selected ({selectedProducts.length})
          </Button>
        </div>
      </div>

      {/* Search and Quantity */}
      <Card>
        <CardContent className="pt-4">
          <div className="flex flex-wrap gap-4 items-end">
            <div className="flex-1 min-w-[250px]">
              <label className="text-sm font-medium mb-2 block">Search Products</label>
              <div className="relative">
                <Icon icon="solar:magnifer-bold" className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search by name, SKU, or barcode..."
                  className="pl-9"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
            </div>
            <div className="w-[150px]">
              <label className="text-sm font-medium mb-2 block">Quantity per Product</label>
              <Input
                type="number"
                min={1}
                max={100}
                value={printQuantity}
                onChange={(e) => setPrintQuantity(Number(e.target.value) || 1)}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Products Table */}
      <Card className="flex-1">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
                  <CardTitle>Products</CardTitle>
            {products.length > 0 && (
              <Button variant="ghost" size="sm" onClick={toggleAll}>
                {selectedProducts.length === products.length ? 'Deselect All' : 'Select All'}
              </Button>
            )}
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center h-64">
              <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : products.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
              <Icon icon="solar:barcode-bold" className="h-16 w-16 mb-4 opacity-50" />
              <p>No products found</p>
              <Link to="/products" className="mt-2">
                <Button variant="link">Go to Products</Button>
              </Link>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">
                    <Checkbox
                      checked={selectedProducts.length === products.length && products.length > 0}
                      onCheckedChange={toggleAll}
                    />
                  </TableHead>
                  <TableHead>Product</TableHead>
                  <TableHead>SKU</TableHead>
                  <TableHead>Barcode</TableHead>
                  <TableHead>Price</TableHead>
                  <TableHead>Stock</TableHead>
                  <TableHead className="w-[200px]">Preview</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {products.map((product: Product) => (
                  <TableRow key={product.id}>
                    <TableCell>
                      <Checkbox
                        checked={selectedProducts.includes(product.id)}
                        onCheckedChange={() => toggleProduct(product.id)}
                      />
                    </TableCell>
                    <TableCell className="font-medium">{product.name}</TableCell>
                    <TableCell>
                      <code className="text-xs bg-muted px-2 py-1 rounded">{product.sku}</code>
                    </TableCell>
                    <TableCell>
                      <code className="text-xs bg-muted px-2 py-1 rounded">{product.barcode || product.sku}</code>
                    </TableCell>
                    <TableCell>{formatCurrency(Number(product.price))}</TableCell>
                    <TableCell>
                      <Badge variant={product.stock > 10 ? "secondary" : product.stock > 0 ? "outline" : "destructive"}>
                        {product.stock}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="scale-75 origin-left">
                        <Barcode
                          value={product.barcode || product.sku}
                          width={1}
                          height={30}
                          fontSize={10}
                          displayValue={false}
                        />
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Print Preview Dialog */}
      <Dialog open={showPrintDialog} onOpenChange={setShowPrintDialog}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Print Preview</DialogTitle>
          </DialogHeader>
          
          <div ref={printRef} className="barcode-grid grid grid-cols-3 gap-4 p-4">
            {selectedProductsData.map((product: Product) => (
              Array.from({ length: printQuantity }).map((_, idx) => (
                <div key={`${product.id}-${idx}`} className="barcode-item text-center p-3 border border-dashed rounded">
                  {settings?.show_name && (
                    <div className="barcode-name text-xs font-bold mb-1 truncate">{product.name}</div>
                  )}
                  {settings?.show_sku && (
                    <div className="barcode-sku text-xs text-muted-foreground mb-1">{product.sku}</div>
                  )}
                  <Barcode
                    value={product.barcode || product.sku}
                    width={settings?.bar_width || 2}
                    height={settings?.bar_height || 50}
                    fontSize={10}
                  />
                  {settings?.show_price && (
                    <div className="barcode-price font-bold mt-1">{formatCurrency(Number(product.price))}</div>
                  )}
                </div>
              ))
            ))}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowPrintDialog(false)}>
              Cancel
            </Button>
            <Button onClick={doPrint}>
              <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
              Print
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Settings Dialog */}
      <Dialog open={showSettingsDialog} onOpenChange={setShowSettingsDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Barcode Settings</DialogTitle>
          </DialogHeader>
          
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Barcode Type</label>
              <Select defaultValue={settings?.barcode_type || 'code128'}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="code128">Code 128</SelectItem>
                  <SelectItem value="code39">Code 39</SelectItem>
                  <SelectItem value="ean13">EAN-13</SelectItem>
                  <SelectItem value="ean8">EAN-8</SelectItem>
                  <SelectItem value="upc">UPC</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Bar Width</label>
                <Input type="number" min={1} max={5} defaultValue={settings?.bar_width || 2} />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Bar Height</label>
                <Input type="number" min={20} max={100} defaultValue={settings?.bar_height || 50} />
              </div>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Display Options</label>
              <div className="space-y-2">
                <label className="flex items-center gap-2">
                  <Checkbox defaultChecked={settings?.show_name ?? true} />
                  <span className="text-sm">Show Product Name</span>
                </label>
                <label className="flex items-center gap-2">
                  <Checkbox defaultChecked={settings?.show_sku ?? true} />
                  <span className="text-sm">Show SKU</span>
                </label>
                <label className="flex items-center gap-2">
                  <Checkbox defaultChecked={settings?.show_price ?? true} />
                  <span className="text-sm">Show Price</span>
                </label>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowSettingsDialog(false)}>
              Cancel
            </Button>
            <Button onClick={() => {
              toast({ title: "Settings saved" })
              setShowSettingsDialog(false)
            }}>
              Save Settings
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
