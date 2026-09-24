import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { auditTrailApi, type AuditTrailEntry } from '@/services/closeApi'

function ActionBadge({ action }: { action: string }) {
  const colors: Record<string, string> = {
    task_started: 'bg-blue-100 text-blue-800',
    task_submitted: 'bg-yellow-100 text-yellow-800',
    task_completed: 'bg-green-100 text-green-800',
    task_returned: 'bg-purple-100 text-purple-800',
    task_blocked: 'bg-red-100 text-red-800',
    task_escalated: 'bg-orange-100 text-orange-800',
    escalation_resolved: 'bg-teal-100 text-teal-800',
    signoff_recorded: 'bg-indigo-100 text-indigo-800',
    signoff_revoked: 'bg-pink-100 text-pink-800',
    task_completion_denied_dependency: 'bg-red-100 text-red-800',
  }
  const color = colors[action] ?? 'bg-gray-100 text-gray-800'
  return <Badge className={`${color} border-0`}>{action.replace(/_/g, ' ')}</Badge>
}

export default function AuditTrailPage() {
  const [page, setPage] = useState(1)
  const [actionFilter, setActionFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const perPage = 50

  const { data, isLoading, refetch } = useQuery({
    queryKey: ['audit-trail', page, actionFilter, dateFrom, dateTo],
    queryFn: () => auditTrailApi.list({
      page,
      per_page: perPage,
      action: actionFilter || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
    }).then(r => ({ entries: r.data.data, meta: r.data.meta })),
  })

  const { data: actionsData } = useQuery({
    queryKey: ['audit-trail-actions'],
    queryFn: () => auditTrailApi.actions().then(r => r.data.data),
  })

  const { data: statsData } = useQuery({
    queryKey: ['audit-trail-stats'],
    queryFn: () => auditTrailApi.stats().then(r => r.data.data),
  })

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Audit Trail</h1>
          <p className="text-muted-foreground mt-1">Track all actions and changes across financial close</p>
        </div>
        <HeaderActionButton icon="solar:refresh-bold" variant="outline" onClick={() => refetch()}>
          Refresh
        </HeaderActionButton>
      </div>

      {statsData && (
        <div className="grid grid-cols-2 gap-4">
          <Card>
            <CardContent className="pt-6">
              <p className="text-2xl font-bold">{statsData.today_count}</p>
              <p className="text-sm text-muted-foreground">Events Today</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <p className="text-2xl font-bold">{statsData.total_count}</p>
              <p className="text-sm text-muted-foreground">Total Events</p>
            </CardContent>
          </Card>
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap gap-3 items-end">
            <div>
              <label className="text-xs font-medium text-muted-foreground block mb-1">Action</label>
              <select
                value={actionFilter}
                onChange={e => { setActionFilter(e.target.value); setPage(1) }}
                className="px-3 py-2 border border-input rounded-md bg-background text-sm"
              >
                <option value="">All Actions</option>
                {(actionsData ?? []).map(a => (
                  <option key={a} value={a}>{a.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-muted-foreground block mb-1">From</label>
              <Input type="date" value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1) }} className="w-40" />
            </div>
            <div>
              <label className="text-xs font-medium text-muted-foreground block mb-1">To</label>
              <Input type="date" value={dateTo} onChange={e => { setDateTo(e.target.value); setPage(1) }} className="w-40" />
            </div>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          {isLoading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-muted-foreground">
              <Icon icon="solar:spinner-bold" className="h-5 w-5 animate-spin" />
              Loading audit trail...
            </div>
          ) : !data?.entries.length ? (
            <div className="py-10 text-center text-muted-foreground">
              <Icon icon="solar:shield-check-bold-duotone" className="h-10 w-10 mx-auto mb-3 opacity-40" />
              <p className="font-medium">No audit entries found</p>
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b text-left text-xs font-medium text-muted-foreground uppercase">
                      <th className="pb-3 pr-4">Date/Time</th>
                      <th className="pb-3 pr-4">Action</th>
                      <th className="pb-3 pr-4">Entity</th>
                      <th className="pb-3 pr-4">Actor</th>
                      <th className="pb-3 pr-4">Details</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {data.entries.map((entry: AuditTrailEntry) => (
                      <tr key={entry.id} className="hover:bg-muted/50">
                        <td className="py-3 pr-4 text-sm whitespace-nowrap">
                          {new Date(entry.created_at).toLocaleString()}
                        </td>
                        <td className="py-3 pr-4">
                          <ActionBadge action={entry.action} />
                        </td>
                        <td className="py-3 pr-4 text-sm">
                          <span className="font-medium">{entry.entity_type}</span>
                          <span className="text-muted-foreground ml-1">#{entry.entity_id?.substring(0, 8)}</span>
                        </td>
                        <td className="py-3 pr-4 text-sm">
                          {entry.actor_name}
                          {entry.actor_email && <span className="text-muted-foreground ml-1">({entry.actor_email})</span>}
                        </td>
                        <td className="py-3 pr-4 text-sm text-muted-foreground max-w-xs truncate">
                          {entry.description}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {data.meta && (
                <div className="flex items-center justify-between pt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {data.meta.current_page} of {data.meta.last_page} ({data.meta.total} entries)
                  </p>
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
                      Previous
                    </Button>
                    <Button size="sm" variant="outline" disabled={page >= (data.meta.last_page || 1)} onClick={() => setPage(p => p + 1)}>
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
