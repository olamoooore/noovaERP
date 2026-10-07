import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { quotationReasonLabel } from './quotationRevisionReasons'

/**
 * Issue #336 (Q12): one row of the quotation's `version_history` (served by
 * `GET /quotations/{id}`) — every version in the correction group created by
 * reject / new revision, with the change in total against the previous one.
 */
export interface QuotationVersionEntry {
  id: number
  quotation_number: string
  version_number: number
  status: string
  is_active_version: boolean
  subtotal: number
  tax_amount: number
  discount_amount: number
  total: number
  total_change: number | null
  correction_reason_code?: string | null
  correction_reason_text?: string | null
}

export function QuotationVersionHistory({
  versions,
  currentId,
  money,
}: {
  versions: QuotationVersionEntry[]
  currentId: number
  money: (value: number) => string
}) {
  if (versions.length < 2) return null

  const formatChange = (change: number | null) => {
    if (change === null || change === undefined) return '—'
    if (change === 0) return money(0)
    return change > 0 ? `+${money(change)}` : money(change)
  }

  return (
    <Card className="overflow-hidden">
      <section aria-labelledby="quotation-version-history-title">
        <div className="flex items-center justify-between border-b bg-muted/20 px-6 py-4">
          <div>
            <h3 id="quotation-version-history-title" className="text-base font-semibold leading-none">
              Version history
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Earlier versions are kept read-only when the quotation is rejected or revised.
            </p>
          </div>
          <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
            {versions.length}
          </span>
        </div>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Version</TableHead>
                <TableHead>Quotation #</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Subtotal</TableHead>
                <TableHead className="text-right">Tax</TableHead>
                <TableHead className="text-right">Total</TableHead>
                <TableHead className="text-right">Change</TableHead>
                <TableHead>Reason</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {versions.map((version) => {
                const isCurrent = version.id === currentId
                const change = version.total_change
                const reason = quotationReasonLabel(version.correction_reason_code)
                return (
                  <TableRow key={version.id} className={cn(isCurrent && 'bg-primary/5')}>
                    <TableCell className="font-medium">
                      <span>v{version.version_number}</span>
                      {version.is_active_version && (
                        <Badge variant="secondary" className="ml-2 text-[10px]">
                          Current
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell>
                      {isCurrent ? (
                        <span className="font-medium">{version.quotation_number}</span>
                      ) : (
                        <Link
                          to={`/quotations/${version.id}`}
                          className="text-primary underline-offset-2 hover:underline"
                        >
                          {version.quotation_number}
                        </Link>
                      )}
                    </TableCell>
                    <TableCell className="capitalize">{version.status}</TableCell>
                    <TableCell className="text-right">{money(Number(version.subtotal))}</TableCell>
                    <TableCell className="text-right">{money(Number(version.tax_amount))}</TableCell>
                    <TableCell className="text-right font-medium">{money(Number(version.total))}</TableCell>
                    <TableCell
                      className={cn(
                        'text-right',
                        change !== null && change > 0 && 'text-emerald-600',
                        change !== null && change < 0 && 'text-red-600',
                      )}
                    >
                      {formatChange(change === null ? null : Number(change))}
                    </TableCell>
                    <TableCell className="max-w-xs text-sm">
                      {reason ? (
                        <div>
                          <span className="font-medium">{reason}</span>
                          {version.correction_reason_text && (
                            <p className="truncate text-muted-foreground" title={version.correction_reason_text}>
                              {version.correction_reason_text}
                            </p>
                          )}
                        </div>
                      ) : (
                        <span className="text-muted-foreground">Original</span>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </CardContent>
      </section>
    </Card>
  )
}
