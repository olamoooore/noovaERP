/**
 * Enhanced Table Component
 * Table component with loading states and skeleton screens
 */

import * as React from "react"
import { cn } from "@/lib/utils"
import { TableSkeleton } from "./skeleton"
import {
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
} from "./table"

export interface EnhancedTableProps extends React.HTMLAttributes<HTMLDivElement> {
  loading?: boolean;
  loadingRows?: number;
  loadingColumns?: number;
  children?: React.ReactNode;
}

const EnhancedTable = React.forwardRef<HTMLDivElement, EnhancedTableProps>(
  ({ className, loading, loadingRows = 5, loadingColumns = 5, children, ...props }, ref) => {
    if (loading) {
      return (
        <div ref={ref} className={cn("relative w-full", className)} {...props}>
          <TableSkeleton rows={loadingRows} columns={loadingColumns} />
        </div>
      )
    }

    return (
      <div ref={ref} className={cn("relative w-full", className)} {...props}>
        <Table>
          {children}
        </Table>
      </div>
    )
  }
)

EnhancedTable.displayName = "EnhancedTable"

// Enhanced Table Row with loading state
export interface EnhancedTableRowProps extends React.HTMLAttributes<HTMLTableRowElement> {
  loading?: boolean;
  columns?: number;
}

const EnhancedTableRow = React.forwardRef<HTMLTableRowElement, EnhancedTableRowProps>(
  ({ className, loading, columns = 5, children, ...props }, ref) => {
    if (loading) {
      return (
        <TableRow ref={ref} className={cn(className)} {...props}>
          {Array.from({ length: columns }).map((_, index) => (
            <TableCell key={index}>
              <div className="animate-pulse bg-gray-200 h-4 rounded" />
            </TableCell>
          ))}
        </TableRow>
      )
    }

    return (
      <TableRow ref={ref} className={cn(className)} {...props}>
        {children}
      </TableRow>
    )
  }
)

EnhancedTableRow.displayName = "EnhancedTableRow"

export {
  EnhancedTable,
  EnhancedTableRow,
  // Re-export original table components
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
}