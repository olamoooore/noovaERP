import React, { useState } from 'react';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface ImportButtonProps {
  importType: 'chart-of-accounts' | 'journal-entries' | 'vendor-bills' | 'payments' | 'customers' | 'vendors' | 'products';
  onImportComplete?: () => void;
  variant?: 'default' | 'outline' | 'ghost';
  size?: 'default' | 'sm' | 'lg';
}

const LEGACY_IMPORT_TYPES = new Set(['journal-entries', 'vendor-bills']);

export const ImportButton: React.FC<ImportButtonProps> = ({
  importType,
  onImportComplete,
  variant = 'outline',
  size = 'default',
}) => {
  const [open, setOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [loading, setLoading] = useState(false);
  const [progress, setProgress] = useState<{
    current: number;
    total: number;
    message: string;
  } | null>(null);
  const { toast } = useToast();

  const importLabels = {
    'chart-of-accounts': 'Chart of Accounts',
    'journal-entries': 'Journal Entries',
    'vendor-bills': 'Vendor Bills',
    'payments': 'Vendor Payments',
    'customers': 'Customers',
    'vendors': 'Vendors',
    'products': 'Products',
  };

  const isLegacyImportType = LEGACY_IMPORT_TYPES.has(importType);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const selectedFile = e.target.files[0];
      
      // Determine expected file type based on import type
      const usesCSV = ['customers', 'vendors', 'products'].includes(importType);
      const usesExcel = ['chart-of-accounts', 'journal-entries', 'vendor-bills', 'payments'].includes(importType);
      
      // Validate file type
      if (usesCSV) {
        if (!selectedFile.name.endsWith('.csv') && !selectedFile.name.endsWith('.txt')) {
          toast({
            title: 'Invalid file type',
            description: 'Please select a CSV file (.csv)',
            variant: 'destructive',
          });
          return;
        }
      } else if (usesExcel) {
        if (!selectedFile.name.endsWith('.xlsx') && !selectedFile.name.endsWith('.xls')) {
          toast({
            title: 'Invalid file type',
            description: 'Please select an Excel file (.xlsx or .xls)',
            variant: 'destructive',
          });
          return;
        }
      }

      // Validate file size (max 10MB)
      if (selectedFile.size > 10 * 1024 * 1024) {
        toast({
          title: 'File too large',
          description: 'Please select a file smaller than 10MB',
          variant: 'destructive',
        });
        return;
      }

      setFile(selectedFile);
    }
  };

  const handleImport = async () => {
    if (isLegacyImportType) {
      toast({
        title: 'Import unavailable',
        description: `${importLabels[importType]} import has moved off this deprecated endpoint. Use the migration workbench flow instead.`,
        variant: 'destructive',
      });
      return;
    }

    if (!file) {
      toast({
        title: 'No file selected',
        description: 'Please select an Excel file to import',
        variant: 'destructive',
      });
      return;
    }

    setLoading(true);
    setProgress({ current: 0, total: 100, message: 'Uploading file...' });

    const formData = new FormData();
    formData.append('file', file);
    
    // Determine the correct endpoint based on import type
    let endpoint = '/import';
    
    // For specific entity imports, use their dedicated endpoints
    if (importType === 'products') {
      endpoint = '/products/import';
    } else if (importType === 'customers') {
      endpoint = '/accounting/customers/import';
    } else if (importType === 'vendors') {
      endpoint = '/accounting/vendors/import';
    } else {
      // For general imports (chart-of-accounts, journal-entries, vendor-bills, payments)
      formData.append('type', importType);
    }

    try {
      const response = await api.post(endpoint, formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
        onUploadProgress: (progressEvent) => {
          if (progressEvent.total) {
            const percentCompleted = Math.round(
              (progressEvent.loaded * 100) / progressEvent.total
            );
            setProgress({
              current: percentCompleted,
              total: 100,
              message: 'Uploading file...',
            });
          }
        },
      });

      // Simulate processing progress
      setProgress({ current: 50, total: 100, message: 'Processing data...' });
      
      // Wait a bit to show processing message
      await new Promise(resolve => setTimeout(resolve, 500));

      setProgress({ current: 100, total: 100, message: 'Import complete!' });

      // Get the count from response (different fields for different endpoints)
      const count = response.data.created || response.data.count || 0;
      const errors = response.data.errors || [];

      toast({
        title: 'Import successful',
        description: errors.length > 0 
          ? `Successfully imported ${count} records with ${errors.length} errors`
          : `Successfully imported ${count} records`,
      });

      // Reset state
      setFile(null);
      setOpen(false);
      setProgress(null);

      // Call callback if provided
      if (onImportComplete) {
        onImportComplete();
      }
    } catch (error: any) {
      console.error('Import error:', error);
      
      const errorMessage = error.response?.data?.message || 
                          error.response?.data?.error ||
                          'Failed to import data. Please check the file format and try again.';
      
      toast({
        title: 'Import failed',
        description: errorMessage,
        variant: 'destructive',
      });

      setProgress(null);
    } finally {
      setLoading(false);
    }
  };

  const handleClose = () => {
    if (!loading) {
      setOpen(false);
      setFile(null);
      setProgress(null);
    }
  };

  return (
    <>
      <Button variant={variant} size={size} onClick={() => setOpen(true)}>
        <Icon icon="solar:import-bold-duotone" className="h-4 w-4 mr-2" />
        Import
      </Button>

      <Dialog open={open} onOpenChange={handleClose}>
        <DialogContent className="max-w-md" enableFullscreen>
          <DialogHeader>
            <DialogTitle>Import {importLabels[importType]}</DialogTitle>
            <DialogDescription>
              {isLegacyImportType
                ? `${importLabels[importType]} import is no longer available from this deprecated endpoint.`
                : `Upload an Excel file to import ${importLabels[importType].toLowerCase()} into the system`}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {isLegacyImportType && (
              <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                This legacy import type was removed from the old import API. Use the migration workbench flow for this data.
              </div>
            )}

            {/* File Upload */}
            <div className="space-y-2">
              <Label htmlFor="file">
                {['customers', 'vendors', 'products'].includes(importType) ? 'CSV File' : 'Excel File'}
              </Label>
              <div className="flex items-center gap-2">
                <input
                  id="file"
                  type="file"
                  accept={['customers', 'vendors', 'products'].includes(importType) ? '.csv,.txt' : '.xlsx,.xls'}
                  onChange={handleFileChange}
                  disabled={loading || isLegacyImportType}
                  className="flex-1 text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 file:cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                />
              </div>
              {file && (
                <p className="text-xs text-muted-foreground">
                  Selected: {file.name} ({(file.size / 1024).toFixed(2)} KB)
                </p>
              )}
            </div>

            {/* Progress Bar */}
            {progress && (
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">{progress.message}</span>
                  <span className="font-medium">{progress.current}%</span>
                </div>
                <div className="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-primary transition-all duration-300"
                    style={{ width: `${progress.current}%` }}
                  />
                </div>
              </div>
            )}

            {/* Info Box */}
            <div className="bg-blue-50 dark:bg-blue-950/20 p-4 rounded-lg">
              <div className="flex items-start gap-3">
                <Icon
                  icon="solar:info-circle-bold-duotone"
                  className="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0"
                />
                <div className="text-sm text-blue-700 dark:text-blue-300">
                  <p className="font-medium mb-1">File Requirements</p>
                  <ul className="list-disc list-inside space-y-1 text-xs">
                    {['customers', 'vendors', 'products'].includes(importType) ? (
                      <>
                        <li>CSV format (.csv)</li>
                        <li>Maximum file size: 10MB</li>
                        <li>First row must contain column headers</li>
                        <li>Required column: name</li>
                      </>
                    ) : (
                      <>
                        <li>Excel format (.xlsx or .xls)</li>
                        <li>Maximum file size: 10MB</li>
                        <li>Follow the standard template format</li>
                        <li>Ensure all required columns are present</li>
                      </>
                    )}
                  </ul>
                </div>
              </div>
            </div>

            {/* Download Template Link */}
            <div className="text-center">
              <Button
                variant="link"
                size="sm"
                onClick={() => {
                  // TODO: Implement template download
                  toast({
                    title: 'Coming soon',
                    description: 'Template download will be available soon',
                  });
                }}
              >
                <Icon icon="solar:download-bold-duotone" className="h-4 w-4 mr-2" />
                Download Template
              </Button>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={handleClose} disabled={loading}>
              Cancel
            </Button>
            <Button onClick={handleImport} disabled={!file || loading || isLegacyImportType}>
              {loading ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Importing...
                </>
              ) : (
                <>
                  <Icon icon="solar:upload-bold-duotone" className="w-4 h-4 mr-2" />
                  Import
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default ImportButton;
