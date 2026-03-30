import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import reportApi from '@/lib/reportApi';

export default function BankReconciliationHistoryPage() {
  const [selectedBankAccount, setSelectedBankAccount] = useState<string>('');

  const { data: bankAccountsData, isLoading: isLoadingAccounts } = useQuery({
    queryKey: ['bank-accounts'],
    queryFn: () => reportApi.getBankAccounts(),
  });

  const { data: historyData, isLoading: isLoadingHistory } = useQuery({
    queryKey: ['reconciliation-history', selectedBankAccount],
    queryFn: async () => {
      const response = await reportApi.getReconciliationHistory(Number(selectedBankAccount));
      return response.data || [];
    },
    enabled: !!selectedBankAccount,
  });

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Bank Reconciliation History</h1>
        <p className="text-muted-foreground mt-1">
          Review completed reconciliations by bank account.
        </p>
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
                  </div>
                </div>
                <div className="text-sm text-muted-foreground flex items-center gap-2">
                  <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-600" />
                  {entry.created_at}
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
