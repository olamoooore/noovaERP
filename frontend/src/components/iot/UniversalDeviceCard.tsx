/**
 * Universal Device Card Component
 * 
 * A smart React component that dynamically renders controls
 * based on device capabilities received from UMP.
 * 
 * Capability-Based Rendering:
 * - PRINT_RAW → "Test Print" button
 * - PRINT_FILE → "Upload File" button
 * - PRINT_LABEL → "Print Label" button
 * - PRINT_RECEIPT → "Print Receipt" button
 * - SCAN_IMAGE → "Start Scan" button
 * - CASH_DRAWER → "Open Drawer" button
 * - DISPLAY → "Show Message" button
 */

import { useState, useRef } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { useToast } from '@/hooks/use-toast'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { PrintDocumentDialog } from '@/components/iot/PrintDocumentDialog'
import universalIoTService, {
  UMPDevice,
  DeviceCapability,
  ScanResult,
} from '@/services/UniversalIoTService'

interface UniversalDeviceCardProps {
  device: UMPDevice
  agentId: string
  onStatusChange?: (deviceId: string, status: UMPDevice['status']) => void
}

// Capability to icon mapping
const capabilityIcons: Record<DeviceCapability, string> = {
  PRINT_RAW: 'solar:printer-bold-duotone',
  PRINT_FILE: 'solar:document-bold-duotone',
  PRINT_LABEL: 'solar:tag-bold-duotone',
  PRINT_RECEIPT: 'solar:bill-list-bold-duotone',
  SCAN_IMAGE: 'solar:scanner-bold-duotone',
  SCAN_BARCODE: 'solar:qr-code-bold-duotone',
  PAYMENT: 'solar:card-bold-duotone',
  WEIGH: 'solar:scale-bold-duotone',
  DISPLAY: 'solar:monitor-bold-duotone',
  CASH_DRAWER: 'solar:safe-square-bold-duotone',
}

// Device type to icon mapping
const deviceTypeIcons: Record<string, string> = {
  printer: 'solar:printer-bold-duotone',
  scanner: 'solar:scanner-bold-duotone',
  terminal: 'solar:card-bold-duotone',
  scale: 'solar:scale-bold-duotone',
  display: 'solar:monitor-bold-duotone',
  other: 'solar:cpu-bolt-bold-duotone',
}

// Status colors
const statusColors: Record<UMPDevice['status'], string> = {
  online: 'bg-green-500',
  offline: 'bg-gray-400',
  busy: 'bg-yellow-500',
  error: 'bg-red-500',
}

