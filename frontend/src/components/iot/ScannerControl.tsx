import { useState, useEffect, useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration, ScanResult, BarcodeScannedEvent } from '@/lib/iotApi'
import { QRScanResult } from './QRScanResult'
import { PalmVeinResult } from './PalmVeinResult'

interface ScannerControlProps {
  device: DeviceConfiguration
  agentId: string
  agentOnline: boolean
}

export function ScannerControl({ device, agentId, agentOnline }: ScannerControlProps) {
  const [isListening, setIsListening] = useState(false)
  const [continuousMode, setContinuousMode] = useState(false)
  const [lastScan, setLastScan] = useState<BarcodeScannedEvent | null>(null)
  const lastScanIdRef = useRef<string | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Quick Scan Dialog state
  const [quickScanOpen, setQuickScanOpen] = useState(false)
  const [quickScanResult, setQuickScanResult] = useState<BarcodeScannedEvent | null>(null)
  const [isQuickScanning, setIsQuickScanning] = useState(false)
  const quickScanIdRef = useRef<string | null>(null)

  // Check if this is a QR scanner
  const isQrScanner = device.driverType === 'qr-scanner-hid'
  
  // Check if this is a palm vein scanner
  const isPalmVeinScanner = device.driverType === 'palm-vein-winusb'

  // Fetch scan history with polling when listening
  const { data: historyData } = useQuery({
    queryKey: ['scan-history', agentId, device.id],
    queryFn: () => iotApi.getScanHistory(agentId, device.id, 10),
    enabled: agentOnline,
    refetchInterval: (isListening || isQuickScanning) ? 500 : 5000, // Poll faster when listening
  })

  // Detect new scans from polling
  useEffect(() => {
    const scanHistory = historyData?.data || []
    if (scanHistory.length > 0) {
      const latestScan = scanHistory[0]
      
      // Check if this is a new scan we haven't seen yet
      if (latestScan.id !== lastScanIdRef.current) {
        const isFirstLoad = lastScanIdRef.current === null
        lastScanIdRef.current = latestScan.id
        
        // Always update the lastScan display
        const newScanEvent: BarcodeScannedEvent = {
          deviceId: device.id,
          deviceName: device.name,
          barcode: latestScan.barcode,
          format: latestScan.format,
          scanResultId: latestScan.id,
          scannedAt: latestScan.scannedAt,
          parsed: latestScan.metadata?.parsed,
          isQrCode: latestScan.metadata?.isQrCode || latestScan.format === 'QR',
        }
        setLastScan(newScanEvent)
        
        // Handle Quick Scan result
        if (isQuickScanning && !isFirstLoad && latestScan.id !== quickScanIdRef.current) {
          setQuickScanResult(newScanEvent)
          setIsQuickScanning(false)
          // Don't close dialog - show result
        }
        
        // Only show toast if we're listening (not quick scan) and it's not the first load
        if (isListening && !isQuickScanning && !isFirstLoad) {
          const parsedType = newScanEvent.parsed?.type
          const isPalmVein = parsedType === 'palm_vein'
          const isQrData = !isPalmVein && (newScanEvent.isQrCode || newScanEvent.parsed)
          
          // Create user-friendly description and title based on scan type
          let title = '✅ Barcode Scanned'
          let description = ''
          
          if (isPalmVein) {
            title = '✅ Palm Vein Scanned'
            const quality = newScanEvent.parsed?.data?.quality
            description = quality ? `🖐️ Quality: ${quality}%` : '🖐️ Biometric captured'
          } else if (parsedType === 'url') {
            title = '✅ QR Code Scanned'
            description = '🔗 ' + (newScanEvent.parsed?.data?.url || latestScan.barcode).substring(0, 60)
          } else if (parsedType === 'noovaerp_product') {
            title = '✅ QR Code Scanned'
            const prod = newScanEvent.parsed?.data?.prod || 'Product'
            const qty = newScanEvent.parsed?.data?.qty || 1
            description = `📦 ${prod} (Qty: ${qty})`
          } else if (parsedType === 'json') {
            title = '✅ QR Code Scanned'
            description = '📄 JSON data scanned'
          } else if (isQrData) {
            title = '✅ QR Code Scanned'
            description = latestScan.barcode.length > 40 
              ? latestScan.barcode.substring(0, 40) + '...' 
              : latestScan.barcode
          } else {
            description = latestScan.barcode.length > 40 
              ? latestScan.barcode.substring(0, 40) + '...' 
              : latestScan.barcode
          }
          
          toast({
            title,
            description,
            duration: 3000, // Auto-dismiss after 3 seconds
          })

          // If single mode, stop listening after scan
          if (!continuousMode) {
            setIsListening(false)
          }
        }
      }
    }
  }, [historyData, isListening, isQuickScanning, device.id, device.name, continuousMode, toast])

  // Quick Scan timeout
  useEffect(() => {
    if (!isQuickScanning) return
    const timeout = setTimeout(() => {
      setIsQuickScanning(false)
      stopListeningMutation.mutate()
      toast({
        title: 'Scan Timeout',
        description: 'No code detected within 30 seconds',
      })
    }, 30000)
    return () => clearTimeout(timeout)
  }, [isQuickScanning])

  // Start listening mutation
  const startListeningMutation = useMutation({
    mutationFn: () => iotApi.startScannerListening(agentId, device.id, {
      mode: continuousMode ? 'continuous' : 'single',
      timeout: 60,
    }),
    onSuccess: () => {
      setIsListening(true)
      // Reset last scan tracking when starting new session
      lastScanIdRef.current = historyData?.data?.[0]?.id || null
      toast({
        title: isPalmVeinScanner ? '🖐️ Scanner Active' : '🔍 Scanner Active',
        description: isPalmVeinScanner 
          ? `${device.name} is ready. Place your palm on the scanner.`
          : `${device.name} is now listening for barcodes. Scan something!`,
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Failed to start scanner',
        description: error.response?.data?.message || error.response?.data?.error || error.message,
        variant: 'destructive',
      })
    },
  })

  // Stop listening mutation
  const stopListeningMutation = useMutation({
    mutationFn: () => iotApi.stopScannerListening(agentId, device.id),
    onSuccess: () => {
      setIsListening(false)
      toast({
        title: 'Scanner Stopped',
        description: `${device.name} stopped listening`,
      })
    },
    onError: (error: any) => {
      setIsListening(false)
      toast({
        title: 'Error stopping scanner',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  const handleToggleListening = () => {
    if (isListening) {
      stopListeningMutation.mutate()
    } else {
      startListeningMutation.mutate()
    }
  }

  // Quick Scan - opens dialog and starts single scan
  const handleQuickScan = () => {
    setQuickScanResult(null)
    setQuickScanOpen(true)
    quickScanIdRef.current = historyData?.data?.[0]?.id || null
    setIsQuickScanning(true)
    
    // Start listening in single mode
    iotApi.startScannerListening(agentId, device.id, {
      mode: 'single',
      timeout: 30,
    }).catch((error) => {
      setIsQuickScanning(false)
      toast({
        title: 'Failed to start scanner',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    })
  }

  // Close Quick Scan dialog
  const handleCloseQuickScan = () => {
    setQuickScanOpen(false)
    setIsQuickScanning(false)
    setQuickScanResult(null)
    // Stop listening if still active
    iotApi.stopScannerListening(agentId, device.id).catch(() => {})
  }

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text)
    toast({
      title: 'Copied!',
      description: 'Barcode copied to clipboard',
    })
  }

  // Handle QR code actions (Add to Cart, Lookup Product, etc.)
  const handleQRAction = (action: string, data: Record<string, any>) => {
    switch (action) {
      case 'add_to_cart':
        toast({
          title: 'Add to Cart',
          description: `Product ${data.prod || data.sku || 'unknown'} - Qty: ${data.qty || 1}`,
        })
        // TODO: Integrate with POS cart when available
        break
      case 'lookup_product':
        toast({
          title: 'Product Lookup',
          description: `Looking up SKU: ${data.sku}`,
        })
        // TODO: Navigate to product page or open product modal
        break
      default:
        console.log('QR Action:', action, data)
    }
  }

  const scanHistory = historyData?.data || []

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className={`p-2 rounded-lg ${isListening ? 'bg-green-500/20 animate-pulse' : 'bg-primary/10'}`}>
              <Icon 
                icon={isPalmVeinScanner ? "solar:fingerprint-bold-duotone" : "solar:qr-code-bold-duotone"} 
                className={`h-6 w-6 ${isListening ? 'text-green-500' : 'text-primary'}`} 
              />
            </div>
            <div>
              <CardTitle className="text-lg">{device.name}</CardTitle>
              <CardDescription>
                {isListening 
                  ? (isPalmVeinScanner ? 'Waiting for palm scan...' : 'Listening for barcodes...') 
                  : 'Scanner ready'}
              </CardDescription>
            </div>
          </div>
          <Badge variant={isListening ? 'default' : 'secondary'}>
            {isListening ? 'Active' : 'Idle'}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Controls */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Switch
              id={`continuous-${device.id}`}
              checked={continuousMode}
              onCheckedChange={setContinuousMode}
              disabled={isListening}
            />
            <Label htmlFor={`continuous-${device.id}`} className="text-sm">
              Continuous Mode
            </Label>
          </div>
          <div className="flex gap-2">
            {/* Quick Scan Button */}
            <Button
              onClick={handleQuickScan}
              disabled={!agentOnline || isListening || isQuickScanning}
              variant="outline"
              size="sm"
              title="Quick single scan with popup result"
            >
              <Icon icon="solar:qr-code-bold" className="mr-2 h-4 w-4" />
              Quick Scan
            </Button>
            {/* Start/Stop Scan Button */}
            <Button
              onClick={handleToggleListening}
              disabled={!agentOnline || startListeningMutation.isPending || stopListeningMutation.isPending}
              variant={isListening ? 'destructive' : 'default'}
              size="sm"
            >
              {startListeningMutation.isPending || stopListeningMutation.isPending ? (
                <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
              ) : isListening ? (
                <Icon icon="solar:stop-bold" className="mr-2 h-4 w-4" />
              ) : (
                <Icon icon="solar:play-bold" className="mr-2 h-4 w-4" />
              )}
              {isListening ? 'Stop' : 'Start Scan'}
            </Button>
          </div>
        </div>

        {/* Last Scan Result */}
        {lastScan && (
          <>
            {lastScan.parsed?.type === 'palm_vein' ? (
              <PalmVeinResult
                rawData={lastScan.barcode}
                parsed={lastScan.parsed}
                timestamp={lastScan.scannedAt}
                onCopy={copyToClipboard}
              />
            ) : (lastScan.isQrCode || lastScan.parsed) ? (
              <QRScanResult
                rawData={lastScan.barcode}
                parsed={lastScan.parsed}
                timestamp={lastScan.scannedAt}
                onCopy={copyToClipboard}
                onAction={handleQRAction}
              />
            ) : (
              <div className="p-3 bg-green-500/10 border border-green-500/20 rounded-lg">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-xs text-muted-foreground mb-1">Last Scanned</p>
                    <p className="font-mono text-lg font-semibold">{lastScan.barcode}</p>
                    {lastScan.format && (
                      <p className="text-xs text-muted-foreground">Format: {lastScan.format}</p>
                    )}
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => copyToClipboard(lastScan.barcode)}
                  >
                    <Icon icon="solar:copy-bold" className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}

        {/* Scan History */}
        {scanHistory.length > 0 && (
          <div className="space-y-2">
            <p className="text-sm font-medium">Recent Scans</p>
            <div className="max-h-40 overflow-y-auto space-y-1">
              {scanHistory.map((scan: ScanResult) => (
                <div
                  key={scan.id}
                  className="flex items-center justify-between p-2 bg-muted/50 rounded text-sm"
                >
                  <div className="flex items-center gap-2 min-w-0 flex-1">
                    <code className="font-mono truncate">
                      {scan.barcode.length > 40 
                        ? scan.barcode.substring(0, 40) + '...' 
                        : scan.barcode}
                    </code>
                    {scan.format && (
                      <Badge 
                        variant={scan.format === 'QR' || scan.metadata?.isQrCode ? 'default' : 'outline'} 
                        className="text-xs flex-shrink-0"
                      >
                        {scan.format}
                      </Badge>
                    )}
                    {scan.metadata?.parsed?.type && (
                      <Badge variant="secondary" className="text-xs flex-shrink-0">
                        {scan.metadata.parsed.type}
                      </Badge>
                    )}
                  </div>
                  <span className="text-xs text-muted-foreground flex-shrink-0 ml-2">
                    {new Date(scan.scannedAt).toLocaleTimeString()}
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
              <span className="text-sm">Agent offline - scanner unavailable</span>
            </div>
          </div>
        )}
      </CardContent>

      {/* Quick Scan Dialog */}
      <Dialog open={quickScanOpen} onOpenChange={(open) => !open && handleCloseQuickScan()}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {device.driverType === 'palm-vein-winusb' ? (
                <Icon icon="solar:fingerprint-bold-duotone" className="h-5 w-5 text-primary" />
              ) : (
                <Icon icon="solar:qr-code-bold-duotone" className="h-5 w-5 text-primary" />
              )}
              Quick Scan
            </DialogTitle>
            <DialogDescription>
              {device.name}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col items-center py-6 space-y-4">
            {!quickScanResult ? (
              <>
                <div className="relative">
                  <div className="w-32 h-32 border-4 border-dashed border-primary/50 rounded-lg flex items-center justify-center">
                    {device.driverType === 'palm-vein-winusb' ? (
                      <Icon 
                        icon="solar:fingerprint-bold-duotone" 
                        className="h-16 w-16 text-primary animate-pulse" 
                      />
                    ) : (
                      <Icon 
                        icon="solar:qr-code-bold-duotone" 
                        className="h-16 w-16 text-primary animate-pulse" 
                      />
                    )}
                  </div>
                  <div className="absolute -top-2 -right-2">
                    <Badge variant="default" className="animate-pulse">
                      Scanning...
                    </Badge>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground text-center">
                  {device.driverType === 'palm-vein-winusb' ? (
                    <>
                      Place your palm on the scanner<br />
                      <span className="text-xs">Auto-timeout in 30 seconds</span>
                    </>
                  ) : (
                    <>
                      Point the scanner at a QR code or barcode<br />
                      <span className="text-xs">Auto-timeout in 30 seconds</span>
                    </>
                  )}
                </p>
              </>
            ) : (
              <div className="w-full space-y-3">
                <div className="flex items-center gap-2 text-green-600">
                  <Icon icon="solar:check-circle-bold" className="h-6 w-6" />
                  <span className="font-medium">Scan Successful!</span>
                </div>
                
                {/* Show user-friendly parsed data */}
                {quickScanResult.parsed?.type === 'palm_vein' ? (
                  <div className="p-4 bg-purple-50 dark:bg-purple-950/20 rounded-lg border border-purple-200 dark:border-purple-800">
                    <div className="flex items-center gap-2 mb-3">
                      <Icon icon="solar:fingerprint-bold-duotone" className="h-5 w-5 text-purple-600" />
                      <span className="font-medium text-purple-900 dark:text-purple-100">Palm Vein Scan</span>
                    </div>
                    <div className="space-y-2 text-sm">
                      {quickScanResult.parsed.data?.quality !== null && quickScanResult.parsed.data?.quality !== undefined && (
                        <div>
                          <div className="flex justify-between mb-1">
                            <span className="text-muted-foreground">Quality:</span>
                            <span className="font-medium">{quickScanResult.parsed.data.quality}%</span>
                          </div>
                          <div className="w-full bg-gray-200 rounded-full h-2">
                            <div
                              className={`h-2 rounded-full ${
                                quickScanResult.parsed.data.quality >= 80
                                  ? 'bg-green-500'
                                  : quickScanResult.parsed.data.quality >= 60
                                    ? 'bg-blue-500'
                                    : 'bg-yellow-500'
                              }`}
                              style={{ width: `${quickScanResult.parsed.data.quality}%` }}
                            />
                          </div>
                        </div>
                      )}
                      {quickScanResult.parsed.data?.matchScore !== null && quickScanResult.parsed.data?.matchScore !== undefined && (
                        <div>
                          <div className="flex justify-between mb-1">
                            <span className="text-muted-foreground">Match Score:</span>
                            <span className="font-medium">{quickScanResult.parsed.data.matchScore}%</span>
                          </div>
                          <div className="w-full bg-gray-200 rounded-full h-2">
                            <div
                              className={`h-2 rounded-full ${
                                quickScanResult.parsed.data.matchScore >= 90
                                  ? 'bg-green-500'
                                  : quickScanResult.parsed.data.matchScore >= 70
                                    ? 'bg-blue-500'
                                    : 'bg-red-500'
                              }`}
                              style={{ width: `${quickScanResult.parsed.data.matchScore}%` }}
                            />
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                ) : quickScanResult.parsed?.type === 'url' ? (
                  <div className="p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-200 dark:border-blue-800">
                    <div className="flex items-center gap-2 mb-2">
                      <Icon icon="solar:link-bold-duotone" className="h-5 w-5 text-blue-600" />
                      <span className="font-medium text-blue-900 dark:text-blue-100">Website Link</span>
                    </div>
                    <a 
                      href={quickScanResult.parsed.data.url} 
                      target="_blank" 
                      rel="noopener noreferrer"
                      className="text-blue-600 hover:underline break-all text-sm"
                    >
                      {quickScanResult.parsed.data.url}
                    </a>
                    {quickScanResult.parsed.data.normalized && (
                      <p className="text-xs text-muted-foreground mt-2">
                        ℹ️ URL was reconstructed from scanner output
                      </p>
                    )}
                  </div>
                ) : quickScanResult.parsed?.type === 'noovaerp_product' ? (
                  <div className="p-4 bg-green-50 dark:bg-green-950/20 rounded-lg border border-green-200 dark:border-green-800">
                    <div className="flex items-center gap-2 mb-3">
                      <Icon icon="solar:box-bold-duotone" className="h-5 w-5 text-green-600" />
                      <span className="font-medium text-green-900 dark:text-green-100">Product</span>
                    </div>
                    <div className="space-y-2 text-sm">
                      {quickScanResult.parsed.data.prod && (
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">SKU:</span>
                          <span className="font-medium">{quickScanResult.parsed.data.prod}</span>
                        </div>
                      )}
                      {quickScanResult.parsed.data.qty && (
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Quantity:</span>
                          <span className="font-medium">{quickScanResult.parsed.data.qty}</span>
                        </div>
                      )}
                      {quickScanResult.parsed.data.price && (
                        <div className="flex justify-between">
                          <span className="text-muted-foreground">Price:</span>
                          <span className="font-medium">${quickScanResult.parsed.data.price}</span>
                        </div>
                      )}
                    </div>
                  </div>
                ) : quickScanResult.parsed?.type === 'json' ? (
                  <div className="p-4 bg-purple-50 dark:bg-purple-950/20 rounded-lg border border-purple-200 dark:border-purple-800">
                    <div className="flex items-center gap-2 mb-2">
                      <Icon icon="solar:code-bold-duotone" className="h-5 w-5 text-purple-600" />
                      <span className="font-medium text-purple-900 dark:text-purple-100">JSON Data</span>
                    </div>
                    <pre className="text-xs bg-background p-2 rounded overflow-auto max-h-32">
                      {JSON.stringify(quickScanResult.parsed.data, null, 2)}
                    </pre>
                  </div>
                ) : (
                  <div className="p-3 bg-muted rounded-lg">
                    <p className="text-xs text-muted-foreground mb-1">Scanned Data:</p>
                    <p className="font-mono text-sm break-all">
                      {quickScanResult.barcode.length > 100 
                        ? quickScanResult.barcode.substring(0, 100) + '...' 
                        : quickScanResult.barcode}
                    </p>
                    {quickScanResult.format && (
                      <Badge variant="outline" className="mt-2 text-xs">
                        {quickScanResult.format}
                      </Badge>
                    )}
                  </div>
                )}
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2">
            {quickScanResult && (
              <>
                {/* Open Link button for URLs */}
                {quickScanResult.parsed?.type === 'url' && (
                  <Button 
                    variant="default"
                    onClick={() => {
                      window.open(quickScanResult.parsed?.data?.url, '_blank')
                    }}
                  >
                    <Icon icon="solar:square-arrow-right-up-bold" className="h-4 w-4 mr-2" />
                    Open Link
                  </Button>
                )}
                {/* Copy button */}
                <Button 
                  variant="outline" 
                  onClick={() => {
                    const textToCopy = quickScanResult.parsed?.data?.url || quickScanResult.barcode
                    navigator.clipboard.writeText(textToCopy)
                    toast({ title: 'Copied to clipboard' })
                  }}
                >
                  <Icon icon="solar:copy-bold" className="h-4 w-4 mr-2" />
                  Copy
                </Button>
              </>
            )}
            <Button 
              variant={quickScanResult ? 'default' : 'outline'} 
              onClick={handleCloseQuickScan}
            >
              {quickScanResult ? 'Done' : 'Cancel'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
