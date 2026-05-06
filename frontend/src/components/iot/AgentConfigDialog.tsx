import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import iotApi from '@/lib/iotApi'
import { Badge } from '@/components/ui/badge'

interface AgentConfigDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function AgentConfigDialog({ open, onOpenChange }: AgentConfigDialogProps) {
  const [agentUrl, setAgentUrl] = useState('')
  const [agentToken, setAgentToken] = useState('')
  const [agentId, setAgentId] = useState('')
  const [testStatus, setTestStatus] = useState<'idle' | 'testing' | 'success' | 'error'>('idle')
  const [testMessage, setTestMessage] = useState('')
  
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch current configuration
  const { data: configData, isLoading } = useQuery({
    queryKey: ['agent-config'],
    queryFn: () => iotApi.getAgentConfig(),
    enabled: open,
  })

  // Load configuration when dialog opens
  useEffect(() => {
    if (configData?.data) {
      setAgentUrl(configData.data.agent_url || '')
      setAgentToken(configData.data.agent_token || '')
      setAgentId(configData.data.agent_id || '')
    }
  }, [configData])

  // Test connection mutation
  const testMutation = useMutation({
    mutationFn: (data: { agent_url: string; agent_token?: string }) =>
      iotApi.testAgentConnection(data),
    onMutate: () => {
      setTestStatus('testing')
      setTestMessage('Testing connection...')
    },
    onSuccess: (data) => {
      if (data.success) {
        setTestStatus('success')
        setTestMessage(`Connected! Agent: ${data.agent_id || 'Unknown'}, Version: ${data.version || 'Unknown'}`)
        toast({
          title: 'Connection successful',
          description: 'Agent is online and responding',
        })
      } else {
        setTestStatus('error')
        setTestMessage(data.error || 'Connection failed')
        toast({
          title: 'Connection failed',
          description: data.error || 'Could not connect to agent',
          variant: 'destructive',
        })
      }
    },
    onError: (error: any) => {
      setTestStatus('error')
      setTestMessage(error.response?.data?.message || error.message || 'Connection failed')
      toast({
        title: 'Connection failed',
        description: error.response?.data?.message || error.message || 'Could not connect to agent',
        variant: 'destructive',
      })
    },
  })

  // Save configuration mutation
  const saveMutation = useMutation({
    mutationFn: async (data: { agent_url: string; agent_token?: string; agent_id?: string }) => {
      const configResponse = await iotApi.updateAgentConfig(data)

      const trimmedAgentId = data.agent_id?.trim()
      if (trimmedAgentId) {
        await iotApi.linkAgent(trimmedAgentId)
      }

      return {
        configResponse,
        linkedAgentId: trimmedAgentId ?? null,
      }
    },
    onSuccess: ({ linkedAgentId }) => {
      if (linkedAgentId && typeof window !== 'undefined') {
        localStorage.setItem('ump_agent_id', linkedAgentId)
      }

      toast({
        title: 'Configuration saved',
        description: linkedAgentId
          ? 'Agent configuration saved and linked to this session'
          : 'Agent configuration has been updated',
      })
      queryClient.invalidateQueries({ queryKey: ['agent-config'] })
      queryClient.invalidateQueries({ queryKey: ['agent-health'] })
      queryClient.invalidateQueries({ queryKey: ['linked-agent'] })
      onOpenChange(false)
    },
    onError: (error: any) => {
      toast({
        title: 'Save failed',
        description: error.response?.data?.message || error.message || 'Failed to save configuration',
        variant: 'destructive',
      })
    },
  })

  const handleTest = () => {
    if (!agentUrl) {
      toast({
        title: 'Validation error',
        description: 'Agent URL is required',
        variant: 'destructive',
      })
      return
    }

    testMutation.mutate({
      agent_url: agentUrl,
      agent_token: agentToken,
    })
  }

