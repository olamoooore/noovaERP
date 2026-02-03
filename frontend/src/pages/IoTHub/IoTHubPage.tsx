/**
 * NoovaERP IoT Hub Page
 * 
 * Universal Dashboard for viewing and controlling remote IoT devices.
 * Fetches devices from UMP and renders dynamic controls based on capabilities.
 * 
 * Sections:
 * 1. Connection Panel - Link/manage agent connection
 * 2. Device Grid - Display all devices with capability-based controls
 */

import { useState, useEffect, useCallback } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/hooks/use-toast'
import { UniversalDeviceCard } from '@/components/iot/UniversalDeviceCard'
import { LinkAccountModal } from '@/components/iot/LinkAccountModal'
import universalIoTService, { UMPDevice, AgentStatus } from '@/services/UniversalIoTService'

export function IoTHubPage() {
  const { toast } = useToast()
  
  // Connection state
  const [linkedAgentId, setLinkedAgentId] = useState<string | null>(null)
  const [agentStatus, setAgentStatus] = useState<AgentStatus | null>(null)
  
  // Devices state
  const [devices, setDevices] = useState<UMPDevice[]>([])
  const [isLoadingDevices, setIsLoadingDevices] = useState(false)
  
  // API Key state
  const [hasApiKey, setHasApiKey] = useState(false)
  const [showLinkModal, setShowLinkModal] = useState(false)
  const [authError, setAuthError] = useState<string | null>(null)

  // Load saved API key on mount and auto-connect if available
  useEffect(() => {
    const savedApiKey = localStorage.getItem('ump_api_key')
    
    if (savedApiKey) {
      setHasApiKey(true)
      universalIoTService.setApiKey(savedApiKey)
      // Auto-connect when API key exists - UMP auto-discovers agents
      setLinkedAgentId('UMP-AUTO')  // Placeholder - actual agent ID comes from devices
    }
  }, [])

  // Fetch devices when we have an API key (agent is auto-discovered by UMP)
  const fetchDevices = useCallback(async () => {
    // Check if we have an API key
    const currentApiKey = localStorage.getItem('ump_api_key')
    if (!currentApiKey) {
      setHasApiKey(false)
      return
    }
    
    setIsLoadingDevices(true)
    setAuthError(null)
    try {
      const deviceList = await universalIoTService.fetchDevices()
      setDevices(deviceList)
      setHasApiKey(true)
      
      // If we got devices, we're connected
      if (deviceList.length > 0) {
        setLinkedAgentId('UMP-CONNECTED')
      }
    } catch (error: unknown) {
      console.error('Failed to fetch devices:', error)
      const axiosError = error as { response?: { status?: number; data?: { error?: string } } }
      
      // If 401, the API key doesn't have device access permissions
      if (axiosError.response?.status === 401) {
        // Keep hasApiKey true since the key validated, but show permission error
        setHasApiKey(true)
        setAuthError(`401 Unauthorized: ${axiosError.response?.data?.error || 'Token lacks device permissions'}`)
        toast({
          title: 'Device Access Denied',
          description: 'Your API key validated but lacks device permissions. Check UMP backend configuration.',
          variant: 'destructive',
        })
      } else if (axiosError.response?.status === 403) {
        setHasApiKey(false)
        setAuthError('403 Forbidden: API key invalid')
        toast({
          title: 'Authentication Required',
          description: 'Please link your UMP account to access devices.',
          variant: 'destructive',
        })
      } else {
        setAuthError(`Error: ${axiosError.response?.data?.error || 'Connection failed'}`)
        toast({
          title: 'Failed to Load Devices',
          description: 'Could not fetch devices from UMP. Check your connection.',
          variant: 'destructive',
        })
      }
    } finally {
      setIsLoadingDevices(false)
    }
  }, [toast])

  // Check agent status (simplified - just check if UMP is reachable)
  const checkAgentStatus = useCallback(async () => {
    const status = await universalIoTService.checkAgentStatus('UMP')
    setAgentStatus(status)
  }, [])

  // Fetch devices and status when we have an API key
  useEffect(() => {
    const savedApiKey = localStorage.getItem('ump_api_key')
    if (savedApiKey) {
      fetchDevices()
      checkAgentStatus()
      
      // Poll for status every 30 seconds
      const interval = setInterval(() => {
        checkAgentStatus()
      }, 30000)
      
      return () => clearInterval(interval)
    }
  }, [fetchDevices, checkAgentStatus])

  // Disconnect from UMP
  const handleDisconnect = () => {
    localStorage.removeItem('ump_api_key')
    universalIoTService.clearApiKey()
    setLinkedAgentId(null)
    setAgentStatus(null)
    setDevices([])
    setHasApiKey(false)
    
    toast({
      title: 'Disconnected',
      description: 'You have been disconnected from UMP',
    })
  }

  // Refresh devices
  const handleRefresh = () => {
    fetchDevices()
    checkAgentStatus()
    toast({
      title: 'Refreshing',
      description: 'Fetching latest device status...',
    })
  }

  // Handle successful account linking
  const handleLinkSuccess = () => {
    setHasApiKey(true)
    setShowLinkModal(false)
    fetchDevices()
    toast({
      title: 'Account Linked',
      description: 'Your UMP account has been linked successfully.',
    })
  }

  // Device status change handler
  const handleDeviceStatusChange = (deviceId: string, status: UMPDevice['status']) => {
    setDevices(prev => prev.map(d => 
      d.id === deviceId ? { ...d, status } : d
    ))
  }

  // Group devices by type
  const devicesByType = devices.reduce((acc, device) => {
    const type = device.type || 'other'
    if (!acc[type]) acc[type] = []
    acc[type].push(device)
    return acc
  }, {} as Record<string, UMPDevice[]>)

  return (
    <div className="container mx-auto py-6 space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2">
            <Icon icon="solar:cpu-bolt-bold-duotone" className="h-7 w-7 text-primary" />
            NoovaERP IoT Hub
          </h1>
          <p className="text-muted-foreground">
            Universal dashboard for your IoT devices
          </p>
        </div>
        {linkedAgentId && (
          <Button variant="outline" onClick={handleRefresh} disabled={isLoadingDevices}>
            <Icon 
              icon="solar:refresh-bold" 
              className={`h-4 w-4 mr-2 ${isLoadingDevices ? 'animate-spin' : ''}`} 
            />
            Refresh
          </Button>
        )}
      </div>

      {/* Section 1: Connection Panel */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 bg-primary/10 rounded-lg">
                <Icon icon="solar:link-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <div>
                <CardTitle>UMP Connection</CardTitle>
                <CardDescription>
                  Connect to your UMP Local Agent to access devices
                </CardDescription>
              </div>
            </div>
            {linkedAgentId && (
              <Badge 
                variant={!hasApiKey ? 'outline' : agentStatus?.connected ? 'default' : 'destructive'} 
                className={`gap-2 ${!hasApiKey ? 'border-yellow-500 text-yellow-600 dark:text-yellow-400' : ''}`}
              >
                <div className={`h-2 w-2 rounded-full ${
                  !hasApiKey ? 'bg-yellow-500' : 
                  agentStatus?.connected ? 'bg-green-500 animate-pulse' : 'bg-red-500'
                }`} />
                {!hasApiKey ? 'Account Not Linked' : agentStatus?.connected ? 'Connected' : 'Disconnected'}
              </Badge>
            )}
          </div>
        </CardHeader>
        <CardContent>
          {linkedAgentId ? (
            // Connected State
            <div className="space-y-4">
              {/* Agent Connected but No API Key */}
              {!hasApiKey ? (
                <div className="flex items-center justify-between p-4 bg-yellow-50 dark:bg-yellow-950/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                  <div className="flex items-center gap-3">
                    <Icon icon="solar:key-bold" className="h-6 w-6 text-yellow-600" />
                    <div>
                      <p className="font-medium text-yellow-900 dark:text-yellow-100">
                        UMP Reachable - Account Not Linked
                      </p>
                      <p className="text-sm text-yellow-700 dark:text-yellow-300">
                        Link your UMP account to access devices
                      </p>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      onClick={() => setShowLinkModal(true)}
                      className="bg-yellow-600 hover:bg-yellow-700"
                    >
                      <Icon icon="solar:key-bold" className="mr-2 h-4 w-4" />
                      Link UMP Account
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={handleDisconnect}
                      className="text-red-600 hover:text-red-700 hover:bg-red-50"
                    >
                      <Icon icon="solar:link-broken-bold" className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              ) : (
                // Fully Connected State
                <div className="flex items-center justify-between p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-lg">
                  <div className="flex items-center gap-3">
                    <Icon icon="solar:check-circle-bold" className="h-6 w-6 text-green-600" />
                    <div>
                      <p className="font-medium text-green-900 dark:text-green-100">
                        Connected to UMP
                      </p>
                      <p className="text-sm text-green-700 dark:text-green-300">
                        {devices.length} device{devices.length !== 1 ? 's' : ''} available
                      </p>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={handleDisconnect}
                    className="text-red-600 hover:text-red-700 hover:bg-red-50"
                  >
                    <Icon icon="solar:link-broken-bold" className="mr-2 h-4 w-4" />
                    Disconnect
                  </Button>
                </div>
              )}

              {/* Agent Stats */}
              {agentStatus?.connected && (
                <div className="grid grid-cols-3 gap-4">
                  <div className="p-3 bg-muted/50 rounded-lg text-center">
                    <p className="text-2xl font-bold">{devices.length}</p>
                    <p className="text-xs text-muted-foreground">Devices</p>
                  </div>
                  <div className="p-3 bg-muted/50 rounded-lg text-center">
                    <p className="text-2xl font-bold">
                      {devices.filter(d => d.status === 'online').length}
                    </p>
                    <p className="text-xs text-muted-foreground">Online</p>
                  </div>
                  <div className="p-3 bg-muted/50 rounded-lg text-center">
                    <p className="text-2xl font-bold">{agentStatus.version || '-'}</p>
                    <p className="text-xs text-muted-foreground">Version</p>
                  </div>
                </div>
              )}

              {/* Auth Error Debug Info */}
              {authError && (
                <div className="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-800 rounded-lg">
                  <div className="flex items-start gap-3">
                    <Icon icon="solar:bug-bold" className="h-5 w-5 text-red-600 mt-0.5" />
                    <div className="flex-1">
                      <p className="font-medium text-red-900 dark:text-red-100 mb-1">
                        UMP Authentication Issue
                      </p>
                      <p className="text-sm text-red-700 dark:text-red-300 font-mono mb-2">
                        {authError}
                      </p>
                      <p className="text-xs text-red-600 dark:text-red-400">
                        The API key validates at /auth/validate but device endpoints require different permissions.
                        Check UMP backend middleware configuration.
                      </p>
                    </div>
                  </div>
                </div>
              )}
            </div>
          ) : (
            // Not Connected State - Just need API key, no agent ID
            <div className="space-y-4">
              <div className="text-center py-4">
                <Icon icon="solar:key-bold-duotone" className="h-12 w-12 text-muted-foreground mx-auto mb-3" />
                <h3 className="font-medium mb-1">Connect to UMP</h3>
                <p className="text-sm text-muted-foreground mb-4">
                  Link your UMP account to access your IoT devices
                </p>
              </div>

              <Button
                onClick={() => setShowLinkModal(true)}
                className="w-full"
              >
                <Icon icon="solar:key-bold" className="mr-2 h-4 w-4" />
                Link UMP Account
              </Button>

              <p className="text-xs text-muted-foreground text-center">
                You'll need your UMP API key (starts with <code className="bg-muted px-1 rounded">ump_</code>)
              </p>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Link Account Modal */}
      <LinkAccountModal
        isOpen={showLinkModal}
        onClose={() => setShowLinkModal(false)}
        onSuccess={handleLinkSuccess}
      />

      {/* Section 2: Device Grid */}
      {linkedAgentId && (
        <div className="space-y-6">
          {isLoadingDevices ? (
            // Loading skeleton
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {[1, 2, 3].map((i) => (
                <Card key={i}>
                  <CardHeader>
                    <Skeleton className="h-6 w-32" />
                    <Skeleton className="h-4 w-24" />
                  </CardHeader>
                  <CardContent>
                    <Skeleton className="h-20 w-full" />
                  </CardContent>
                </Card>
              ))}
            </div>
          ) : devices.length === 0 ? (
            // No devices
            <Card>
              <CardContent className="py-12 text-center">
                <Icon icon="solar:box-bold-duotone" className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
                <h3 className="text-lg font-medium mb-2">No Devices Found</h3>
                <p className="text-muted-foreground mb-4">
                  No devices are registered with this agent yet.
                </p>
                <Button variant="outline" onClick={handleRefresh}>
                  <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
                  Refresh
                </Button>
              </CardContent>
            </Card>
          ) : (
            // Device grid grouped by type
            Object.entries(devicesByType).map(([type, typeDevices]) => (
              <div key={type} className="space-y-4">
                <h2 className="text-lg font-semibold capitalize flex items-center gap-2">
                  <Icon 
                    icon={
                      type === 'printer' ? 'solar:printer-bold-duotone' :
                      type === 'scanner' ? 'solar:scanner-bold-duotone' :
                      type === 'terminal' ? 'solar:card-bold-duotone' :
                      type === 'scale' ? 'solar:scale-bold-duotone' :
                      type === 'display' ? 'solar:monitor-bold-duotone' :
                      'solar:cpu-bolt-bold-duotone'
                    } 
                    className="h-5 w-5" 
                  />
                  {type}s ({typeDevices.length})
                </h2>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {typeDevices.map((device) => (
                    <UniversalDeviceCard
                      key={device.id}
                      device={device}
                      agentId={linkedAgentId}
                      onStatusChange={handleDeviceStatusChange}
                    />
                  ))}
                </div>
              </div>
            ))
          )}
        </div>
      )}
    </div>
  )
}

export default IoTHubPage
