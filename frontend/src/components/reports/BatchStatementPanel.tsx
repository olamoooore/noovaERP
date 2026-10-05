import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { formatCurrency, getApiErrorMessage } from '@/lib/utils'

interface BatchRow {
  id: number
  code?: string | null
  name: string
  email?: string | null
  currency?: string
  closing_balance: number
  overdue_amount: number
  oldest_overdue_days: number
}

interface BatchProgress {
  id: number
  status: 'queued' | 'processing' | 'completed' | 'failed'
  total_customers: number
  processed_customers: number
  generated_count: number
  sent_count: number
  failed_count: number
  channel?: string | null
}

const isoDate = (date: Date) => date.toISOString().split('T')[0]

const firstOfMonth = () => {
  const now = new Date()
  return isoDate(new Date(now.getFullYear(), now.getMonth(), 1))
}

/**
 * Issue #607 (RPT-F1): batch statement generation. Customers are selected
 * with the same filters as the preview ("customers with a balance",
 * salesperson, legal entity, overdue > N days, customer group); the queued
 * backend job generates one statement per selected customer and records each
 * in statement history with its send log.
 */
export function BatchStatementPanel() {
  const { toast } = useToast()
  const [withBalance, setWithBalance] = useState(true)
  const [salespersonId, setSalespersonId] = useState('all')
  const [legalEntityId, setLegalEntityId] = useState('all')
  const [overdueDays, setOverdueDays] = useState('')
  const [customerGroup, setCustomerGroup] = useState('')
  const [mode, setMode] = useState<'range' | 'as_of'>('range')
  const [startDate, setStartDate] = useState(firstOfMonth)
  const [endDate, setEndDate] = useState(() => isoDate(new Date()))
  const [asOf, setAsOf] = useState(() => isoDate(new Date()))
  const [includePaid, setIncludePaid] = useState(false)
  const [channel, setChannel] = useState<'email' | 'whatsapp'>('email')
  const [rows, setRows] = useState<BatchRow[]>([])
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [batchId, setBatchId] = useState<number | null>(null)
  const [batchError, setBatchError] = useState<string | null>(null)

  const periodEnd = mode === 'range' ? endDate : asOf

  const filterPayload = useMemo(() => ({
    with_balance: withBalance,
    salesperson_id: salespersonId !== 'all' ? Number(salespersonId) : undefined,
    legal_entity_id: legalEntityId !== 'all' ? Number(legalEntityId) : undefined,
    overdue_days: overdueDays !== '' ? Number(overdueDays) : undefined,
    customer_group: customerGroup.trim() !== '' ? customerGroup.trim() : undefined,
    as_of: periodEnd,
  }), [withBalance, salespersonId, legalEntityId, overdueDays, customerGroup, periodEnd])

  const { data: filterCustomers = [] } = useQuery({
    queryKey: ['sales-statement-filters', 'customers'],
    queryFn: async () => {
      const response = await api.get('/customers', { params: { per_page: 500 } })
      return (response.data?.data?.data ?? response.data?.data ?? []) as Array<{
        id: number
        name: string
        salesperson?: { id: number; name: string } | null
      }>
    },
    staleTime: 60 * 1000,
    retry: false,
  })

  const { data: legalEntities = [] } = useQuery({
    queryKey: ['sales-statement-filters', 'legal-entities'],
    queryFn: async () => {
      const response = await api.get('/accounting/legal-entities')
      return (Array.isArray(response.data) ? response.data : response.data?.data || []) as Array<{
        id: number
        name: string
      }>
    },
    staleTime: 60 * 1000,
    retry: false,
  })

  const salespersons = useMemo(() => {
    const byId = new Map<number, string>()
    for (const customer of filterCustomers) {
      const salesperson = customer.salesperson
      if (salesperson?.id && !byId.has(salesperson.id)) {
        byId.set(salesperson.id, salesperson.name)
      }
    }
    return [...byId].map(([id, name]) => ({ id, name }))
  }, [filterCustomers])

  const preview = useMutation({
    mutationFn: async () => {
      const response = await api.post('/accounting/customer-statements/batch/preview', filterPayload)
      return response.data as { data: BatchRow[] }
    },
    onSuccess: (data) => {
      setRows(data.data)
      setSelectedIds(data.data.map((row) => row.id))
      setBatchId(null)
      setBatchError(null)
    },
    onError: (error) => toast({
      variant: 'destructive',
      title: 'Could not preview the batch',
      description: getApiErrorMessage(error, 'The batch preview failed.'),
    }),
  })

  const sendBatch = useMutation({
    mutationFn: async () => {
      const periodParams = mode === 'range'
        ? { start_date: startDate, end_date: endDate }
        : { as_of: asOf }
      const response = await api.post('/accounting/customer-statements/batch', {
        ...filterPayload,
        ...periodParams,
        customer_ids: selectedIds,
        include_paid: includePaid,
        channel,
      })
      return response.data?.data as BatchProgress
    },
    onSuccess: (data) => {
      setBatchId(data.id)
      setBatchError(null)
    },
    onError: (error) => {
      setBatchError(getApiErrorMessage(error, 'The statement batch could not be queued.'))
      toast({
        variant: 'destructive',
        title: 'Could not queue the batch',
        description: getApiErrorMessage(error, 'The statement batch could not be queued.'),
      })
    },
  })

  const { data: progress } = useQuery({
    queryKey: ['statement-batch', batchId],
    queryFn: async () => {
      const response = await api.get(`/accounting/customer-statements/batches/${batchId}`)
      return response.data?.data as BatchProgress
    },
    enabled: batchId !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'completed' || status === 'failed' ? false : 2000
    },
  })

  const allSelected = rows.length > 0 && selectedIds.length === rows.length

  const toggleAll = () => {
    setSelectedIds(allSelected ? [] : rows.map((row) => row.id))
  }

  const toggleRow = (id: number) => {
    setSelectedIds((current) => (
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
    ))
  }

  const totalClosing = rows
    .filter((row) => selectedIds.includes(row.id))
    .reduce((sum, row) => sum + Number(row.closing_balance || 0), 0)

  const progressValue = progress && progress.total_customers > 0
    ? Math.round((progress.processed_customers / progress.total_customers) * 100)
    : 0

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="border-b pb-4">
          <CardTitle className="text-base">Batch statements</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4 pt-6">
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Salesperson</Label>
              <Select value={salespersonId} onValueChange={setSalespersonId}>
                <SelectTrigger aria-label="Salesperson">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All salespeople</SelectItem>
                  {salespersons.map((salesperson) => (
                    <SelectItem key={salesperson.id} value={String(salesperson.id)}>
                      {salesperson.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Legal entity</Label>
              <Select value={legalEntityId} onValueChange={setLegalEntityId}>
                <SelectTrigger aria-label="Legal entity">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All entities</SelectItem>
                  {legalEntities.map((entity) => (
                    <SelectItem key={entity.id} value={String(entity.id)}>
                      {entity.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="batch-overdue-days" className="text-xs font-medium text-muted-foreground">
                Overdue &gt; N days
              </Label>
              <Input
                id="batch-overdue-days"
                type="number"
                min={0}
                value={overdueDays}
                onChange={(event) => setOverdueDays(event.target.value)}
                placeholder="Any"
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="batch-customer-group" className="text-xs font-medium text-muted-foreground">
                Customer / hospital group
              </Label>
              <Input
                id="batch-customer-group"
                value={customerGroup}
                onChange={(event) => setCustomerGroup(event.target.value)}
                placeholder="All groups"
              />
            </div>
          </div>

          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Period</Label>
              <Select value={mode} onValueChange={(value) => setMode(value as 'range' | 'as_of')}>
                <SelectTrigger aria-label="Batch statement period">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="range">Date range</SelectItem>
                  <SelectItem value="as_of">As of date</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {mode === 'range' ? (
              <div className="grid grid-cols-2 gap-2 lg:col-span-2">
                <div className="space-y-1.5">
                  <Label htmlFor="batch-statement-start" className="text-xs font-medium text-muted-foreground">From</Label>
                  <Input
                    id="batch-statement-start"
                    type="date"
                    value={startDate}
                    onChange={(event) => setStartDate(event.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="batch-statement-end" className="text-xs font-medium text-muted-foreground">To</Label>
                  <Input
                    id="batch-statement-end"
                    type="date"
                    value={endDate}
                    onChange={(event) => setEndDate(event.target.value)}
                  />
                </div>
              </div>
            ) : (
              <div className="space-y-1.5">
                <Label htmlFor="batch-statement-as-of" className="text-xs font-medium text-muted-foreground">As of</Label>
                <Input
                  id="batch-statement-as-of"
                  type="date"
                  value={asOf}
                  onChange={(event) => setAsOf(event.target.value)}
                />
              </div>
            )}

            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Send via</Label>
              <Select value={channel} onValueChange={(value) => setChannel(value as 'email' | 'whatsapp')}>
                <SelectTrigger aria-label="Send channel">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="email">Email</SelectItem>
                  <SelectItem value="whatsapp">WhatsApp</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-4">
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={withBalance}
                  onCheckedChange={(checked) => setWithBalance(checked === true)}
                  aria-label="Customers with a balance"
                />
                Customers with a balance
              </label>
              <label className="flex items-center gap-2 text-sm">
                <Checkbox
                  checked={includePaid}
                  onCheckedChange={(checked) => setIncludePaid(checked === true)}
                  aria-label="Include fully paid invoices"
                />
                Include fully paid invoices
              </label>
            </div>

            <Button
              type="button"
              variant="outline"
              onClick={() => preview.mutate()}
              disabled={preview.isPending}
            >
              <Icon icon="solar:magnifier-bold" className="mr-2 h-4 w-4" />
              {preview.isPending ? 'Loading...' : 'Preview list'}
            </Button>
          </div>
        </CardContent>
      </Card>

      {rows.length > 0 && (
        <Card className="rounded-xl">
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
            <div>
              <CardTitle className="text-base">Batch preview</CardTitle>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {selectedIds.length} of {rows.length} customers selected · {formatCurrency(totalClosing)} outstanding
              </p>
            </div>
            <Button
              type="button"
              onClick={() => sendBatch.mutate()}
              disabled={selectedIds.length === 0 || sendBatch.isPending}
            >
              <Icon icon="solar:letter-bold" className="mr-2 h-4 w-4" />
              {sendBatch.isPending ? 'Queuing...' : `Generate & send (${selectedIds.length})`}
            </Button>
          </CardHeader>
          <CardContent className="pt-6">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-10">
                    <Checkbox
                      checked={allSelected}
                      onCheckedChange={toggleAll}
                      aria-label="Select all customers"
                    />
                  </TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead className="text-right">Closing balance</TableHead>
                  <TableHead className="text-right">Overdue</TableHead>
                  <TableHead className="text-right">Overdue days</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell>
                      <Checkbox
                        checked={selectedIds.includes(row.id)}
                        onCheckedChange={() => toggleRow(row.id)}
                        aria-label={`Select ${row.name}`}
                      />
                    </TableCell>
                    <TableCell>
                      <div className="font-medium">{row.name}</div>
                      {row.code && <div className="text-xs text-muted-foreground">{row.code}</div>}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(row.closing_balance, row.currency)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(row.overdue_amount, row.currency)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {row.oldest_overdue_days > 0 ? row.oldest_overdue_days : '-'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {batchError && (
        <Card>
          <CardContent className="py-6 text-center text-destructive">{batchError}</CardContent>
        </Card>
      )}

      {(progress || sendBatch.data) && (
        <Card className="rounded-xl">
          <CardHeader className="flex flex-row items-center justify-between gap-3 border-b pb-4">
            <CardTitle className="text-base">Batch progress</CardTitle>
            <Badge variant={progress?.status === 'failed' ? 'destructive' : 'outline'}>
              {progress?.status ?? sendBatch.data?.status ?? 'queued'}
            </Badge>
          </CardHeader>
          <CardContent className="space-y-4 pt-6">
            <Progress value={progressValue} />
            <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
              <div>
                <p className="text-muted-foreground">Processed</p>
                <p className="font-semibold tabular-nums">
                  {progress?.processed_customers ?? 0} / {progress?.total_customers ?? selectedIds.length}
                </p>
              </div>
              <div>
                <p className="text-muted-foreground">Generated</p>
                <p className="font-semibold tabular-nums">{progress?.generated_count ?? 0}</p>
              </div>
              <div>
                <p className="text-muted-foreground">Sent</p>
                <p className="font-semibold tabular-nums">{progress?.sent_count ?? 0}</p>
              </div>
              <div>
                <p className="text-muted-foreground">Failed</p>
                <p className="font-semibold tabular-nums">{progress?.failed_count ?? 0}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
