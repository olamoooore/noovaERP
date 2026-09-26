import React, { useState, useEffect } from 'react';
import { Icon } from '@iconify/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AccountSelector } from './AccountSelector';
import { DepartmentSelector } from './DepartmentSelector';
import { TagFilterSelect } from '@/components/tags/TagFilterSelect';
import type { ReportFilters as ReportFiltersType } from '@/types/reports';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface CompactReportFiltersProps {
  onFilterChange: (filters: ReportFiltersType) => void;
  initialFilters?: Partial<ReportFiltersType>;
  showDateRange?: boolean;
  showAccount?: boolean;
  showDepartment?: boolean;
  showAccountType?: boolean;
  showComparePeriod?: boolean;
  showInterval?: boolean;
  accountType?: string;
  /**
   * When true the filters apply automatically on every change (default true).
   * When false the user must click the Apply button.
   */
  autoApply?: boolean;
  /** Show a quick "Refresh" button to re-trigger the report query. */
  showRefresh?: boolean;
  onRefresh?: () => void;
  /** Show the document-tag filter (restricts the report to tagged documents). */
  showTag?: boolean;
  /** Label for the tag filter, e.g. "Tagged orders". */
  tagLabel?: string;
  /**
   * Render as a plain compact pill row directly under the page header
   * (no bordered card shell). Used by the report pages that keep their
   * filters outside the header action row.
   */
  plain?: boolean;
}

export const CompactReportFilters: React.FC<CompactReportFiltersProps> = ({
  onFilterChange,
  initialFilters = {},
  showDateRange = true,
  showAccount = false,
  showDepartment = false,
  showAccountType = false,
  showComparePeriod = false,
  showInterval = false,
  accountType,
  autoApply = true,
  showRefresh = false,
  onRefresh,
  showTag = false,
  tagLabel = 'All Tags',
  plain = false,
}) => {
  const [filters, setFilters] = useState<ReportFiltersType>({
    start_date: initialFilters.start_date || '',
    end_date: initialFilters.end_date || '',
    account_id: initialFilters.account_id,
    department_id: initialFilters.department_id,
    account_type: initialFilters.account_type || accountType,
    compare_period: initialFilters.compare_period || false,
    interval: initialFilters.interval || 'month',
    tag: initialFilters.tag,
  });

  useEffect(() => {
    if (autoApply) {
      onFilterChange(filters);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, autoApply]);

  const update = <K extends keyof ReportFiltersType>(key: K, value: ReportFiltersType[K]) => {
    setFilters((prev) => ({ ...prev, [key]: value }));
  };

  return (
    <div className={plain
      ? 'flex flex-wrap items-center gap-2'
      : 'flex flex-wrap items-center gap-2 rounded-md border bg-card px-3 py-2'}
    >
      {/* Header / label */}
      {!plain && (
        <>
          <div className="flex items-center gap-1.5 text-muted-foreground">
            <Icon icon="solar:filter-bold" className="h-3.5 w-3.5" />
            <span className="text-xs font-medium">Filters</span>
          </div>

          <div className="h-4 w-px bg-border" />
        </>
      )}

      {/* Date range */}
      {showDateRange && (
        <div className="flex items-center gap-1">
          <Input
            type="date"
            value={filters.start_date || ''}
            onChange={(e) => update('start_date', e.target.value)}
            className={plain ? 'h-8 w-[140px] rounded-full px-3 text-xs' : 'h-8 w-[140px] text-xs'}
          />
          <span className="text-muted-foreground text-xs">→</span>
          <Input
            type="date"
            value={filters.end_date || ''}
            onChange={(e) => update('end_date', e.target.value)}
            className={plain ? 'h-8 w-[140px] rounded-full px-3 text-xs' : 'h-8 w-[140px] text-xs'}
          />
        </div>
      )}

      {/* Account */}
      {showAccount && (
        <div className="min-w-[180px]">
          <AccountSelector
            value={filters.account_id}
            onChange={(id) => update('account_id', id)}
            accountType={filters.account_type}
          />
        </div>
      )}

      {/* Department */}
      {showDepartment && (
        <div className="min-w-[160px]">
          <DepartmentSelector
            value={filters.department_id}
            onChange={(id) => update('department_id', id)}
          />
        </div>
      )}

      {/* Account type */}
      {showAccountType && (
        <Select
          value={filters.account_type ?? 'all'}
          onValueChange={(v) => update('account_type', v === 'all' ? undefined : v)}
        >
          <SelectTrigger className="h-8 w-[140px] text-xs">
            <SelectValue placeholder="Account type" />
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
      )}

      {/* Document tag */}
      {showTag && (
        <TagFilterSelect
          className="h-8 w-[160px] text-xs"
          value={filters.tag || ''}
          onChange={(value) => update('tag', value || undefined)}
          placeholder={tagLabel}
        />
      )}

      {/* Compare period */}
      {showComparePeriod && (
        <Select
          value={filters.compare_period ? 'true' : 'false'}
          onValueChange={(v) => update('compare_period', v === 'true')}
        >
          <SelectTrigger className="h-8 w-[120px] text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="false">No compare</SelectItem>
            <SelectItem value="true">Compare prev</SelectItem>
          </SelectContent>
        </Select>
      )}

      {/* Interval */}
      {showInterval && (
        <Select
          value={filters.interval}
          onValueChange={(v) => update('interval', v as 'day' | 'week' | 'month' | 'year')}
        >
          <SelectTrigger className="h-8 w-[110px] text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="day">Daily</SelectItem>
            <SelectItem value="week">Weekly</SelectItem>
            <SelectItem value="month">Monthly</SelectItem>
            <SelectItem value="year">Yearly</SelectItem>
          </SelectContent>
        </Select>
      )}

      {/* Right-side action buttons */}
      <div className="ml-auto flex items-center gap-1.5">
        {showRefresh && onRefresh && (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-xs"
            onClick={onRefresh}
          >
            <Icon icon="solar:refresh-bold" className="h-3.5 w-3.5" />
          </Button>
        )}
        {!autoApply && (
          <Button
            variant="default"
            size="sm"
            className="h-7 px-3 text-xs"
            onClick={() => onFilterChange(filters)}
          >
            Apply
          </Button>
        )}
      </div>
    </div>
  );
};

export default CompactReportFilters;
