import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import api from '@/lib/api'

interface Alert {
  id: number
  device_id: number
  device_name: string
  device_type: string
  status: string
  error_message?: string
  checked_at: string
  severity: 'critical' | 'warning'
}

export function RecentAlertsPanel() {
  const [timeRange, setTimeRange] = useState('24')
  const [statusFilter, setStatusFilter] = useState('all')
  const [searchQuery, setSearchQuery] = useState('')

  // Fetch recent alerts
  const { data: alerts, isLoading, refetch } = useQuery<Alert[]>({
    queryKey: ['iot-recent-alerts', timeRange],
    queryFn: async () => {
      const response = await api.get(`/iot/monitoring/recent-alerts?hours=${timeRange}`)
      return response.data
    },
    refetchInterval: 30000, // Refresh every 30 seconds
  })

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return 'text-red-600 bg-red-100'
      case 'warning':
        return 'text-yellow-600 bg-yellow-100'
      default:
        return 'text-gray-600 bg-gray-100'
    }
  }

  const getSeverityIcon = (severity: string) => {
    switch (severity) {
      case 'critical':
        return 'solar:danger-bold'
      case 'warning':
        return 'solar:warning-bold'
      default:
        return 'solar:info-circle-bold'
    }
  }

  const getDeviceIcon = (type: string) => {
    switch (type) {
      case 'printer':
        return 'solar:printer-bold-duotone'
      case 'terminal':
        return 'solar:card-bold-duotone'
      case 'scanner':
        return 'solar:qr-code-bold-duotone'
      case 'kiosk':
        return 'solar:monitor-bold-duotone'
      default:
        return 'solar:devices-bold-duotone'
    }
  }

  const filteredAlerts = alerts?.filter(alert => {
    if (statusFilter !== 'all' && alert.status !== statusFilter) return false
    if (searchQuery && !alert.device_name.toLowerCase().includes(searchQuery.toLowerCase())) return false
    return true
  }) || []

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Recent Alerts</CardTitle>
          <CardDescription>Device alerts and notifications</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-center h-64">
            <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between">
          <div>
            <CardTitle>Recent Alerts</CardTitle>
            <CardDescription>Device alerts and notifications</CardDescription>
          </div>
          <Button onClick={() => refetch()} variant="outline" size="sm">
            <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
            Refresh
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Filters */}
        <div className="flex gap-4">
          <Input
            placeholder="Search devices..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="max-w-xs"
          />
          <Select value={timeRange} onValueChange={setTimeRange}>
            <SelectTrigger className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="1">Last Hour</SelectItem>
              <SelectItem value="6">Last 6 Hours</SelectItem>
              <SelectItem value="24">Last 24 Hours</SelectItem>
              <SelectItem value="168">Last Week</SelectItem>
            </SelectContent>
          </Select>
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="w-40">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="offline">Offline</SelectItem>
              <SelectItem value="error">Error</SelectItem>
              <SelectItem value="timeout">Timeout</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Alerts List */}
        {filteredAlerts.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12">
            <Icon icon="solar:shield-check-bold-duotone" className="h-16 w-16 text-green-500 mb-4" />
            <h3 className="text-lg font-semibold mb-2">No alerts found</h3>
            <p className="text-muted-foreground text-center">
              {searchQuery || statusFilter !== 'all' 
                ? 'Try adjusting your filters' 
                : 'All devices are operating normally'}
            </p>
          </div>
        ) : (
          <div className="space-y-3">
            {filteredAlerts.map((alert) => (
              <div key={alert.id} className="flex items-start gap-4 p-4 border rounded-lg">
                <div className="flex items-center gap-3">
                  <Icon 
                    icon={getSeverityIcon(alert.severity)} 
                    className={`h-5 w-5 ${alert.severity === 'critical' ? 'text-red-500' : 'text-yellow-500'}`}
                  />
                  <div className="p-2 bg-muted rounded-lg">
                    <Icon 
                      icon={getDeviceIcon(alert.device_type)} 
                      className="h-5 w-5 text-primary" 
                    />
                  </div>
                </div>
                
                <div className="flex-1 space-y-2">
                  <div className="flex items-center justify-between">
                    <h4 className="font-semibold">{alert.device_name}</h4>
                    <div className="flex items-center gap-2">
                      <Badge className={getSeverityColor(alert.severity)}>
                        {alert.severity}
                      </Badge>
                      <Badge variant="outline" className="capitalize">
                        {alert.status}
                      </Badge>
                    </div>
                  </div>
                  
                  <p className="text-sm text-muted-foreground capitalize">
                    {alert.device_type} • {new Date(alert.checked_at).toLocaleString()}
                  </p>
                  
                  {alert.error_message && (
                    <p className="text-sm text-red-600 bg-red-50 p-2 rounded">
                      {alert.error_message}
                    </p>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Summary */}
        {filteredAlerts.length > 0 && (
          <div className="pt-4 border-t">
            <div className="flex items-center justify-between text-sm text-muted-foreground">
              <span>Showing {filteredAlerts.length} alerts</span>
              <div className="flex gap-4">
                <span className="flex items-center gap-1">
                  <div className="h-2 w-2 rounded-full bg-red-500"></div>
                  {filteredAlerts.filter(a => a.severity === 'critical').length} Critical
                </span>
                <span className="flex items-center gap-1">
                  <div className="h-2 w-2 rounded-full bg-yellow-500"></div>
                  {filteredAlerts.filter(a => a.severity === 'warning').length} Warning
                </span>
              </div>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}