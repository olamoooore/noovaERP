import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { fetchPrinters, getLinkedAgentId, type Device } from '@/services/printService'

// LocalStorage keys
const RECEIPT_PRINTER_KEY = 'selected_receipt_printer'
const LABEL_PRINTER_KEY = 'selected_label_printer'

interface PrinterSetupProps {
  onPrinterSelected?: (type: 'receipt' | 'label', deviceId: string) => void
}

export function PrinterSetup({ onPrinterSelected }: PrinterSetupProps) {
  const [devices, setDevices] = useState<Device[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [selectedReceiptPrinter, setSelectedReceiptPrinter] = useState<string>('')
  const [selectedLabelPrinter, setSelectedLabelPrinter] = useState<string>('')
  const [agentId, setAgentId] = useState<string | null>(null)
  const { toast } = useToast()

  // Load saved printer selections and fetch devices on mount
  useEffect(() => {
    loadSavedSelections()
    loadDevices()
  }, [])

  const loadSavedSelections = () => {
    const savedReceiptPrinter = localStorage.getItem(RECEIPT_PRINTER_KEY)
    const savedLabelPrinter = localStorage.getItem(LABEL_PRINTER_KEY)

    if (savedReceiptPrinter) {
      setSelectedReceiptPrinter(savedReceiptPrinter)
    }

    if (savedLabelPrinter) {
      setSelectedLabelPrinter(savedLabelPrinter)
    }
  }

  const loadDevices = async () => {
    const linkedAgentId = getLinkedAgentId()

    if (!linkedAgentId) {
      toast({
        title: 'No Agent Linked',
        description: 'Please link a UMP agent first to see available printers',
        variant: 'destructive',
      })
      return
    }

    setAgentId(linkedAgentId)
    setIsLoading(true)

    try {
      const response = await fetchPrinters(linkedAgentId)

      if (response.success) {
        setDevices(response.devices)

        if (response.devices.length === 0) {
          toast({
            title: 'No Printers Found',
            description: 'No printers are connected to your agent',
          })
        }
      } else {
        toast({
          title: 'Failed to Load Printers',
          description: response.error || 'Could not fetch printer list',
          variant: 'destructive',
        })
      }
    } catch (error) {
      toast({
        title: 'Error',
        description: 'An unexpected error occurred while loading printers',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleReceiptPrinterChange = (deviceId: string) => {
    setSelectedReceiptPrinter(deviceId)
    localStorage.setItem(RECEIPT_PRINTER_KEY, deviceId)

    const device = devices.find(d => d.device_id === deviceId)

    toast({
      title: 'Receipt Printer Selected',
      description: `${device?.vendor} ${device?.model} is now your default receipt printer`,
    })

    if (onPrinterSelected) {
      onPrinterSelected('receipt', deviceId)
    }
  }

  const handleLabelPrinterChange = (deviceId: string) => {
    setSelectedLabelPrinter(deviceId)
    localStorage.setItem(LABEL_PRINTER_KEY, deviceId)

    const device = devices.find(d => d.device_id === deviceId)

    toast({
      title: 'Label Printer Selected',
      description: `${device?.vendor} ${device?.model} is now your default label printer`,
    })

    if (onPrinterSelected) {
      onPrinterSelected('label', deviceId)
    }
  }

  const handleRefresh = () => {
    loadDevices()
  }

  const handleClearSelection = (type: 'receipt' | 'label') => {
    if (type === 'receipt') {
      setSelectedReceiptPrinter('')
      localStorage.removeItem(RECEIPT_PRINTER_KEY)
      toast({
        title: 'Selection Cleared',
        description: 'Receipt printer selection has been cleared',
      })
    } else {
      setSelectedLabelPrinter('')
      localStorage.removeItem(LABEL_PRINTER_KEY)
      toast({
        title: 'Selection Cleared',
        description: 'Label printer selection has been cleared',
      })
    }
  }

  // Filter devices by type
  const receiptPrinters = devices.filter(d => d.type === 'receipt')
  const labelPrinters = devices.filter(d => d.type === 'label')

  // Get device status color
  const getStatusColor = (status: string) => {
    switch (status) {
      case 'ready':
        return 'bg-green-500'
      case 'busy':
        return 'bg-yellow-500'
      case 'offline':
        return 'bg-gray-500'
      case 'error':
        return 'bg-red-500'
      default:
        return 'bg-gray-500'
    }
  }

  if (!agentId) {
    return (
      <Card>
        <CardHeader>
          <div className="flex items-center gap-3">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon icon="solar:printer-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <CardTitle>Printer Setup</CardTitle>
              <CardDescription>Select your default printers for printing</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-8">
            <Icon icon="solar:link-broken-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No Agent Linked</h3>
            <p className="text-muted-foreground text-center mb-4">
              Please link a UMP agent first to configure printers
            </p>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon icon="solar:printer-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <CardTitle>Printer Setup</CardTitle>
              <CardDescription>
                Select your default printers for printing labels and receipts
              </CardDescription>
            </div>
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={handleRefresh}
            disabled={isLoading}
          >
            {isLoading ? (
              <>
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                Loading...
              </>
            ) : (
              <>
                <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
                Refresh
              </>
            )}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-6">
        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
          </div>
        ) : (
          <>
            {/* Receipt Printer Selection */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label htmlFor="receipt-printer" className="text-base font-medium">
                  Receipt Printer
                </Label>
                {selectedReceiptPrinter && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleClearSelection('receipt')}
                  >
                    <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                  </Button>
                )}
              </div>

              {receiptPrinters.length === 0 ? (
                <div className="p-4 bg-muted/50 rounded-lg">
                  <p className="text-sm text-muted-foreground">
                    No receipt printers available. Please connect a receipt printer to your agent.
                  </p>
                </div>
              ) : (
                <>
                  <Select
                    value={selectedReceiptPrinter}
                    onValueChange={handleReceiptPrinterChange}
                  >
                    <SelectTrigger id="receipt-printer">
                      <SelectValue placeholder="Select a receipt printer" />
                    </SelectTrigger>
                    <SelectContent>
                      {receiptPrinters.map((device) => (
                        <SelectItem key={device.device_id} value={device.device_id}>
                          <div className="flex items-center gap-2">
                            <div className={`h-2 w-2 rounded-full ${getStatusColor(device.status)}`} />
                            <span>{device.vendor} {device.model}</span>
                            <Badge variant="outline" className="ml-2 capitalize">
                              {device.status}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  {selectedReceiptPrinter && (
                    <div className="flex items-center gap-2 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-lg">
                      <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-600" />
                      <p className="text-sm text-green-900 dark:text-green-100">
                        Receipt printer configured
                      </p>
                    </div>
                  )}
                </>
              )}
            </div>

            {/* Label Printer Selection */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label htmlFor="label-printer" className="text-base font-medium">
                  Label Printer
                </Label>
                {selectedLabelPrinter && (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => handleClearSelection('label')}
                  >
                    <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
                  </Button>
                )}
              </div>

              {labelPrinters.length === 0 ? (
                <div className="p-4 bg-muted/50 rounded-lg">
                  <p className="text-sm text-muted-foreground">
                    No label printers available. Please connect a label printer to your agent.
                  </p>
                </div>
              ) : (
                <>
                  <Select
                    value={selectedLabelPrinter}
                    onValueChange={handleLabelPrinterChange}
                  >
                    <SelectTrigger id="label-printer">
                      <SelectValue placeholder="Select a label printer" />
                    </SelectTrigger>
                    <SelectContent>
                      {labelPrinters.map((device) => (
                        <SelectItem key={device.device_id} value={device.device_id}>
                          <div className="flex items-center gap-2">
                            <div className={`h-2 w-2 rounded-full ${getStatusColor(device.status)}`} />
                            <span>{device.vendor} {device.model}</span>
                            <Badge variant="outline" className="ml-2 capitalize">
                              {device.status}
                            </Badge>
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  {selectedLabelPrinter && (
                    <div className="flex items-center gap-2 p-3 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-lg">
                      <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-600" />
                      <p className="text-sm text-green-900 dark:text-green-100">
                        Label printer configured
                      </p>
                    </div>
                  )}
                </>
              )}
            </div>

            {/* Info Section */}
            <div className="p-3 bg-muted/50 rounded-lg">
              <div className="flex items-start gap-2">
                <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-muted-foreground mt-0.5" />
                <div className="space-y-1">
                  <p className="text-sm font-medium">About Printer Setup</p>
                  <p className="text-xs text-muted-foreground">
                    Select your default printers for automatic printing. Receipt printers are used for order receipts, 
                    while label printers are used for product labels. You can change these selections at any time.
                  </p>
                </div>
              </div>
            </div>

            {/* Summary */}
            {(selectedReceiptPrinter || selectedLabelPrinter) && (
              <div className="pt-4 border-t">
                <h4 className="text-sm font-medium mb-3">Current Configuration</h4>
                <div className="space-y-2">
                  {selectedReceiptPrinter && (
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Receipt Printer:</span>
                      <span className="font-medium">
                        {receiptPrinters.find(d => d.device_id === selectedReceiptPrinter)?.model || 'Unknown'}
                      </span>
                    </div>
                  )}
                  {selectedLabelPrinter && (
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Label Printer:</span>
                      <span className="font-medium">
                        {labelPrinters.find(d => d.device_id === selectedLabelPrinter)?.model || 'Unknown'}
                      </span>
                    </div>
                  )}
                </div>
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  )
}

// Helper functions to get selected printers from localStorage
export function getSelectedReceiptPrinter(): string | null {
  return localStorage.getItem(RECEIPT_PRINTER_KEY)
}

export function getSelectedLabelPrinter(): string | null {
  return localStorage.getItem(LABEL_PRINTER_KEY)
}

export function hasReceiptPrinterConfigured(): boolean {
  return !!getSelectedReceiptPrinter()
}

export function hasLabelPrinterConfigured(): boolean {
  return !!getSelectedLabelPrinter()
}
