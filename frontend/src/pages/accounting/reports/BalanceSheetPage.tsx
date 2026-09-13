import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import { ComparisonTable, type ComparisonRow } from '@/components/reports/ComparisonTable';

interface Account {
  account_id: number;
  code: string;
  name: string;
  balance: number;
  currency_code?: string | null;
  account_group?: string;
  group_name?: string;
}

interface AccountGroup {
  code: string;
  name: string;
  accounts: Account[];
  total: number;
}

interface BalanceSheetData {
  assets: {
    current: Array<AccountGroup | Account>;
    non_current: Array<AccountGroup | Account>;
    total: number;
  };
  liabilities: {
    current: Array<AccountGroup | Account>;
    non_current: Array<AccountGroup | Account>;
    total: number;
  };
  equity: {
    groups?: Array<AccountGroup | Account>;
    accounts?: Array<AccountGroup | Account>;
    current_year_earnings?: number;
    total: number;
  };
  total_assets: number;
  total_liabilities: number;
  total_equity: number;
  total_liabilities_and_equity: number;
  balance_check: number;
  working_capital: number;
  comparison?: {
    as_of_date: string;
    total_assets: { amount: number; change: number; change_percent: number };
    total_liabilities: { amount: number; change: number; change_percent: number };
    total_equity: { amount: number; change: number; change_percent: number };
  };
}

interface BalanceSheetPageProps {
  pageTitle?: string;
  statementTitle?: string;
  financialStatementStyle?: boolean;
}

