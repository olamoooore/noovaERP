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

export default function ProfitLossPage() {
  const navigate = useNavigate();
  const [startDate, setStartDate] = useState(
    new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);

  // Fetch profit & loss
  const { data, isLoading, error } = useQuery({
    queryKey: ['profit-loss', startDate, endDate],
    queryFn: () => reportApi.getProfitLoss({ 
      start_date: startDate, 
      end_date: endDate 
    }),
    enabled: !!startDate && !!endDate,
  });

  // reportApi.getProfitLoss() already returns response.data.data
  const profitLoss = data;

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount);
  };

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
          <div className="flex items-end">
            <Button onClick={() => window.print()} variant="outline" className="w-full">
              <Icon icon="solar:printer-linear" className="w-4 h-4 mr-2" />
              Print
            </Button>
          </div>
        </div>
      </Card>

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
              <TableHeader>
                <TableRow>
                  <TableHead>Account</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {/* Revenue Section */}
                <TableRow className="bg-muted/50">
                  <TableCell className="font-bold">Revenue</TableCell>
                  <TableCell></TableCell>
                </TableRow>
                {profitLoss.revenue?.accounts?.map((account: any, index: number) => (
                  <TableRow key={account.account_id || account.id || `revenue-${index}`}>
                    <TableCell className="pl-6">{account.name}</TableCell>
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
                  <TableCell className="font-bold pt-4">Cost of Goods Sold</TableCell>
                  <TableCell></TableCell>
                </TableRow>
                {profitLoss.cost_of_goods_sold?.accounts?.map((account: any, index: number) => (
                  <TableRow key={account.account_id || account.id || `cogs-${index}`}>
                    <TableCell className="pl-6">{account.name}</TableCell>
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
                      <TableCell className="font-bold pt-4">Operating Expenses</TableCell>
                      <TableCell></TableCell>
                    </TableRow>
                    {profitLoss.operating_expenses.accounts.map((account: any, index: number) => (
                      <TableRow key={account.account_id || account.id || `opex-${index}`}>
                        <TableCell className="pl-6">{account.name}</TableCell>
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
                      <TableCell className="font-bold pt-4">Other Expenses</TableCell>
                      <TableCell></TableCell>
                    </TableRow>
                    {profitLoss.other_expenses.accounts.map((account: any, index: number) => (
                      <TableRow key={account.account_id || account.id || `other-${index}`}>
                        <TableCell className="pl-6">{account.name}</TableCell>
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
