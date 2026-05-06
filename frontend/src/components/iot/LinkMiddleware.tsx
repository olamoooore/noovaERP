import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface LinkMiddlewareProps {
  onLinked?: (agentId: string) => void
}

export function LinkMiddleware({ onLinked }: LinkMiddlewareProps) {
  const [agentId, setAgentId] = useState('')
  const [linkedAgentId, setLinkedAgentId] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [isVerifying, setIsVerifying] = useState(false)
  const { toast } = useToast()

  // Load linked agent ID from localStorage on mount
  useEffect(() => {
    const storedAgentId = localStorage.getItem('ump_agent_id')
    if (storedAgentId) {
      setLinkedAgentId(storedAgentId)
      setAgentId(storedAgentId)
    }
  }, [])

  const handleConnect = async () => {
    if (!agentId.trim()) {
      toast({
        title: 'Agent ID Required',
        description: 'Please enter your UMP Agent ID',
        variant: 'destructive',
      })
      return
    }

    setIsLoading(true)

    try {
      // Save to backend (user profile)
      const response = await api.post('/iot/user/link-agent', {
        agent_id: agentId.trim(),
      })

      if (response.data.success) {
        // Save to localStorage
        localStorage.setItem('ump_agent_id', agentId.trim())
        setLinkedAgentId(agentId.trim())

        toast({
          title: 'Successfully Linked',
          description: `Your account is now linked to Agent: ${agentId.trim()}`,
        })

        // Call callback if provided
        if (onLinked) {
          onLinked(agentId.trim())
        }
      }
    } catch (error: any) {
      toast({
        title: 'Connection Failed',
        description: error.response?.data?.message || 'Failed to link agent. Please try again.',
        variant: 'destructive',
      })
    } finally {
      setIsLoading(false)
    }
  }

  const handleDisconnect = () => {
    // Remove from localStorage
    localStorage.removeItem('ump_agent_id')
    setLinkedAgentId(null)
    setAgentId('')

    // Remove from backend
    api.post('/iot/user/unlink-agent').catch(() => {
      // Silent fail - already removed from localStorage
    })

    toast({
      title: 'Disconnected',
      description: 'Your account has been unlinked from the UMP Agent',
    })
  }

  const handleVerifyConnection = async () => {
    if (!linkedAgentId) return

    setIsVerifying(true)

    try {
      const response = await api.get(`/iot/agent/verify/${linkedAgentId}`)

      if (response.data.success) {
        toast({
          title: 'Connection Verified',
          description: `Agent ${linkedAgentId} is online and responding`,
        })
      } else {
        toast({
          title: 'Connection Failed',
          description: 'Agent is not responding. Please check if the Local Agent is running.',
          variant: 'destructive',
        })
      }
    } catch (error) {
      toast({
        title: 'Verification Failed',
        description: 'Could not verify agent connection',
        variant: 'destructive',
      })
    } finally {
      setIsVerifying(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon icon="solar:link-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <CardTitle>UMP Middleware Connection</CardTitle>
              <CardDescription>
                Link your account to a UMP Local Agent for printer and device access
              </CardDescription>
            </div>
          </div>
          {linkedAgentId && (
            <Badge variant="default" className="gap-2">
              <div className="h-2 w-2 rounded-full bg-green-500 animate-pulse" />
              Linked
            </Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {linkedAgentId ? (
          // Linked State
          <div className="space-y-4">
            <div className="flex items-center justify-between p-4 bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-800 rounded-lg">
              <div className="flex items-center gap-3">
                <Icon icon="solar:check-circle-bold" className="h-6 w-6 text-green-600" />
                <div>
                  <p className="font-medium text-green-900 dark:text-green-100">
                    Connected to Agent
                  </p>
                  <p className="text-sm text-green-700 dark:text-green-300">
                    Agent ID: <span className="font-mono font-semibold">{linkedAgentId}</span>
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

            <div className="flex gap-2">
              <Button
                variant="outline"
                onClick={handleVerifyConnection}
                disabled={isVerifying}
                className="flex-1"
              >
                {isVerifying ? (
                  <>
                    <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                    Verifying...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:shield-check-bold" className="mr-2 h-4 w-4" />
                    Verify Connection
                  </>
                )}
              </Button>
            </div>

            <div className="p-3 bg-muted/50 rounded-lg">
              <div className="flex items-start gap-2">
                <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-muted-foreground mt-0.5" />
                <div className="space-y-1">
                  <p className="text-sm font-medium">What does this do?</p>
                  <p className="text-xs text-muted-foreground">
                    This links your NoovaERP account to a Local Agent running on your POS machine. 
                    Once linked, you can print labels and receipts directly from NoovaERP to printers 
                    connected to that agent.
                  </p>
                </div>
              </div>
            </div>
          </div>
        ) : (
          // Not Linked State
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="agent-id">UMP Agent ID</Label>
              <Input
                id="agent-id"
                placeholder="e.g., agent-auto-generated"
                value={agentId}
                onChange={(e) => setAgentId(e.target.value)}
                disabled={isLoading}
              />
              <p className="text-xs text-muted-foreground">
                Enter the Agent ID from your Local Agent installation
              </p>
            </div>

            <Button
              onClick={handleConnect}
              disabled={isLoading || !agentId.trim()}
              className="w-full"
            >
              {isLoading ? (
                <>
                  <Icon icon="solar:restart-circle-bold" className="mr-2 h-4 w-4 animate-spin" />
                  Connecting...
                </>
              ) : (
                <>
                  <Icon icon="solar:link-bold" className="mr-2 h-4 w-4" />
                  Connect to Agent
                </>
              )}
            </Button>

            <div className="p-3 bg-muted/50 rounded-lg">
              <div className="flex items-start gap-2">
                <Icon icon="solar:question-circle-bold" className="h-5 w-5 text-muted-foreground mt-0.5" />
                <div className="space-y-1">
                  <p className="text-sm font-medium">Where do I find my Agent ID?</p>
                  <p className="text-xs text-muted-foreground">
                    Your Agent ID is displayed in the Local Agent application running on your POS machine. 
                    It's usually shown in the main window or in the settings.
                  </p>
                  <p className="text-xs text-muted-foreground mt-2">
                    Don't have a Local Agent installed? 
                    <a href="#" className="text-primary hover:underline ml-1">
                      Download and install it here
                    </a>
                  </p>
                </div>
              </div>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
