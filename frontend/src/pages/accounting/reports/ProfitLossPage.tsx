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
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import { ComparisonTable } from '@/components/reports/ComparisonTable';

export default function ProfitLossPage() {
  const navigate = useNavigate();
  const [startDate, setStartDate] = useState(
    new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareStartDate, setCompareStartDate] = useState('');
  const [compareEndDate, setCompareEndDate] = useState('');
  const [collapsedRows, setCollapsedRows] = useState<Set<string>>(new Set());

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
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Profit & Loss Statement</h1>
          <p className="text-muted-foreground mt-1">
            Show company's financial performance
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="space-y-2">
            <Label htmlFor="start_date">Start Date</Label>
            <Input
              id="start_date"
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="end_date">End Date</Label>
            <Input
              id="end_date"
              type="date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
            />
          </div>
          <div className="flex items-end gap-2">
            <Button onClick={() => window.print()} variant="outline" className="flex-1">
              <Icon icon="solar:printer-linear" className="w-4 h-4 mr-2" />
              Print
            </Button>
          </div>
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
              Compare with another period
            </Label>
          </div>
          {compareEnabled && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="compare_start_date">Comparison Start Date</Label>
                <Input
                  id="compare_start_date"
                  type="date"
                  value={compareStartDate}
                  onChange={(e) => setCompareStartDate(e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="compare_end_date">Comparison End Date</Label>
                <Input
                  id="compare_end_date"
                  type="date"
                  value={compareEndDate}
                  onChange={(e) => setCompareEndDate(e.target.value)}
                />
              </div>
            </div>
          )}
        </div>
      </Card>

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
      <Card className="p-6">
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
            <div className="mb-6">
              <h2 className="text-xl font-semibold">Profit & Loss Statement</h2>
              <p className="text-sm text-muted-foreground">
                {new Date(startDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })} - {new Date(endDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'long', 
                  day: 'numeric' 
                })}
              </p>
            </div>

            <Table>
              <caption className="caption-top pb-2">
                <div className="flex justify-end gap-2">
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
      </Card>
    </div>
  );
}
