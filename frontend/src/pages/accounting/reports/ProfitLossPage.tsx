import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import api from '@/lib/api';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import { ComparisonTable } from '@/components/reports/ComparisonTable';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
import {
  type FiscalYearOption,
  type FiscalYearSettings,
  fiscalYearForRange,
  fiscalYearOptions,
  formatFiscalYearOption,
  priorFiscalYear,
} from '@/lib/fiscalYear';

export default function ProfitLossPage() {
  const navigate = useNavigate();
  const [startDate, setStartDate] = useState('');
  const [endDate, setEndDate] = useState('');
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareStartDate, setCompareStartDate] = useState('');
  const [compareEndDate, setCompareEndDate] = useState('');
  const [collapsedRows, setCollapsedRows] = useState<Set<string>>(new Set());
  const userEditedDates = useRef(false);
  const reportRef = useRef<HTMLDivElement>(null);

  // Fiscal-year settings drive the default reporting period (e.g. 1 Aug –
  // 31 Jul) instead of the calendar year.
  const { data: fiscalSettings } = useQuery({
    queryKey: ['fiscal-year'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/fiscal-year');
      return response.data.data as FiscalYearSettings;
    },
    staleTime: 5 * 60 * 1000,
  });

  const fyOptions: FiscalYearOption[] = useMemo(
    () =>
      fiscalSettings
        ? fiscalYearOptions(
            fiscalSettings.fiscal_year_start_month ?? 1,
            fiscalSettings.fiscal_year_start_day ?? 1,
          )
        : [],
    [fiscalSettings],
  );

  // Default to the fiscal year containing today until the user edits dates.
  useEffect(() => {
    if (!userEditedDates.current && fyOptions.length > 0 && (!startDate || !endDate)) {
      setStartDate(fyOptions[0].start);
      setEndDate(fyOptions[0].end);
    }
  }, [fyOptions, startDate, endDate]);

  const selectedFiscalYear = fiscalYearForRange(fyOptions, startDate, endDate);
  const selectedCompareFiscalYear = fiscalYearForRange(fyOptions, compareStartDate, compareEndDate);

  const applyFiscalYear = (fy: FiscalYearOption) => {
    userEditedDates.current = true;
    setStartDate(fy.start);
    setEndDate(fy.end);
  };

  const applyCompareFiscalYear = (fy: FiscalYearOption) => {
    setCompareStartDate(fy.start);
    setCompareEndDate(fy.end);
  };

  const toggleRow = (id: string) => {
    setCollapsedRows((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const sectionLabel = (id: string, label: string) => (
    <button type="button" className="flex items-center gap-2" onClick={() => toggleRow(id)}>
      <Icon icon={collapsedRows.has(id) ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'} className="w-4 h-4" />
      {label}
    </button>
  );

  // Fetch profit & loss
  const { data, isLoading, error } = useQuery({
    queryKey: ['profit-loss', startDate, endDate, compareEnabled, compareStartDate, compareEndDate],
    queryFn: () => reportApi.getProfitLoss({
      start_date: startDate,
      end_date: endDate,
      ...(compareEnabled && compareStartDate && compareEndDate ? {
        compare_start_date: compareStartDate,
        compare_end_date: compareEndDate,
      } : {}),
    }),
    enabled: !!startDate && !!endDate,
  });

  // reportApi.getProfitLoss() already returns response.data.data
  const profitLoss = data;

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Profit & Loss Statement</h1>
          <p className="text-muted-foreground mt-1">
            Show company's financial performance
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
            Back to Accounting
          </HeaderActionButton>
        </div>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-2">
        {fyOptions.length > 0 && (
          <Select
            value={selectedFiscalYear?.label ?? 'custom'}
            onValueChange={(value) => {
              const fy = fyOptions.find((option) => option.label === value);
              if (fy) applyFiscalYear(fy);
            }}
          >
            <SelectTrigger className="h-8 w-64 rounded-full px-3 text-xs" aria-label="Fiscal year">
              <SelectValue placeholder="Fiscal year" />
            </SelectTrigger>
            <SelectContent>
              {fyOptions.map((option) => (
                <SelectItem key={option.label} value={option.label}>
                  {formatFiscalYearOption(option)}
                </SelectItem>
              ))}
              <SelectItem value="custom">Custom range</SelectItem>
            </SelectContent>
          </Select>
        )}
        <Input
          id="start_date"
          type="date"
          value={startDate}
          onChange={(e) => {
            const value = e.target.value;
            userEditedDates.current = true;
            setStartDate(value);
            // Keep the range valid: the API rejects end_date < start_date.
            if (endDate && value > endDate) setEndDate(value);
          }}
          className="h-8 w-40 rounded-full px-3 text-xs"
        />
        <Input
          id="end_date"
          type="date"
          value={endDate}
          onChange={(e) => {
            const value = e.target.value;
            userEditedDates.current = true;
            setEndDate(value);
            if (startDate && value < startDate) setStartDate(value);
          }}
          className="h-8 w-40 rounded-full px-3 text-xs"
        />
        <label
          htmlFor="compare_enabled"
          className="flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground"
        >
          <input
            type="checkbox"
            id="compare_enabled"
            checked={compareEnabled}
            onChange={(e) => {
              const checked = e.target.checked;
              setCompareEnabled(checked);
              // Default the comparison to the fiscal year immediately before
              // the selected reporting period (e.g. FY2026 vs FY2027).
              if (checked && !compareStartDate && !compareEndDate) {
                const prior = priorFiscalYear(fyOptions, selectedFiscalYear, startDate)
                  ?? priorFiscalYear(fyOptions, undefined, startDate)
                  ?? fyOptions[1];
                if (prior) {
                  setCompareStartDate(prior.start);
                  setCompareEndDate(prior.end);
                }
              }
            }}
            className="h-3.5 w-3.5 rounded border-gray-300"
          />
          Compare with another period
        </label>
        {compareEnabled && (
          <>
            {fyOptions.length > 0 && (
              <Select
                value={selectedCompareFiscalYear?.label ?? 'custom'}
                onValueChange={(value) => {
                  const fy = fyOptions.find((option) => option.label === value);
                  if (fy) applyCompareFiscalYear(fy);
                }}
              >
                <SelectTrigger className="h-8 w-64 rounded-full px-3 text-xs" aria-label="Compare fiscal year">
                  <SelectValue placeholder="Compare fiscal year" />
                </SelectTrigger>
                <SelectContent>
                  {fyOptions.map((option) => (
                    <SelectItem key={option.label} value={option.label}>
                      {formatFiscalYearOption(option)}
                    </SelectItem>
                  ))}
                  <SelectItem value="custom">Custom range</SelectItem>
                </SelectContent>
              </Select>
            )}
            <Input
              id="compare_start_date"
              type="date"
              value={compareStartDate}
              onChange={(e) => {
                const value = e.target.value;
                setCompareStartDate(value);
                if (compareEndDate && value > compareEndDate) setCompareEndDate(value);
              }}
              className="h-8 w-40 rounded-full px-3 text-xs"
            />
            <Input
              id="compare_end_date"
              type="date"
              value={compareEndDate}
              onChange={(e) => {
                const value = e.target.value;
                setCompareEndDate(value);
                if (compareStartDate && value < compareStartDate) setCompareStartDate(value);
              }}
              className="h-8 w-40 rounded-full px-3 text-xs"
            />
          </>
        )}
      </div>

      {/* Comparison Table */}
      {compareEnabled && profitLoss?.comparison && (
        <Card className="p-6">
          <h3 className="font-bold text-lg mb-4">
            Comparison: {new Date(startDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            {' - '}
            {new Date(endDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            {' vs '}
            {new Date(profitLoss.comparison.period?.start || '').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            {' - '}
            {new Date(profitLoss.comparison.period?.end || '').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
          </h3>
          <ComparisonTable
            currentLabel={`${new Date(startDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} - ${new Date(endDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            compareLabel={`${new Date(profitLoss.comparison.period?.start || '').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })} - ${new Date(profitLoss.comparison.period?.end || '').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            rows={[
              { label: 'Revenue', current: profitLoss.revenue?.total || 0, compare: profitLoss.comparison.revenue?.amount || 0, bold: true },
              { label: 'Cost of Goods Sold', current: profitLoss.cost_of_goods_sold?.total || 0, compare: (profitLoss.revenue?.total || 0) - (profitLoss.gross_profit?.amount || 0), indent: true },
              { label: 'Gross Profit', current: profitLoss.gross_profit?.amount || 0, compare: profitLoss.comparison.gross_profit?.amount || 0, bold: true },
              { label: 'Operating Expenses', current: profitLoss.operating_expenses?.total || 0, compare: (profitLoss.gross_profit?.amount || 0) - (profitLoss.operating_profit?.amount || 0), indent: true },
              { label: 'Operating Profit', current: profitLoss.operating_profit?.amount || 0, compare: profitLoss.comparison.operating_profit?.amount || 0, bold: true },
              { label: 'Other Income/Expenses', current: (profitLoss.other_income?.total || 0) - (profitLoss.other_expenses?.total || 0), compare: ((profitLoss.comparison.net_profit?.amount || 0) - (profitLoss.comparison.operating_profit?.amount || 0)), indent: true },
              { label: 'Net Profit/(Loss)', current: profitLoss.net_profit?.amount || 0, compare: profitLoss.comparison.net_profit?.amount || 0, bold: true },
            ]}
            formatCurrency={formatCurrency}
          />
        </Card>
      )}

      {/* Report */}
      <Card className="rounded-xl">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b pb-4">
          <div>
            <CardTitle className="text-base">Profit & Loss Statement</CardTitle>
            <p className="mt-0.5 text-sm text-muted-foreground">
              {new Date(startDate).toLocaleDateString('en-GB', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}{' '}
              –{' '}
              {new Date(endDate).toLocaleDateString('en-GB', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}
            </p>
          </div>
          <ReportExportToolbar
            targetRef={reportRef}
            title="Profit & Loss Statement"
            subtitle={`${new Date(startDate).toLocaleDateString('en-GB', { year: 'numeric', month: 'long', day: 'numeric' })} – ${new Date(endDate).toLocaleDateString('en-GB', { year: 'numeric', month: 'long', day: 'numeric' })}`}
            filename={`profit-and-loss-${startDate}-to-${endDate}`}
          />
        </CardHeader>

        <CardContent ref={reportRef} className="pt-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading profit & loss statement...</p>
          </div>
        ) : error ? (
          <div className="text-center py-12 text-destructive">
            <Icon icon="solar:danger-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>Failed to load profit & loss statement</p>
          </div>
        ) : !profitLoss ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:graph-new-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No data available</p>
          </div>
        ) : (
          <>
            <Table>
              <caption className="caption-top pb-2">
                <div className="flex justify-end gap-2" data-no-export>
                  <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setCollapsedRows(new Set())}>
                    <Icon icon="solar:maximize-linear" className="w-3.5 h-3.5 mr-1" /> Expand all
                  </Button>
                  <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setCollapsedRows(new Set(['revenue', 'cogs', 'operating-expenses', 'other-expenses']))}>
                    <Icon icon="solar:minimize-linear" className="w-3.5 h-3.5 mr-1" /> Collapse all
                  </Button>
                </div>
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {/* Revenue Section */}
                <TableRow className="bg-muted/50">
                  <TableCell className="font-bold">{sectionLabel('revenue', 'Revenue')}</TableCell>
                  <TableCell></TableCell>
                </TableRow>
                {!collapsedRows.has('revenue') && profitLoss.revenue?.accounts?.map((account: any, index: number) => (
                  <TableRow
                    key={account.account_id || account.id || `revenue-${index}`}
                    className="cursor-pointer hover:bg-muted/50"
                    onClick={() => account.account_id && navigate(`/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${startDate}&end_date=${endDate}`)}
                  >
                    <TableCell className="pl-6">{account.code ? `${account.code} - ` : ''}{account.name}</TableCell>
                    <TableCell className="text-right font-mono">
                      {formatCurrency(account.amount || account.balance || 0)}
                    </TableCell>
                  </TableRow>
                ))}
                <TableRow className="font-bold">
                  <TableCell>Total Revenue</TableCell>
                  <TableCell className="text-right font-mono">
                    {formatCurrency(profitLoss.revenue?.total || 0)}
                  </TableCell>
                </TableRow>

                {/* Cost of Goods Sold Section */}
                <TableRow className="bg-muted/50">
                  <TableCell className="font-bold pt-4">{sectionLabel('cogs', 'Cost of Goods Sold')}</TableCell>
                  <TableCell></TableCell>
                </TableRow>
                {!collapsedRows.has('cogs') && profitLoss.cost_of_goods_sold?.accounts?.map((account: any, index: number) => (
                  <TableRow
                    key={account.account_id || account.id || `cogs-${index}`}
                    className="cursor-pointer hover:bg-muted/50"
                    onClick={() => account.account_id && navigate(`/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${startDate}&end_date=${endDate}`)}
                  >
                    <TableCell className="pl-6">{account.code ? `${account.code} - ` : ''}{account.name}</TableCell>
                    <TableCell className="text-right font-mono">
                      {formatCurrency(account.amount || account.balance || 0)}
                    </TableCell>
                  </TableRow>
                ))}
                <TableRow className="font-bold">
                  <TableCell>Total COGS</TableCell>
                  <TableCell className="text-right font-mono">
                    {formatCurrency(profitLoss.cost_of_goods_sold?.total || 0)}
                  </TableCell>
                </TableRow>

                {/* Gross Profit */}
                {profitLoss.gross_profit?.amount !== undefined && (
                  <TableRow className="font-bold border-t-2">
                    <TableCell>Gross Profit</TableCell>
                    <TableCell className="text-right font-mono">
                      {formatCurrency(profitLoss.gross_profit.amount)}
                    </TableCell>
                  </TableRow>
                )}

                {/* Operating Expenses Section */}
                {profitLoss.operating_expenses?.accounts && profitLoss.operating_expenses.accounts.length > 0 && (
                  <>
                    <TableRow className="bg-muted/50">
                      <TableCell className="font-bold pt-4">{sectionLabel('operating-expenses', 'Operating Expenses')}</TableCell>
                      <TableCell></TableCell>
                    </TableRow>
                    {!collapsedRows.has('operating-expenses') && profitLoss.operating_expenses.accounts.map((account: any, index: number) => (
                      <TableRow
                        key={account.account_id || account.id || `opex-${index}`}
                        className="cursor-pointer hover:bg-muted/50"
                        onClick={() => account.account_id && navigate(`/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${startDate}&end_date=${endDate}`)}
                      >
                        <TableCell className="pl-6">{account.code ? `${account.code} - ` : ''}{account.name}</TableCell>
                        <TableCell className="text-right font-mono">
                          {formatCurrency(account.amount || account.balance || 0)}
                        </TableCell>
                      </TableRow>
                    ))}
                    <TableRow className="font-bold">
                      <TableCell>Total Operating Expenses</TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(profitLoss.operating_expenses.total || 0)}
                      </TableCell>
                    </TableRow>
                  </>
                )}

                {/* Operating Profit */}
                {profitLoss.operating_profit?.amount !== undefined && (
                  <TableRow className="font-bold border-t-2">
                    <TableCell>Operating Profit</TableCell>
                    <TableCell className="text-right font-mono">
                      {formatCurrency(profitLoss.operating_profit.amount)}
                    </TableCell>
                  </TableRow>
                )}

                {/* Other Expenses Section */}
                {profitLoss.other_expenses?.accounts && profitLoss.other_expenses.accounts.length > 0 && (
                  <>
                    <TableRow className="bg-muted/50">
                      <TableCell className="font-bold pt-4">{sectionLabel('other-expenses', 'Other Expenses')}</TableCell>
                      <TableCell></TableCell>
                    </TableRow>
                    {!collapsedRows.has('other-expenses') && profitLoss.other_expenses.accounts.map((account: any, index: number) => (
                      <TableRow
                        key={account.account_id || account.id || `other-${index}`}
                        className="cursor-pointer hover:bg-muted/50"
                        onClick={() => account.account_id && navigate(`/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${startDate}&end_date=${endDate}`)}
                      >
                        <TableCell className="pl-6">{account.code ? `${account.code} - ` : ''}{account.name}</TableCell>
                        <TableCell className="text-right font-mono">
                          {formatCurrency(account.amount || account.balance || 0)}
                        </TableCell>
                      </TableRow>
                    ))}
                    <TableRow className="font-bold">
                      <TableCell>Total Other Expenses</TableCell>
                      <TableCell className="text-right font-mono">
                        {formatCurrency(profitLoss.other_expenses.total || 0)}
                      </TableCell>
                    </TableRow>
                  </>
                )}

                {/* Net Profit */}
                <TableRow className="font-bold border-t-2 bg-primary/10">
                  <TableCell className="text-lg">Net Profit/(Loss)</TableCell>
                  <TableCell className={`text-right font-mono text-lg ${
                    (profitLoss.net_profit?.amount || 0) >= 0 ? 'text-green-600' : 'text-red-600'
                  }`}>
                    {formatCurrency(profitLoss.net_profit?.amount || 0)}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </>
        )}
        </CardContent>
      </Card>
    </div>
  );
}
