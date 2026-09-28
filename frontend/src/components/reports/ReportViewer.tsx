import React from 'react';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton';

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
  /** When set, renders the working CSV/PDF/Print toolbar for this ref. */
  exportTargetRef?: React.RefObject<HTMLElement | null>;
  exportFilename?: string;
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
  exportTargetRef,
  exportFilename,
}) => {
  return (
    <Card className={`p-6 ${className}`}>
      {/* Header Section */}
      <div className="mb-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
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
            <div className="flex flex-wrap items-center gap-2 ml-4">
              {exportTargetRef && (
                <ReportExportToolbar
                  targetRef={exportTargetRef}
                  title={title}
                  subtitle={description}
                  filename={exportFilename ?? `report-${Date.now()}`}
                />
              )}

              {onRefresh && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:refresh-bold-duotone"
                  onClick={onRefresh}
                  disabled={isLoading}
                  loading={isLoading}
                  title="Refresh Report"
                  aria-label="Refresh report"
                />
              )}

              {onPrint && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:printer-bold-duotone"
                  onClick={onPrint}
                  disabled={isLoading}
                  title="Print Report"
                  aria-label="Print report"
                />
              )}

              {onExport && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:download-minimalistic-bold-duotone"
                  onClick={onExport}
                  disabled={isLoading}
                  title="Export Report"
                  aria-label="Export report"
                />
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
            <div className="flex items-center gap-2 text-muted-foreground">
              <Icon icon="eos-icons:loading" className="h-6 w-6 animate-spin text-primary" />
              <span>Loading report data...</span>
            </div>
          </div>
        </div>
      )}

      {/* Content */}
      {!isLoading && !error && (
        <div
          className="report-content"
          ref={exportTargetRef as React.RefObject<HTMLDivElement> | undefined}
        >
          {children}
        </div>
      )}

      {/* Empty State */}
      {!isLoading && !error && !children && (
        <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
          <Icon icon="solar:document-bold-duotone" className="mb-4 h-12 w-12 opacity-30" />
          <p className="text-lg font-medium">No data available</p>
          <p className="mt-1 text-sm">Try adjusting your filters or date range</p>
        </div>
      )}
    </Card>
  );
};

export default ReportViewer;
