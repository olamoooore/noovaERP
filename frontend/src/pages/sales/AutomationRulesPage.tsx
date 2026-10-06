import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

/**
 * Issue #913 (SALES-DIFF) — no-code automation rules over the #901 sales
 * event catalogue. Settings page: rule list + builder (event picker,
 * condition rows, action rows), dry-run preview, version history and the
 * execution log. Backed by /api/v1/sales/automation/* (sales.settings).
 */

interface AutomationCondition {
  field: string
  operator: string
  value?: unknown
}

interface AutomationAction {
  type: string
  [key: string]: unknown
}

interface AutomationRule {
  id: number
  name: string
  event_name: string
  legal_entity_id: number
  conditions: AutomationCondition[]
  actions: AutomationAction[]
  is_active: boolean
  version: number
  updated_at?: string | null
}

interface CatalogEvent {
  event_name: string
  description: string
  payload_fields: Record<string, string>
  condition_fields: string[]
}

interface AutomationCatalog {
  events: CatalogEvent[]
  operators: string[]
  action_types: string[]
  virtual_fields: Record<string, string>
}

interface AutomationExecution {
  id: number
  rule_id: number
  rule?: AutomationRule | null
  event_name: string
  status: string
  actions: Array<{ type?: string; status?: string; error?: string }> | null
  error?: string | null
  created_at: string
}

interface RuleVersion {
  id: number
  version: number
  name: string
  event_name: string
  conditions: AutomationCondition[]
  actions: AutomationAction[]
  is_active: boolean
  created_at: string
}

interface FormState {
  name: string
  event_name: string
  is_active: boolean
  conditions: AutomationCondition[]
  actions: AutomationAction[]
}

const NUMERIC_FIELDS = new Set([
  'total',
  'balance',
  'paid_amount',
  'days_overdue',
  'customer_id',
  'legal_entity_id',
  'document_id',
  'sales_order_id',
  'invoice_id',
  'warehouse_id',
  'journal_entry_id',
])

const NUMERIC_OPERATORS = new Set(['gt', 'gte', 'lt', 'lte'])
const LIST_OPERATORS = new Set(['in', 'not_in'])
const VALUELESS_OPERATORS = new Set(['is_null', 'is_not_null'])

function defaultAction(type: string): AutomationAction {
  switch (type) {
    case 'notify':
      return { type, user_ids: [], roles: [], account_owner: false, subject: '', message: '' }
    case 'whatsapp_customer':
      return { type, message: '', respect_preferences: true }
    case 'create_task':
      return { type, summary: '', note: '', due_in_days: null, assignee_user_id: null }
    case 'hold_customer':
      return { type, reason: '' }
    case 'webhook':
      return { type, url: '' }
    default:
      return { type }
  }
}

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  success: 'default',
  matched: 'secondary',
  skipped: 'outline',
  partial: 'secondary',
  failed: 'destructive',
}

function statusBadge(status: string) {
  return <Badge variant={STATUS_VARIANTS[status] ?? 'outline'}>{status}</Badge>
}

function emptyForm(catalog: AutomationCatalog | undefined): FormState {
  const event = catalog?.events?.[0]

  return {
    name: '',
    event_name: event?.event_name ?? '',
    is_active: true,
    conditions: [],
    actions: [defaultAction('create_task')],
  }
}

