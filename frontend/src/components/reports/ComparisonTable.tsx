import { useRef } from 'react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar'

export interface ComparisonRow {
  label: string
  current: number
  compare: number
  bold?: boolean
  indent?: boolean
  sectionHeader?: boolean
}

interface ComparisonTableProps {
  currentLabel: string
  compareLabel: string
  rows: ComparisonRow[]
  formatCurrency: (amount: number) => string
  title?: string
  companyName?: string
  asOfDate?: string
  compareDate?: string
  filename?: string
}

export function ComparisonTable({
  currentLabel,
  compareLabel,
  rows,
  formatCurrency,
  title = 'Financial Report',
  companyName,
  asOfDate,
  compareDate,
  filename = 'report-comparison'
}: ComparisonTableProps) {
  const tableRef = useRef<HTMLDivElement>(null)

  // Explicit export rows so CSV/PDF keep the comparison columns (Change, %)
  // even though the toolbar otherwise reads the rendered table.
  const exportRows: string[][] = [
    ['Account', currentLabel, compareLabel, 'Change', 'Change %'],
    ...rows.map((row) => {
      const change = row.current - row.compare
      const changePct = row.compare !== 0 ? ((change / Math.abs(row.compare)) * 100) : null

      return [
        row.label,
        formatCurrency(row.current),
        formatCurrency(row.compare),
        change !== 0 ? formatCurrency(change) : '—',
        changePct !== null ? `${changePct.toFixed(1)}%` : '—',
      ]
    }),
  ]

  return (
    <div className="space-y-4">
      {/* Header: period pills + the same export toolbar used on report cards */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Badge variant="outline" className="px-3 py-1 text-sm">
            {currentLabel}
          </Badge>
          <span className="text-muted-foreground">vs</span>
          <Badge variant="outline" className="px-3 py-1 text-sm">
            {compareLabel}
          </Badge>
        </div>
        <ReportExportToolbar
          targetRef={tableRef}
          rows={exportRows}
          title={title}
          subtitle={`${currentLabel} vs ${compareLabel}`}
          companyName={companyName}
          asOfDate={asOfDate}
          compareDate={compareDate}
          filename={filename}
        />
      </div>

      {/* Comparison Table */}
      <div ref={tableRef} className="border rounded-lg overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="bg-gray-50">
              <TableHead className="w-2/5 font-semibold text-gray-900">Account</TableHead>
              <TableHead className="text-right w-1/5 font-semibold text-gray-900">{currentLabel}</TableHead>
              <TableHead className="text-right w-1/5 font-semibold text-gray-900">{compareLabel}</TableHead>
              <TableHead className="text-right w-1/5 font-semibold text-gray-900">Change</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row, i) => {
              const change = row.current - row.compare
              const changePct = row.compare !== 0 ? ((change / Math.abs(row.compare)) * 100) : null

              return (
                <TableRow
                  key={i}
                  className={cn(
                    'transition-colors',
                    row.sectionHeader && 'bg-blue-50/80 border-l-4 border-l-blue-500',
                    row.bold && 'font-bold bg-gray-50/80 border-t-2 border-gray-300',
                    !row.bold && !row.sectionHeader && 'hover:bg-gray-50/50'
                  )}
                >
                  <TableCell className={cn(
                    row.sectionHeader ? 'font-bold text-base text-blue-900 py-3' : 'py-2.5',
                    row.indent && 'pl-8',
                    row.bold && 'font-bold text-base'
                  )}>
                    <div className="flex items-center gap-2">
                      {row.sectionHeader && (
                        <span className="w-2 h-2 rounded-full bg-blue-500" />
                      )}
                      {row.label}
                    </div>
                  </TableCell>
                  <TableCell className={cn(
                    'text-right font-mono tabular-nums',
                    row.sectionHeader ? 'text-base font-bold text-blue-900' : row.bold ? 'font-bold text-base' : 'text-sm'
                  )}>
                    {formatCurrency(row.current)}
                  </TableCell>
                  <TableCell className={cn(
                    'text-right font-mono tabular-nums',
                    row.sectionHeader ? 'text-base font-bold text-blue-900' : row.bold ? 'font-bold text-base' : 'text-sm'
                  )}>
                    {formatCurrency(row.compare)}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      {change !== 0 && (
                        <span className={cn(
                          'inline-flex items-center justify-center w-5 h-5 rounded-full',
                          change > 0 ? 'bg-green-100' : 'bg-red-100'
                        )}>
                          {change > 0 ? (
                            <svg className="w-3 h-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 15l7-7 7 7" />
                            </svg>
                          ) : (
                            <svg className="w-3 h-3 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M19 9l-7 7-7-7" />
                            </svg>
                          )}
                        </span>
                      )}
                      <span className={cn(
                        'font-mono tabular-nums text-sm font-medium',
                        change > 0 ? 'text-green-600' : change < 0 ? 'text-red-600' : 'text-gray-400'
                      )}>
                        {change !== 0 && (
                          <>
                            {change > 0 ? '+' : ''}{formatCurrency(change)}
                            {changePct !== null && (
                              <span className="ml-1 text-xs opacity-75">
                                ({changePct > 0 ? '+' : ''}{changePct.toFixed(1)}%)
                              </span>
                            )}
                          </>
                        )}
                        {change === 0 && '—'}
                      </span>
                    </div>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}
