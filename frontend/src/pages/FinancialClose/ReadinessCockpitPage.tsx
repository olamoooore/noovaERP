import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { readinessApi, type ReadinessAccount, type ReadinessSummary } from '@/services/closeApi'
import { useToast } from '@/hooks/use-toast'

function ScoreGauge({ score }: { score: number }) {
  const color = score >= 80 ? '#22c55e' : score >= 60 ? '#eab308' : '#ef4444'
  const dash  = (score / 100) * 251.2   // circumference of r=40 circle ≈ 251.2
  return (
    <div className="flex flex-col items-center gap-2">
      <div className="relative w-[120px] h-[120px]">
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
        <div className="absolute inset-0 flex items-center justify-center">
          <p className="text-3xl font-bold leading-none" style={{ color }}>{score}%</p>
        </div>
      </div>
      <p className="text-sm text-muted-foreground">Readiness Score</p>
    </div>
  )
}

const accountStatusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  not_started: { label: 'Not Started', variant: 'secondary' },
  in_progress: { label: 'In Progress', variant: 'default' },
  submitted: { label: 'Submitted', variant: 'outline' },
  approved: { label: 'Approved', variant: 'outline' },
  locked: { label: 'Signed Off', variant: 'outline' },
  returned: { label: 'Returned', variant: 'destructive' },
}

const accountRiskConfig: Record<string, { label: string; className: string }> = {
  normal: { label: 'Normal', className: 'text-slate-500' },
  watch: { label: 'Watch', className: 'text-amber-600' },
  high: { label: 'High Risk', className: 'text-red-500' },
  critical: { label: 'Critical', className: 'text-red-600 font-semibold' },
}

