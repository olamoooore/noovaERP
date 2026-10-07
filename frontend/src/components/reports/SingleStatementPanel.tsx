import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { CustomerSearchCombobox } from '@/components/ui/CustomerSearchCombobox'
import { PrintSendMenu } from '@/components/documents/PrintSendMenu'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { toLocalIsoDate } from '@/lib/dateOnly'
import { formatCurrency, getApiErrorMessage } from '@/lib/utils'

interface StatementCustomer {
  id: string
  code?: string
  name?: string
  legal_entity_id?: number | null
  customer_group?: string | null
  language?: string | null
}

interface Aging {
  current: number
  days_1_30: number
  days_31_60: number
  days_61_90: number
  days_90_plus: number
  total: number
}

interface StatementPayload {
  currency?: string
  closing_balance: number
  total_debits: number
  total_credits: number
  aging: Aging
}

const firstOfMonth = () => {
  const now = new Date()
  return toLocalIsoDate(new Date(now.getFullYear(), now.getMonth(), 1))
}

const AGING_BUCKETS: Array<{ key: keyof Aging; label: string }> = [
  { key: 'current', label: 'Current' },
  { key: 'days_1_30', label: '1 – 30 days' },
  { key: 'days_31_60', label: '31 – 60 days' },
  { key: 'days_61_90', label: '61 – 90 days' },
  { key: 'days_90_plus', label: '90+ days' },
]

/**
 * Issue #607 (RPT-F1): single-customer statement preview and send. Reuses the
 * existing statement read (canonical JSON payload with aging) and the shared
 * customer picker and print/send menu (#386/#618); send goes through the new
 * customers.view-gated endpoint so SALES does not need accounting write.
 */
