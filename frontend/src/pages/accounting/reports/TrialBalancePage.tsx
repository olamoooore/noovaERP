import { useState } from 'react';
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
import { ComparisonTable } from '@/components/reports/ComparisonTable';

export default function TrialBalancePage() {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState(new Date().toISOString().split('T')[0]);
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareDate, setCompareDate] = useState('');

  // Fetch trial balance
  const { data, isLoading, error } = useQuery({
    queryKey: ['trial-balance', asOfDate, compareEnabled, compareDate],
    queryFn: () => reportApi.getTrialBalance({
      as_of_date: asOfDate,
      ...(compareEnabled && compareDate ? { compare_date: compareDate } : {}),
    }),
    enabled: !!asOfDate,
  });

  const trialBalance = data as any;

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount);
  };

  // CSV Download
  const handleDownloadCSV = () => {
    if (!trialBalance?.comparison) return;

    const currentLabel = new Date(asOfDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    const compareLabel = new Date(trialBalance.comparison.as_of_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

    const rows = [
      ['Account Code', 'Account Name', `Debit (${currentLabel})`, `Credit (${currentLabel})`, `Debit (${compareLabel})`, `Credit (${compareLabel})`],
    ];

    // Build comparison map
    const compareMap: Record<string, any> = {};
    (trialBalance.comparison.accounts || []).forEach((acc: any) => {
      compareMap[acc.code] = acc;
    });

    (trialBalance.accounts || []).forEach((account: any) => {
      const compareAcc = compareMap[account.code];
      rows.push([
        account.code,
        account.name,
        account.debit > 0 ? formatCurrency(account.debit) : '-',
        account.credit > 0 ? formatCurrency(account.credit) : '-',
        compareAcc ? (compareAcc.debit > 0 ? formatCurrency(compareAcc.debit) : '-') : '-',
        compareAcc ? (compareAcc.credit > 0 ? formatCurrency(compareAcc.credit) : '-') : '-',
      ]);
    });

    rows.push([
      '',
      'TOTAL',
      formatCurrency(trialBalance.total_debits || 0),
      formatCurrency(trialBalance.total_credits || 0),
      formatCurrency(trialBalance.comparison.total_debits || 0),
      formatCurrency(trialBalance.comparison.total_credits || 0),
    ]);

    const csv = rows.map(row => row.map(cell => `"${cell}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `trial-balance-comparison-${asOfDate}.csv`);
    document.body.appendChild(link);
    link.click();
    link.remove();
  };

  // PDF Download
  const handleDownloadPDF = () => {
    window.print();
  };

  // Build comparison rows for ComparisonTable
  const comparisonRows = () => {
    if (!trialBalance?.comparison) return [];

    const compareMap: Record<string, any> = {};
    (trialBalance.comparison.accounts || []).forEach((acc: any) => {
      compareMap[acc.code] = acc;
    });

    const rows: Array<{ label: string; current: number; compare: number; bold?: boolean; indent?: boolean; sectionHeader?: boolean }> = [];

    // Add account rows
    (trialBalance.accounts || []).forEach((account: any) => {
      const compareAcc = compareMap[account.code];
      const currentTotal = (account.debit || 0) - (account.credit || 0);
      const compareTotal = compareAcc ? ((compareAcc.debit || 0) - (compareAcc.credit || 0)) : 0;
      rows.push({
        label: `${account.code} - ${account.name}`,
        current: currentTotal,
        compare: compareTotal,
      });
    });

    // Add totals
    rows.push({
      label: 'TOTAL DEBITS',
      current: trialBalance.total_debits || 0,
      compare: trialBalance.comparison.total_debits || 0,
      bold: true,
    });
    rows.push({
      label: 'TOTAL CREDITS',
      current: trialBalance.total_credits || 0,
      compare: trialBalance.comparison.total_credits || 0,
      bold: true,
    });

    return rows;
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Trial Balance</h1>
          <p className="text-muted-foreground mt-1">
            Verify that debits equal credits
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
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
          <div className="flex gap-2">
            <Button onClick={handleDownloadCSV} variant="outline" className="flex-1" disabled={!trialBalance?.comparison}>
              <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              CSV
            </Button>
            <Button onClick={handleDownloadPDF} variant="outline" className="flex-1">
              <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              PDF
            </Button>
          </div>
        </div>
      </Card>

      {/* Comparison Table */}
      {compareEnabled && trialBalance?.comparison && (
        <Card className="p-6">
          <ComparisonTable
            currentLabel={`As of ${new Date(asOfDate).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            compareLabel={`As of ${new Date(trialBalance.comparison.as_of_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`}
            rows={comparisonRows()}
            formatCurrency={formatCurrency}
            onDownloadCSV={handleDownloadCSV}
            onDownloadPDF={handleDownloadPDF}
          />
        </Card>
      )}

      {/* Report */}
      <Card className="p-6">
        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading trial balance...</p>
          </div>
        ) : error ? (
          <div className="text-center py-12 text-destructive">
            <Icon icon="solar:danger-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>Failed to load trial balance</p>
          </div>
        ) : !trialBalance?.accounts || trialBalance.accounts.length === 0 ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:balance-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No accounts found</p>
          </div>
        ) : (
          <>
            <div className="mb-6">
              <h2 className="text-xl font-semibold">Trial Balance</h2>
              <p className="text-sm text-muted-foreground">
                As of {new Date(asOfDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })}
              </p>
            </div>

            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Account Code</TableHead>
                  <TableHead>Account Name</TableHead>
                  <TableHead className="text-right">Debit</TableHead>
                  <TableHead className="text-right">Credit</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {trialBalance.accounts.map((account: any) => (
                  <TableRow key={account.id}>
                    <TableCell className="font-mono">{account.code}</TableCell>
                    <TableCell>{account.name}</TableCell>
                    <TableCell className="text-right font-mono">
                      {account.debit > 0 ? formatCurrency(account.debit) : '-'}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {account.credit > 0 ? formatCurrency(account.credit) : '-'}
                    </TableCell>
                  </TableRow>
                ))}
                <TableRow className="font-bold border-t-2">
                  <TableCell colSpan={2}>Total</TableCell>
                  <TableCell className="text-right font-mono">
                    {formatCurrency(trialBalance.total_debits || 0)}
                  </TableCell>
                  <TableCell className="text-right font-mono">
                    {formatCurrency(trialBalance.total_credits || 0)}
                  </TableCell>
                </TableRow>
              </TableBody>
            </Table>

            {/* Balance Check */}
            <div className="mt-6 flex items-center justify-between p-4 bg-muted rounded-lg">
              <div className="flex items-center gap-2">
                {trialBalance.is_balanced ? (
                  <>
                    <Icon icon="solar:check-circle-bold" className="w-5 h-5 text-green-600" />
                    <span className="font-semibold">Books are balanced</span>
                  </>
                ) : (
                  <>
                    <Icon icon="solar:danger-circle-bold" className="w-5 h-5 text-destructive" />
                    <span className="font-semibold">Books are not balanced</span>
                  </>
                )}
              </div>
              {!trialBalance.is_balanced && (
                <Badge variant="destructive">
                  Difference: {formatCurrency(Math.abs(trialBalance.difference || 0))}
                </Badge>
              )}
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