export default function BalanceSheetPage({
  pageTitle = 'Balance Sheet (SFRS)',
  statementTitle = 'Balance Sheet',
  financialStatementStyle = false,
}: BalanceSheetPageProps) {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState(new Date().toISOString().split('T')[0]);
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareDate, setCompareDate] = useState('');
  const [collapsedRows, setCollapsedRows] = useState<Set<string>>(new Set());

  const toggleRow = (id: string) => {
    setCollapsedRows((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const { data, isLoading, error } = useQuery({
    queryKey: ['balance-sheet', asOfDate, compareEnabled, compareDate],
    queryFn: () => reportApi.getBalanceSheet({
      as_of_date: asOfDate,
      ...(compareEnabled && compareDate ? { compare_date: compareDate } : {}),
    }),
    enabled: !!asOfDate,
  });

  const balanceSheet = data as BalanceSheetData | undefined;

  const normalizeGroups = (items: Array<AccountGroup | Account> = []): AccountGroup[] => {
    return items.map((item, index) => {
      if ('accounts' in item) {
        return item;
      }

      return {
        code: item.account_group || item.code || `GROUP-${index + 1}`,
        name: item.group_name || item.name,
        accounts: [item],
        total: item.balance,
      };
    });
  };

  const renderAccountGroup = (group: AccountGroup, sectionId = '') => {
    const rowId = `group:${sectionId}:${group.code}`;
    const isCollapsed = collapsedRows.has(rowId);

    return (
      <React.Fragment key={group.code}>
        <TableRow className={financialStatementStyle ? '' : 'bg-blue-50 border-l-4 border-l-blue-400'}>
          <TableCell className={`font-semibold pl-6 ${financialStatementStyle ? '' : 'text-blue-900'}`}>
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label={isCollapsed ? `Expand ${group.name}` : `Collapse ${group.name}`}
                className="p-0.5 rounded hover:bg-black/5 text-muted-foreground shrink-0"
                onClick={() => toggleRow(rowId)}
              >
                <Icon
                  icon={isCollapsed ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'}
                  className="w-4 h-4"
                />
              </button>
              <span>{group.name}</span>
            </div>
          </TableCell>
          <TableCell className={`text-right font-mono font-semibold ${financialStatementStyle ? '' : 'text-blue-900'}`}>
            {formatCurrency(group.total)}
          </TableCell>
        </TableRow>
        {!isCollapsed && group.accounts.map((account) => (
          <TableRow
            key={account.account_id}
            className="hover:bg-gray-50 cursor-pointer"
            onClick={() => navigate(`/accounting/reporting/general-ledger?account_id=${account.account_id}&start_date=${new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]}&end_date=${asOfDate}`)}
          >
            <TableCell className="pl-12">
              <div className="flex items-center gap-2">
                {!financialStatementStyle && <Badge className="bg-gray-100 text-gray-600 text-xs">L3</Badge>}
                <span className="font-mono text-sm text-muted-foreground">{account.code}</span>
                <span>{account.name}</span>
                {account.currency_code && <Badge variant="outline" className="text-xs">{account.currency_code}</Badge>}
              </div>
            </TableCell>
            <TableCell className="text-right font-mono">
              {formatCurrency(account.balance)}
            </TableCell>
          </TableRow>
        ))}
      </React.Fragment>
    );
  };

  const renderSection = (title: string, groups: AccountGroup[], total: number) => {
    if (!groups || groups.length === 0) return null;
    const sectionId = `section:${title.toLowerCase().replace(/ /g, '-')}`;
    const isCollapsed = collapsedRows.has(sectionId);

    return (
      <>
        <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-purple-50 border-l-4 border-l-purple-500'}>
          <TableCell className={`font-bold text-lg pl-4 ${financialStatementStyle ? '' : 'text-purple-900'}`}>
            <div className="flex items-center gap-2">
              <button
                type="button"
                aria-label={isCollapsed ? `Expand ${title}` : `Collapse ${title}`}
                className="p-0.5 rounded hover:bg-black/5 text-muted-foreground shrink-0"
                onClick={() => toggleRow(sectionId)}
              >
                <Icon
                  icon={isCollapsed ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'}
                  className="w-4 h-4"
                />
              </button>
              {!financialStatementStyle && <Badge className="bg-purple-100 text-purple-800 font-bold text-xs">L1</Badge>}
              <span>{title}</span>
            </div>
          </TableCell>
          <TableCell></TableCell>
        </TableRow>
        {!isCollapsed && groups.map((group) => renderAccountGroup(group, sectionId))}
        {!isCollapsed && (
          <TableRow className="font-bold border-t-2">
            <TableCell className="pl-4">Total {title}</TableCell>
            <TableCell className="text-right font-mono text-lg">{formatCurrency(total)}</TableCell>
          </TableRow>
        )}
      </>
    );
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{pageTitle}</h1>
          <p className="text-muted-foreground mt-1">
            Singapore Financial Reporting Standards compliant
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back
        </Button>
      </div>

      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
          <div className="space-y-2">
            <Label htmlFor="as_of_date" className="text-sm font-medium text-gray-700">As of Date</Label>
            <Input
              id="as_of_date"
              type="date"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
              className="w-full"
            />
          </div>
          <div className="flex items-center gap-3 h-10">
            <label className="flex items-center gap-2 cursor-pointer">
              <input
                type="checkbox"
                id="compare_enabled"
                checked={compareEnabled}
                onChange={(e) => {
                  setCompareEnabled(e.target.checked)
                  if (!e.target.checked) setCompareDate('')
                }}
                className="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="text-sm font-medium text-gray-700">Compare</span>
            </label>
          </div>
          {compareEnabled && (
            <div className="space-y-2">
              <Label htmlFor="compare_date" className="text-sm font-medium text-gray-700">Comparison Date</Label>
              <Input
                id="compare_date"
                type="date"
                value={compareDate}
                onChange={(e) => setCompareDate(e.target.value)}
                className="w-full"
              />
            </div>
          )}
        </div>
      </Card>

      {/* Comparison Table */}
      {compareEnabled && balanceSheet?.comparison && (
        <Card className="p-6">
          <ComparisonTable
            title={`${statementTitle} Comparison`}
            companyName="NoovaERP"
            asOfDate={new Date(asOfDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            compareDate={new Date(balanceSheet.comparison.as_of_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            filename={`balance-sheet-comparison-${asOfDate}`}
            currentLabel={`As of ${new Date(asOfDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            compareLabel={`As of ${new Date(balanceSheet.comparison.as_of_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            rows={[
              { label: 'ASSETS', current: 0, compare: 0, sectionHeader: true },
              { label: 'Total Current Assets', current: normalizeGroups(balanceSheet.assets?.current || []).reduce((sum, group) => sum + group.total, 0), compare: normalizeGroups(balanceSheet.assets?.current || []).reduce((sum, group) => sum + group.total, 0) - (balanceSheet.comparison?.total_assets?.change || 0), indent: true },
              { label: 'Total Non-Current Assets', current: normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0), compare: normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0) - (balanceSheet.comparison?.total_assets?.change || 0), indent: true },
              { label: 'TOTAL ASSETS', current: balanceSheet.total_assets || 0, compare: balanceSheet.comparison.total_assets.amount, bold: true },
              { label: 'LIABILITIES', current: 0, compare: 0, sectionHeader: true },
              { label: 'Total Current Liabilities', current: normalizeGroups(balanceSheet.liabilities?.current || []).reduce((sum, group) => sum + group.total, 0), compare: normalizeGroups(balanceSheet.liabilities?.current || []).reduce((sum, group) => sum + group.total, 0) - (balanceSheet.comparison?.total_liabilities?.change || 0), indent: true },
              { label: 'Total Non-Current Liabilities', current: normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0), compare: normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0) - (balanceSheet.comparison?.total_liabilities?.change || 0), indent: true },
              { label: 'TOTAL LIABILITIES', current: balanceSheet.total_liabilities || 0, compare: balanceSheet.comparison.total_liabilities.amount, bold: true },
              { label: 'EQUITY', current: 0, compare: 0, sectionHeader: true },
              { label: 'TOTAL EQUITY', current: balanceSheet.total_equity || 0, compare: balanceSheet.comparison.total_equity.amount, bold: true },
              { label: 'TOTAL LIABILITIES + EQUITY', current: balanceSheet.total_liabilities_and_equity || 0, compare: (balanceSheet.comparison.total_liabilities.amount + balanceSheet.comparison.total_equity.amount), bold: true },
            ]}
            formatCurrency={formatCurrency}
          />
        </Card>
      )}

      {!financialStatementStyle && <Card className="p-4 bg-blue-50 border-blue-200">
        <div className="flex items-start gap-3">
          <Icon icon="solar:info-circle-bold" className="w-5 h-5 text-blue-600 mt-0.5" />
          <div className="flex-1">
            <h3 className="font-semibold text-blue-900 mb-2">SFRS 3-Level Hierarchy</h3>
            <div className="flex items-center gap-4 text-sm text-blue-800">
              <div className="flex items-center gap-2">
                <Badge className="bg-purple-100 text-purple-800 font-bold text-xs">L1</Badge>
                <span>Main Categories</span>
              </div>
              <div className="flex items-center gap-2">
                <Badge className="bg-blue-100 text-blue-800 font-semibold text-xs">L2</Badge>
                <span>Account Groups</span>
              </div>
              <div className="flex items-center gap-2">
                <Badge className="bg-gray-100 text-gray-600 text-xs">L3</Badge>
                <span>Individual Accounts</span>
              </div>
            </div>
          </div>
        </div>
      </Card>}

      <Card className="p-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading...</p>
          </div>
        ) : error ? (
          <div className="text-center py-12 text-destructive">
            <Icon icon="solar:danger-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>Failed to load balance sheet</p>
          </div>
        ) : !balanceSheet ? (
          <div className="text-center py-12 text-muted-foreground">
            <p>No data available</p>
          </div>
        ) : (
          <>
            <div className="mb-6 text-center border-b pb-4">
              <h2 className="text-2xl font-bold uppercase">{statementTitle}</h2>
              <p className="text-sm text-muted-foreground mt-1">
                As of {new Date(asOfDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })}
              </p>
              <Badge className="mt-2" variant="outline">SFRS Compliant</Badge>
            </div>

            <Table>
              <caption className="caption-top pb-2">
                <div className="flex justify-end gap-2">
                  <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setCollapsedRows(new Set())}>
                    <Icon icon="solar:maximize-linear" className="w-3.5 h-3.5 mr-1" />
                    Expand all
                  </Button>
                  <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => {
                    const ids = ['section:current-assets', 'section:non-current-assets', 'section:current-liabilities', 'section:non-current-liabilities', 'section:equity'];
                    setCollapsedRows(new Set(ids));
                  }}>
                    <Icon icon="solar:minimize-linear" className="w-3.5 h-3.5 mr-1" />
                    Collapse all
                  </Button>
                </div>
              </caption>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-2/3">Account</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-primary/10'}>
                  <TableCell className="font-bold text-xl py-4">ASSETS</TableCell>
                  <TableCell></TableCell>
                </TableRow>
                
                {renderSection(
                  'Current Assets',
                  normalizeGroups(balanceSheet.assets?.current || []),
                  normalizeGroups(balanceSheet.assets?.current || []).reduce((sum, group) => sum + group.total, 0)
                )}

                {renderSection(
                  'Non-Current Assets',
                  normalizeGroups(balanceSheet.assets?.non_current || []),
                  normalizeGroups(balanceSheet.assets?.non_current || []).reduce((sum, group) => sum + group.total, 0)
                )}

                <TableRow className={financialStatementStyle ? 'font-bold border-t-2' : 'font-bold border-t-4 bg-primary/20'}>
                  <TableCell className="text-xl py-4">TOTAL ASSETS</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_assets || 0)}
                  </TableCell>
                </TableRow>

                <TableRow>
                  <TableCell colSpan={2} className="h-6"></TableCell>
                </TableRow>

                <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-primary/10'}>
                  <TableCell className="font-bold text-xl py-4">LIABILITIES</TableCell>
                  <TableCell></TableCell>
                </TableRow>

                {renderSection(
                  'Current Liabilities',
                  normalizeGroups(balanceSheet.liabilities?.current || []),
                  normalizeGroups(balanceSheet.liabilities?.current || []).reduce((sum, group) => sum + group.total, 0)
                )}

                {renderSection(
                  'Non-Current Liabilities',
                  normalizeGroups(balanceSheet.liabilities?.non_current || []),
                  normalizeGroups(balanceSheet.liabilities?.non_current || []).reduce((sum, group) => sum + group.total, 0)
                )}

                <TableRow className={financialStatementStyle ? 'font-bold border-t-2' : 'font-bold border-t-4 bg-primary/20'}>
                  <TableCell className="text-xl py-4">TOTAL LIABILITIES</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_liabilities || 0)}
                  </TableCell>
                </TableRow>

                <TableRow className={financialStatementStyle ? 'font-bold border-y-2' : 'font-bold border-y-4 bg-muted/60'}>
                  <TableCell className="text-xl py-4">NET ASSETS/(LIABILITIES)</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency((balanceSheet.total_assets || 0) - (balanceSheet.total_liabilities || 0))}
                  </TableCell>
                </TableRow>

                <TableRow>
                  <TableCell colSpan={2} className="h-6"></TableCell>
                </TableRow>

                <TableRow className={financialStatementStyle ? 'bg-muted/50' : 'bg-primary/10'}>
                  <TableCell className="font-bold text-xl py-4">
                    <button type="button" className="flex items-center gap-2" onClick={() => toggleRow('section:equity')}>
                      <Icon icon={collapsedRows.has('section:equity') ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-down-linear'} className="w-4 h-4" />
                      EQUITY
                    </button>
                  </TableCell>
                  <TableCell></TableCell>
                </TableRow>

                {!collapsedRows.has('section:equity') && (financialStatementStyle ? (() => {
                  const equityAccounts = normalizeGroups(
                    balanceSheet.equity?.groups || balanceSheet.equity?.accounts || []
                  ).flatMap((group) => group.accounts);
                  const shareCapital = equityAccounts
                    .filter((account) => /share capital/i.test(account.name))
                    .reduce((sum, account) => sum + account.balance, 0);
                  const retainedProfit = (balanceSheet.total_equity || 0) - shareCapital;

                  return (
                    <>
                      <TableRow>
                        <TableCell className="pl-6">Share Capital</TableCell>
                        <TableCell className="text-right font-mono">{formatCurrency(shareCapital)}</TableCell>
                      </TableRow>
                      <TableRow>
                        <TableCell className="pl-6">Retained Profit</TableCell>
                        <TableCell className="text-right font-mono">{formatCurrency(retainedProfit)}</TableCell>
                      </TableRow>
                    </>
                  );
                })() : normalizeGroups(balanceSheet.equity?.groups || balanceSheet.equity?.accounts || []).map((group) => renderAccountGroup(group, 'section:equity')))}

                {/* Show Current Year Earnings as a separate line on the standard balance sheet. */}
                {!collapsedRows.has('section:equity') && !financialStatementStyle && balanceSheet.equity?.current_year_earnings !== undefined && (
                  <TableRow className="border-t">
                    <TableCell className="pl-8 font-semibold">
                      Current Year Earnings (Net Profit/Loss)
                    </TableCell>
                    <TableCell className={`text-right font-mono ${balanceSheet.equity.current_year_earnings < 0 ? 'text-red-600' : 'text-green-600'}`}>
                      {formatCurrency(balanceSheet.equity.current_year_earnings)}
                    </TableCell>
                  </TableRow>
                )}

                {!collapsedRows.has('section:equity') && <TableRow className={financialStatementStyle ? 'font-bold border-y-2' : 'font-bold border-t-4 bg-primary/20'}>
                  <TableCell className="text-xl py-4">
                    {financialStatementStyle ? 'TOTAL EQUITY/(CAPITAL DEFICIENCY)' : 'TOTAL EQUITY'}
                  </TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_equity || 0)}
                  </TableCell>
                </TableRow>}

                {!financialStatementStyle && (
                  <>
                    <TableRow>
                      <TableCell colSpan={2} className="h-4"></TableCell>
                    </TableRow>
                    <TableRow className="font-bold border-t-4 border-b-4 bg-primary/30">
                      <TableCell className="text-xl py-4">TOTAL LIABILITIES + EQUITY</TableCell>
                      <TableCell className="text-right font-mono text-xl">
                        {formatCurrency(balanceSheet.total_liabilities_and_equity || 0)}
                      </TableCell>
                    </TableRow>
                  </>
                )}
              </TableBody>
            </Table>

            {!financialStatementStyle && <div className="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
              <Card className={`p-4 ${Math.abs(balanceSheet.balance_check || 0) < 0.01 ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`}>
                <div className="flex items-center gap-3">
                  {Math.abs(balanceSheet.balance_check || 0) < 0.01 ? (
                    <Icon icon="solar:check-circle-bold" className="w-8 h-8 text-green-600" />
                  ) : (
                    <Icon icon="solar:danger-circle-bold" className="w-8 h-8 text-red-600" />
                  )}
                  <div>
                    <p className="text-sm font-semibold">Balance Check</p>
                    <p className="text-lg font-mono">
                      {Math.abs(balanceSheet.balance_check || 0) < 0.01 ? 'Balanced ✓' : formatCurrency(Math.abs(balanceSheet.balance_check || 0))}
                    </p>
                  </div>
                </div>
              </Card>

              <Card className="p-4 bg-blue-50 border-blue-200">
                <div className="flex items-center gap-3">
                  <Icon icon="solar:wallet-bold-duotone" className="w-8 h-8 text-blue-600" />
                  <div>
                    <p className="text-sm font-semibold">Working Capital</p>
                    <p className="text-lg font-mono">{formatCurrency(balanceSheet.working_capital || 0)}</p>
                  </div>
                </div>
              </Card>

              <Card className="p-4 bg-purple-50 border-purple-200">
                <div className="flex items-center gap-3">
                  <Icon icon="solar:chart-2-bold-duotone" className="w-8 h-8 text-purple-600" />
                  <div>
                    <p className="text-sm font-semibold">Current Ratio</p>
                    <p className="text-lg font-mono">
                      {(() => {
                        const currentAssets = normalizeGroups(balanceSheet.assets?.current || []).reduce((sum, group) => sum + group.total, 0);
                        const currentLiabilities = normalizeGroups(balanceSheet.liabilities?.current || []).reduce((sum, group) => sum + group.total, 0);
                        return currentLiabilities > 0 ? (currentAssets / currentLiabilities).toFixed(2) : '∞';
                      })()}
                    </p>
                  </div>
                </div>
              </Card>
            </div>}
          </>
        )}
      </Card>
    </div>
  );
}
