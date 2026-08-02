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

interface Account {
  account_id: number;
  code: string;
  name: string;
  balance: number;
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
}

export default function BalanceSheetPage() {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState(new Date().toISOString().split('T')[0]);
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareDate, setCompareDate] = useState('');

  const { data, isLoading, error } = useQuery({
    queryKey: ['balance-sheet', asOfDate, compareEnabled, compareDate],
    queryFn: () => reportApi.getBalanceSheet({
      as_of_date: asOfDate,
      ...(compareEnabled && compareDate ? { compare_date: compareDate } : {}),
    }),
    enabled: !!asOfDate,
  });

  const balanceSheet = data as BalanceSheetData | undefined;

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount);
  };

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

  const renderAccountGroup = (group: AccountGroup) => {
    return (
      <React.Fragment key={group.code}>
        <TableRow className="bg-blue-50 border-l-4 border-l-blue-400">
          <TableCell className="font-semibold text-blue-900 pl-6">
            <div className="flex items-center gap-2">
              <Badge className="bg-blue-100 text-blue-800 text-xs">L2</Badge>
              <span className="font-mono text-sm">{group.code}</span>
              <span>{group.name}</span>
            </div>
          </TableCell>
          <TableCell className="text-right font-mono font-semibold text-blue-900">
            {formatCurrency(group.total)}
          </TableCell>
        </TableRow>
        {group.accounts.map((account) => (
          <TableRow key={account.account_id} className="hover:bg-gray-50">
            <TableCell className="pl-12">
              <div className="flex items-center gap-2">
                <Badge className="bg-gray-100 text-gray-600 text-xs">L3</Badge>
                <span className="font-mono text-sm text-muted-foreground">{account.code}</span>
                <span>{account.name}</span>
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

    return (
      <>
        <TableRow className="bg-purple-50 border-l-4 border-l-purple-500">
          <TableCell className="font-bold text-lg text-purple-900 pl-4">
            <div className="flex items-center gap-2">
              <Badge className="bg-purple-100 text-purple-800 font-bold text-xs">L1</Badge>
              <span>{title}</span>
            </div>
          </TableCell>
          <TableCell></TableCell>
        </TableRow>
        {groups.map((group) => renderAccountGroup(group))}
        <TableRow className="font-bold bg-muted border-t-2">
          <TableCell className="pl-4">Total {title}</TableCell>
          <TableCell className="text-right font-mono text-lg">
            {formatCurrency(total)}
          </TableCell>
        </TableRow>
      </>
    );
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Balance Sheet (SFRS)</h1>
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
        <div className="flex items-end gap-4">
          <div className="flex-1 space-y-2">
            <Label htmlFor="as_of_date">As of Date</Label>
            <Input
              id="as_of_date"
              type="date"
              value={asOfDate}
              onChange={(e) => setAsOfDate(e.target.value)}
            />
          </div>
          <Button onClick={() => window.print()} variant="outline">
            <Icon icon="solar:printer-linear" className="w-4 h-4 mr-2" />
            Print
          </Button>
        </div>

        {/* Comparison Toggle */}
        <div className="mt-4 border-t pt-4">
          <div className="flex items-center gap-2 mb-3">
            <input
              type="checkbox"
              id="compare_enabled"
              checked={compareEnabled}
              onChange={(e) => setCompareEnabled(e.target.checked)}
              className="rounded border-gray-300"
            />
            <Label htmlFor="compare_enabled" className="cursor-pointer font-medium">
              Compare with another date
            </Label>
          </div>
          {compareEnabled && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="compare_date">Comparison Date</Label>
                <Input
                  id="compare_date"
                  type="date"
                  value={compareDate}
                  onChange={(e) => setCompareDate(e.target.value)}
                />
              </div>
            </div>
          )}
        </div>
      </Card>

      {/* Comparison Summary */}
      {compareEnabled && (balanceSheet as any)?.comparison && (
        <Card className="p-6">
          <h3 className="font-bold text-lg mb-4">
            Comparison: As of {asOfDate} vs As of {(balanceSheet as any).comparison.period}
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {[
              { label: 'Total Assets', current: balanceSheet?.total_assets, compare: (balanceSheet as any)?.comparison?.total_assets },
              { label: 'Total Liabilities', current: balanceSheet?.total_liabilities, compare: (balanceSheet as any)?.comparison?.total_liabilities },
              { label: 'Total Equity', current: balanceSheet?.total_equity, compare: (balanceSheet as any)?.comparison?.total_equity },
            ].map(({ label, current, compare }) => {
              const change = (current || 0) - (compare || 0);
              const changePct = compare ? Math.round((change / Math.abs(compare)) * 100) : 0;
              return (
                <div key={label} className="border rounded-lg p-4">
                  <p className="text-sm text-muted-foreground">{label}</p>
                  <p className="text-lg font-bold mt-1">{formatCurrency(current || 0)}</p>
                  {compare !== undefined && (
                    <p className={`text-sm mt-1 font-medium ${
                      change >= 0 ? 'text-green-600' : 'text-red-600'
                    }`}>
                      {change >= 0 ? '▲' : '▼'} {formatCurrency(Math.abs(change))} ({changePct}%)
                    </p>
                  )}
                </div>
              );
            })}
          </div>
        </Card>
      )}

      <Card className="p-4 bg-blue-50 border-blue-200">
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
      </Card>

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
              <h2 className="text-2xl font-bold">Balance Sheet</h2>
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
              <TableHeader>
                <TableRow>
                  <TableHead className="w-2/3">Account</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow className="bg-primary/10">
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

                <TableRow className="font-bold border-t-4 bg-primary/20">
                  <TableCell className="text-xl py-4">TOTAL ASSETS</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_assets || 0)}
                  </TableCell>
                </TableRow>

                <TableRow>
                  <TableCell colSpan={2} className="h-6"></TableCell>
                </TableRow>

                <TableRow className="bg-primary/10">
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

                <TableRow className="font-bold border-t-4 bg-primary/20">
                  <TableCell className="text-xl py-4">TOTAL LIABILITIES</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_liabilities || 0)}
                  </TableCell>
                </TableRow>

                <TableRow>
                  <TableCell colSpan={2} className="h-6"></TableCell>
                </TableRow>

                <TableRow className="bg-primary/10">
                  <TableCell className="font-bold text-xl py-4">EQUITY</TableCell>
                  <TableCell></TableCell>
                </TableRow>

                {normalizeGroups(balanceSheet.equity?.groups || balanceSheet.equity?.accounts || []).map((group) => renderAccountGroup(group))}

                {/* Show Current Year Earnings as a separate line */}
                {balanceSheet.equity?.current_year_earnings !== undefined && (
                  <TableRow className="border-t">
                    <TableCell className="pl-8 font-semibold">
                      Current Year Earnings (Net Profit/Loss)
                    </TableCell>
                    <TableCell className={`text-right font-mono ${balanceSheet.equity.current_year_earnings < 0 ? 'text-red-600' : 'text-green-600'}`}>
                      {formatCurrency(balanceSheet.equity.current_year_earnings)}
                    </TableCell>
                  </TableRow>
                )}

                <TableRow className="font-bold border-t-4 bg-primary/20">
                  <TableCell className="text-xl py-4">TOTAL EQUITY</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_equity || 0)}
                  </TableCell>
                </TableRow>

                <TableRow>
                  <TableCell colSpan={2} className="h-4"></TableCell>
                </TableRow>

                <TableRow className="font-bold border-t-4 border-b-4 bg-primary/30">
                  <TableCell className="text-xl py-4">TOTAL LIABILITIES + EQUITY</TableCell>
                  <TableCell className="text-right font-mono text-xl">
                    {formatCurrency(balanceSheet.total_liabilities_and_equity || 0)}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>

            <div className="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
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
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
