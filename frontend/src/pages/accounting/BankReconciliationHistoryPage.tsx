import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import reportApi from '@/lib/reportApi';
import { useToast } from '@/hooks/use-toast';

export default function BankReconciliationHistoryPage() {
  const navigate = useNavigate();
  const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');
  const queryClient = useQueryClient();
  const { toast } = useToast();

  const { data: bankAccountsData, isLoading: isLoadingAccounts } = useQuery({
    queryKey: ['bank-accounts'],
    queryFn: () => reportApi.getBankAccounts(),
  });

  const reverseMutation = useMutation({
    mutationFn: ({ reconciliationId, reversalId, reason }: { reconciliationId?: number; reversalId?: string; reason?: string }) => reversalId
      ? reportApi.approveStatementReconciliationReversal(reversalId)
      : reportApi.reverseStatementReconciliation(Number(reconciliationId), String(reason)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reconciliation-history', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions'] });
      toast({ title: 'Reversal workflow updated' });
    },
    onError: (error: any) => toast({ title: 'Reversal blocked', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const requestReversal = (entry: any) => {
    const reason = window.prompt('Enter the controlled reversal reason:');
    if (reason?.trim()) reverseMutation.mutate({ reconciliationId: entry.id, reason: reason.trim() });
  };

  const { data: historyData, isLoading: isLoadingHistory, error: historyError } = useQuery({
    queryKey: ['reconciliation-history', selectedBankAccount],
    queryFn: async () => {
      const response = await reportApi.getReconciliationHistory(Number(selectedBankAccount));
      return response.data || [];
    },
    enabled: !!selectedBankAccount,
  });

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold">Bank Reconciliation History</h1>
          <p className="text-muted-foreground mt-1">
            Review completed reconciliations by bank account.
          </p>
        </div>
        <Button type="button" variant="outline" onClick={() => navigate(`/accounting/core/reconciliation${selectedBankAccount ? `?account=${selectedBankAccount}` : ''}`)}>
          <Icon icon="solar:arrow-left-bold" className="mr-2 h-4 w-4" />
          Back to Reconcile
        </Button>
      </div>

      <Card className="p-6">
        <div className="max-w-md space-y-2">
          <label className="text-sm font-medium">Bank Account</label>
          <Select value={selectedBankAccount || 'select'} onValueChange={(value) => setSelectedBankAccount(value === 'select' ? '' : value)}>
            <SelectTrigger>
              <SelectValue placeholder="Select a bank account" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="select">Select a bank account</SelectItem>
              {isLoadingAccounts ? null : (bankAccountsData?.data || []).map((account: any) => (
                <SelectItem key={account.id} value={account.id.toString()}>
                  {account.name} ({account.account_number})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </Card>

      <Card className="p-6">
        {!selectedBankAccount ? (
          <div className="text-sm text-muted-foreground">Select a bank account to view reconciliation history.</div>
        ) : isLoadingHistory ? (
          <div className="text-sm text-muted-foreground">Loading history...</div>
        ) : historyError ? (
          <div className="text-sm text-red-600">
            <Icon icon="solar:danger-triangle-bold" className="inline h-4 w-4 mr-1" />
            Failed to load history: {historyError instanceof Error ? historyError.message : 'Unknown error'}
          </div>
        ) : !historyData || historyData.length === 0 ? (
          <div className="text-sm text-muted-foreground">No reconciliation history found for this bank account.</div>
        ) : (
          <div className="space-y-3">
            {historyData.map((entry: any) => (
              <div key={entry.id} className="border rounded-lg p-4 flex items-center justify-between">
                <div>
                  <div className="font-medium">Statement Date: {entry.statement_date}</div>
                  <div className="text-sm text-muted-foreground">
                    Ending balance: {entry.statement_balance}
                    {entry.opening_balance != null && <> | Opening: {entry.opening_balance}</>}
                    {entry.difference != null && <> | Difference: {entry.difference}</>}
                  </div>
                </div>
                <div className="text-sm text-muted-foreground flex items-center gap-2">
                  <Icon icon={entry.reversed ? 'solar:restart-bold' : 'solar:check-circle-bold'} className={`h-4 w-4 ${entry.reversed ? 'text-amber-600' : 'text-green-600'}`} />
                  {entry.reversed ? `Reversed ${entry.reversed_at}` : (entry.reconciled_at || entry.created_at)}
                  {entry.reconciled_by && <span>by {entry.reconciled_by}</span>}
                  {!entry.reversed && !entry.reversal_id && <Button type="button" size="sm" variant="outline" disabled={reverseMutation.isPending} onClick={() => requestReversal(entry)}>Request reversal</Button>}
                  {entry.reversal_status === 'pending' && <Button type="button" size="sm" variant="outline" disabled={reverseMutation.isPending} onClick={() => reverseMutation.mutate({ reversalId: entry.reversal_id })}>Approve pending reversal</Button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
