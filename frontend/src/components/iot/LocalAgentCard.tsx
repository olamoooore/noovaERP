import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'

interface LocalAgent {
  id: number
  name: string
  agent_id: string
  status: 'online' | 'offline' | 'error'
  version: string
  last_seen_at: string | null
  config: {
    hostname?: string
    os?: string
    ip_address?: string
  }
}

interface LocalAgentCardProps {
  agent: LocalAgent
  onViewDevices: (agent: LocalAgent) => void
  onViewLogs: (agent: LocalAgent) => void
}

export function LocalAgentCard({ agent, onViewDevices, onViewLogs }: LocalAgentCardProps) {
  const getStatusColor = (status: string) => {
    switch (status) {
      case 'online':
        return 'bg-green-500'
      case 'offline':
        return 'bg-gray-500'
      case 'error':
        return 'bg-red-500'
      default:
        return 'bg-gray-500'
    }
  }

  const formatLastSeen = (lastSeen: string | null) => {
    if (!lastSeen) return 'Never'
    const date = new Date(lastSeen)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / 60000)
    
    if (diffMins < 1) return 'Just now'
    if (diffMins < 60) return `${diffMins}m ago`
    if (diffMins < 1440) return `${Math.floor(diffMins / 60)}h ago`
    return date.toLocaleDateString()
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-primary/10 rounded-lg">
              <Icon icon="solar:server-bold-duotone" className="h-6 w-6 text-primary" />
            </div>
            <div>
              <CardTitle className="text-lg">{agent.name}</CardTitle>
              <CardDescription className="font-mono text-xs">
                {agent.agent_id}
              </CardDescription>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <div className={`h-2 w-2 rounded-full ${getStatusColor(agent.status)}`} />
            <Badge variant={agent.status === 'online' ? 'default' : 'secondary'} className="capitalize">
              {agent.status}
            </Badge>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-2 text-sm">
            <div>
              <span className="text-muted-foreground">Version</span>
              <p className="font-medium">{agent.version}</p>
            </div>
            <div>
              <span className="text-muted-foreground">Last seen</span>
              <p className="font-medium">{formatLastSeen(agent.last_seen_at)}</p>
            </div>
          </div>

          {agent.config?.hostname && (
            <div className="text-sm">
              <span className="text-muted-foreground">Hostname</span>
              <p className="font-medium">{agent.config.hostname}</p>
            </div>
          )}

          {agent.config?.os && (
            <div className="text-sm">
              <span className="text-muted-foreground">OS</span>
              <p className="font-medium">{agent.config.os}</p>
            </div>
          )}

          <div className="flex gap-2 pt-2">
            <Button
              variant="outline"
              size="sm"
              className="flex-1"
              onClick={() => onViewDevices(agent)}
            >
              <Icon icon="solar:devices-bold" className="mr-2 h-4 w-4" />
              Devices
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => onViewLogs(agent)}
            >
              <Icon icon="solar:document-text-bold" className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
