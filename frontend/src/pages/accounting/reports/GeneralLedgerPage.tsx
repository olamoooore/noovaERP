import { useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { ReportExportToolbar } from '@/components/reports/ReportExportToolbar';
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

  const reportRef = useRef<HTMLDivElement>(null);

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
      {/* Header — the action pills align with the bottom of the title block so
          they sit level with the subtitle, not centred against the title. */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <Button
            variant="ghost"
            size="sm"
            className="-ml-2 mb-3 text-muted-foreground hover:text-foreground"
            onClick={() => navigate('/accounting')}
          >
            <Icon icon="solar:alt-arrow-left-bold-duotone" className="mr-1.5 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold">General Ledger</h1>
          <p className="text-muted-foreground mt-1">
            Detailed transaction listing by account
          </p>
        </div>
        <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
          <SearchableSelect
            className="h-8 w-56 rounded-full text-xs"
            value={effectiveAccountId}
            onValueChange={setAccountId}
            placeholder="Search account…"
            emptyValue="all"
            emptyLabel="All Accounts (Summary)"
            items={accounts
              .filter((acc: any) => acc.is_active)
              .map((acc: any) => ({
                value: acc.id.toString(),
                label: `${acc.code} - ${acc.name}`,
              }))}
          />
          <Input
            id="start_date"
            type="date"
            value={startDate}
            onChange={(e) => {
              const value = e.target.value;
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
              setEndDate(value);
              if (startDate && value < startDate) setStartDate(value);
            }}
            className="h-8 w-40 rounded-full px-3 text-xs"
          />
          <ReportExportToolbar
            targetRef={reportRef}
            title="General Ledger"
            subtitle={`${ledger?.account ? `${ledger.account.code} - ${ledger.account.name}` : 'All Accounts Summary'} · ${startDate} → ${endDate}`}
            filename={`general-ledger-${ledger?.account?.code ?? 'all'}-${startDate}-${endDate}`}
          />
        </div>
      </div>

      {/* Report */}
      <Card className="p-6" ref={reportRef}>
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