export function AutomationRulesPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [builderOpen, setBuilderOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState<FormState>(() => emptyForm(undefined))
  const [dryRun, setDryRun] = useState<Record<string, unknown> | null>(null)
  const [dryRunRule, setDryRunRule] = useState<AutomationRule | null>(null)
  const [historyRule, setHistoryRule] = useState<AutomationRule | null>(null)

  const catalogQuery = useQuery<AutomationCatalog>({
    queryKey: ['automation-catalog'],
    queryFn: async () => (await api.get('/sales/automation/catalog')).data.data,
  })

  const rulesQuery = useQuery<AutomationRule[]>({
    queryKey: ['automation-rules'],
    queryFn: async () => (await api.get('/sales/automation/rules')).data.data,
  })

  const executionsQuery = useQuery<AutomationExecution[]>({
    queryKey: ['automation-executions'],
    queryFn: async () => (await api.get('/sales/automation/executions')).data.data,
  })

  const versionsQuery = useQuery<RuleVersion[]>({
    queryKey: ['automation-versions', historyRule?.id],
    enabled: historyRule !== null,
    queryFn: async () => (await api.get(`/sales/automation/rules/${historyRule?.id}/versions`)).data.data,
  })

  const catalog = catalogQuery.data
  const eventsByName = useMemo(() => {
    const map = new Map<string, CatalogEvent>()
    catalog?.events.forEach((event) => map.set(event.event_name, event))
    return map
  }, [catalog])

  const selectedEvent = eventsByName.get(form.event_name)
  const conditionFields = selectedEvent?.condition_fields ?? []

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['automation-rules'] })
    queryClient.invalidateQueries({ queryKey: ['automation-executions'] })
  }

  const saveMutation = useMutation({
    mutationFn: async (payload: FormState) => {
      const body = {
        name: payload.name,
        event_name: payload.event_name,
        is_active: payload.is_active,
        conditions: payload.conditions,
        actions: payload.actions,
      }

      if (editingId !== null) {
        return api.put(`/sales/automation/rules/${editingId}`, body)
      }

      return api.post('/sales/automation/rules', body)
    },
    onSuccess: () => {
      toast({ title: editingId !== null ? 'Rule updated' : 'Rule created' })
      setBuilderOpen(false)
      invalidate()
    },
    onError: (error: unknown) => {
      const message =
        (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
        'Could not save the rule.'
      toast({ title: 'Save failed', description: message, variant: 'destructive' })
    },
  })

  const toggleMutation = useMutation({
    mutationFn: async (rule: AutomationRule) =>
      api.put(`/sales/automation/rules/${rule.id}`, {
        name: rule.name,
        event_name: rule.event_name,
        conditions: rule.conditions,
        actions: rule.actions,
        is_active: !rule.is_active,
      }),
    onSuccess: invalidate,
  })

  const deleteMutation = useMutation({
    mutationFn: async (rule: AutomationRule) => api.delete(`/sales/automation/rules/${rule.id}`),
    onSuccess: () => {
      toast({ title: 'Rule deleted' })
      invalidate()
    },
  })

  const dryRunMutation = useMutation({
    mutationFn: async (rule: AutomationRule) =>
      (await api.post(`/sales/automation/rules/${rule.id}/dry-run`, {})).data.data,
    onSuccess: (data: Record<string, unknown>, rule: AutomationRule) => {
      setDryRun(data)
      setDryRunRule(rule)
    },
    onError: () => toast({ title: 'Dry-run failed', variant: 'destructive' }),
  })

  const openCreate = () => {
    setEditingId(null)
    setForm(emptyForm(catalog))
    setBuilderOpen(true)
  }

  const openEdit = (rule: AutomationRule) => {
    setEditingId(rule.id)
    setForm({
      name: rule.name,
      event_name: rule.event_name,
      is_active: rule.is_active,
      conditions: rule.conditions ?? [],
      actions: rule.actions ?? [],
    })
    setBuilderOpen(true)
  }

  const updateCondition = (index: number, patch: Partial<AutomationCondition>) => {
    setForm((prev) => ({
      ...prev,
      conditions: prev.conditions.map((condition, i) => (i === index ? { ...condition, ...patch } : condition)),
    }))
  }

  const updateAction = (index: number, patch: Record<string, unknown>) => {
    setForm((prev) => ({
      ...prev,
      actions: prev.actions.map((action, i) => (i === index ? { ...action, ...patch } : action)),
    }))
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Automation Rules</h1>
          <p className="mt-1 text-muted-foreground">
            No-code rules over sales events: when something happens, notify people, message the customer,
            create a task, hold the customer or call a webhook.
          </p>
        </div>
        <Button onClick={openCreate} disabled={!catalog}>
          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
          New rule
        </Button>
      </div>

      <Tabs defaultValue="rules" className="space-y-4">
        <TabsList>
          <TabsTrigger value="rules">Rules</TabsTrigger>
          <TabsTrigger value="executions">Execution log</TabsTrigger>
        </TabsList>

        <TabsContent value="rules" className="space-y-4">
          {rulesQuery.isLoading && <p className="text-sm text-muted-foreground">Loading rules…</p>}
          {rulesQuery.isError && <p className="text-sm text-destructive">Failed to load automation rules.</p>}
          {rulesQuery.data && rulesQuery.data.length === 0 && (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                No automation rules yet. Create one to react to sales events without code.
              </CardContent>
            </Card>
          )}

          <div className="grid gap-4 lg:grid-cols-2">
            {(rulesQuery.data ?? []).map((rule) => (
              <Card key={rule.id} data-testid={`automation-rule-${rule.id}`}>
                <CardHeader className="pb-3">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <CardTitle className="text-base">{rule.name}</CardTitle>
                      <CardDescription>
                        <Badge variant="secondary" className="mr-2">{rule.event_name}</Badge>
                        Entity #{rule.legal_entity_id} · v{rule.version}
                      </CardDescription>
                    </div>
                    <Switch
                      aria-label={`Toggle ${rule.name}`}
                      checked={rule.is_active}
                      onCheckedChange={() => toggleMutation.mutate(rule)}
                    />
                  </div>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <div>
                    <span className="font-medium">Conditions: </span>
                    {rule.conditions.length === 0
                      ? <span className="text-muted-foreground">always matches</span>
                      : rule.conditions.map((condition, index) => (
                          <span key={index} className="mr-2 inline-block rounded bg-muted px-2 py-0.5">
                            {condition.field} {condition.operator} {String(condition.value ?? '')}
                          </span>
                        ))}
                  </div>
                  <div>
                    <span className="font-medium">Actions: </span>
                    {rule.actions.map((action) => (
                      <Badge key={action.type} variant="outline" className="mr-1">{action.type}</Badge>
                    ))}
                  </div>
                  <div className="flex flex-wrap gap-2 pt-1">
                    <Button size="sm" variant="outline" onClick={() => dryRunMutation.mutate(rule)}>
                      <Icon icon="solar:play-circle-bold" className="mr-1 h-4 w-4" />
                      Dry run
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => openEdit(rule)}>Edit</Button>
                    <Button size="sm" variant="outline" onClick={() => setHistoryRule(rule)}>History</Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-destructive"
                      onClick={() => deleteMutation.mutate(rule)}
                    >
                      Delete
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </TabsContent>

        <TabsContent value="executions">
          <Card>
            <CardHeader>
              <CardTitle>Execution log</CardTitle>
              <CardDescription>Every rule evaluation, its status and each action result.</CardDescription>
            </CardHeader>
            <CardContent>
              {executionsQuery.isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>Rule</TableHead>
                    <TableHead>Event</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Actions / error</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(executionsQuery.data ?? []).map((execution) => (
                    <TableRow key={execution.id} data-testid={`automation-execution-${execution.id}`}>
                      <TableCell className="whitespace-nowrap text-xs">
                        {new Date(execution.created_at).toLocaleString()}
                      </TableCell>
                      <TableCell>{execution.rule?.name ?? `#${execution.rule_id}`}</TableCell>
                      <TableCell className="text-xs">{execution.event_name}</TableCell>
                      <TableCell>{statusBadge(execution.status)}</TableCell>
                      <TableCell className="max-w-[320px] text-xs">
                        {(execution.actions ?? []).map((action, index) => (
                          <div key={index}>
                            <span className="font-medium">{action.type}</span>: {action.status}
                            {action.error ? ` — ${action.error}` : ''}
                          </div>
                        ))}
                        {execution.error && <div className="text-destructive">{execution.error}</div>}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Builder */}
      <Dialog open={builderOpen} onOpenChange={setBuilderOpen}>
        <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingId !== null ? 'Edit automation rule' : 'New automation rule'}</DialogTitle>
            <DialogDescription>
              Pick a sales event, add conditions (all must match) and the actions to run.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="automation-name">Rule name</Label>
                <Input
                  id="automation-name"
                  value={form.name}
                  onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                  placeholder="Overdue collection reminder"
                />
              </div>
              <div className="space-y-2">
                <Label>Event</Label>
                <Select
                  value={form.event_name}
                  onValueChange={(value) => setForm((prev) => ({ ...prev, event_name: value, conditions: [] }))}
                >
                  <SelectTrigger aria-label="Event">
                    <SelectValue placeholder="Select an event" />
                  </SelectTrigger>
                  <SelectContent>
                    {(catalog?.events ?? []).map((event) => (
                      <SelectItem key={event.event_name} value={event.event_name}>
                        {event.event_name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex items-center gap-3">
              <Switch
                id="automation-active"
                checked={form.is_active}
                onCheckedChange={(checked) => setForm((prev) => ({ ...prev, is_active: checked }))}
              />
              <Label htmlFor="automation-active">Active</Label>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Conditions (all must match)</Label>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() =>
                    setForm((prev) => ({
                      ...prev,
                      conditions: [
                        ...prev.conditions,
                        { field: conditionFields[0] ?? 'total', operator: 'eq', value: '' },
                      ],
                    }))
                  }
                >
                  <Icon icon="solar:add-circle-bold" className="mr-1 h-4 w-4" />
                  Add condition
                </Button>
              </div>

              {form.conditions.map((condition, index) => (
                <div key={index} className="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                  <Select value={condition.field} onValueChange={(value) => updateCondition(index, { field: value })}>
                    <SelectTrigger aria-label={`Condition field ${index + 1}`}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {conditionFields.map((field) => (
                        <SelectItem key={field} value={field}>{field}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  <Select value={condition.operator} onValueChange={(value) => updateCondition(index, { operator: value })}>
                    <SelectTrigger aria-label={`Condition operator ${index + 1}`}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(catalog?.operators ?? []).map((operator) => (
                        <SelectItem key={operator} value={operator}>{operator}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  {VALUELESS_OPERATORS.has(condition.operator) ? (
                    <div className="flex items-center text-sm text-muted-foreground">No value needed</div>
                  ) : (
                    <Input
                      aria-label={`Condition value ${index + 1}`}
                      value={Array.isArray(condition.value) ? condition.value.join(',') : String(condition.value ?? '')}
                      placeholder={LIST_OPERATORS.has(condition.operator) ? 'a,b,c' : 'value'}
                      onChange={(event) => updateCondition(index, { value: event.target.value })}
                    />
                  )}

                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Remove condition ${index + 1}`}
                    onClick={() => setForm((prev) => ({
                      ...prev,
                      conditions: prev.conditions.filter((_, i) => i !== index),
                    }))}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                  </Button>
                </div>
              ))}
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Actions</Label>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => setForm((prev) => ({ ...prev, actions: [...prev.actions, defaultAction('create_task')] }))}
                >
                  <Icon icon="solar:add-circle-bold" className="mr-1 h-4 w-4" />
                  Add action
                </Button>
              </div>

              {form.actions.map((action, index) => (
                <Card key={index} className="border-dashed">
                  <CardContent className="space-y-3 pt-4">
                    <div className="flex items-center gap-2">
                      <Select
                        value={action.type}
                        onValueChange={(value) => updateAction(index, defaultAction(value))}
                      >
                        <SelectTrigger aria-label={`Action type ${index + 1}`}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {(catalog?.action_types ?? []).map((type) => (
                            <SelectItem key={type} value={type}>{type}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>

                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Remove action ${index + 1}`}
                        onClick={() => setForm((prev) => ({
                          ...prev,
                          actions: prev.actions.filter((_, i) => i !== index),
                        }))}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                      </Button>
                    </div>

                    {action.type === 'notify' && (
                      <div className="space-y-2">
                        <Input
                          aria-label={`Notify user ids ${index + 1}`}
                          placeholder="User IDs, comma separated"
                          value={(action.user_ids as number[] | undefined)?.join(',') ?? ''}
                          onChange={(event) => updateAction(index, {
                            user_ids: event.target.value.split(',').map((v) => v.trim()).filter(Boolean).map(Number),
                          })}
                        />
                        <Input
                          aria-label={`Notify roles ${index + 1}`}
                          placeholder="Roles, comma separated (e.g. manager, accountant)"
                          value={(action.roles as string[] | undefined)?.join(',') ?? ''}
                          onChange={(event) => updateAction(index, {
                            roles: event.target.value.split(',').map((v) => v.trim()).filter(Boolean),
                          })}
                        />
                        <div className="flex items-center gap-2">
                          <Switch
                            aria-label={`Notify account owner ${index + 1}`}
                            checked={Boolean(action.account_owner)}
                            onCheckedChange={(checked) => updateAction(index, { account_owner: checked })}
                          />
                          <span className="text-sm">Also notify the customer&apos;s account owner</span>
                        </div>
                        <Input
                          aria-label={`Notify subject ${index + 1}`}
                          placeholder="Subject (optional)"
                          value={(action.subject as string) ?? ''}
                          onChange={(event) => updateAction(index, { subject: event.target.value })}
                        />
                        <Textarea
                          aria-label={`Notify message ${index + 1}`}
                          placeholder="Message (optional; :customer_name, :document_number, :total, :balance)"
                          value={(action.message as string) ?? ''}
                          onChange={(event) => updateAction(index, { message: event.target.value })}
                        />
                      </div>
                    )}

                    {action.type === 'whatsapp_customer' && (
                      <div className="space-y-2">
                        <Textarea
                          aria-label={`WhatsApp message ${index + 1}`}
                          placeholder="Message (optional; :customer_name, :document_number, :total, :balance)"
                          value={(action.message as string) ?? ''}
                          onChange={(event) => updateAction(index, { message: event.target.value })}
                        />
                        <div className="flex items-center gap-2">
                          <Switch
                            aria-label={`Respect contact preference ${index + 1}`}
                            checked={action.respect_preferences !== false}
                            onCheckedChange={(checked) => updateAction(index, { respect_preferences: checked })}
                          />
                          <span className="text-sm">Respect the customer&apos;s preferred contact channel</span>
                        </div>
                      </div>
                    )}

                    {action.type === 'create_task' && (
                      <div className="space-y-2">
                        <Input
                          aria-label={`Task summary ${index + 1}`}
                          placeholder="Task summary"
                          value={(action.summary as string) ?? ''}
                          onChange={(event) => updateAction(index, { summary: event.target.value })}
                        />
                        <Input
                          aria-label={`Task due in days ${index + 1}`}
                          type="number"
                          placeholder="Due in days (optional)"
                          value={action.due_in_days === null || action.due_in_days === undefined ? '' : String(action.due_in_days)}
                          onChange={(event) => updateAction(index, {
                            due_in_days: event.target.value === '' ? null : Number(event.target.value),
                          })}
                        />
                        <Textarea
                          aria-label={`Task note ${index + 1}`}
                          placeholder="Note (optional)"
                          value={(action.note as string) ?? ''}
                          onChange={(event) => updateAction(index, { note: event.target.value })}
                        />
                      </div>
                    )}

                    {action.type === 'hold_customer' && (
                      <Input
                        aria-label={`Hold reason ${index + 1}`}
                        placeholder="Reason (optional)"
                        value={(action.reason as string) ?? ''}
                        onChange={(event) => updateAction(index, { reason: event.target.value })}
                      />
                    )}

                    {action.type === 'webhook' && (
                      <Input
                        aria-label={`Webhook url ${index + 1}`}
                        placeholder="https://…"
                        value={(action.url as string) ?? ''}
                        onChange={(event) => updateAction(index, { url: event.target.value })}
                      />
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setBuilderOpen(false)}>Cancel</Button>
            <Button
              onClick={() => saveMutation.mutate(coerceForm(form))}
              disabled={saveMutation.isPending || form.name.trim() === '' || form.event_name === ''}
            >
              {editingId !== null ? 'Save changes' : 'Create rule'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Dry-run result */}
      <Dialog open={dryRun !== null} onOpenChange={(open) => { if (!open) setDryRun(null) }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Dry run — {dryRunRule?.name}</DialogTitle>
            <DialogDescription>
              Evaluated against {String((dryRun?.event as Record<string, unknown>)?.document_number ?? 'the latest event payload')}.
              No action was executed.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 text-sm">
            <div className="flex items-center gap-2">
              <span className="font-medium">Result:</span>
              <Badge variant={dryRun?.matched ? 'default' : 'outline'}>
                {dryRun?.matched ? 'Matched' : 'Not matched'}
              </Badge>
              <Badge variant="secondary">no side effects</Badge>
            </div>

            <div className="space-y-1">
              <p className="font-medium">Conditions</p>
              {((dryRun?.conditions as Array<Record<string, unknown>>) ?? []).map((condition, index) => (
                <div key={index} className="rounded border p-2">
                  <code>{String(condition.field)} {String(condition.operator)} {String(condition.expected ?? '')}</code>
                  {' → actual: '}
                  <code>{String(condition.actual ?? 'null')}</code>{' '}
                  {condition.matched ? <Badge>match</Badge> : <Badge variant="destructive">no match</Badge>}
                </div>
              ))}
            </div>

            <div className="space-y-1">
              <p className="font-medium">Actions that would run</p>
              {((dryRun?.actions as Array<Record<string, unknown>>) ?? []).map((action, index) => (
                <div key={index} className="flex items-center justify-between rounded border p-2">
                  <code>{String(action.type)}</code>
                  <span>{String(action.description ?? '')}</span>
                </div>
              ))}
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Version history */}
      <Dialog open={historyRule !== null} onOpenChange={(open) => { if (!open) setHistoryRule(null) }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Version history — {historyRule?.name}</DialogTitle>
            <DialogDescription>Every edit appends an immutable snapshot.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            {(versionsQuery.data ?? []).map((version) => (
              <Card key={version.id} data-testid={`automation-version-${version.version}`}>
                <CardContent className="space-y-1 pt-4 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">v{version.version}</span>
                    <span className="text-xs text-muted-foreground">
                      {new Date(version.created_at).toLocaleString()}
                    </span>
                  </div>
                  <div><Badge variant="secondary">{version.event_name}</Badge></div>
                  <div className="text-muted-foreground">
                    {version.conditions.length} condition(s) · {version.actions.length} action(s)
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/**
 * Coerce condition values for the API: numeric fields/operators become
 * numbers, in/not_in become arrays, empty values are dropped.
 */
function coerceForm(form: FormState): FormState {
  const conditions = form.conditions.map((condition) => {
    const numeric = NUMERIC_FIELDS.has(condition.field) || NUMERIC_OPERATORS.has(condition.operator)
    const raw = condition.value

    if (VALUELESS_OPERATORS.has(condition.operator)) {
      return { field: condition.field, operator: condition.operator }
    }

    if (LIST_OPERATORS.has(condition.operator)) {
      const list = Array.isArray(raw)
        ? raw
        : String(raw ?? '').split(',').map((part) => part.trim()).filter(Boolean)

      return { field: condition.field, operator: condition.operator, value: numeric ? list.map(Number) : list }
    }

    if (numeric) {
      return { field: condition.field, operator: condition.operator, value: Number(raw) }
    }

    return { field: condition.field, operator: condition.operator, value: raw }
  })

  const actions = form.actions.map((action) => {
    if (action.type === 'notify') {
      return {
        type: action.type,
        user_ids: action.user_ids ?? [],
        roles: action.roles ?? [],
        account_owner: Boolean(action.account_owner),
        subject: action.subject || undefined,
        message: action.message || undefined,
      }
    }

    if (action.type === 'create_task') {
      return {
        type: action.type,
        summary: action.summary,
        due_in_days: action.due_in_days === null || action.due_in_days === undefined || action.due_in_days === ''
          ? undefined
          : Number(action.due_in_days),
        note: action.note || undefined,
      }
    }

    if (action.type === 'whatsapp_customer') {
      return { ...action, message: action.message || undefined }
    }

    return action
  })

  return { ...form, conditions, actions }
}
