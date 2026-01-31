import React from 'react';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';

interface ReportViewerProps {
  title: string;
  description?: string;
  isLoading?: boolean;
  error?: string | null;
  children: React.ReactNode;
  onRefresh?: () => void;
  onExport?: () => void;
  onPrint?: () => void;
  showActions?: boolean;
  className?: string;
}

/**
 * ReportViewer Component
 * 
 * A container component for displaying reports with consistent styling,
 * loading states, error handling, and action buttons.
 * 
 * @example
 * ```tsx
 * <ReportViewer
 *   title="Profit & Loss Statement"
 *   description="For the period Jan 1 - Dec 31, 2024"
 *   isLoading={isLoading}
 *   error={error}
 *   onRefresh={handleRefresh}
 *   onExport={handleExport}
 *   onPrint={handlePrint}
 * >
 *   <ReportTable data={reportData} />
 * </ReportViewer>
 * ```
 */
export const ReportViewer: React.FC<ReportViewerProps> = ({
  title,
  description,
  isLoading = false,
  error = null,
  children,
  onRefresh,
  onExport,
  onPrint,
  showActions = true,
  className = '',
}) => {
  return (
    <Card className={`p-6 ${className}`}>
      {/* Header Section */}
      <div className="mb-6">
        <div className="flex items-start justify-between">
          <div className="flex-1">
            <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
              {title}
            </h2>
            {description && (
              <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {description}
              </p>
            )}
          </div>

          {/* Action Buttons */}
          {showActions && (
            <div className="flex gap-2 ml-4">
              {onRefresh && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={onRefresh}
                  disabled={isLoading}
                  title="Refresh Report"
                >
                  <Icon
                    icon={isLoading ? 'solar:refresh-bold' : 'solar:refresh-bold'}
                    className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`}
                  />
                </Button>
              )}

              {onPrint && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={onPrint}
                  disabled={isLoading}
                  title="Print Report"
                >
                  <Icon icon="solar:printer-bold" className="h-4 w-4" />
                </Button>
              )}

              {onExport && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={onExport}
                  disabled={isLoading}
                  title="Export Report"
                >
                  <Icon icon="solar:download-bold" className="h-4 w-4" />
                </Button>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Error State */}
      {error && !isLoading && (
        <Alert variant="destructive" className="mb-6">
          <Icon icon="solar:danger-circle-bold" className="h-4 w-4" />
          <AlertTitle>Error Loading Report</AlertTitle>
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {/* Loading State */}
      {isLoading && (
        <div className="space-y-4">
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
          <Skeleton className="h-8 w-full" />
          <div className="flex justify-center py-8">
            <div className="flex items-center gap-2 text-gray-600 dark:text-gray-400">
              <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
              <span>Loading report data...</span>
            </div>
          </div>
        </div>
      )}

      {/* Content */}
      {!isLoading && !error && (
        <div className="report-content">
          {children}
        </div>
      )}

      {/* Empty State */}
      {!isLoading && !error && !children && (
        <div className="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400">
          <Icon icon="solar:document-bold" className="h-16 w-16 mb-4 opacity-50" />
          <p className="text-lg font-medium">No data available</p>
          <p className="text-sm mt-1">Try adjusting your filters or date range</p>
        </div>
      )}
    </Card>
  );
};

export default ReportViewer;
