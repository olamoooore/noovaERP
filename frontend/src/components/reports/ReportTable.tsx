import React, { useState, useMemo } from 'react';
import { Icon } from '@iconify/react';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Pagination } from '@/components/ui/Pagination';

export interface Column {
  key: string;
  label: string;
  sortable?: boolean;
  filterable?: boolean;
  align?: 'left' | 'center' | 'right';
  format?: (value: any, row: any) => React.ReactNode;
  className?: string;
}

export interface ReportTableProps {
  columns: Column[];
  data: any[];
  showPagination?: boolean;
  showFilters?: boolean;
  showSearch?: boolean;
  pageSize?: number;
  emptyMessage?: string;
  className?: string;
  onRowClick?: (row: any) => void;
}

type SortDirection = 'asc' | 'desc' | null;

interface SortConfig {
  key: string;
  direction: SortDirection;
}

/**
 * ReportTable Component
 * 
 * A flexible table component for displaying report data with built-in
 * sorting, filtering, pagination, and search functionality.
 * 
 * @example
 * ```tsx
 * const columns: Column[] = [
 *   { key: 'name', label: 'Account Name', sortable: true, filterable: true },
 *   { 
 *     key: 'amount', 
 *     label: 'Amount', 
 *     sortable: true, 
 *     align: 'right',
 *     format: (value) => `$${value.toFixed(2)}`
 *   },
 * ];
 * 
 * <ReportTable
 *   columns={columns}
 *   data={reportData}
 *   showPagination
 *   showSearch
 *   pageSize={20}
 * />
 * ```
 */
