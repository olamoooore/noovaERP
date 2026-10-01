import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import api from '@/lib/api'
import { formatCurrency, formatDate } from '@/lib/utils'

/**
 * MRD pricelist-multicurrency FR-7.7 — FX report.
 * Realized gain/loss per currency+period (from settled foreign payments at
 * their snapshot rates, matching the ledger's realized-FX postings) and the
 * open foreign AR/AP exposure at each document's snapshot rate.
 */

interface FxRealizedRow {
  currency_code: string
  base_currency: string
  period: string
  settled_amount: number
  gain: number
  loss: number
  net: number
  payment_count: number
}

interface FxExposureBucket {
  currency_code: string
  outstanding: number
  base_outstanding: number
  document_count: number
}

interface FxNetBucket {
  currency_code: string
  ar_outstanding: number
  ap_outstanding: number
  net_outstanding: number
  net_base: number
}

export default function FxReportPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['fx-report'],
    queryFn: async () => {
      const response = await api.get('/accounting/reports/fx')
      return response.data?.data
    },
  })

  const realized = data?.realized
  const exposure = data?.exposure
  const base = realized?.base_currency ?? exposure?.base_currency ?? 'USD'

  const money = (value: number, code?: string) => formatCurrency(value, code ?? base)
  const signedMoney = (value: number) => formatCurrency(value, base)
  const netClass = (value: number) => (value > 0 ? 'text-green-600' : value < 0 ? 'text-red-600' : '')

  if (isLoading) {
    return <div className="p-10 text-center text-sm text-muted-foreground">Loading FX report…</div>
  }

  return (
    <div className="space-y-6 p-6">
      <div>
        <h1 className="text-2xl font-semibold">FX Report</h1>
        <p className="text-sm text-muted-foreground">
          Realized exchange gain/loss and open foreign-currency exposure. All figures use each document's
          snapshot rate — never today's rate.
        </p>
      </div>

      {/* Realized FX */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Icon icon="solar:refresh-circle-bold-duotone" className="h-4 w-4" />
            Realized FX gain/loss
            {realized?.period && (
              <span className="text-xs font-normal text-muted-foreground">
                {formatDate(realized.period.from)} → {formatDate(realized.period.to)}
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {!realized?.rows?.length ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              No settled foreign-currency payments in this period.
            </p>
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Currency</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">Settled</TableHead>
                    <TableHead className="text-right">Gain</TableHead>
                    <TableHead className="text-right">Loss</TableHead>
                    <TableHead className="text-right">Net ({base})</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {realized.rows.map((row: FxRealizedRow) => (
                    <TableRow key={`${row.currency_code}-${row.period}`}>
                      <TableCell>
                        <Badge variant="outline">{row.currency_code}</Badge>
                      </TableCell>
                      <TableCell>{row.period}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {money(row.settled_amount, row.currency_code)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-green-600">
                        {row.gain ? signedMoney(row.gain) : '—'}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-red-600">
                        {row.loss ? signedMoney(row.loss) : '—'}
                      </TableCell>
                      <TableCell className={`text-right font-medium tabular-nums ${netClass(row.net)}`}>
                        {signedMoney(row.net)}
                      </TableCell>
                    </TableRow>
                  ))}
                  <TableRow className="bg-muted/30">
                    <TableCell colSpan={5} className="font-semibold">
                      Total realized ({base})
                    </TableCell>
                    <TableCell className={`text-right font-bold tabular-nums ${netClass(realized.total_base)}`}>
                      {signedMoney(realized.total_base)}
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
              {realized.totals?.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {realized.totals.map((total: any) => (
                    <Badge key={total.currency_code} variant="secondary">
                      {total.currency_code}: net {signedMoney(total.net)}
                    </Badge>
                  ))}
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* Exposure */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Icon icon="solar:globus-bold-duotone" className="h-4 w-4" />
            Foreign currency exposure
            {exposure?.as_of && (
              <span className="text-xs font-normal text-muted-foreground">as of {formatDate(exposure.as_of)}</span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent>
          {!exposure?.ar?.length && !exposure?.ap?.length ? (
            <p className="py-6 text-center text-sm text-muted-foreground">No open receivables or payables.</p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Currency</TableHead>
                  <TableHead className="text-right">AR outstanding</TableHead>
                  <TableHead className="text-right">AP outstanding</TableHead>
                  <TableHead className="text-right">Net ({base})</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {(exposure?.net ?? []).map((bucket: FxNetBucket) => (
                  <TableRow key={bucket.currency_code}>
                    <TableCell>
                      <Badge variant="outline">{bucket.currency_code}</Badge>
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {money(bucket.ar_outstanding, bucket.currency_code)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {money(bucket.ap_outstanding, bucket.currency_code)}
                    </TableCell>
                    <TableCell className={`text-right font-medium tabular-nums ${netClass(bucket.net_base)}`}>
                      {signedMoney(bucket.net_base)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}