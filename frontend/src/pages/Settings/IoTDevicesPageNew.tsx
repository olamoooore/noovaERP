import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import iotApi, { DeviceConfiguration, DiscoveredDevice } from '@/lib/iotApi'

// Hardcoded agent ID for now - will be dynamic later
const DEFAULT_AGENT_ID = 'agent-676da24f-97d3-4d74-bcd2-c235a30dbac7'

export function IoTDevicesPageNew() {
  const [selectedTab, setSelectedTab] = useState('configured')
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // Fetch agent health
  const { data: agentHealth, isLoading: healthLoading } = useQuery({
    queryKey: ['agent-health', DEFAULT_AGENT_ID],
    queryFn: () => iotApi.checkAgentHealth(DEFAULT_AGENT_ID),
    refetchInterval: 30000, // Poll every 30 seconds
  })

  // Fetch device configurations
  const { data: deviceConfigs, isLoading: configsLoading } = useQuery({
    queryKey: ['device-configs', DEFAULT_AGENT_ID],
    queryFn: () => iotA