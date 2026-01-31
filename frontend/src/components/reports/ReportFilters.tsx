import React, { useState, useEffect } from 'react';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { DateRangeSelector } from './DateRangeSelector';
import { AccountSelector } from './AccountSelector';
import { DepartmentSelector } from './DepartmentSelector';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Label } from '@/components/ui/label';

interface ReportFiltersProps {
  onFilterChange: (filters: ReportFiltersType) => void;
  initialFilters?: Partial<ReportFiltersType>;
  showDateRange?: boolean;
  showAccount?: boolean;
  showDepartment?: boolean;
  showAccountType?: boolean;
  showComparePeriod?: boolean;
  showInterval?: boolean;
  accountType?: string;
  autoApply?: boolean;
}

export const ReportFilters: React.FC<ReportFiltersProps> = ({
  onFilterChange,
  initialFilters = {},
  showDateRange = true,
  showAccount = false,
  showDepartment = false,
  showAccountType = false,
  showComparePeriod = false,
  showInterval = false,
  accountType,
  autoApply = false,
}) => {
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: initialFilters.start_date || '',
    end_date: initialFilters.end_date || '',
    account_id: initialFilters.account_id,
    department_id: initialFilters.department_id,
    account_type: initialFilters.account_type || accountType,
    compare_period: initialFilters.compare_period || false,
    interval: initialFilters.interval || 'month',
  });

  const [isExpanded, setIsExpanded] = useState(true);

  useEffect(() => {
    if (autoApply) {
      onFilterChange(filters);
    }
  }, [filters, autoApply]);

  const handleStartDateChange = (date: string) => {
    setFilters((prev) => ({ ...prev, start_date: date }));
  };

  const handleEndDateChange = (date: string) => {
    setFilters((prev) => ({ ...prev, end_date: date }));
  };

  const handleAccountChange = (accountId: number | undefined) => {
    setFilters((prev) => ({ ...prev, account_id: accountId }));
  };

  const handleDepartmentChange = (departmentId: number | undefined) => {
    setFilters((prev) => ({ ...prev, department_id: departmentId }));
  };

  const handleAccountTypeChange = (type: string) => {
    setFilters((prev) => ({ ...prev, account_type: type }));
  };

  const handleComparePeriodChange = (value: string) => {
    setFilters((prev) => ({ ...prev, compare_period: value === 'true' }));
  };

  const handleIntervalChange = (interval: string) => {
    setFilters((prev) => ({
      ...prev,
      interval: interval as 'day' | 'week' | 'month' | 'year',
    }));
  };

  const handleApplyFilters = () => {
    onFilterChange(filters);
  };

  const handleResetFilters = () => {
    const resetFilters: ReportFiltersType = {
      start_date: '',
      end_date: '',
      account_id: undefined,
      department_id: undefined,
      account_type: accountType,
      compare_period: false,
      interval: 'month',
    };
    setFilters(resetFilters);
    if (autoApply) {
      onFilterChange(resetFilters);
    }
  };

  return (
    <Card className="p-4">
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Icon icon="solar:filter-bold" className="h-5 w-5 text-primary" />
            <h3 className="text-lg font-semibold">Filters</h3>
          </div>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setIsExpanded(!isExpanded)}
          >
            <Icon
              icon={
                isExpanded
                  ? 'solar:alt-arrow-up-bold'
                  : 'solar:alt-arrow-down-bold'
              }
              className="h-4 w-4"
            />
          </Button>
        </div>

        {/* Filter Content */}
        {isExpanded && (
          <div className="space-y-4">
            {/* Date Range */}
            {showDateRange && (
              <DateRangeSelector
                startDate={filters.start_date || ''}
                endDate={filters.end_date || ''}
                onStartDateChange={handleStartDateChange}
                onEndDateChange={handleEndDateChange}
              />
            )}

            {/* Account Selector */}
            {showAccount && (
              <AccountSelector
                value={filters.account_id}
                onChange={handleAccountChange}
                accountType={filters.account_type}
              />
            )}

            {/* Department Selector */}
            {showDepartment && (
              <DepartmentSelector
                value={filters.department_id}
                onChange={handleDepartmentChange}
              />
            )}

            {/* Account Type Filter */}
            {showAccountType && (
              <div className="space-y-2">
                <Label>Account Type</Label>
                <Select
                  value={filters.account_type}
                  onValueChange={handleAccountTypeChange}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue placeholder="Select account type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem value="asset">Asset</SelectItem>
                    <SelectItem value="liability">Liability</SelectItem>
                    <SelectItem value="equity">Equity</SelectItem>
                    <SelectItem value="revenue">Revenue</SelectItem>
                    <SelectItem value="expense">Expense</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}

            {/* Compare Period */}
            {showComparePeriod && (
              <div className="space-y-2">
                <Label>Compare with Previous Period</Label>
                <Select
                  value={filters.compare_period ? 'true' : 'false'}
                  onValueChange={handleComparePeriodChange}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="false">No</SelectItem>
                    <SelectItem value="true">Yes</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}

            {/* Interval */}
            {showInterval && (
              <div className="space-y-2">
                <Label>Interval</Label>
                <Select
                  value={filters.interval}
                  onValueChange={handleIntervalChange}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="day">Daily</SelectItem>
                    <SelectItem value="week">Weekly</SelectItem>
                    <SelectItem value="month">Monthly</SelectItem>
                    <SelectItem value="year">Yearly</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}

            {/* Action Buttons */}
            {!autoApply && (
              <div className="flex gap-2 pt-2">
                <Button onClick={handleApplyFilters} className="flex-1">
                  <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                  Apply Filters
                </Button>
                <Button
                  variant="outline"
                  onClick={handleResetFilters}
                  className="flex-1"
                >
                  <Icon icon="solar:restart-bold" className="mr-2 h-4 w-4" />
                  Reset
                </Button>
              </div>
            )}
          </div>
        )}
      </div>
    </Card>
  );
};