function StatCard({
  label,
  value,
  icon,
  iconClass,
  hint,
  onClick,
}: {
  label: string
  value: string | number
  icon: string
  iconClass?: string
  hint?: string
  onClick?: () => void
}) {
  return (
    <Card
      className={onClick ? 'cursor-pointer border-slate-200/80 transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800' : 'border-slate-200/80 dark:border-slate-800'}
      onClick={onClick}
    >
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-2xl font-bold mt-0.5">{value}</p>
            {hint && <p className="text-xs text-muted-foreground mt-2">{hint}</p>}
          </div>
          <div className={`p-2 rounded-lg ${iconClass ?? 'bg-primary/10 text-primary'}`}>
            <Icon icon={icon} className="h-5 w-5" />
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function getAccountAction(account: ReadinessAccount) {
  if (account.open_findings > 0) {
    return { label: 'Open Findings', href: `/financial-close/findings?reviewId=${account.review_id}` }
  }

  if (account.status === 'submitted') {
    return { label: 'Open Queue', href: '/financial-close/review-queue' }
  }

  return { label: 'Open Tasks', href: '/financial-close/tasks' }
}

export function ReadinessCockpitPage() {
  const navigate = useNavigate()
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

  function focusAccounts() {
    document.getElementById('readiness-accounts')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

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
          <Card className="overflow-hidden border-slate-200/80 bg-gradient-to-br from-white via-slate-50 to-rose-50/70 dark:border-slate-800 dark:from-slate-950 dark:via-slate-950 dark:to-slate-900">
            <CardContent className="p-0">
              <div className="grid lg:grid-cols-[220px_1fr]">
                <div className="border-b border-slate-200/80 p-6 dark:border-slate-800 lg:border-b-0 lg:border-r">
                  <ScoreGauge score={readiness.readiness_score} />
                </div>
                <div className="p-6 space-y-4">
                  <div className="flex flex-wrap items-center gap-3">
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
                    <Badge variant="outline" className="px-3 py-1 text-xs">Entity {entityId}</Badge>
                    <Badge variant="outline" className="px-3 py-1 text-xs">Period {periodId}</Badge>
                  </div>

                  {readiness.generated_at && (
                    <p className="text-sm text-muted-foreground">
                      Snapshot generated {new Date(readiness.generated_at).toLocaleString()}
                    </p>
                  )}

                  {readiness.open_blockers > 0 && (
                    <p className="text-base text-red-500 flex items-center gap-2 font-medium">
                      <Icon icon="solar:danger-bold" className="h-4 w-4" />
                      {readiness.open_blockers} open blocker{readiness.open_blockers !== 1 ? 's' : ''} must be resolved
                    </p>
                  )}

                  <p className="text-sm text-muted-foreground max-w-2xl">
                    Score must reach 100% with 0 open blockers to close. Use the account coverage list below to jump straight into AI findings or the review queue during the demo.
                  </p>

                  <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={focusAccounts}>
                      <Icon icon="solar:book-bookmark-bold-duotone" className="mr-2 h-4 w-4" />
                      View Account Coverage
                    </Button>
                    <Button variant="outline" onClick={() => navigate('/financial-close/findings')}>
                      <Icon icon="solar:magic-stick-3-bold-duotone" className="mr-2 h-4 w-4" />
                      Open AI Findings
                    </Button>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Stats grid */}
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <StatCard
              label="Total Accounts"
              value={readiness.total_accounts}
              icon="solar:book-bold-duotone"
              iconClass="bg-blue-500/10 text-blue-500"
              hint="Jump to the account coverage section"
              onClick={focusAccounts}
            />
            <StatCard
              label="Reviewed"
              value={readiness.reviewed_accounts}
              icon="solar:eye-bold-duotone"
              iconClass="bg-violet-500/10 text-violet-500"
              hint="See which accounts are already in motion"
              onClick={focusAccounts}
            />
            <StatCard
              label="Signed Off"
              value={readiness.signed_off_accounts}
              icon="solar:clipboard-check-bold-duotone"
              iconClass="bg-green-500/10 text-green-500"
              hint="Open coverage and highlight approved work"
              onClick={focusAccounts}
            />
            <StatCard
              label="Open Blockers"
              value={readiness.open_blockers}
              icon="solar:lock-keyhole-bold-duotone"
              iconClass={readiness.open_blockers > 0 ? 'bg-red-500/10 text-red-500' : 'bg-green-500/10 text-green-500'}
              hint="Open the task queue"
              onClick={() => navigate('/financial-close/tasks')}
            />
            <StatCard
              label="Open AI Findings"
              value={readiness.open_findings}
              icon="solar:magic-stick-3-bold-duotone"
              iconClass={readiness.open_findings > 0 ? 'bg-yellow-500/10 text-yellow-600' : 'bg-green-500/10 text-green-500'}
              hint="Open the AI findings workspace"
              onClick={() => navigate('/financial-close/findings')}
            />
            <StatCard
              label="Review Coverage"
              value={readiness.total_accounts > 0
                ? `${Math.round((readiness.reviewed_accounts / readiness.total_accounts) * 100)}%`
                : '—'}
              icon="solar:pie-chart-2-bold-duotone"
              iconClass="bg-primary/10 text-primary"
              hint="Show account-by-account progress"
              onClick={focusAccounts}
            />
          </div>

          <div id="readiness-accounts" className="space-y-4">
            <div className="flex items-center justify-between gap-3 flex-wrap">
              <div>
                <h2 className="text-xl font-semibold">Account Coverage</h2>
                <p className="text-sm text-muted-foreground mt-1">
                  Demo-friendly account rows with direct links into findings or the review queue.
                </p>
              </div>
              <Button variant="outline" size="sm" onClick={() => navigate('/financial-close/review-queue')}>
                Open Review Queue
              </Button>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
              {readiness.accounts.map(account => {
                const status = accountStatusConfig[account.status] ?? accountStatusConfig.not_started
                const risk = accountRiskConfig[account.risk_status] ?? accountRiskConfig.normal
                const action = getAccountAction(account)

                return (
                  <Card key={account.review_id} className="border-slate-200/80 transition-all hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800">
                    <CardContent className="pt-5 space-y-4">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                              {account.account_code}
                            </span>
                            <Badge variant={status.variant}>{status.label}</Badge>
                          </div>
                          <h3 className="text-base font-semibold mt-2">{account.account_name}</h3>
                          <p className={`text-sm mt-1 ${risk.className}`}>{risk.label}</p>
                        </div>
                        <div className="text-right">
                          <p className="text-xs uppercase tracking-wide text-muted-foreground">Coverage</p>
                          <p className="text-2xl font-bold">{account.score}%</p>
                        </div>
                      </div>

                      <div className="space-y-2">
                        <div className="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                          <div
                            className={`h-full rounded-full ${account.score >= 80 ? 'bg-green-500' : account.score >= 50 ? 'bg-amber-500' : 'bg-rose-500'}`}
                            style={{ width: `${account.score}%` }}
                          />
                        </div>
                        <div className="flex items-center justify-between text-sm">
                          <span className="text-muted-foreground">Open AI findings</span>
                          <span className="font-medium">{account.open_findings}</span>
                        </div>
                      </div>

                      <div className="flex flex-wrap gap-2">
                        <Button size="sm" onClick={() => navigate(action.href)}>
                          {action.label}
                        </Button>
                        <Button size="sm" variant="outline" onClick={focusAccounts}>
                          Stay in Coverage
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                )
              })}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
