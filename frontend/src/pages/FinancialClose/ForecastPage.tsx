import { useQuery } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { forecastApi, type Forecast } from '@/services/closeApi'

const riskConfig = {
  low:    { label: 'Low Risk',    class: 'bg-green-500/10 text-green-600',  badge: 'outline' as const },
  medium: { label: 'Medium Risk', class: 'bg-yellow-500/10 text-yellow-600', badge: 'outline' as const },
  high:   { label: 'High Risk',   class: 'bg-red-500/10 text-red-600',      badge: 'destructive' as const },
}

function StatCard({ label, value, icon, iconClass }: { label: string; value: string | number; icon: string; iconClass?: string }) {
  return (
    <Card>
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between">
          <div>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-2xl font-bold mt-0.5">{value}</p>
          </div>
          <div className={`p-2 rounded-lg ${iconClass ?? 'bg-primary/10 text-primary'}`}>
            <Icon icon={icon} className="h-5 w-5" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function CompletionBar({ rate }: { rate: number }) {
  const color = rate >= 80 ? '#22c55e' : rate >= 50 ? '#eab308' : '#ef4444'
  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium">Completion Rate</p>
        <span className="text-2xl font-bold" style={{ color }}>{rate}%</span>
      </div>
      <div className="h-3 rounded-full bg-muted overflow-hidden">
        <div
          className="h-full rounded-full transition-all duration-700"
          style={{ width: `${rate}%`, backgroundColor: color }}
        />
      </div>
    </div>
  )
}

export function ForecastPage() {
  const [params] = useSearchParams()
  const cycleId = params.get('cycleId') ?? ''

  const { data: forecast, isLoading } = useQuery<Forecast>({
    queryKey: ['forecast', cycleId],
    queryFn: () => forecastApi.byCycle(cycleId).then(r => r.data.data),
    enabled: !!cycleId,
  })

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Cycle Forecast</h1>
        <p className="text-muted-foreground mt-1">
          {cycleId ? `Projected close trajectory for cycle ${cycleId}` : 'Select a cycle to view forecast'}
        </p>
      </div>

      {/* AI Advisory Banner */}
      <div className="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-50 dark:bg-yellow-950/20 dark:border-yellow-700 px-4 py-3">
        <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-yellow-600 flex-shrink-0 mt-0.5" />
        <p className="text-sm text-yellow-800 dark:text-yellow-300">
          <strong>Advisory only.</strong> Forecast projections are AI-generated estimates based on current task progress and historical patterns. They are not guaranteed outcomes.
        </p>
      </div>

      {!cycleId ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:chart-2-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">No cycle selected.</p>
            <p className="text-sm mt-1">Navigate here from a cycle with ?cycleId= in the URL.</p>
          </CardContent>
        </Card>
      ) : isLoading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-muted-foreground">
          <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
          Generating forecast…
        </div>
      ) : !forecast?.forecast_available ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:chart-2-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">Forecast not yet available.</p>
            <p className="text-sm mt-1">A forecast will be generated once tasks have been started.</p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-6">
          {/* Risk + Completion */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-base">Close Risk</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                {forecast.risk_level && (
                  <div className={`flex items-center gap-3 rounded-xl p-4 ${riskConfig[forecast.risk_level].class}`}>
                    <Icon
                      icon={
                        forecast.risk_level === 'low'    ? 'solar:check-circle-bold-duotone' :
                        forecast.risk_level === 'medium' ? 'solar:danger-bold-duotone' :
                        'solar:fire-bold-duotone'
                      }
                      className="h-7 w-7 flex-shrink-0"
                    />
                    <div>
                      <p className="text-lg font-bold">{riskConfig[forecast.risk_level].label}</p>
                      <p className="text-xs mt-0.5">
                        {forecast.risk_level === 'low'    ? 'On track for timely close' :
                         forecast.risk_level === 'medium' ? 'Some tasks are at risk — review overdue items' :
                         'Significant blockers threaten close timeline'}
                      </p>
                    </div>
                  </div>
                )}
                {forecast.generated_at && (
                  <p className="text-xs text-muted-foreground">
                    Generated {new Date(forecast.generated_at).toLocaleString()}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-base">Task Progress</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <CompletionBar rate={forecast.completion_rate ?? 0} />
                <p className="text-sm text-muted-foreground">
                  {forecast.completed_tasks ?? 0} of {forecast.total_tasks ?? 0} tasks completed
                </p>
              </CardContent>
            </Card>
          </div>

          {/* Stats */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <StatCard
              label="Total Tasks"
              value={forecast.total_tasks ?? 0}
              icon="solar:list-check-bold-duotone"
              iconClass="bg-blue-500/10 text-blue-500"
            />
            <StatCard
              label="Completed"
              value={forecast.completed_tasks ?? 0}
              icon="solar:check-circle-bold-duotone"
              iconClass="bg-green-500/10 text-green-500"
            />
            <StatCard
              label="Overdue"
              value={forecast.overdue_tasks ?? 0}
              icon="solar:alarm-bold-duotone"
              iconClass={(forecast.overdue_tasks ?? 0) > 0 ? 'bg-red-500/10 text-red-500' : 'bg-muted text-muted-foreground'}
            />
            <StatCard
              label="Blocked"
              value={forecast.blocked_tasks ?? 0}
              icon="solar:lock-keyhole-bold-duotone"
              iconClass={(forecast.blocked_tasks ?? 0) > 0 ? 'bg-orange-500/10 text-orange-500' : 'bg-muted text-muted-foreground'}
            />
          </div>
        </div>
      )}
    </div>
  )
}
