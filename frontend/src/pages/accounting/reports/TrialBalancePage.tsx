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

export default function TrialBalancePage() {
  const navigate = useNavigate();
  const [asOfDate, setAsOfDate] = useState(new Date().toISOString().split('T')[0]);

  // Fetch trial balance
  const { data, isLoading, error } = useQuery({
    queryKey: ['trial-balance', asOfDate],
    queryFn: () => reportApi.getTrialBalance({ as_of_date: asOfDate }),
    enabled: !!asOfDate,
  });

  // reportApi.getTrialBalance() already returns response.data.data
  // So 'data' here is the actual trial balance data
  const trialBalance = data;

  // Debug logging
  console.log('Trial Balance Debug:', {
    asOfDate,
    queryKey: ['trial-balance', asOfDate],
    data,
    trialBalance,
    hasData: !!trialBalance,
    dataKeys: trialBalance ? Object.keys(trialBalance) : [],
    error,
    isLoading,
  });

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
          <Button
            onClick={() => window.print()}
            variant="outline"
          >
            <Icon icon="solar:printer-linear" className="w-4 h-4 mr-2" />
            Print
          </Button>
        </div>
      </Card>

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