export function UniversalDeviceCard({ device, agentId, onStatusChange }: UniversalDeviceCardProps) {
  const { toast } = useToast()
  const fileInputRef = useRef<HTMLInputElement>(null)
  
  // Loading states for each action
  const [loadingAction, setLoadingAction] = useState<string | null>(null)
  
  // Scan result state
  const [scanResult, setScanResult] = useState<ScanResult | null>(null)
  const [showScanModal, setShowScanModal] = useState(false)
  
  // Display message input
  const [displayMessage, setDisplayMessage] = useState('')
  const [showDisplayInput, setShowDisplayInput] = useState(false)

  // Print document dialog
  const [showPrintDocumentDialog, setShowPrintDocumentDialog] = useState(false)

  // Check if device supports a specific action
  const supportsAction = (action: string) => {
    // Check supportedActions if available
    if (device.supportedActions && device.supportedActions.length > 0) {
      return device.supportedActions.includes(action)
    }
    // Fallback: check capabilities (for backwards compatibility)
    return device.capabilities.some(cap => cap.toUpperCase() === action.toUpperCase())
  }

  // Generic action handler
  const handleAction = async (action: string, handler: () => Promise<void>) => {
    setLoadingAction(action)
    try {
      await handler()
    } finally {
      setLoadingAction(null)
    }
  }

  // PRINT_RAW: Test print with sample text
  const handleTestPrint = () => handleAction('TEST_PRINT', async () => {
    // Use device's agentId (MongoDB ObjectId) for the command
    const effectiveAgentId = device.agentId || agentId
    
    if (!effectiveAgentId) {
      toast({
        title: 'Agent ID Missing',
        description: 'Device does not have an associated agent.',
        variant: 'destructive',
      })
      return
    }
    
    const testContent = '================================\n' +
                        '        NoovaERP TEST PRINT      \n' +
                        '================================\n' +
                        `Device: ${device.name}\n` +
                        `Time: ${new Date().toLocaleString()}\n` +
                        '================================\n' +
                        '    Print test successful!      \n' +
                        '================================\n\n\n'
    
    // Log device info for debugging
    console.log('[Test Print] Device:', device.name)
    console.log('[Test Print] Device ID:', device.id)
    console.log('[Test Print] Device capabilities:', device.capabilities)
    console.log('[Test Print] Device supportedActions:', device.supportedActions)
    console.log('[Test Print] Device metadata:', device.metadata)
    
    // Since supportedActions is empty, try common action names with different payload structures
    const attempts = [
      // Attempt 1: PRINT_DOCUMENT with text field
      { action: 'PRINT_DOCUMENT', data: { text: testContent } },
      // Attempt 2: PRINT_DOCUMENT with content field
      { action: 'PRINT_DOCUMENT', data: { content: testContent } },
      // Attempt 3: PRINT_RAW with content
      { action: 'PRINT_RAW', data: { content: testContent } },
      // Attempt 4: PRINT with text
      { action: 'PRINT', data: { text: testContent } },
      // Attempt 5: No data wrapper, just fields at top level
      { action: 'PRINT_DOCUMENT', data: { text: testContent, format: 'text/plain' } },
    ]
    
    let lastResult: any = null
    
    for (const attempt of attempts) {
      console.log(`[Test Print] Attempt: ${attempt.action}`)
      console.log('[Test Print] Payload:', JSON.stringify(attempt.data, null, 2))
      
      const result = await universalIoTService.executeCommand(
        effectiveAgentId,
        device.id,
        attempt.action,
        attempt.data
      )
      
      lastResult = result
      
      if (result.success) {
        console.log(`[Test Print] ✅ SUCCESS with ${attempt.action}!`)
        toast({
          title: 'Print Sent',
          description: result.message || 'Print job sent successfully',
        })
        return
      } else {
        console.log(`[Test Print] ❌ Failed with ${attempt.action}:`, result.error)
      }
    }

    // All attempts failed
    console.error('[Test Print] All attempts failed!')
    console.error('[Test Print] Last error:', lastResult?.error)
    console.error('[Test Print] Device capabilities:', device.capabilities)
    console.error('[Test Print] Device supportedActions:', device.supportedActions)
    
    toast({
      title: 'Print Failed',
      description: `${lastResult?.error || 'Unknown error'}. Check UMP backend logs for validation details. Device capabilities: ${device.capabilities?.join(', ') || 'none'}`,
      variant: 'destructive',
    })
  })

  // PRINT_FILE: Upload and print file
  const handleFileUpload = () => {
    fileInputRef.current?.click()
  }

  const handleFileSelected = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (!file) return

    const effectiveAgentId = device.agentId || agentId

    await handleAction('PRINT_FILE', async () => {
      const result = await universalIoTService.printFile(effectiveAgentId, device.id, file)

      toast({
        title: result.success ? 'File Sent to Printer' : 'Print Failed',
        description: result.message || result.error,
        variant: result.success ? 'default' : 'destructive',
      })
    })

    // Reset file input
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }

  // PRINT_LABEL: Print a sample label
  const handlePrintLabel = () => handleAction('PRINT_LABEL', async () => {
    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.printLabel(effectiveAgentId, device.id, {
      productName: 'Sample Product',
      barcode: '1234567890123',
      price: '$9.99',
      sku: 'SKU-001',
      width: 40,
      height: 30,
    })

    toast({
      title: result.success ? 'Label Sent' : 'Label Print Failed',
      description: result.message || result.error,
      variant: result.success ? 'default' : 'destructive',
    })
  })

  // PRINT_RECEIPT: Print a sample receipt
  const handlePrintReceipt = () => handleAction('PRINT_RECEIPT', async () => {
    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.printReceipt(effectiveAgentId, device.id, {
      header: 'NoovaERP - Sample Receipt',
      items: [
        { name: 'Product A', qty: 2, price: 10.00 },
        { name: 'Product B', qty: 1, price: 15.50 },
        { name: 'Product C', qty: 3, price: 5.00 },
      ],
      subtotal: 50.50,
      tax: 5.05,
      total: 55.55,
      footer: 'Thank you for your purchase!',
    })

    toast({
      title: result.success ? 'Receipt Sent' : 'Receipt Print Failed',
      description: result.message || result.error,
      variant: result.success ? 'default' : 'destructive',
    })
  })

  // SCAN_IMAGE: Start scan and wait for result
  const handleStartScan = () => handleAction('SCAN_IMAGE', async () => {
    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.startScan(effectiveAgentId, device.id, {
      format: 'jpeg',
      resolution: 300,
      colorMode: 'color',
    })

    if (result.success && result.jobId) {
      toast({
        title: 'Scan Started',
        description: 'Waiting for scan to complete...',
      })

      // Poll for result (in production, use WebSocket)
      const effectiveAgentIdForPoll = device.agentId || agentId
      const scanResult = await universalIoTService.pollScanResult(effectiveAgentIdForPoll, result.jobId)
      setScanResult(scanResult)
      setShowScanModal(true)

      if (!scanResult.success) {
        toast({
          title: 'Scan Failed',
          description: scanResult.error,
          variant: 'destructive',
        })
      }
    } else {
      toast({
        title: 'Scan Failed',
        description: result.error || 'Could not start scan',
        variant: 'destructive',
      })
    }
  })

  // CASH_DRAWER: Open drawer
  const handleOpenDrawer = () => handleAction('CASH_DRAWER', async () => {
    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.openCashDrawer(effectiveAgentId, device.id)

    toast({
      title: result.success ? 'Drawer Opened' : 'Failed to Open Drawer',
      description: result.message || result.error,
      variant: result.success ? 'default' : 'destructive',
    })
  })

  // DISPLAY: Show message
  const handleShowDisplay = () => handleAction('DISPLAY', async () => {
    if (!displayMessage.trim()) {
      toast({
        title: 'Enter a message',
        description: 'Please enter a message to display',
        variant: 'destructive',
      })
      return
    }

    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.showDisplay(effectiveAgentId, device.id, displayMessage)

    toast({
      title: result.success ? 'Message Sent' : 'Display Failed',
      description: result.message || result.error,
      variant: result.success ? 'default' : 'destructive',
    })

    if (result.success) {
      setDisplayMessage('')
      setShowDisplayInput(false)
    }
  })

  // OPEN_CAMERA: Open camera preview (for palm scanners)
  const handleOpenCamera = () => handleAction('OPEN_CAMERA', async () => {
    const effectiveAgentId = device.agentId || agentId
    const result = await universalIoTService.executeCommand(
      effectiveAgentId,
      device.id,
      'OPEN_CAMERA',
      {}
    )

    toast({
      title: result.success ? 'Camera Preview Opened' : 'Failed to Open Camera',
      description: result.success 
        ? 'Check the Palm Scanner GUI window for camera preview' 
        : result.error || 'Could not open camera preview',
      variant: result.success ? 'default' : 'destructive',
    })
  })

  return (
    <>
      <Card className="relative overflow-hidden">
        {/* Status indicator */}
        <div className={`absolute top-0 left-0 right-0 h-1 ${statusColors[device.status]}`} />
        
        <CardHeader className="pb-3">
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-primary/10 rounded-lg">
                <Icon 
                  icon={deviceTypeIcons[device.type] || deviceTypeIcons.other} 
                  className="h-6 w-6 text-primary" 
                />
              </div>
              <div>
                <CardTitle className="text-base">{device.name}</CardTitle>
                <p className="text-xs text-muted-foreground">
                  {device.vendor} {device.model}
                </p>
              </div>
            </div>
            <Badge 
              variant={device.status === 'online' ? 'default' : 'secondary'}
              className="capitalize"
            >
              <div className={`h-2 w-2 rounded-full mr-1.5 ${statusColors[device.status]}`} />
              {device.status}
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="space-y-4">
          {/* Capabilities and Supported Actions */}
          <div className="space-y-2">
            {device.capabilities && device.capabilities.length > 0 && (
              <div>
                <p className="text-xs text-muted-foreground mb-1">Capabilities:</p>
                <div className="flex flex-wrap gap-1">
                  {device.capabilities.map((cap, index) => (
                    <Badge key={`cap-${index}`} variant="outline" className="text-xs">
                      {cap.replace(/_/g, ' ')}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
            {device.supportedActions && device.supportedActions.length > 0 && (
              <div>
                <p className="text-xs text-muted-foreground mb-1">Supported Actions:</p>
                <div className="flex flex-wrap gap-1">
                  {device.supportedActions.map((action, index) => (
                    <Badge key={`action-${index}`} variant="secondary" className="text-xs">
                      {action.replace(/_/g, ' ')}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
          </div>

          {/* Action buttons - rendered based on supportedActions from UMP */}
          <div className="grid grid-cols-2 gap-2">
            {/* Always show Test Print for printers */}
            {device.type === 'printer' && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleTestPrint}
                disabled={device.status !== 'online' || loadingAction === 'TEST_PRINT'}
              >
                {loadingAction === 'TEST_PRINT' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
                )}
                Test Print
              </Button>
            )}

            {/* Print Document button for printers */}
            {device.type === 'printer' && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowPrintDocumentDialog(true)}
                disabled={device.status !== 'online'}
              >
                <Icon icon="solar:document-add-bold" className="h-4 w-4 mr-2" />
                Print Document
              </Button>
            )}

            {/* PRINT_RAW */}
            {supportsAction('PRINT_RAW') && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleTestPrint}
                disabled={device.status !== 'online' || loadingAction === 'PRINT_RAW'}
              >
                {loadingAction === 'PRINT_RAW' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:printer-bold" className="h-4 w-4 mr-2" />
                )}
                Test Print
              </Button>
            )}

            {/* PRINT_FILE */}
            {supportsAction('PRINT_FILE') && (
              <>
                <input
                  ref={fileInputRef}
                  type="file"
                  accept=".pdf,.png,.jpg,.jpeg"
                  className="hidden"
                  onChange={handleFileSelected}
                />
                <Button
                  variant="outline"
                  size="sm"
                  onClick={handleFileUpload}
                  disabled={device.status !== 'online' || loadingAction === 'PRINT_FILE'}
                >
                  {loadingAction === 'PRINT_FILE' ? (
                    <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                  ) : (
                    <Icon icon="solar:upload-bold" className="h-4 w-4 mr-2" />
                  )}
                  Upload File
                </Button>
              </>
            )}

            {/* PRINT_LABEL */}
            {supportsAction('PRINT_LABEL') && (
              <Button
                variant="outline"
                size="sm"
                onClick={handlePrintLabel}
                disabled={device.status !== 'online' || loadingAction === 'PRINT_LABEL'}
              >
                {loadingAction === 'PRINT_LABEL' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:tag-bold" className="h-4 w-4 mr-2" />
                )}
                Print Label
              </Button>
            )}

            {/* PRINT_RECEIPT */}
            {supportsAction('PRINT_RECEIPT') && (
              <Button
                variant="outline"
                size="sm"
                onClick={handlePrintReceipt}
                disabled={device.status !== 'online' || loadingAction === 'PRINT_RECEIPT'}
              >
                {loadingAction === 'PRINT_RECEIPT' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:bill-list-bold" className="h-4 w-4 mr-2" />
                )}
                Print Receipt
              </Button>
            )}

            {/* SCAN_IMAGE */}
            {supportsAction('SCAN_IMAGE') && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleStartScan}
                disabled={device.status !== 'online' || loadingAction === 'SCAN_IMAGE'}
              >
                {loadingAction === 'SCAN_IMAGE' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:scanner-bold" className="h-4 w-4 mr-2" />
                )}
                Start Scan
              </Button>
            )}

            {/* CASH_DRAWER / OPEN_DRAWER */}
            {(supportsAction('CASH_DRAWER') || supportsAction('OPEN_DRAWER')) && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleOpenDrawer}
                disabled={device.status !== 'online' || loadingAction === 'CASH_DRAWER'}
              >
                {loadingAction === 'CASH_DRAWER' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:safe-square-bold" className="h-4 w-4 mr-2" />
                )}
                Open Drawer
              </Button>
            )}

            {/* DISPLAY */}
            {supportsAction('DISPLAY') && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowDisplayInput(!showDisplayInput)}
                disabled={device.status !== 'online'}
              >
                <Icon icon="solar:monitor-bold" className="h-4 w-4 mr-2" />
                Show Message
              </Button>
            )}

            {/* OPEN_CAMERA - Palm Scanner Camera Preview */}
            {(supportsAction('OPEN_CAMERA') || supportsAction('open-camera')) && (
              <Button
                variant="outline"
                size="sm"
                onClick={handleOpenCamera}
                disabled={device.status !== 'online' || loadingAction === 'OPEN_CAMERA'}
                className="col-span-2"
              >
                {loadingAction === 'OPEN_CAMERA' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Icon icon="solar:camera-bold-duotone" className="h-4 w-4 mr-2" />
                )}
                📷 Camera Preview
              </Button>
            )}
          </div>

          {/* Display message input */}
          {showDisplayInput && supportsAction('DISPLAY') && (
            <div className="flex gap-2">
              <Input
                placeholder="Enter message..."
                value={displayMessage}
                onChange={(e) => setDisplayMessage(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleShowDisplay()}
              />
              <Button
                size="sm"
                onClick={handleShowDisplay}
                disabled={loadingAction === 'DISPLAY'}
              >
                {loadingAction === 'DISPLAY' ? (
                  <Icon icon="solar:refresh-bold" className="h-4 w-4 animate-spin" />
                ) : (
                  <Icon icon="solar:plain-bold" className="h-4 w-4" />
                )}
              </Button>
            </div>
          )}

          {/* Device info */}
          {device.lastSeen && (
            <p className="text-xs text-muted-foreground">
              Last seen: {new Date(device.lastSeen).toLocaleString()}
            </p>
          )}
        </CardContent>
      </Card>

      {/* Scan Result Modal */}
      <Dialog open={showScanModal} onOpenChange={setShowScanModal}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Scan Result</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            {scanResult?.success ? (
              <>
                {scanResult.imageUrl && (
                  <img
                    src={scanResult.imageUrl}
                    alt="Scanned document"
                    className="w-full rounded-lg border"
                  />
                )}
                {scanResult.imageBase64 && (
                  <img
                    src={`data:image/${scanResult.format || 'jpeg'};base64,${scanResult.imageBase64}`}
                    alt="Scanned document"
                    className="w-full rounded-lg border"
                  />
                )}
                <div className="flex gap-2">
                  <Button
                    onClick={() => {
                      const link = document.createElement('a')
                      link.href = scanResult.imageUrl || 
                        `data:image/${scanResult.format || 'jpeg'};base64,${scanResult.imageBase64}`
                      link.download = `scan-${Date.now()}.${scanResult.format || 'jpg'}`
                      link.click()
                    }}
                  >
                    <Icon icon="solar:download-bold" className="h-4 w-4 mr-2" />
                    Download
                  </Button>
                  <Button variant="outline" onClick={() => setShowScanModal(false)}>
                    Close
                  </Button>
                </div>
              </>
            ) : (
              <div className="text-center py-8">
                <Icon icon="solar:danger-triangle-bold" className="h-12 w-12 text-destructive mx-auto mb-4" />
                <p className="text-destructive">{scanResult?.error || 'Scan failed'}</p>
                <Button variant="outline" className="mt-4" onClick={() => setShowScanModal(false)}>
                  Close
                </Button>
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      {/* Print Document Dialog */}
      <PrintDocumentDialog
        isOpen={showPrintDocumentDialog}
        onClose={() => setShowPrintDocumentDialog(false)}
        device={device}
        agentId={agentId}
      />
    </>
  )
}

export default UniversalDeviceCard
