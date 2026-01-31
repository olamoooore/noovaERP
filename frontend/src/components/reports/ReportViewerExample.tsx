import React, { useState } from 'react';
import { ReportViewer } from './ReportViewer';
import { Button } from '@/components/ui/button';

/**
 * Example usage of the ReportViewer component
 * This demonstrates the different states and features of the component
 */
export const ReportViewerExample: React.FC = () => {
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [hasData, setHasData] = useState(true);

  const handleRefresh = () => {
    setIsLoading(true);
    setError(null);
    
    // Simulate API call
    setTimeout(() => {
      setIsLoading(false);
    }, 2000);
  };

  const handleExport = () => {
    alert('Export functionality would be triggered here');
  };

  const handlePrint = () => {
    window.print();
  };

  const simulateError = () => {
    setError('Failed to load report data. Please try again.');
    setHasData(false);
  };

  const clearError = () => {
    setError(null);
    setHasData(true);
  };

  return (
    <div className="space-y-6 p-6">
      <h1 className="text-3xl font-bold">ReportViewer Component Examples</h1>

      {/* Control Panel */}
      <div className="flex gap-2 p-4 bg-gray-100 dark:bg-gray-800 rounded-lg">
        <Button onClick={() => setIsLoading(!isLoading)} variant="outline">
          Toggle Loading
        </Button>
        <Button onClick={simulateError} variant="outline">
          Simulate Error
        </Button>
        <Button onClick={clearError} variant="outline">
          Clear Error
        </Button>
        <Button onClick={() => setHasData(!hasData)} variant="outline">
          Toggle Data
        </Button>
      </div>

      {/* Example 1: Normal State with Data */}
      <ReportViewer
        title="Profit & Loss Statement"
        description="For the period January 1 - December 31, 2024"
        isLoading={isLoading}
        error={error}
        onRefresh={handleRefresh}
        onExport={handleExport}
        onPrint={handlePrint}
      >
        {hasData && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="p-4 bg-green-50 dark:bg-green-950/20 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Total Revenue</p>
                <p className="text-2xl font-bold text-green-600">$125,430.00</p>
              </div>
              <div className="p-4 bg-red-50 dark:bg-red-950/20 rounded-lg">
                <p className="text-sm text-gray-600 dark:text-gray-400">Total Expenses</p>
                <p className="text-2xl font-bold text-red-600">$87,250.00</p>
              </div>
            </div>
            
            <div className="p-4 bg-blue-50 dark:bg-blue-950/20 rounded-lg">
              <p className="text-sm text-gray-600 dark:text-gray-400">Net Profit</p>
              <p className="text-2xl font-bold text-blue-600">$38,180.00</p>
            </div>

            <table className="w-full border-collapse">
              <thead>
                <tr className="border-b">
                  <th className="text-left p-2">Account</th>
                  <th className="text-right p-2">Amount</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-b">
                  <td className="p-2">Sales Revenue</td>
                  <td className="text-right p-2">$125,430.00</td>
                </tr>
                <tr className="border-b">
                  <td className="p-2">Cost of Goods Sold</td>
                  <td className="text-right p-2">$45,000.00</td>
                </tr>
                <tr className="border-b">
                  <td className="p-2">Operating Expenses</td>
                  <td className="text-right p-2">$42,250.00</td>
                </tr>
              </tbody>
            </table>
          </div>
        )}
      </ReportViewer>

      {/* Example 2: Without Action Buttons */}
      <ReportViewer
        title="Balance Sheet"
        description="As of December 31, 2024"
        showActions={false}
      >
        <div className="text-center py-8 text-gray-600 dark:text-gray-400">
          This report viewer has no action buttons
        </div>
      </ReportViewer>

      {/* Example 3: Minimal Configuration */}
      <ReportViewer title="Simple Report">
        <div className="p-4">
          <p>This is a minimal report viewer with just a title and content.</p>
        </div>
      </ReportViewer>
    </div>
  );
};

export default ReportViewerExample;
