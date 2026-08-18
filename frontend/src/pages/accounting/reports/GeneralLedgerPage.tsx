import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import reportApi from '@/lib/reportApi';
import { formatCurrency } from '@/lib/utils';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';

export default function GeneralLedgerPage() {
  const navigate = useNavigate();
  const [accountId, setAccountId] = useState<string>('all');
  const [startDate, setStartDate] = useState(
    new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(new Date().toISOString().split('T')[0]);

  // Fetch accounts for dropdown
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  // Fetch general ledger
  const { data, isLoading, error } = useQuery({
    queryKey: ['general-ledger', accountId, startDate, endDate],
    queryFn: () => {
      if (accountId === 'all') {
        // Don't pass account_id, backend will return multi-account summary
        return reportApi.getGeneralLedger({
          start_date: startDate,
          end_date: endDate,
        });
      }
      return reportApi.getGeneralLedger({
        account_id: parseInt(accountId),
        start_date: startDate,
        end_date: endDate,
      });
    },
    enabled: !!startDate && !!endDate,
  });

  // reportApi.getGeneralLedger() already returns response.data.data
  const ledger = data;

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">General Ledger</h1>
          <p className="text-muted-foreground mt-1">
            Detailed transaction listing by account
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="space-y-2">
            <Label htmlFor="account">Account</Label>
            <Select value={accountId} onValueChange={setAccountId}>
              <SelectTrigger>
                <SelectValue placeholder="Select account" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Accounts (Summary)</SelectItem>
                {accounts
                  .filter((acc: any) => acc.is_active)
                  .map((acc: any) => (
                    <SelectItem key={acc.id} value={acc.id.toString()}>
                      {acc.code} - {acc.name}
                    </SelectItem>
                  ))}
              </SelectContent>
            </Select>
          </div>
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
            <p className="text-muted-foreground mt-2">Loading general ledger...</p>
          </div>
        ) : error ? (
          <div className="text-center py-12 text-destructive">
            <Icon icon="solar:danger-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>Failed to load general ledger</p>
          </div>
        ) : !ledger?.transactions || ledger.transactions.length === 0 ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:book-2-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No transactions found</p>
          </div>
        ) : (
          <>
            <div className="mb-6">
              <h2 className="text-xl font-semibold">
                {ledger.account ? `${ledger.account.code} - ${ledger.account.name}` : 'All Accounts Summary'}
              </h2>
              <p className="text-sm text-muted-foreground">
                {new Date(startDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'short', 
                  day: 'numeric' 
                })} - {new Date(endDate).toLocaleDateString('en-US', { 
                  year: 'numeric', 
                  month: 'short', 
                  day: 'numeric' 
                })}
              </p>
            </div>

            {ledger.account && (
              <div className="mb-4 p-4 bg-muted rounded-lg">
                <div className="flex justify-between items-center">
                  <span className="font-semibold">Opening Balance:</span>
                  <span className="font-mono">{formatCurrency(ledger.opening_balance || 0)}</span>
                </div>
              </div>
            )}

            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Date</TableHead>
                  <TableHead>Reference</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Debit</TableHead>
                  <TableHead className="text-right">Credit</TableHead>
                  <TableHead className="text-right">Balance</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {ledger.transactions.map((tx: any, index: number) => (
                  <TableRow key={index}>
                    <TableCell>
                      {tx.date ? new Date(tx.date).toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric' 
                      }) : '-'}
                    </TableCell>
                    <TableCell className="font-mono">{tx.reference || '-'}</TableCell>
                    <TableCell>{tx.description}</TableCell>
                    <TableCell className="text-right font-mono">
                      {tx.debit > 0 ? formatCurrency(tx.debit) : '-'}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {tx.credit > 0 ? formatCurrency(tx.credit) : '-'}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {formatCurrency(tx.balance || 0)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>

            {ledger.account && (
              <div className="mt-4 p-4 bg-muted rounded-lg">
                <div className="flex justify-between items-center font-bold">
                  <span>Closing Balance:</span>
                  <span className="font-mono">{formatCurrency(ledger.closing_balance || 0)}</span>
                </div>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  );
}
