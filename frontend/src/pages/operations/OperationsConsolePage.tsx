import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { StatCard } from '@/components/ui/stat-card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import api from '@/lib/api'
import {
  operationsConsoleApi,
  type ExceptionFilters,
  type ExceptionQueueRow,
} from '@/lib/operationsConsoleApi'
import { useToast } from '@/hooks/use-toast'
import { formatDateTime } from '@/lib/utils'

/**
 * Operations Console (#914) — one listing for every operational exception on
 * the platform queue (#902): failed postings, e-invoice rejections,
 * notification failures, import errors, tax variances and reconciliation
 * drift. Rows are grouped by exception type and legal entity, carry the
 * owner role and SLA age, and expose assignment plus one-click retry where
 * the backend has a registered retry handler.
 *
 * The backend is the authority for both visibility and actions: the list is
 * already scoped to the exception types the caller is permissioned for, and
 * each row carries `can_assign` / `can_retry` (owner_role → existing seeded
 * permissions).
 */

const SEVERITY_STYLES: Record<string, string> = {
  low: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
  medium: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
  high: 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
  critical: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
}

const STATUS_STYLES: Record<string, { label: string; className: string }> = {
  open: { label: 'Open', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
  in_progress: { label: 'In progress', className: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300' },
  retrying: { label: 'Retrying', className: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300' },
  resolved: { label: 'Resolved', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' },
}

const TYPE_LABELS: Record<string, string> = {
  'sales.payment.posting_failed': 'Failed posting',
  'sales.einvoice.auto_submit_failed': 'E-invoice rejection',
  'sales.delivery_order.approval_notification_failed': 'Approval notification failed',
  'sales.invoice.tax_variance': 'Tax variance',
  'sales.invoice.fx_residual': 'FX residual',
  'platform.audit.chain.broken': 'Audit chain broken',
}

const typeLabel = (type: string) =>
  TYPE_LABELS[type] ?? type.replace(/[._]/g, ' ').replace(/^\w/, (c) => c.toUpperCase())

const roleLabel = (role: string | null) =>
  role ? role.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()) : 'Platform'

interface ConsoleGroup {
  key: string
  type: string
  entityId: number | null
  rows: ExceptionQueueRow[]
}

interface TenantMember {
  id: number
  name: string
  email: string
}

export function OperationsConsolePage() {
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const [typeFilter, setTypeFilter] = useState('all')
  const [moduleFilter, setModuleFilter] = useState('all')
  const [entityFilter, setEntityFilter] = useState('all')
  const [statusFilter, setStatusFilter] = useState('all')
  const [ownerFilter, setOwnerFilter] = useState('all')

  const [assignTarget, setAssignTarget] = useState<ExceptionQueueRow | null>(null)
  const [assigneeId, setAssigneeId] = useState('')

  const filters: ExceptionFilters = useMemo(
    () => ({
      type: typeFilter === 'all' ? undefined : typeFilter,
      module: moduleFilter === 'all' ? undefined : moduleFilter,
      legal_entity_id: entityFilter === 'all' ? undefined : entityFilter,
      status: statusFilter === 'all' ? undefined : statusFilter,
      owner: ownerFilter === 'all' ? undefined : ownerFilter,
    }),
    [typeFilter, moduleFilter, entityFilter, statusFilter, ownerFilter],
  )

  const { data, isLoading, isError } = useQuery({
    queryKey: ['ops-exceptions', filters],
    queryFn: () => operationsConsoleApi.list(filters).then((r) => r.data),
  })

  const { data: stats } = useQuery({
    queryKey: ['ops-exception-stats', filters],
    queryFn: () => operationsConsoleApi.stats(filters).then((r) => r.data.data),
  })

  const { data: members = [] } = useQuery<TenantMember[]>({
    queryKey: ['ops-console-members'],
    queryFn: () => api.get('/tenant-users').then((r) => (r.data.data ?? []) as TenantMember[]),
    enabled: assignTarget !== null,
    staleTime: 5 * 60 * 1000,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ops-exceptions'] })
    queryClient.invalidateQueries({ queryKey: ['ops-exception-stats'] })
  }

  const retryMutation = useMutation({
    mutationFn: (id: string) => operationsConsoleApi.retry(id),
    onSuccess: (response) => {
      invalidate()
      const retry = response.data.retry
      toast({
        title: retry?.outcome === 'resolved' ? 'Retry succeeded' : 'Retry failed',
        description: retry?.error ?? undefined,
        variant: retry?.outcome === 'resolved' ? undefined : 'destructive',
      })
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast({
        title: 'Retry failed',
        description: error?.response?.data?.message,
        variant: 'destructive',
      })
    },
  })

  const assignMutation = useMutation({
    mutationFn: ({ id, userId }: { id: string; userId: number }) =>
      operationsConsoleApi.assign(id, userId),
    onSuccess: () => {
      invalidate()
      setAssignTarget(null)
      setAssigneeId('')
      toast({ title: 'Exception assigned' })
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast({
        title: 'Assignment failed',
        description: error?.response?.data?.message,
        variant: 'destructive',
      })
    },
  })

  const rows = useMemo(() => data?.data ?? [], [data])

  const groups = useMemo<ConsoleGroup[]>(() => {
    const map = new Map<string, ConsoleGroup>()

    for (const row of rows) {
      const key = `${row.exception_type}::${row.legal_entity_id ?? 'none'}`
      const existing = map.get(key)

      if (existing) {
        existing.rows.push(row)
      } else {
        map.set(key, {
          key,
          type: row.exception_type,
          entityId: row.legal_entity_id,
          rows: [row],
        })
      }
    }

    return [...map.values()].sort(
      (a, b) => a.type.localeCompare(b.type) || (a.entityId ?? 0) - (b.entityId ?? 0),
    )
  }, [rows])

  const typeOptions = Object.keys(stats?.by_type ?? {})
  const moduleOptions = Object.keys(stats?.by_module ?? {})
  const entityOptions = Object.keys(stats?.by_entity ?? {}).filter((key) => key !== 'unassigned')
  const ownerOptions = Object.keys(stats?.by_owner_role ?? {})

  const renderSeverity = (severity: string) => (
    <Badge
      variant="outline"
      className={`border-transparent font-normal capitalize ${SEVERITY_STYLES[severity] ?? 'bg-muted text-muted-foreground'}`}
    >
      {severity}
    </Badge>
  )

  const renderStatus = (status: string) => {
    const cfg = STATUS_STYLES[status] ?? { label: status, className: 'bg-muted text-muted-foreground' }
    return (
      <Badge variant="outline" className={`border-transparent font-normal ${cfg.className}`}>
        {cfg.label}
      </Badge>
    )
  }

  const renderSla = (row: ExceptionQueueRow) => {
    const age = `${row.age_hours}h / ${row.sla_hours}h`
    return (
      <div className="flex items-center gap-2">
        <span className="text-xs tabular-nums text-muted-foreground">{age}</span>
        {row.sla_breached ? (
          <Badge variant="outline" className="border-transparent bg-red-100 font-normal text-red-700 dark:bg-red-950/60 dark:text-red-300">
            SLA breached
          </Badge>
        ) : null}
      </div>
    )
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Operations Console</h1>
          <p className="mt-1 text-muted-foreground">
            Every operational exception on the platform queue — failed postings, e-invoice
            rejections, notification failures, tax variances and audit alerts. Assign an owner or
            re-run the failed operation where a retry handler exists.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Select value={typeFilter} onValueChange={setTypeFilter}>
            <SelectTrigger className="h-8 w-48 rounded-full text-xs" aria-label="Filter by exception type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All exception types</SelectItem>
              {typeOptions.map((type) => (
                <SelectItem key={type} value={type}>
                  {typeLabel(type)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={moduleFilter} onValueChange={setModuleFilter}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs" aria-label="Filter by module">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All modules</SelectItem>
              {moduleOptions.map((module) => (
                <SelectItem key={module} value={module}>
                  {module}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={entityFilter} onValueChange={setEntityFilter}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs" aria-label="Filter by entity">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All entities</SelectItem>
              {entityOptions.map((entity) => (
                <SelectItem key={entity} value={entity}>
                  Entity {entity}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs" aria-label="Filter by status">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="open">Open</SelectItem>
              <SelectItem value="in_progress">In progress</SelectItem>
              <SelectItem value="retrying">Retrying</SelectItem>
              <SelectItem value="resolved">Resolved</SelectItem>
            </SelectContent>
          </Select>

          <Select value={ownerFilter} onValueChange={setOwnerFilter}>
            <SelectTrigger className="h-8 w-32 rounded-full text-xs" aria-label="Filter by owner role">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All owners</SelectItem>
              {ownerOptions.map((owner) => (
                <SelectItem key={owner} value={owner}>
                  {roleLabel(owner)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Total" value={stats?.total ?? rows.length} icon="solar:document-text-bold-duotone" />
        <StatCard
          label="Open"
          value={stats?.by_status?.open ?? 0}
          icon="solar:hourglass-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
        />
        <StatCard
          label="SLA breached"
          value={stats?.sla_breached ?? 0}
          icon="solar:danger-triangle-bold-duotone"
          tone="bg-red-100 text-red-600 dark:bg-red-950/60 dark:text-red-400"
        />
        <StatCard
          label="Unassigned"
          value={rows.filter((row) => row.assigned_to === null).length}
          icon="solar:user-cross-bold-duotone"
          tone="bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400"
        />
      </div>

      {isLoading ? (
        <Card className="rounded-xl">
          <CardContent className="py-12 text-center text-muted-foreground">
            <Icon icon="eos-icons:loading" className="mx-auto h-7 w-7 animate-spin" />
            <p className="mt-2 text-sm">Loading exceptions…</p>
          </CardContent>
        </Card>
      ) : isError ? (
        <Card className="rounded-xl">
          <CardContent className="py-12 text-center text-muted-foreground">
            <Icon icon="solar:danger-triangle-bold-duotone" className="mx-auto h-8 w-8 text-red-500" />
            <p className="mt-2 text-sm">Could not load the exception queue. Try again.</p>
          </CardContent>
        </Card>
      ) : rows.length === 0 ? (
        <Card className="rounded-xl">
          <CardContent className="flex flex-col items-center justify-center gap-2 px-4 py-14 text-center">
            <Icon icon="solar:check-circle-bold-duotone" className="h-10 w-10 text-emerald-500/60" />
            <p className="text-sm font-medium">No operational exceptions</p>
            <p className="max-w-md text-xs text-muted-foreground">
              Nothing is outstanding for the current filters — or your role is not permissioned for
              any exception type that is currently queued.
            </p>
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-4">
          {groups.map((group) => (
            <Card key={group.key} className="overflow-hidden rounded-xl">
              <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 border-b pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                  {typeLabel(group.type)}
                  <Badge variant="secondary" className="font-normal">
                    {group.rows.length}
                  </Badge>
                </CardTitle>
                <span className="text-sm text-muted-foreground">
                  {group.entityId !== null ? `Entity ${group.entityId}` : 'No entity'}
                </span>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Exception</TableHead>
                      <TableHead className="w-[6.5rem]">Severity</TableHead>
                      <TableHead className="w-[7rem]">Owner</TableHead>
                      <TableHead className="w-[10rem]">Assigned</TableHead>
                      <TableHead className="w-[11rem]">SLA age</TableHead>
                      <TableHead className="w-[7rem]">Status</TableHead>
                      <TableHead className="w-[9rem]">Raised</TableHead>
                      <TableHead className="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {group.rows.map((row) => (
                      <TableRow key={row.id}>
                        <TableCell>
                          <div className="text-sm">{row.message}</div>
                          <div className="mt-0.5 text-xs text-muted-foreground">
                            {row.document_type ? `${row.document_type} ${row.document_id ?? ''}`.trim() : row.module}
                            {row.retry_count > 0 ? ` · ${row.retry_count}/${row.max_retries} retries` : ''}
                          </div>
                        </TableCell>
                        <TableCell>{renderSeverity(row.severity)}</TableCell>
                        <TableCell className="text-sm capitalize">{roleLabel(row.owner_role)}</TableCell>
                        <TableCell className="text-sm">
                          {row.assigned_to_name ?? (row.assigned_to !== null ? `#${row.assigned_to}` : '—')}
                        </TableCell>
                        <TableCell>{renderSla(row)}</TableCell>
                        <TableCell>{renderStatus(row.status)}</TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {row.created_at ? formatDateTime(row.created_at) : '—'}
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex items-center justify-end gap-1">
                            {row.can_retry ? (
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7 text-sky-600 hover:text-sky-700"
                                title="Retry the failed operation"
                                aria-label={`Retry ${row.message}`}
                                disabled={retryMutation.isPending}
                                onClick={() => retryMutation.mutate(row.id)}
                              >
                                <Icon icon="solar:refresh-bold-duotone" className="h-3.5 w-3.5" />
                              </Button>
                            ) : null}
                            {row.can_assign ? (
                              <Button
                                size="icon"
                                variant="ghost"
                                className="h-7 w-7 text-muted-foreground"
                                title="Assign an owner"
                                aria-label={`Assign ${row.message}`}
                                onClick={() => {
                                  setAssignTarget(row)
                                  setAssigneeId(row.assigned_to !== null ? String(row.assigned_to) : '')
                                }}
                              >
                                <Icon icon="solar:user-plus-bold-duotone" className="h-3.5 w-3.5" />
                              </Button>
                            ) : null}
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog
        open={assignTarget !== null}
        onOpenChange={(open) => {
          if (!open) {
            setAssignTarget(null)
            setAssigneeId('')
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Assign owner</DialogTitle>
            <DialogDescription>
              {assignTarget ? assignTarget.message : 'Choose the workspace member who will resolve this exception.'}
            </DialogDescription>
          </DialogHeader>

          <Select value={assigneeId} onValueChange={setAssigneeId}>
            <SelectTrigger aria-label="Select assignee">
              <SelectValue placeholder="Select a workspace member" />
            </SelectTrigger>
            <SelectContent>
              {members.map((member) => (
                <SelectItem key={member.id} value={String(member.id)}>
                  {member.name} · {member.email}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setAssignTarget(null)
                setAssigneeId('')
              }}
            >
              Cancel
            </Button>
            <Button
              disabled={assigneeId === '' || assignMutation.isPending}
              onClick={() => {
                if (assignTarget && assigneeId !== '') {
                  assignMutation.mutate({ id: assignTarget.id, userId: Number(assigneeId) })
                }
              }}
            >
              Assign
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export default OperationsConsolePage
