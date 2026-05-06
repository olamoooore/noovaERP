import { useState, useEffect, useCallback } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration, QRParsedData } from '@/lib/iotApi'
import { useLinkedAgent } from '@/hooks/useLinkedAgent'

interface QRScanButtonProps {
  onScanResult?: (barcode: string, parsed?: QRParsedData) => void
  className?: string
}

export function QRScanButton({ onScanResult, className }: QRScanButtonProps) {
  const [isScanning, setIsScanning] = useState(false)
  const [showDialog, setShowDialog] = useState(false)
  const [lastScan, setLastScan] = useState<{ barcode: string; parsed?: QRParsedData } | null>(null)
  const [lastScanId, setLastScanId] = useState<string | null>(null)
  const { toast } = useToast()
  const { agentId } = useLinkedAgent()
  const hasLinkedAgent = !!agentId

  // Get agent health
  const { data: agentHealth } = useQuery({
    queryKey: ['agent-health', agentId],
    queryFn: () => iotApi.checkAgentHealth(agentId!),
    enabled: hasLinkedAgent,
    refetchInterval: 30000,
  })

  const agentOnline = agentHealth?.status === 'online'

  // Find scanner device
  const { data: devicesData } = useQuery({
    queryKey: ['device-configs', agentId],
    queryFn: () => iotApi.getDeviceConfigs(agentId!),
    enabled: hasLinkedAgent && agentOnline,
  })

  const scanner = devicesData?.data?.find(
    (d: DeviceConfiguration) => 
      d.deviceType === 'scanner' && d.enabled
  )

  // Poll for scan results when scanning
  const { data: historyData } = useQuery({
    queryKey: ['scan-history', agentId, scanner?.id],
    queryFn: () => iotApi.getScanHistory(agentId!, scanner?.id, 1),
    enabled: hasLinkedAgent && isScanning && !!scanner,
    refetchInterval: isScanning ? 500 : false,
  })

  // Detect new scan from polling
  useEffect(() => {
    if (!isScanning || !historyData?.data?.length) return

    const latestScan = historyData.data[0]
    if (latestScan.id !== lastScanId) {
      // First poll - just record the ID
      if (lastScanId === null) {
        setLastScanId(latestScan.id)
        return
      }

      // New scan detected!
      setLastScanId(latestScan.id)
      const parsed = latestScan.metadata?.parsed as QRParsedData | undefined

      setLastScan({ barcode: latestScan.barcode, parsed })
      
      // Call optional callback
      onScanResult?.(latestScan.barcode, parsed)

      // Stop scanning (single mode)
      stopScanning()

      toast({
        title: '✅ Scan Successful!',
        description: latestScan.barcode.length > 50 
          ? latestScan.barcode.substring(0, 50) + '...'
          : latestScan.barcode,
      })
    }
  }, [historyData, isScanning, lastScanId, onScanResult, toast])

  // Start scanning mutation
  const startScanMutation = useMutation({
    mutationFn: () => {
      if (!agentId || !scanner) {
        throw new Error('No linked scanner agent configured.')
      }

      return iotApi.startScannerListening(agentId, scanner.id, {
        mode: 'single',
        timeout: 30,
      })
    },
    onSuccess: () => {
      setIsScanning(true)
      setLastScanId(historyData?.data?.[0]?.id || null)
      setShowDialog(true)
    },
    onError: (error: any) => {
      toast({
        title: 'Failed to start scanner',
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  // Stop scanning mutation
  const stopScanMutation = useMutation({
    mutationFn: () => {
      if (!agentId || !scanner) {
        throw new Error('No linked scanner agent configured.')
      }

      return iotApi.stopScannerListening(agentId, scanner.id)
    },
  })

  const startScanning = () => {
    if (!agentId) {
      toast({
        title: 'No Linked Agent',
        description: 'Link this POS session to a tenant agent before scanning.',
        variant: 'destructive',
      })
      return
    }

    if (!scanner) {
      toast({
        title: 'No Scanner Available',
        description: 'Configure a scanner in IoT Hub first',
        variant: 'destructive',
      })
      return
    }
    setLastScan(null)
    startScanMutation.mutate()
  }

  const stopScanning = useCallback(() => {
    setIsScanning(false)
    if (scanner) {
      stopScanMutation.mutate()
    }
  }, [scanner, stopScanMutation])

  // Auto-stop after timeout
  useEffect(() => {
    if (!isScanning) return
    const timeout = setTimeout(() => {
      stopScanning()
      setShowDialog(false)
      toast({
        title: 'Scan Timeout',
        description: 'No code detected within 30 seconds',
      })
    }, 30000)
    return () => clearTimeout(timeout)
  }, [isScanning, stopScanning, toast])

  const isDisabled = !agentOnline || !scanner || startScanMutation.isPending

  // Get button title for accessibility
  const getButtonTitle = () => {
    if (!agentId) return 'No linked agent configured'
    if (!agentOnline) return 'Agent offline'
    if (!scanner) return 'No scanner configured'
    return 'Scan QR/Barcode'
  }

  return (
    <>
      <Button
        variant={isScanning ? 'default' : 'outline'}
        size="icon"
        className={className}
        onClick={startScanning}
        disabled={isDisabled}
        title={getButtonTitle()}
      >
        <Icon 
          icon="solar:qr-code-bold-duotone" 
          className={`h-5 w-5 ${isScanning ? 'animate-pulse' : ''}`} 
        />
      </Button>

      {/* Scanning Dialog */}
      <Dialog open={showDialog} onOpenChange={(open) => {
        if (!open) {
          stopScanning()
          setShowDialog(false)
        }
      }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:qr-code-bold-duotone" className="h-5 w-5 text-primary" />
              Scan QR Code / Barcode
            </DialogTitle>
            <DialogDescription>
              Point the scanner at a code
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col items-center py-6 space-y-4">
            {!lastScan ? (
              <>
                <div className="relative">
                  <div className="w-32 h-32 border-4 border-dashed border-primary/50 rounded-lg flex items-center justify-center">
                    <Icon 
                      icon="solar:qr-code-bold-duotone" 
                      className="h-16 w-16 text-primary animate-pulse"
                    />
                  </div>
                  <div className="absolute -top-2 -right-2">
                    <Badge variant="default" className="animate-pulse">
                      Scanning...
                    </Badge>
                  </div>
                </div>
                <p className="text-sm text-muted-foreground text-center">
                  Waiting for scan...<br />
                  <span className="text-xs">Auto-timeout in 30 seconds</span>
                </p>
              </>
            ) : (
              <div className="w-full space-y-3">
                <div className="flex items-center gap-2 text-green-600">
                  <Icon icon="solar:check-circle-bold" className="h-6 w-6" />
                  <span className="font-medium">Scan Successful!</span>
                </div>
                
                <div className="p-3 bg-muted rounded-lg">
                  <p className="text-xs text-muted-foreground mb-1">Scanned Data:</p>
                  <p className="font-mono text-sm break-all">
                    {lastScan.barcode}
                  </p>
                </div>

                {lastScan.parsed && (
                  <div className="p-3 bg-primary/10 rounded-lg">
                    <p className="text-xs text-muted-foreground mb-1">
                      Parsed ({lastScan.parsed.type}):
                    </p>
                    <pre className="text-xs font-mono overflow-auto max-h-32">
                      {JSON.stringify(lastScan.parsed.data, null, 2)}
                    </pre>
                  </div>
                )}
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2">
            {lastScan && (
              <Button 
                variant="outline" 
                onClick={() => {
                  navigator.clipboard.writeText(lastScan.barcode)
                  toast({ title: 'Copied to clipboard' })
                }}
              >
                <Icon icon="solar:copy-bold" className="h-4 w-4 mr-2" />
                Copy
              </Button>
            )}
            <Button 
              variant={lastScan ? 'default' : 'outline'} 
              onClick={() => {
                stopScanning()
                setShowDialog(false)
              }}
            >
              {lastScan ? 'Done' : 'Cancel'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