  const handleSave = () => {
    if (!agentUrl) {
      toast({
        title: 'Validation error',
        description: 'Agent URL is required',
        variant: 'destructive',
      })
      return
    }

    saveMutation.mutate({
      agent_url: agentUrl,
      agent_token: agentToken,
      agent_id: agentId,
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[600px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Icon icon="solar:settings-bold-duotone" className="h-5 w-5" />
            Agent Configuration
          </DialogTitle>
          <DialogDescription>
            Configure the connection to your local IoT agent
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
          </div>
        ) : (
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="agent-url">Agent URL</Label>
              <Input
                id="agent-url"
                placeholder="https://your-tunnel-url.trycloudflare.com"
                value={agentUrl}
                onChange={(e) => setAgentUrl(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                The URL where your agent is accessible (e.g., Cloudflare tunnel URL)
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="agent-token">Agent Token</Label>
              <Input
                id="agent-token"
                type="password"
                placeholder="Optional bearer token for authentication"
                value={agentToken}
                onChange={(e) => setAgentToken(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Leave blank if your local agent does not require authentication
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="agent-id">Agent ID</Label>
              <Input
                id="agent-id"
                placeholder="Enter the agent ID for this POS machine"
                value={agentId}
                onChange={(e) => setAgentId(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Unique identifier for this agent (optional)
              </p>
            </div>

            {/* Test Connection Status */}
            {testStatus !== 'idle' && (
              <div className={`p-4 rounded-lg border ${
                testStatus === 'testing' ? 'bg-blue-50 border-blue-200' :
                testStatus === 'success' ? 'bg-green-50 border-green-200' :
                'bg-red-50 border-red-200'
              }`}>
                <div className="flex items-start gap-3">
                  {testStatus === 'testing' && (
                    <Icon icon="solar:restart-circle-bold" className="h-5 w-5 animate-spin text-blue-600 mt-0.5" />
                  )}
                  {testStatus === 'success' && (
                    <Icon icon="solar:check-circle-bold" className="h-5 w-5 text-green-600 mt-0.5" />
                  )}
                  {testStatus === 'error' && (
                    <Icon icon="solar:close-circle-bold" className="h-5 w-5 text-red-600 mt-0.5" />
                  )}
                  <div className="flex-1">
                    <p className={`text-sm font-medium ${
                      testStatus === 'testing' ? 'text-blue-900' :
                      testStatus === 'success' ? 'text-green-900' :
                      'text-red-900'
                    }`}>
                      {testStatus === 'testing' ? 'Testing Connection' :
                       testStatus === 'success' ? 'Connection Successful' :
                       'Connection Failed'}
                    </p>
                    <p className={`text-xs mt-1 ${
                      testStatus === 'testing' ? 'text-blue-700' :
                      testStatus === 'success' ? 'text-green-700' :
                      'text-red-700'
                    }`}>
                      {testMessage}
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* Current Configuration Info */}
            {configData?.data && (
              <div className="p-4 rounded-lg bg-muted">
                <div className="flex items-center gap-2 mb-2">
                  <Icon icon="solar:info-circle-bold" className="h-4 w-4 text-muted-foreground" />
                  <span className="text-sm font-medium">Current Configuration</span>
                </div>
                <div className="space-y-1 text-xs text-muted-foreground">
                  <div className="flex items-center justify-between">
                    <span>Agent URL:</span>
                    <Badge variant="outline" className="font-mono text-xs">
                      {configData.data.agent_url || 'Not configured'}
                    </Badge>
                  </div>
                  <div className="flex items-center justify-between">
                    <span>Agent ID:</span>
                    <Badge variant="outline" className="font-mono text-xs">
                      {configData.data.agent_id || 'Not configured'}
                    </Badge>
                  </div>
                  <div className="flex items-center justify-between">
                    <span>Token:</span>
                    <Badge variant="outline" className="font-mono text-xs">
                      {configData.data.agent_token ? '••••••••' : 'Not configured'}
                    </Badge>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        <DialogFooter className="gap-2">
          <Button
            variant="outline"
            onClick={handleTest}
            disabled={testMutation.isPending || !agentUrl}
          >
            <Icon icon="solar:link-bold" className="mr-2 h-4 w-4" />
            Test Connection
          </Button>
          <Button
            onClick={handleSave}
            disabled={saveMutation.isPending || !agentUrl}
          >
            <Icon icon="solar:diskette-bold" className="mr-2 h-4 w-4" />
            Save Configuration
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
