import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { readinessApi, type ReadinessSummary } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'

function ScoreGauge({ score }: { score: number }) {
  const color = score >= 80 ? '#22c55e' : score >= 60 ? '#eab308' : '#ef4444'
  const dash  = (score / 100) * 251.2   // circumference of r=40 circle ≈ 251.2
  return (
    <div className="flex flex-col items-center gap-2">
      <svg width="120" height="120" viewBox="0 0 120 120" className="-rotate-90">
        <circle cx="60" cy="60" r="40" fill="none" stroke="currentColor" strokeWidth="12" className="text-muted-foreground/20" />
        <circle
          cx="60" cy="60" r="40"
          fill="none"
          stroke={color}
          strokeWidth="12"
          strokeDasharray={`${dash} 251.2`}
          strokeLinecap="round"
          style={{ transition: 'stroke-dasharray 0.8s ease' }}
        />
      </svg>
      <div className="text-center -mt-14 rotate-0">
        <p className="text-4xl font-bold" style={{ color }}>{score}%</p>
      </div>
      <p className="text-sm text-muted-foreground mt-8">Readiness Score</p>
    </div>
  )
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

export function ReadinessCockpitPage() {
  const [params] = useSearchParams()
  const entityId = params.get('entityId') ?? 'entity-001'
  const periodId = params.get('periodId') ?? '2026-04'
  const cycleId  = params.get('cycleId') ?? undefined

  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: readiness, isLoading } = useQuery<ReadinessSummary>({
    queryKey: ['readiness', entityId, periodId],
    queryFn: () => readinessApi.get(entityId, periodId).then(r => r.data.data),
  })

  const refreshMutation = useMutation({
    mutationFn: () => readinessApi.refresh(entityId, periodId, cycleId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['readiness', entityId, periodId] })
      toast({ title: 'Readiness snapshot refreshed.' })
    },
    onError: () => toast({ title: 'Refresh failed.', variant: 'destructive' }),
  })

  return (
    <div className="space-y-8">
      <div className="flex items-start justify-between flex-wrap gap-4">
        <div>
          <h1 className="text-3xl font-bold">Readiness Cockpit</h1>
          <p className="text-muted-foreground mt-1">
            {entityId} — {periodId}
          </p>
        </div>
        <Button
          variant="outline"
          onClick={() => refreshMutation.mutate()}
          disabled={refreshMutation.isPending}
        >
          <Icon icon={refreshMutation.isPending ? 'solar:spinner-bold' : 'solar:refresh-bold-duotone'}
            className={`mr-2 h-4 w-4 ${refreshMutation.isPending ? 'animate-spin' : ''}`}
          />
          Refresh Snapshot
        </Button>
      </div>

      {/* AI Advisory Banner */}
      <div className="flex items-start gap-3 rounded-lg border border-yellow-300 bg-yellow-50 dark:bg-yellow-950/20 dark:border-yellow-700 px-4 py-3">
        <Icon icon="solar:info-circle-bold-duotone" className="h-5 w-5 text-yellow-600 flex-shrink-0 mt-0.5" />
        <p className="text-sm text-yellow-800 dark:text-yellow-300">
          <strong>Advisory only.</strong> Readiness scores are AI-assisted estimates based on current task and account data. Final close authorization must be performed by an authorized user.
        </p>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center gap-2 py-16 text-muted-foreground">
          <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
          Loading readiness data…
        </div>
      ) : !readiness ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:shield-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
            <p className="font-medium">No readiness data available.</p>
            <p className="text-sm mt-1">Click "Refresh Snapshot" to generate a readiness snapshot.</p>
          </CardContent>
        </Card>
      ) : (
        <>
          {/* Score + Status */}
          <div className="flex items-center gap-8 flex-wrap">
            <ScoreGauge score={readiness.readiness_score} />
            <div className="space-y-3">
              <div>
                {readiness.is_ready ? (
                  <Badge className="text-sm px-4 py-1.5 bg-green-500 hover:bg-green-600 text-white">
                    <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                    Ready to Close
                  </Badge>
                ) : (
                  <Badge variant="destructive" className="text-sm px-4 py-1.5">
                    <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                    Not Ready
                  </Badge>
                )}
              </div>
              {readiness.generated_at && (
                <p className="text-xs text-muted-foreground">
                  Snapshot generated {new Date(readiness.generated_at).toLocaleString()}
                </p>
              )}
              {readiness.open_blockers > 0 && (
                <p className="text-sm text-red-500 flex items-center gap-1.5">
                  <Icon icon="solar:danger-bold" className="h-4 w-4" />
                  {readiness.open_blockers} open blocker{readiness.open_blockers !== 1 ? 's' : ''} must be resolved
                </p>
              )}
              {readiness.readiness_score < 100 && (
                <p className="text-sm text-muted-foreground">
                  Score must reach 100% with 0 open blockers to close.
                </p>
              )}
            </div>
          </div>

          {/* Stats grid */}
          <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <StatCard
              label="Total Accounts"
              value={readiness.total_accounts}
              icon="solar:book-bold-duotone"
              iconClass="bg-blue-500/10 text-blue-500"
            />
            <StatCard
              label="Reviewed"
              value={readiness.reviewed_accounts}
              icon="solar:eye-bold-duotone"
              iconClass="bg-violet-500/10 text-violet-500"
            />
            <StatCard
              label="Signed Off"
              value={readiness.signed_off_accounts}
              icon="solar:clipboard-check-bold-duotone"
              iconClass="bg-green-500/10 text-green-500"
            />
            <StatCard
              label="Open Blockers"
              value={readiness.open_blockers}
              icon="solar:lock-keyhole-bold-duotone"
              iconClass={readiness.open_blockers > 0 ? 'bg-red-500/10 text-red-500' : 'bg-green-500/10 text-green-500'}
            />
            <StatCard
              label="Open AI Findings"
              value={readiness.open_findings}
              icon="solar:magic-stick-3-bold-duotone"
              iconClass={readiness.open_findings > 0 ? 'bg-yellow-500/10 text-yellow-600' : 'bg-green-500/10 text-green-500'}
            />
            <StatCard
              label="Review Coverage"
              value={readiness.total_accounts > 0
                ? `${Math.round((readiness.reviewed_accounts / readiness.total_accounts) * 100)}%`
                : '—'}
              icon="solar:pie-chart-2-bold-duotone"
              iconClass="bg-primary/10 text-primary"
            />
          </div>
        </>
      )}
    </div>
  )
}