export const ReportTable: React.FC<ReportTableProps> = ({
  columns,
  data = [],
  showPagination = true,
  showFilters = false,
  showSearch = true,
  pageSize = 10,
  emptyMessage = 'No data available',
  className = '',
  onRowClick,
}) => {
  const [sortConfig, setSortConfig] = useState<SortConfig>({ key: '', direction: null });
  const [searchTerm, setSearchTerm] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage, setItemsPerPage] = useState(pageSize);
  const [columnFilters, setColumnFilters] = useState<Record<string, string>>({});

  // Handle sorting
  const handleSort = (key: string) => {
    const column = columns.find((col) => col.key === key);
    if (!column?.sortable) return;

    let direction: SortDirection = 'asc';
    if (sortConfig.key === key) {
      if (sortConfig.direction === 'asc') {
        direction = 'desc';
      } else if (sortConfig.direction === 'desc') {
        direction = null;
      }
    }

    setSortConfig({ key, direction });
  };

  // Get sort icon
  const getSortIcon = (key: string) => {
    if (sortConfig.key !== key) {
      return <Icon icon="solar:sort-vertical-linear" className="h-4 w-4 text-gray-400" />;
    }
    if (sortConfig.direction === 'asc') {
      return <Icon icon="solar:sort-from-bottom-to-top-bold" className="h-4 w-4 text-primary" />;
    }
    if (sortConfig.direction === 'desc') {
      return <Icon icon="solar:sort-from-top-to-bottom-bold" className="h-4 w-4 text-primary" />;
    }
    return <Icon icon="solar:sort-vertical-linear" className="h-4 w-4 text-gray-400" />;
  };

  // Filter and sort data
  const processedData = useMemo(() => {
    // Ensure data is an array before processing
    if (!Array.isArray(data)) {
      console.warn('ReportTable: data prop is not an array', data);
      return [];
    }
    
    let filtered = [...data];

    // Apply search filter
    if (searchTerm) {
      filtered = filtered.filter((row) =>
        columns.some((col) => {
          const value = row[col.key];
          if (value == null) return false;
          return String(value).toLowerCase().includes(searchTerm.toLowerCase());
        })
      );
    }

    // Apply column filters
    Object.entries(columnFilters).forEach(([key, filterValue]) => {
      if (filterValue) {
        filtered = filtered.filter((row) => {
          const value = row[key];
          if (value == null) return false;
          return String(value).toLowerCase().includes(filterValue.toLowerCase());
        });
      }
    });

    // Apply sorting
    if (sortConfig.key && sortConfig.direction) {
      filtered.sort((a, b) => {
        const aValue = a[sortConfig.key];
        const bValue = b[sortConfig.key];

        if (aValue == null) return 1;
        if (bValue == null) return -1;

        let comparison = 0;
        if (typeof aValue === 'number' && typeof bValue === 'number') {
          comparison = aValue - bValue;
        } else {
          comparison = String(aValue).localeCompare(String(bValue));
        }

        return sortConfig.direction === 'asc' ? comparison : -comparison;
      });
    }

    return filtered;
  }, [data, searchTerm, columnFilters, sortConfig, columns]);

  // Pagination
  const totalPages = Math.ceil(processedData.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedData = showPagination
    ? processedData.slice(startIndex, endIndex)
    : processedData;

  // Reset to first page when data changes
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, columnFilters, sortConfig]);

  // Handle page change
  const handlePageChange = (page: number) => {
    setCurrentPage(Math.max(1, Math.min(page, totalPages)));
  };

  // Handle items per page change
  const handleItemsPerPageChange = (value: string) => {
    setItemsPerPage(Number(value));
    setCurrentPage(1);
  };

  // Handle column filter change
  const handleColumnFilterChange = (key: string, value: string) => {
    setColumnFilters((prev) => ({
      ...prev,
      [key]: value,
    }));
  };

  // Format cell value
  const formatCellValue = (column: Column, row: any) => {
    const value = row[column.key];

    // A missing or non-finite value is rendered as '-' instead of being handed
    // to the formatter: `formatCurrency(undefined)` renders "$NaN" and
    // `value.toFixed()` throws, which used to blank whole report pages when an
    // API renamed or omitted a field.
    if (value == null || (typeof value === 'number' && !Number.isFinite(value))) {
      return '-';
    }

    if (column.format) {
      return column.format(value, row);
    }
    return value;
  };

  return (
    <div className={`space-y-4 ${className}`}>
      {/* Search and Filters */}
      {(showSearch || showFilters) && (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          {/* Search */}
          {showSearch && (
            <div className="relative flex-1 max-w-sm">
              <Icon
                icon="solar:magnifer-bold"
                className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
              />
              <Input
                type="text"
                placeholder="Search..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="pl-10"
              />
            </div>
          )}

          {/* Results count */}
          <div className="text-sm text-gray-600 dark:text-gray-400">
            Showing {paginatedData.length} of {processedData.length} results
            {processedData.length !== data.length && ` (filtered from ${data.length})`}
          </div>
        </div>
      )}

      {/* Column Filters */}
      {showFilters && (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          {columns
            .filter((col) => col.filterable)
            .map((col) => (
              <div key={col.key} className="space-y-1">
                <label className="text-xs font-medium text-gray-700 dark:text-gray-300">
                  {col.label}
                </label>
                <Input
                  type="text"
                  placeholder={`Filter ${col.label.toLowerCase()}...`}
                  value={columnFilters[col.key] || ''}
                  onChange={(e) => handleColumnFilterChange(col.key, e.target.value)}
                  className="h-8 text-sm"
                />
              </div>
            ))}
        </div>
      )}

      {/* Table */}
      <div className="rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              {columns.map((column) => (
                <TableHead
                  key={column.key}
                  className={`${column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left'} ${column.className || ''}`}
                >
                  {column.sortable ? (
                    <button
                      onClick={() => handleSort(column.key)}
                      className={`flex w-full items-center gap-2 hover:text-primary transition-colors font-medium ${
                        column.align === 'right'
                          ? 'justify-end'
                          : column.align === 'center'
                            ? 'justify-center'
                            : 'justify-start'
                      }`}
                    >
                      {column.label}
                      {getSortIcon(column.key)}
                    </button>
                  ) : (
                    <span className="font-medium">{column.label}</span>
                  )}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {paginatedData.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={columns.length}
                  className="h-24 text-center text-gray-500 dark:text-gray-400"
                >
                  <div className="flex flex-col items-center justify-center gap-2">
                    <Icon icon="solar:document-bold" className="h-12 w-12 opacity-50" />
                    <p>{emptyMessage}</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              paginatedData.map((row, index) => (
                <TableRow
                  key={index}
                  onClick={() => onRowClick?.(row)}
                  className={onRowClick ? 'cursor-pointer' : ''}
                >
                  {columns.map((column) => (
                    <TableCell
                      key={column.key}
                      className={`${column.align === 'right' ? 'text-right' : column.align === 'center' ? 'text-center' : 'text-left'} ${column.className || ''}`}
                    >
                      {formatCellValue(column, row)}
                    </TableCell>
                  ))}
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {showPagination && processedData.length > 0 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
          {/* Items per page selector */}
          <div className="flex items-center gap-2">
            <span className="text-sm text-gray-600 dark:text-gray-400">Rows per page:</span>
            <Select value={String(itemsPerPage)} onValueChange={handleItemsPerPageChange}>
              <SelectTrigger className="w-20 h-8">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="10">10</SelectItem>
                <SelectItem value="20">20</SelectItem>
                <SelectItem value="50">50</SelectItem>
                <SelectItem value="100">100</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Pagination controls */}
          <Pagination
            page={currentPage}
            totalPages={totalPages}
            onPageChange={handlePageChange}
            from={startIndex + 1}
            to={Math.min(endIndex, processedData.length)}
            total={processedData.length}
            label="rows"
            pageSize={itemsPerPage}
            onPageSizeChange={(size) => handleItemsPerPageChange(String(size))}
          />
        </div>
      )}
    </div>
  );
};

export default ReportTable;
