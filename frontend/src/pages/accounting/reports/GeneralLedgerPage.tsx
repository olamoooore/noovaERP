import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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
  const [searchParams] = useSearchParams();
  const urlAccountId = searchParams.get('account_id');
  const [accountId, setAccountId] = useState<string>(urlAccountId || 'all');
  const [startDate, setStartDate] = useState(
    searchParams.get('start_date') || new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
  );
  const [endDate, setEndDate] = useState(
    searchParams.get('end_date') || new Date().toISOString().split('T')[0]
  );

  // Fetch accounts for dropdown
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  // If accountId from URL is not present in the current tenant's chart of accounts,
  // fall back to "all" instead of crashing with a 422.
  const accountIdIsValid = accountId === 'all' || accounts.some((a: any) => String(a.id) === accountId);
  const effectiveAccountId = accountIdIsValid ? accountId : 'all';

  // Fetch general ledger
  const { data, isLoading, error } = useQuery({
    queryKey: ['general-ledger', effectiveAccountId, startDate, endDate],
    queryFn: () => {
      if (effectiveAccountId === 'all') {
        // Don't pass account_id, backend will return multi-account summary
        return reportApi.getGeneralLedger({
          start_date: startDate,
          end_date: endDate,
          per_page: 0,
        });
      }
      return reportApi.getGeneralLedger({
        account_id: parseInt(effectiveAccountId),
        start_date: startDate,
        end_date: endDate,
        per_page: 0,
      });
    },
    enabled: !!startDate && !!endDate,
    retry: false,
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
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Filters */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div className="space-y-2">
            <Label htmlFor="account">Account</Label>
            <Select value={effectiveAccountId} onValueChange={setAccountId}>
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
            <HeaderActionButton icon="solar:printer-linear" onClick={() => window.print()} variant="outline" className="w-full">
              Print
            </HeaderActionButton>
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
                  <TableRow
                    key={index}
                    className={tx.journal_entry_id ? 'cursor-pointer hover:bg-muted/50' : ''}
                    onClick={() => tx.journal_entry_id && navigate(`/accounting/core/journal-entries?entry_id=${tx.journal_entry_id}`)}
                  >
                    <TableCell>
                      {tx.date ? new Date(tx.date).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                      }) : '-'}
                    </TableCell>
                    <TableCell className="font-mono">
                      {tx.invoice_id ? (
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation();
                            navigate(`/invoices/${tx.invoice_id}`);
                          }}
                          className="text-primary hover:underline focus:outline-none focus:ring-2 focus:ring-primary/20 rounded"
                        >
                          {tx.reference || '-'}
                        </button>
                      ) : (
                        tx.reference || '-'
                      )}
                    </TableCell>
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
