import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatCurrency } from '@/lib/utils'
import { getSalesOrderStatusMeta } from '@/lib/salesOrderStatus'
import api from '@/lib/api'

interface PipelineData {
  period: { from: string; to: string }
  base_currency?: string
  unconverted?: Record<string, number>
  orders_by_status: { status: string; count: number; value: number }[]
  pipeline: { open_orders: number; open_value: number; won_orders: number; won_value: number }
  quotations: {
    total: number
    open: number
    won: number
    lost: number
    win_rate_pct: number | null
    aging: Record<string, number>
  }
  expected_revenue_by_month: { month: string; count: number; value: number }[]
  salespeople: { user_id: number | null; name: string; orders: number; value: number; won: number }[]
  product_mix: { product_id: number | null; product_name?: string | null; sku?: string | null; quantity: number; value: number }[]
}

const AGING_LABELS: Record<string, string> = {
  '0_7_days': '0-7 days',
  '8_30_days': '8-30 days',
  '31_60_days': '31-60 days',
  over_60_days: 'Over 60 days',
}

const toIsoDate = (date: Date) => {
  const offset = date.getTimezoneOffset() * 60000
  return new Date(date.getTime() - offset).toISOString().slice(0, 10)
}

function Stat({ label, value, sub, testId }: { label: string; value: string; sub?: string; testId?: string }) {
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="text-sm text-muted-foreground">{label}</div>
        <div className="mt-1 text-2xl font-semibold" data-testid={testId}>
          {value}
        </div>
        {sub && <div className="mt-1 text-xs text-muted-foreground">{sub}</div>}
      </CardContent>
    </Card>
  )
}

/**
 * Issue #376 (O7) / #150: the Sales menu page for the existing
 * GET /sales/analytics/pipeline endpoint (pre-invoice pipeline, quotation win
 * rate and aging, expected revenue, salesperson performance, product mix).
 * All figures are the endpoint's own, in the tenant base currency.
 */
export function SalesPipelinePage() {
  const now = new Date()
  const [fromDate, setFromDate] = useState(toIsoDate(new Date(now.getFullYear(), now.getMonth(), 1)))
  const [toDate, setToDate] = useState(toIsoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)))
  const periodValid = Boolean(fromDate && toDate && fromDate <= toDate)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['sales-pipeline', fromDate, toDate],
    queryFn: async () => {
      const response = await api.get('/sales/analytics/pipeline', { params: { from_date: fromDate, to_date: toDate } })
      return response.data?.data as PipelineData
    },
    enabled: periodValid,
  })

  const currency = data?.base_currency || undefined
  const money = (value: number) => formatCurrency(Number(value ?? 0), currency)
  const unconverted = Object.entries(data?.unconverted ?? {})

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Sales Pipeline</h1>
          <p className="mt-1 text-muted-foreground">Open and won orders, quotation win rate and expected revenue.</p>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div className="space-y-1">
            <Label htmlFor="pipeline-from">From</Label>
            <Input id="pipeline-from" type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
          </div>
          <div className="space-y-1">
            <Label htmlFor="pipeline-to">To</Label>
            <Input id="pipeline-to" type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} />
          </div>
        </div>
      </div>

      {!periodValid && <p className="text-sm text-destructive">Choose a start date on or before the end date.</p>}
      {isLoading && periodValid && <p className="text-sm text-muted-foreground">Loading pipeline...</p>}
      {isError && (
        <p className="text-sm text-destructive">
          {(error as any)?.response?.data?.message || 'The pipeline could not be loaded.'}
        </p>
      )}

      {data && (
        <>
          {unconverted.length > 0 && (
            <p className="text-sm text-amber-700">
              Some amounts could not be converted to {currency} and are excluded:{' '}
              {unconverted.map(([code, amount]) => `${code} ${amount}`).join(', ')}
            </p>
          )}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Open pipeline" value={money(data.pipeline.open_value)} sub={`${data.pipeline.open_orders} orders`} />
            <Stat label="Won" value={money(data.pipeline.won_value)} sub={`${data.pipeline.won_orders} orders`} />
            <Stat
              label="Quotation win rate"
              testId="pipeline-win-rate"
              value={data.quotations.win_rate_pct == null ? 'n/a' : `${data.quotations.win_rate_pct}%`}
              sub={`${data.quotations.won} won · ${data.quotations.lost} lost · ${data.quotations.open} open`}
            />
            <Stat label="Quotations" value={String(data.quotations.total)} sub="in period" />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Orders by status</CardTitle>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Orders</TableHead>
                      <TableHead className="text-right">Value</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.orders_by_status.map((row) => (
                      <TableRow key={row.status}>
                        <TableCell>{getSalesOrderStatusMeta(row.status).label}</TableCell>
                        <TableCell className="text-right">{row.count}</TableCell>
                        <TableCell className="text-right">{money(row.value)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Open quotation aging</CardTitle>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableBody>
                    {Object.entries(data.quotations.aging).map(([bucket, count]) => (
                      <TableRow key={bucket}>
                        <TableCell>{AGING_LABELS[bucket] ?? bucket}</TableCell>
                        <TableCell className="text-right">{count}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Expected revenue by month</CardTitle>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Month</TableHead>
                      <TableHead className="text-right">Orders</TableHead>
                      <TableHead className="text-right">Value</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.expected_revenue_by_month.map((row) => (
                      <TableRow key={row.month}>
                        <TableCell>{row.month}</TableCell>
                        <TableCell className="text-right">{row.count}</TableCell>
                        <TableCell className="text-right">{money(row.value)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Salesperson performance</CardTitle>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Salesperson</TableHead>
                      <TableHead className="text-right">Orders</TableHead>
                      <TableHead className="text-right">Won</TableHead>
                      <TableHead className="text-right">Value</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {data.salespeople.map((row) => (
                      <TableRow key={row.user_id ?? 'unassigned'}>
                        <TableCell>{row.name}</TableCell>
                        <TableCell className="text-right">{row.orders}</TableCell>
                        <TableCell className="text-right">{row.won}</TableCell>
                        <TableCell className="text-right">{money(row.value)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Product mix of open orders</CardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead>SKU</TableHead>
                    <TableHead className="text-right">Quantity</TableHead>
                    <TableHead className="text-right">Value</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {data.product_mix.map((row) => (
                    <TableRow key={row.product_id ?? 'none'}>
                      <TableCell>{row.product_name ?? 'Unknown product'}</TableCell>
                      <TableCell>{row.sku ?? ''}</TableCell>
                      <TableCell className="text-right">{row.quantity}</TableCell>
                      <TableCell className="text-right">{money(row.value)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  )
}

export default SalesPipelinePage
