import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

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
  onDownloadCSV?: () => void
  onDownloadPDF?: () => void
}

export function ComparisonTable({ currentLabel, compareLabel, rows, formatCurrency, onDownloadCSV, onDownloadPDF }: ComparisonTableProps) {
  return (
    <div className="space-y-4">
      {/* Header with download buttons */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Badge variant="outline" className="text-sm px-3 py-1">
            {currentLabel}
          </Badge>
          <span className="text-muted-foreground">vs</span>
          <Badge variant="outline" className="text-sm px-3 py-1">
            {compareLabel}
          </Badge>
        </div>
        <div className="flex gap-2">
          {onDownloadCSV && (
            <button
              onClick={onDownloadCSV}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              CSV
            </button>
          )}
          {onDownloadPDF && (
            <button
              onClick={onDownloadPDF}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-red-600 border border-red-600 rounded-md hover:bg-red-700 transition-colors"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              PDF
            </button>
          )}
        </div>
      </div>

      {/* Comparison Table */}
      <div className="border rounded-lg overflow-hidden">
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
                      {/* Change indicator arrow */}
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
