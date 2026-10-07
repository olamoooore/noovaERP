import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { salesOrderHealthQueryKey, type HealthStatus, type SalesOrderHealth } from './salesOrderHealth'

/**
 * Issue #412 (DX2): order health panel before Confirm/Send. Reads the
 * read-only dry run GET /sales/orders/{id}/health — stock per line (with the
 * backorder date from incoming purchase orders), credit exposure vs limit,
 * whether approval will be required and why, and margin for roles that may
 * see cost. Advisory: confirmation keeps its own server-side gates.
 */

const STATUS_ICON: Record<HealthStatus, string> = {
  pass: 'solar:check-circle-bold',
  warn: 'solar:danger-triangle-bold',
  fail: 'solar:close-circle-bold',
}

const STATUS_COLOR: Record<HealthStatus, string> = {
  pass: 'text-emerald-600',
  warn: 'text-amber-600',
  fail: 'text-red-600',
}

function formatQuantity(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString(undefined, { maximumFractionDigits: 4 })
}

export function SalesOrderHealthPanel({ orderId }: { orderId: string | number }) {
  const { data: health, isError } = useQuery({
    queryKey: salesOrderHealthQueryKey(orderId),
    queryFn: async () => (await api.get(`/sales/orders/${orderId}/health`)).data as SalesOrderHealth,
  })

  if (isError) {
    return (
      <Card role="region" aria-label="Order health">
        <CardContent className="py-4 text-sm text-muted-foreground">
          Order health could not be loaded. Confirming still runs the server checks.
        </CardContent>
      </Card>
    )
  }

  // Nothing to show until the dry run answers with its checks.
  if (!health || !Array.isArray(health.checks)) return null

  const productLines = (health.lines ?? []).filter((line) => line.tracked || line.margin_percent !== undefined)

  return (
    <Card role="region" aria-label="Order health">
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0">
        <CardTitle className="text-sm">Order health</CardTitle>
        <Badge
          variant="outline"
          className={cn(
            health.ready
              ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300'
              : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300',
          )}
        >
          {`${health.passed}/${health.total} checks pass`}
        </Badge>
      </CardHeader>
      <CardContent className="space-y-4">
        <ul className="grid gap-1.5 text-xs sm:grid-cols-2">
          {health.checks.map((check) => (
            <li
              key={check.key}
              data-testid={`health-check-${check.key}`}
              data-status={check.status}
              className="flex gap-2 rounded-md border p-2"
            >
              <Icon icon={STATUS_ICON[check.status]} className={cn('mt-0.5 h-4 w-4 shrink-0', STATUS_COLOR[check.status])} />
              <div className="min-w-0">
                <div className="font-medium">{check.label}</div>
                <div className="text-muted-foreground">{check.detail}</div>
              </div>
            </li>
          ))}
        </ul>

        {productLines.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-1.5 pr-2 font-medium">Line</th>
                  <th className="py-1.5 pr-2 text-right font-medium">Qty</th>
                  <th className="py-1.5 pr-2 text-right font-medium">Available</th>
                  <th className="py-1.5 pr-2 font-medium">Stock</th>
                  {health.can_see_cost && <th className="py-1.5 text-right font-medium">Margin</th>}
                </tr>
              </thead>
              <tbody>
                {productLines.map((line) => (
                  <tr key={line.line_id} data-testid={`health-line-${line.line_id}`} className="border-b last:border-0 align-top">
                    <td className="py-1.5 pr-2">{line.product_name ?? line.description ?? `Line ${line.line_id}`}</td>
                    <td className="py-1.5 pr-2 text-right tabular-nums">{formatQuantity(line.open_quantity)}</td>
                    <td className="py-1.5 pr-2 text-right tabular-nums">{line.tracked ? formatQuantity(line.available) : '—'}</td>
                    <td className="py-1.5 pr-2">
                      <div className="flex items-start gap-1.5">
                        <Icon icon={STATUS_ICON[line.status]} className={cn('mt-0.5 h-3.5 w-3.5 shrink-0', STATUS_COLOR[line.status])} />
                        <span>
                          {!line.tracked
                            ? 'Not stock-tracked'
                            : line.status === 'pass'
                              ? 'Available'
                              : line.backorder_date
                                ? `Backorder until ${line.backorder_date} (short ${formatQuantity(line.shortage)})`
                                : line.shortage > 0
                                  ? `Backorder, no dated supply (short ${formatQuantity(line.shortage)})`
                                  : line.detail}
                        </span>
                        {line.promise_at_risk && (
                          <Badge variant="destructive" className="h-4 px-1.5 text-[10px]">
                            Promise at risk
                          </Badge>
                        )}
                      </div>
                    </td>
                    {health.can_see_cost && (
                      <td className="py-1.5 text-right tabular-nums">
                        {line.margin_percent === null || line.margin_percent === undefined
                          ? '—'
                          : `${Number(line.margin_percent).toFixed(1)}%`}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <p className="text-[11px] text-muted-foreground">
          Advisory: confirming still runs the credit, approval and stock rules on the server.
        </p>
      </CardContent>
    </Card>
  )
}