export function SingleStatementPanel() {
  const { toast } = useToast()
  const [customerId, setCustomerId] = useState('')
  const [selectedCustomer, setSelectedCustomer] = useState<StatementCustomer | null>(null)
  const [mode, setMode] = useState<'range' | 'as_of'>('range')
  const [startDate, setStartDate] = useState(firstOfMonth)
  const [endDate, setEndDate] = useState(() => toLocalIsoDate(new Date()))
  const [asOf, setAsOf] = useState(() => toLocalIsoDate(new Date()))
  const [includePaid, setIncludePaid] = useState(false)
  // #1018: a range whose To precedes its From is rejected client-side (the
  // API answers 422) — no preview request and no print/send for it.
  const rangeInvalid = mode === 'range' && Boolean(startDate && endDate && endDate < startDate)

  const periodParams = useMemo<Record<string, string>>(() => {
    const params: Record<string, string> = {}
    if (mode === 'range') {
      params.start_date = startDate
      params.end_date = endDate
    } else {
      params.as_of = asOf
    }
    return params
  }, [mode, startDate, endDate, asOf])

  const previewQuery = useQuery({
    queryKey: ['sales-statement-preview', customerId, mode, startDate, endDate, asOf, includePaid],
    queryFn: async () => {
      const response = await api.get(`/accounting/customers/${customerId}/statement`, {
        params: { ...periodParams, format: 'json', include_paid: includePaid ? 1 : 0 },
      })
      return response.data as { statement_id: string; filename: string; payload: StatementPayload }
    },
    enabled: Boolean(customerId) && !rangeInvalid,
  })

  const sendStatement = useMutation({
    mutationFn: async (channel: 'email' | 'whatsapp') => {
      const response = await api.post('/accounting/customer-statements/send', {
        customer_id: Number(customerId),
        ...periodParams,
        include_paid: includePaid,
        channel,
      })
      return response.data
    },
    onSuccess: (_data, channel) => {
      toast({
        title: channel === 'whatsapp' ? 'Statement sent via WhatsApp' : 'Statement emailed to the customer',
      })
    },
    onError: (error) => toast({
      variant: 'destructive',
      title: 'Failed to send statement',
      description: getApiErrorMessage(error, 'The customer statement could not be sent.'),
    }),
  })

  const payload = previewQuery.data?.payload
  const currency = payload?.currency

  const statementPdfPath = useMemo(() => {
    const query = new URLSearchParams(periodParams)
    return `/accounting/customers/${customerId}/statement?${query.toString()}`
  }, [customerId, periodParams])

  // #618/#612: the print/send menu resolves the tenant's default statement
  // template from the customer's own context.
  const resolutionPath = useMemo(() => {
    const params = new URLSearchParams()
    if (selectedCustomer?.legal_entity_id != null) params.set('entity_id', String(selectedCustomer.legal_entity_id))
    if (selectedCustomer?.customer_group) params.set('customer_group', String(selectedCustomer.customer_group))
    if (selectedCustomer?.language) params.set('language', String(selectedCustomer.language))
    const query = params.toString()
    return `/documents/customer_statement/resolution${query ? `?${query}` : ''}`
  }, [selectedCustomer])

  const periodSuffix = (mode === 'range' ? endDate : asOf).split('-').join('')

  return (
    <div className="space-y-6">
      <Card className="rounded-xl">
        <CardHeader className="border-b pb-4">
          <CardTitle className="text-base">Single customer statement</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4 pt-6">
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div className="space-y-1.5 lg:col-span-2">
              <Label className="text-xs font-medium text-muted-foreground">Customer</Label>
              <CustomerSearchCombobox
                value={customerId}
                onValueChange={(value, customer) => {
                  setCustomerId(value)
                  setSelectedCustomer((customer as StatementCustomer) ?? null)
                }}
                placeholder="Search customer name or code..."
              />
            </div>

            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Period</Label>
              <Select value={mode} onValueChange={(value) => setMode(value as 'range' | 'as_of')}>
                <SelectTrigger aria-label="Statement period">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="range">Date range</SelectItem>
                  <SelectItem value="as_of">As of date</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {mode === 'range' ? (
              <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1.5">
                  <Label htmlFor="single-statement-start" className="text-xs font-medium text-muted-foreground">From</Label>
                  <Input
                    id="single-statement-start"
                    type="date"
                    value={startDate}
                    onChange={(event) => setStartDate(event.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="single-statement-end" className="text-xs font-medium text-muted-foreground">To</Label>
                  <Input
                    id="single-statement-end"
                    type="date"
                    value={endDate}
                    min={startDate || undefined}
                    onChange={(event) => setEndDate(event.target.value)}
                  />
                </div>
                {rangeInvalid && (
                  <p className="col-span-2 text-xs text-destructive">
                    The To date must be on or after the From date.
                  </p>
                )}
              </div>
            ) : (
              <div className="space-y-1.5">
                <Label htmlFor="single-statement-as-of" className="text-xs font-medium text-muted-foreground">As of</Label>
                <Input
                  id="single-statement-as-of"
                  type="date"
                  value={asOf}
                  onChange={(event) => setAsOf(event.target.value)}
                />
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center justify-between gap-3">
            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={includePaid}
                onCheckedChange={(checked) => setIncludePaid(checked === true)}
                aria-label="Include fully paid invoices"
              />
              Include fully paid invoices (with their payments and credit notes)
            </label>

            {customerId && !rangeInvalid && (
              <PrintSendMenu
                documentType="customer_statement"
                pdfPath={statementPdfPath}
                filename={`SOA_${selectedCustomer?.code || customerId || 'UNKNOWN'}_${periodSuffix}.pdf`}
                title="Statement of Account"
                resolutionPath={resolutionPath}
                onEmail={() => sendStatement.mutate('email')}
                onWhatsApp={() => sendStatement.mutate('whatsapp')}
              />
            )}
          </div>
        </CardContent>
      </Card>

      {!customerId ? (
        <Card>
          <CardContent className="py-16 text-center text-muted-foreground">
            <Icon icon="solar:user-id-bold-duotone" className="mx-auto mb-3 h-12 w-12 opacity-50" />
            Select a customer to preview their statement and aging.
          </CardContent>
        </Card>
      ) : rangeInvalid ? null : previewQuery.isLoading ? (
        <Card><CardContent className="py-12 text-center text-muted-foreground">Loading statement preview...</CardContent></Card>
      ) : previewQuery.isError ? (
        <Card><CardContent className="py-12 text-center text-destructive">Could not load the statement preview.</CardContent></Card>
      ) : payload ? (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Closing balance</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-semibold tabular-nums">
                {formatCurrency(payload.closing_balance, currency)}
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Total debits</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-semibold tabular-nums">
                {formatCurrency(payload.total_debits, currency)}
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">Total credits</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-semibold tabular-nums">
                {formatCurrency(payload.total_credits, currency)}
              </CardContent>
            </Card>
          </div>

          <Card className="rounded-xl">
            <CardHeader className="border-b pb-4">
              <CardTitle className="text-base">Aging</CardTitle>
            </CardHeader>
            <CardContent className="pt-6">
              <Table>
                <TableHeader>
                  <TableRow>
                    {AGING_BUCKETS.map((bucket) => (
                      <TableHead key={bucket.key}>{bucket.label}</TableHead>
                    ))}
                    <TableHead>Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow>
                    {AGING_BUCKETS.map((bucket) => (
                      <TableCell key={bucket.key} className="tabular-nums">
                        {formatCurrency(payload.aging[bucket.key], currency)}
                      </TableCell>
                    ))}
                    <TableCell className="font-semibold tabular-nums">
                      {formatCurrency(payload.aging.total, currency)}
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      ) : null}
    </div>
  )
}
