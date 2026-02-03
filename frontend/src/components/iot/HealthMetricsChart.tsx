import { Icon } from '@iconify/react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

interface PerformanceTrend {
  date: string
  avg_uptime: number
  avg_response_time: number
  operation_success_rate: number
  active_devices: number
}

interface HealthMetricsChartProps {
  data?: PerformanceTrend[]
  loading?: boolean
}

export function HealthMetricsChart({ data, loading }: HealthMetricsChartProps) {
  if (loading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Performance Trends</CardTitle>
          <CardDescription>7-day performance metrics</CardDescription>
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
        <CardTitle>Performance Trends</CardTitle>
        <CardDescription>7-day performance metrics and trends</CardDescription>
      </CardHeader>
      <CardContent>
        {data && data.length > 0 ? (
          <div className="space-y-6">
            {/* Summary Stats */}
            <div className="grid gap-4 md:grid-cols-4">
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Average Uptime</p>
                <p className="text-2xl font-bold text-green-600">
                  {data.length > 0 ? Math.round(data.reduce((sum, d) => sum + d.avg_uptime, 0) / data.length) : 0}%
                </p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Avg Response Time</p>
                <p className="text-2xl font-bold">
                  {data.length > 0 ? Math.round(data.reduce((sum, d) => sum + d.avg_response_time, 0) / data.length) : 0}ms
                </p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Success Rate</p>
                <p className="text-2xl font-bold text-blue-600">
                  {data.length > 0 ? Math.round(data.reduce((sum, d) => sum + d.operation_success_rate, 0) / data.length) : 0}%
                </p>
              </div>
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">Active Devices</p>
                <p className="text-2xl font-bold">
                  {data.length > 0 ? Math.max(...data.map(d => d.active_devices)) : 0}
                </p>
              </div>
            </div>

            {/* Simple Data Table */}
            <div className="space-y-2">
              <h4 className="font-semibold">Daily Breakdown</h4>
              <div className="space-y-2">
                {data.slice(-7).map((trend, index) => (
                  <div key={trend.date} className="flex items-center justify-between p-3 bg-muted/30 rounded-lg">
                    <div className="space-y-1">
                      <p className="font-medium">{new Date(trend.date).toLocaleDateString()}</p>
                      <p className="text-sm text-muted-foreground">{trend.active_devices} devices active</p>
                    </div>
                    <div className="text-right space-y-1">
                      <p className="text-sm">
                        <span className="text-green-600">{trend.avg_uptime.toFixed(1)}%</span> uptime
                      </p>
                      <p className="text-sm">
                        <span className="text-blue-600">{trend.avg_response_time}ms</span> response
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center py-12">
            <Icon icon="solar:chart-bold-duotone" className="h-16 w-16 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No performance data</h3>
            <p className="text-muted-foreground text-center">
              Performance metrics will appear here once devices start reporting data
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  )
}