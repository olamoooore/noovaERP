/**
 * Interactive Demo of Report Filter Components
 * 
 * This component provides a visual demonstration of all filter components
 * and their various configurations.
 */

import React, { useState } from 'react';
import { ReportFilters } from './ReportFilters';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';

export const ReportFiltersDemo: React.FC = () => {
  const [currentFilters, setCurrentFilters] = useState<ReportFiltersType>({});

  const handleFilterChange = (filters: ReportFiltersType) => {
    setCurrentFilters(filters);
  };

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="space-y-2">
        <h1 className="text-3xl font-bold">Report Filters Demo</h1>
        <p className="text-muted-foreground">
          Interactive demonstration of report filter components
        </p>
      </div>

      <Tabs defaultValue="financial" className="space-y-4">
        <TabsList>
          <TabsTrigger value="financial">Financial Report</TabsTrigger>
          <TabsTrigger value="account">Account Statement</TabsTrigger>
          <TabsTrigger value="sales">Sales Analytics</TabsTrigger>
          <TabsTrigger value="minimal">Minimal</TabsTrigger>
        </TabsList>

        <TabsContent value="financial" className="space-y-4">
          <Card className="p-4">
            <h2 className="text-xl font-semibold mb-4">
              Financial Report Filters
            </h2>
            <p className="text-sm text-muted-foreground mb-4">
              Date range, department, and period comparison
            </p>
            <ReportFilters
              onFilterChange={handleFilterChange}
              showDateRange={true}
              showDepartment={true}
              showComparePeriod={true}
              initialFilters={{
                start_date: '2025-01-01',
                end_date: '2025-01-31',
              }}
            />
          </Card>
        </TabsContent>

        <TabsContent value="account" className="space-y-4">
          <Card className="p-4">
            <h2 className="text-xl font-semibold mb-4">
              Account Statement Filters
            </h2>
            <p className="text-sm text-muted-foreground mb-4">
              Date range and account selection
            </p>
            <ReportFilters
              onFilterChange={handleFilterChange}
              showDateRange={true}
              showAccount={true}
              accountType="asset"
            />
          </Card>
        </TabsContent>

        <TabsContent value="sales" className="space-y-4">
          <Card className="p-4">
            <h2 className="text-xl font-semibold mb-4">
              Sales Analytics Filters
            </h2>
            <p className="text-sm text-muted-foreground mb-4">
              Date range, interval, and auto-apply
            </p>
            <ReportFilters
              onFilterChange={handleFilterChange}
              showDateRange={true}
              showInterval={true}
              autoApply={true}
              initialFilters={{
                interval: 'month',
              }}
            />
          </Card>
        </TabsContent>

        <TabsContent value="minimal" className="space-y-4">
          <Card className="p-4">
            <h2 className="text-xl font-semibold mb-4">Minimal Filters</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Just date range with auto-apply
            </p>
            <ReportFilters
              onFilterChange={handleFilterChange}
              showDateRange={true}
              autoApply={true}
            />
          </Card>
        </TabsContent>
      </Tabs>

      {/* Current Filter State Display */}
      <Card className="p-4">
        <h3 className="text-lg font-semibold mb-2">Current Filter State</h3>
        <pre className="bg-muted p-4 rounded-md overflow-auto text-sm">
          {JSON.stringify(currentFilters, null, 2)}
        </pre>
      </Card>
    </div>
  );
};
