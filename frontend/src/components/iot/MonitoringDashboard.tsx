import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { DeviceHealthWidget } from './DeviceHealthWidget'
import { HealthMetricsChart } from './HealthMetricsChart'
import { RecentAlertsPanel } from './RecentAlertsPanel'

interface SystemHealth {
  system_status: 'healthy' | 'degraded' | 'critical'
  total_devices: number
  online_devices: number
  offline_devices: number
  overall_uptime: number
  performance: {
    avg_uptime: number | null
    avg_response_time: number | null
    operation_success_rate: number | null
  }
  alerts: {
    critical: number
    warning: number
  }
}

interface DashboardData {
  overview: {
    total_devices: number
    online_devices: number
    offline_devices: number
    error_devices: number
    overall_uptime: number
  }
  recent_alerts: Array<{
    id: number
    device_id: number
    device_name: string
    status: string
    error_message?: string
    checked_at: string
  }>
  performance_trends: Array<{
    date: string
    avg_uptime: number
    avg_response_time: number
    total_operations: number
    successful_operations: number
  }>
}

export function MonitoringDashboard() {
  const { toast } = useToast()
  const [autoRefresh, setAutoRefresh] = useState(true)

  // Fetch system health data
  const { data: systemHealth, isLoading: systemHealthLoading, refetch: refetchSystemHealth } = useQuery<SystemHealth>({
    queryKey: ['iot-system-health'],
    queryFn: async () => {
      const response = await api.get('/iot/monitoring/system-health')
      return response.data
    },
    refetchInterval: autoRefresh ? 30000 : false, // Refresh every 30 seconds
  })

  // Fetch dashboard data
  const { data: dashboardData, isLoading: dashboardLoading, refetch: refetchDashboard } = useQuery<DashboardData>({
    queryKey: ['iot-monitoring-dashboard'],
    queryFn: async () => {
      const response = await api.get('/iot/monitoring/dashboard')
      return response.data
    },
    refetchInterval: autoRefresh ? 60000 : false, // Refresh every minute
  })

  // Fetch performance trends
  const { data: performanceTrends, isLoading: trendsLoading } = useQuery({
    queryKey: ['iot-performance-trends'],
    queryFn: async () => {
      const response = await api.get('/iot/monitoring/performance-trends?days=7')
      return response.data
    },
    refetchInterval: autoRefresh ? 300000 : false, // Refresh every 5 minutes
  })

  const handleRefresh = () => {
    refetchSystemHealth()
    refetchDashboard()
    toast({
      title: 'Refreshed',
      description: 'Monitoring data has been updated',
    })
  }

  const getSystemStatusColor = (status: string) => {
    switch (status) {
      case 'healthy':
        return 'text-green-600 bg-green-100'
      case 'degraded':
        return 'text-yellow-600 bg-yellow-100'
      case 'critical':
        return 'text-red-600 bg-red-100'
      default:
        return 'text-gray-600 bg-gray-100'
    }
  }

  const getUptimeColor = (uptime: number) => {
    if (uptime >= 95) return 'text-green-600'
    if (uptime >= 85) return 'text-yellow-600'
    return 'text-red-600'
  }

  if (systemHealthLoading || dashboardLoading) {
    return (
      <div className="flex items-center justify-center h-96">
        <Icon icon="solar:restart-circle-bold" className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="p-3 bg-primary/10 rounded-xl">
            <Icon icon="solar:monitor-bold-duotone" className="h-8 w-8 text-primary" />
          </div>
          <div>
            <h1 className="text-3xl font-bold">IoT Monitoring</h1>
            <p className="text-muted-foreground">
              Real-time device health and performance monitoring
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => setAutoRefresh(!autoRefresh)}
            className={autoRefresh ? 'bg-green-50 border-green-200' : ''}
          >
            <Icon 
              icon={autoRefresh ? "solar:pause-bold" : "solar:play-bold"} 
              className="mr-2 h-4 w-4" 
            />
            {autoRefresh ? 'Auto Refresh On' : 'Auto Refresh Off'}
          </Button>
          <Button onClick={handleRefresh}>
            <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
            Refresh
          </Button>
        </div>
      </div>

      {/* System Status Overview */}
      {systemHealth && (
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle>System Health</CardTitle>
                <CardDescription>Overall IoT infrastructure status</CardDescription>
              </div>
              <Badge className={getSystemStatusColor(systemHealth.system_status)}>
                {systemHealth.system_status.toUpperCase()}
              </Badge>
            </div>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 md:grid-cols-4">
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Total Devices</p>
                <p className="text-2xl font-bold">{systemHealth.total_devices}</p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Online</p>
                <p className="text-2xl font-bold text-green-600">{systemHealth.online_devices}</p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Offline</p>
                <p className="text-2xl font-bold text-red-600">{systemHealth.offline_devices}</p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Uptime</p>
                <p className={`text-2xl font-bold ${getUptimeColor(systemHealth.overall_uptime)}`}>
                  {systemHealth.overall_uptime}%
                </p>
              </div>
            </div>

            {/* Performance Metrics */}
            {systemHealth.performance && (
              <div className="mt-6 pt-6 border-t">
                <h4 className="font-semibold mb-4">Performance Metrics (Last 7 Days)</h4>
                <div className="grid gap-4 md:grid-cols-3">
                  <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">Average Uptime</p>
                    <p className="text-lg font-semibold">
                      {systemHealth.performance.avg_uptime ? `${systemHealth.performance.avg_uptime}%` : 'N/A'}
                    </p>
                  </div>
                  <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">Average Response Time</p>
                    <p className="text-lg font-semibold">
                      {systemHealth.performance.avg_response_time ? `${systemHealth.performance.avg_response_time}ms` : 'N/A'}
                    </p>
                  </div>
                  <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">Operation Success Rate</p>
                    <p className="text-lg font-semibold">
                      {systemHealth.performance.operation_success_rate ? `${systemHealth.performance.operation_success_rate}%` : 'N/A'}
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* Alerts Summary */}
            {(systemHealth.alerts.critical > 0 || systemHealth.alerts.warning > 0) && (
              <div className="mt-6 pt-6 border-t">
                <h4 className="font-semibold mb-4">Active Alerts (Last Hour)</h4>
                <div className="flex gap-4">
                  {systemHealth.alerts.critical > 0 && (
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:danger-bold" className="h-5 w-5 text-red-500" />
                      <span className="text-red-600 font-medium">{systemHealth.alerts.critical} Critical</span>
                    </div>
                  )}
                  {systemHealth.alerts.warning > 0 && (
                    <div className="flex items-center gap-2">
                      <Icon icon="solar:warning-bold" className="h-5 w-5 text-yellow-500" />
                      <span className="text-yellow-600 font-medium">{systemHealth.alerts.warning} Warning</span>
                    </div>
                  )}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Detailed Monitoring Tabs */}
      <Tabs defaultValue="overview" className="space-y-4">
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="devices">Device Health</TabsTrigger>
          <TabsTrigger value="performance">Performance</TabsTrigger>
          <TabsTrigger value="alerts">Alerts</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-4">
          {dashboardData && (
            <div className="grid gap-4 md:grid-cols-2">
              <Card>
                <CardHeader>
                  <CardTitle>Device Status Distribution</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full bg-green-500"></div>
                        <span>Online</span>
                      </div>
                      <span className="font-semibold">{dashboardData.overview.online_devices}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full bg-gray-500"></div>
                        <span>Offline</span>
                      </div>
                      <span className="font-semibold">{dashboardData.overview.offline_devices}</span>
                    </div>
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <div className="h-3 w-3 rounded-full bg-red-500"></div>
                        <span>Error</span>
                      </div>
                      <span className="font-semibold">{dashboardData.overview.error_devices}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Recent Activity</CardTitle>
                </CardHeader>
                <CardContent>
                  {dashboardData.recent_alerts.length > 0 ? (
                    <div className="space-y-3">
                      {dashboardData.recent_alerts.slice(0, 5).map((alert) => (
                        <div key={alert.id} className="flex items-center gap-3 p-2 bg-muted/50 rounded">
                          <Icon 
                            icon="solar:warning-bold" 
                            className="h-4 w-4 text-yellow-500" 
                          />
                          <div className="flex-1">
                            <p className="text-sm font-medium">{alert.device_name}</p>
                            <p className="text-xs text-muted-foreground">
                              {new Date(alert.checked_at).toLocaleString()}
                            </p>
                          </div>
                          <Badge variant="outline" className="capitalize">
                            {alert.status}
                          </Badge>
                        </div>
                      ))}
                    </div>
                  ) : (
                    <p className="text-muted-foreground text-center py-4">No recent alerts</p>
                  )}
                </CardContent>
              </Card>
            </div>
          )}
        </TabsContent>

        <TabsContent value="devices">
          <DeviceHealthWidget />
        </TabsContent>

        <TabsContent value="performance">
          <HealthMetricsChart data={performanceTrends} loading={trendsLoading} />
        </TabsContent>

        <TabsContent value="alerts">
          <RecentAlertsPanel />
        </TabsContent>
      </Tabs>
    </div>
  )
}