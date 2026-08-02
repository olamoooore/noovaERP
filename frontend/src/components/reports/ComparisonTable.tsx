import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
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
}

export function ComparisonTable({ currentLabel, compareLabel, rows, formatCurrency }: ComparisonTableProps) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-2/5"></TableHead>
          <TableHead className="text-right w-1/5">{currentLabel}</TableHead>
          <TableHead className="text-right w-1/5">{compareLabel}</TableHead>
          <TableHead className="text-right w-1/5">Change</TableHead>
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
                row.bold && 'font-bold border-t-2',
                row.sectionHeader && 'bg-primary/10',
                !row.bold && !row.sectionHeader && 'hover:bg-gray-50'
              )}
            >
              <TableCell className={cn(
                row.sectionHeader ? 'font-bold text-lg py-4' : 'py-2',
                row.indent && 'pl-8'
              )}>
                {row.label}
              </TableCell>
              <TableCell className={cn(
                'text-right font-mono',
                row.sectionHeader ? 'text-lg font-bold' : row.bold ? 'font-bold' : ''
              )}>
                {formatCurrency(row.current)}
              </TableCell>
              <TableCell className={cn(
                'text-right font-mono',
                row.sectionHeader ? 'text-lg font-bold' : row.bold ? 'font-bold' : ''
              )}>
                {formatCurrency(row.compare)}
              </TableCell>
              <TableCell className="text-right font-mono">
                <span className={cn(
                  'text-sm font-medium',
                  change > 0 ? 'text-green-600' : change < 0 ? 'text-red-600' : 'text-muted-foreground'
                )}>
                  {change !== 0 && (
                    <>
                      {change > 0 ? '+' : ''}{formatCurrency(change)}
                      {changePct !== null && (
                        <span className="ml-1 text-xs">
                          ({changePct > 0 ? '+' : ''}{changePct.toFixed(1)}%)
                        </span>
                      )}
                    </>
                  )}
                  {change === 0 && '—'}
                </span>
              </TableCell>
            </TableRow>
          )
        })}
      </TableBody>
    </Table>
  )
}
