import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'
import iotApi, { DeviceConfiguration, PrintJob } from '@/lib/iotApi'

interface PrinterControlProps {
  device: DeviceConfiguration
  agentId: string
  agentOnline: boolean
}

export function PrinterControl({ device, agentId, agentOnline }: PrinterControlProps) {
  const [printDialogOpen, setPrintDialogOpen] = useState(false)
  const [receiptDialogOpen, setReceiptDialogOpen] = useState(false)
  const [jobType, setJobType] = useState<'product_label' | 'barcode_label' | 'test_label'>('product_label')
  const [productName, setProductName] = useState('')
  const [barcode, setBarcode] = useState('')
  const [price, setPrice] = useState('')
  const [sku, setSku] = useState('')
  const [copies, setCopies] = useState(1)
  
  // Receipt data
  const [storeName, setStoreName] = useState('NoovaERP Store')
  const [receiptItems, setReceiptItems] = useState([
    { name: 'Sample Product 1', quantity: 2, price: 10.00 },
    { name: 'Sample Product 2', quantity: 1, price: 15.00 },
  ])
  
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Check if this is a label printer or receipt printer
  const isLabelPrinter = device.driverType === 'label-printer-raw' || device.driverType === 'label-printer-tspl'
  const isReceiptPrinter = device.driverType === 'receipt-printer-raw' || device.driverType === 'receipt-printer-escpos' || device.name?.toLowerCase().includes('kpos')

  // Fetch print history
  const { data: historyData } = useQuery({
    queryKey: ['print-history', agentId, device.id],
    queryFn: () => iotApi.getPrintHistory(agentId, device.id, 10),
    enabled: agentOnline,
    refetchInterval: 5000,
  })

  // Print mutation for label printer
  const printMutation = useMutation({
    mutationFn: () => {
      const data: Record<string, any> = {}
      
      if (jobType === 'product_label') {
        data.productName = productName
        data.barcode = barcode
        data.price = price
        data.sku = sku
      } else if (jobType === 'barcode_label') {
        data.barcode = barcode
        data.text = productName
      }
      
      return iotApi.printLabel(agentId, device.id, {
        jobType,
        data,
        copies,
      })
    },
    onSuccess: (response) => {
      toast({
        title: '🖨️ Print Job Sent',
        description: `Label sent to ${device.name}`,
        duration: 3000,
      })
      setPrintDialogOpen(false)
      resetForm()
      // Refresh print history and device status
      queryClient.invalidateQueries({ queryKey: ['print-history', agentId, device.id] })
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Print Failed',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  // Test print mutation for label printer
  const testPrintMutation = useMutation({
    mutationFn: () => iotApi.printLabel(agentId, device.id, {
      jobType: 'test_label',
      data: {},
      copies: 1,
    }),
    onSuccess: () => {
      toast({
        title: '🖨️ Test Print Sent',
        description: 'Test label sent to printer',
        duration: 3000,
      })
      // Refresh print history and device status
      queryClient.invalidateQueries({ queryKey: ['print-history', agentId, device.id] })
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Test Print Failed',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  // Test receipt print mutation for receipt printer
  const testReceiptMutation = useMutation({
    mutationFn: () => iotApi.testReceiptPrint(agentId, device.id),
    onSuccess: () => {
      toast({
        title: '🧾 Test Receipt Sent',
        description: 'Test receipt sent to printer',
        duration: 3000,
      })
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Test Receipt Failed',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  // Print receipt mutation
  const printReceiptMutation = useMutation({
    mutationFn: () => {
      const subtotal = receiptItems.reduce((sum, item) => sum + (item.quantity * item.price), 0)
      const tax = subtotal * 0.1 // 10% tax
      const total = subtotal + tax
      
      return iotApi.printReceipt({
        agent_id: agentId,
        device_id: device.id,
        receipt_data: {
          store_name: storeName,
          items: receiptItems,
          subtotal,
          tax,
          total,
          payment_method: 'Cash',
          payment_amount: total + 10, // Add $10 for change
          change: 10,
          receipt_number: `R-${Date.now()}`,
          cashier: 'Demo User',
          date: new Date().toISOString(),
        },
      })
    },
    onSuccess: () => {
      toast({
        title: '🧾 Receipt Sent',
        description: `Receipt sent to ${device.name}`,
        duration: 3000,
      })
      setReceiptDialogOpen(false)
      queryClient.invalidateQueries({ queryKey: ['device-configs'] })
    },
    onError: (error: any) => {
      toast({
        title: 'Receipt Print Failed',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  const resetForm = () => {
    setProductName('')
    setBarcode('')
    setPrice('')
    setSku('')
    setCopies(1)
    setJobType('product_label')
  }

  const handlePrint = () => {
    if (jobType === 'product_label' && !productName) {
      toast({
        title: 'Validation Error',
        description: 'Product name is required',
        variant: 'destructive',
      })
      return
    }
    if ((jobType === 'product_label' || jobType === 'barcode_label') && !barcode) {
      toast({
        title: 'Validation Error',
        description: 'Barcode is required',
        variant: 'destructive',
      })
      return
    }
    printMutation.mutate()
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'completed':
        return <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
      case 'failed':
        return <Icon icon="solar:close-circle-bold" className="h-4 w-4 text-red-500" />
      case 'pending':
      case 'sent':
      case 'printing':
        return <Icon icon="solar:clock-circle-bold" className="h-4 w-4 text-yellow-500" />
      default:
        return <Icon icon="solar:question-circle-bold" className="h-4 w-4 text-gray-500" />
    }
  }

  const printHistory = historyData?.data || []

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-lg bg-primary/10">
              <Icon 
                icon={isLabelPrinter ? "solar:tag-bold-duotone" : "solar:printer-bold-duotone"} 
                className="h-6 w-6 text-primary" 
              />
            </div>
            <div>
              <CardTitle className="text-lg">{device.name}</CardTitle>
              <CardDescription>
                {isLabelPrinter ? 'Label Printer (TSPL)' : isReceiptPrinter ? 'Receipt Printer (ESC/POS)' : 'Printer'}
              </CardDescription>
            </div>
          </div>
          <Badge variant={device.currentStatus === 'ready' ? 'default' : 'secondary'}>
            {agentOnline ? device.currentStatus : 'offline'}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Controls - Different for label vs receipt printers */}
        <div className="flex gap-2">
          {isLabelPrinter ? (
            <>
              <Button
                onClick={() => setPrintDialogOpen(true)}
                disabled={!agentOnline}
                className="flex-1"
              >
                <Icon icon="solar:tag-bold" className="mr-2 h-4 w-4" />
                Print Label
              </Button>
              <Button
                variant="outline"
                onClick={() => testPrintMutation.mutate()}
                disabled={!agentOnline || testPrintMutation.isPending}
              >
                <Icon icon="solar:test-tube-bold" className="mr-2 h-4 w-4" />
                Test
              </Button>
            </>
          ) : isReceiptPrinter ? (
            <>
              <Button
                onClick={() => setReceiptDialogOpen(true)}
                disabled={!agentOnline}
                className="flex-1"
              >
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Print Receipt
              </Button>
              <Button
                variant="outline"
                onClick={() => testReceiptMutation.mutate()}
                disabled={!agentOnline || testReceiptMutation.isPending}
              >
                <Icon icon="solar:test-tube-bold" className="mr-2 h-4 w-4" />
                Test
              </Button>
            </>
          ) : (
            <>
              <Button
                onClick={() => setPrintDialogOpen(true)}
                disabled={!agentOnline}
                className="flex-1"
              >
                <Icon icon="solar:printer-bold" className="mr-2 h-4 w-4" />
                Print
              </Button>
              <Button
                variant="outline"
                onClick={() => testPrintMutation.mutate()}
                disabled={!agentOnline || testPrintMutation.isPending}
              >
                <Icon icon="solar:test-tube-bold" className="mr-2 h-4 w-4" />
                Test
              </Button>
            </>
          )}
        </div>

        {/* Print History */}
        {printHistory.length > 0 && (
          <div className="space-y-2">
            <p className="text-sm font-medium">Recent Print Jobs</p>
            <div className="max-h-40 overflow-y-auto space-y-1">
              {printHistory.map((job: PrintJob) => (
                <div
                  key={job.id}
                  className="flex items-center justify-between p-2 bg-muted/50 rounded text-sm"
                >
                  <div className="flex items-center gap-2 min-w-0 flex-1">
                    {getStatusIcon(job.status)}
                    <span className="truncate">
                      {job.jobType === 'product_label' && job.content?.productName}
                      {job.jobType === 'barcode_label' && job.content?.barcode}
                      {job.jobType === 'test_label' && 'Test Label'}
                    </span>
                    <Badge variant="outline" className="text-xs flex-shrink-0">
                      {job.jobType.replace('_', ' ')}
                    </Badge>
                  </div>
                  <span className="text-xs text-muted-foreground flex-shrink-0 ml-2">
                    {new Date(job.createdAt).toLocaleTimeString()}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Agent Offline Warning */}
        {!agentOnline && (
          <div className="p-3 bg-destructive/10 border border-destructive/20 rounded-lg">
            <div className="flex items-center gap-2 text-destructive">
              <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
              <span className="text-sm">Agent offline - printer unavailable</span>
            </div>
          </div>
        )}
      </CardContent>

      {/* Print Dialog */}
      <Dialog open={printDialogOpen} onOpenChange={setPrintDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:tag-bold-duotone" className="h-5 w-5 text-primary" />
              Print Label
            </DialogTitle>
            <DialogDescription>
              {device.name}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {/* Job Type */}
            <div className="space-y-2">
              <Label>Label Type</Label>
              <Select value={jobType} onValueChange={(v: any) => setJobType(v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="product_label">Product Label</SelectItem>
                  <SelectItem value="barcode_label">Barcode Only</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Product Name */}
            <div className="space-y-2">
              <Label>{jobType === 'barcode_label' ? 'Text (optional)' : 'Product Name'}</Label>
              <Input
                value={productName}
                onChange={(e) => setProductName(e.target.value)}
                placeholder={jobType === 'barcode_label' ? 'Optional text above barcode' : 'Enter product name'}
              />
            </div>

            {/* Barcode */}
            <div className="space-y-2">
              <Label>Barcode</Label>
              <Input
                value={barcode}
                onChange={(e) => setBarcode(e.target.value)}
                placeholder="Enter barcode number"
              />
            </div>

            {/* Product Label specific fields */}
            {jobType === 'product_label' && (
              <>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Price</Label>
                    <Input
                      value={price}
                      onChange={(e) => setPrice(e.target.value)}
                      placeholder="$0.00"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>SKU</Label>
                    <Input
                      value={sku}
                      onChange={(e) => setSku(e.target.value)}
                      placeholder="SKU-001"
                    />
                  </div>
                </div>
              </>
            )}

            {/* Copies */}
            <div className="space-y-2">
              <Label>Copies</Label>
              <Input
                type="number"
                min={1}
                max={100}
                value={copies}
                onChange={(e) => setCopies(parseInt(e.target.value) || 1)}
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setPrintDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handlePrint} disabled={printMutation.isPending}>
              {printMutation.isPending ? (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Icon icon="solar:printer-bold" className="mr-2 h-4 w-4" />
              )}
              Print
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Receipt Print Dialog */}
      <Dialog open={receiptDialogOpen} onOpenChange={setReceiptDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-primary" />
              Print Receipt
            </DialogTitle>
            <DialogDescription>
              {device.name} - Sample receipt with demo data
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {/* Store Name */}
            <div className="space-y-2">
              <Label>Store Name</Label>
              <Input
                value={storeName}
                onChange={(e) => setStoreName(e.target.value)}
                placeholder="Store name"
              />
            </div>

            {/* Items Preview */}
            <div className="space-y-2">
              <Label>Items (Demo Data)</Label>
              <div className="border rounded-lg p-3 space-y-2 bg-muted/50">
                {receiptItems.map((item, idx) => (
                  <div key={idx} className="flex justify-between text-sm">
                    <span>{item.name} x{item.quantity}</span>
                    <span className="font-medium">{formatCurrency(Number(item.quantity * item.price) || 0)}</span>
                  </div>
                ))}
                <div className="border-t pt-2 mt-2">
                  <div className="flex justify-between text-sm">
                    <span>Subtotal:</span>
                    <span>{formatCurrency(Number(receiptItems.reduce((sum, item) => sum + (item.quantity * item.price), 0)) || 0)}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span>Tax (10%):</span>
                    <span>{formatCurrency(Number(receiptItems.reduce((sum, item) => sum + (item.quantity * item.price), 0) * 0.1) || 0)}</span>
                  </div>
                  <div className="flex justify-between font-bold">
                    <span>Total:</span>
                    <span>{formatCurrency(Number(receiptItems.reduce((sum, item) => sum + (item.quantity * item.price), 0) * 1.1) || 0)}</span>
                  </div>
                </div>
              </div>
              <p className="text-xs text-muted-foreground">
                This is a demo receipt. In production, this would come from actual sales data.
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setReceiptDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => printReceiptMutation.mutate()} disabled={printReceiptMutation.isPending}>
              {printReceiptMutation.isPending ? (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <Icon icon="solar:printer-bold" className="mr-2 h-4 w-4" />
              )}
              Print Receipt
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
