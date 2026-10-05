import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn, formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

export interface InvoiceJournalLine {
  account_id: number | null
  account_code: string | null
  account_name: string | null
  debit: number
  credit: number
  description: string | null
  tax_code: string | null
  currency_code: string | null
  original_amount: number | null
  exchange_rate: number | null
  department_id: number | null
  department_name: string | null
  project_id: number | null
  project_name: string | null
}

export interface InvoiceJournalPayload {
  status: 'draft' | 'posted'
  source: 'preview' | 'actual'
  preview: boolean
  error: string | null
  journal_entry: {
    id: number
    entry_number: string
    entry_date: string | null
    status: string
    description: string | null
    reference: string | null
  } | null
  posting_date: string | null
  description: string
  reference: string | null
  legal_entity: { id: number; name: string | null; code: string | null } | null
  currency: { document: string; base: string; exchange_rate: number | null }
  lines: InvoiceJournalLine[]
  total_debit: number
  total_credit: number
  is_balanced: boolean
}

/**
 * Issue #883 (SAU1): the invoice Journal tab. A draft shows the entry Post
 * will create — computed server-side by the same builder as posting and
 * flagged as a preview — while a posted invoice shows its actual journal
 * entry with each line drilling into the account ledger.
 */
export function InvoiceJournalTab({
  invoiceId,
  status,
}: {
  invoiceId: string | number
  status?: string
}) {
  const { data, isLoading } = useQuery<InvoiceJournalPayload>({
    // status is part of the key so posting the invoice refetches the tab and
    // swaps the draft preview for the actual entry.
    queryKey: ['invoice-journal', String(invoiceId), status ?? ''],
    queryFn: async () => (await api.get(`/invoices/${invoiceId}/journal`)).data,
    enabled: !!invoiceId,
  })

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex items-center gap-3 py-10 text-sm text-muted-foreground">
          <Icon icon="solar:refresh-circle-bold-duotone" className="h-5 w-5 animate-spin" />
          Loading the journal…
        </CardContent>
      </Card>
    )
  }

  if (!data) return null

  const money = (value: number) => formatCurrency(value, data.currency.base || undefined)
  const ledgerHref = (line: InvoiceJournalLine) =>
    `/accounting/reporting/general-ledger?account_id=${line.account_id}${
      data.posting_date ? `&start_date=${data.posting_date}&end_date=${data.posting_date}` : ''
    }`

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="space-y-1">
          <CardTitle className="flex items-center gap-2 text-lg">
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-primary" />
            {data.preview ? 'Journal preview' : `Journal entry ${data.journal_entry?.entry_number ?? ''}`}
          </CardTitle>
          <p className="text-sm text-muted-foreground">
            {data.preview
              ? 'The entry Posting will create — nothing is written until you post this invoice.'
              : data.journal_entry?.status === 'posted'
                ? 'The posted entry. Each line links to the account ledger.'
                : `Journal entry status: ${data.journal_entry?.status ?? 'unknown'}.`}
          </p>
        </div>
        <Badge variant={data.preview ? 'secondary' : data.journal_entry?.status === 'posted' ? 'default' : 'outline'}>
          {data.preview ? 'Preview — not posted' : data.journal_entry?.entry_number ?? 'Posted'}
        </Badge>
      </CardHeader>

      <CardContent className="space-y-4">
        {data.error && (
          <div className="flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
            <Icon icon="solar:danger-triangle-bold-duotone" className="mt-0.5 h-5 w-5 shrink-0" />
            <div>
              <p className="font-medium">Journal cannot be built yet</p>
              <p className="mt-1">{data.error}</p>
            </div>
          </div>
        )}

        <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Entity</p>
            <p className="font-medium">
              {data.legal_entity
                ? [data.legal_entity.code, data.legal_entity.name].filter(Boolean).join(' · ')
                : 'No legal entity resolved'}
            </p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Posting date</p>
            <p className="font-medium">{data.posting_date ?? '—'}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Currency</p>
            <p className="font-medium">
              {data.currency.document}
              {data.currency.document !== data.currency.base && (
                <span className="text-muted-foreground">
                  {' '}→ {data.currency.base}
                  {data.currency.exchange_rate !== null && ` @ ${data.currency.exchange_rate}`}
                </span>
              )}
            </p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-muted-foreground">Balance</p>
            <p className={cn('font-medium', data.is_balanced ? 'text-emerald-600' : 'text-red-600')}>
              {data.is_balanced ? 'Balanced' : 'Out of balance'}
            </p>
          </div>
        </div>

        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Account</TableHead>
                <TableHead>Description</TableHead>
                <TableHead>Dimensions</TableHead>
                <TableHead>Tax</TableHead>
                <TableHead className="text-right">Debit</TableHead>
                <TableHead className="text-right">Credit</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.lines.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                    No journal lines {data.preview ? 'would be posted' : 'were posted'}.
                  </TableCell>
                </TableRow>
              )}
              {data.lines.map((line, index) => (
                <TableRow key={`${line.account_id ?? 'line'}-${index}`}>
                  <TableCell>
                    {line.account_id && !data.preview ? (
                      <Link to={ledgerHref(line)} className="font-medium text-primary hover:underline">
                        {line.account_code ? `${line.account_code} — ` : ''}{line.account_name ?? `#${line.account_id}`}
                      </Link>
                    ) : (
                      <span className="font-medium">
                        {line.account_code ? `${line.account_code} — ` : ''}
                        {line.account_name ?? (line.account_id ? `#${line.account_id}` : 'Unresolved account')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="max-w-[22rem] text-sm">{line.description ?? '—'}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {[line.department_name, line.project_name].filter(Boolean).join(' · ') || '—'}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">{line.tax_code ?? '—'}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {Number(line.debit) ? money(Number(line.debit)) : '—'}
                  </TableCell>
                  <TableCell className="text-right tabular-nums">
                    {Number(line.credit) ? money(Number(line.credit)) : '—'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>

        <div className="flex flex-wrap items-center justify-end gap-6 text-sm">
          <span>
            Total debit <span className="font-semibold tabular-nums">{money(data.total_debit)}</span>
          </span>
          <span>
            Total credit <span className="font-semibold tabular-nums">{money(data.total_credit)}</span>
          </span>
        </div>
      </CardContent>
    </Card>
  )
}
