import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import reportApi from '@/lib/reportApi';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';
import type { AddTransactionRequest } from '@/types/reports';

interface ReconciliationFilters {
  status?: 'all' | 'reconciled' | 'unreconciled';
  search?: string;
}

export function BankReconciliationPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();

  // State management
  const [selectedBankAccount, setSelectedBankAccount] = useState<number | null>(null);
  const [statementDate, setStatementDate] = useState<string>('');
  const [statementBalance, setStatementBalance] = useState<string>('');
  const [reconciledTransactions, setReconciledTransactions] = useState<Set<number>>(new Set());
  const [showAddTransaction, setShowAddTransaction] = useState(false);
  const [filters, setFilters] = useState<ReconciliationFilters>({
    status: 'all',
    search: '',
  });

  // Add transaction form state
  const [newTransaction, setNewTransaction] = useState<AddTransactionRequest>({
    bank_account_id: 0,
    date: '',
    description: '',
    amount: 0,
    type: 'debit',
  });

  // Fetch bank accounts
  const {
    data: bankAccountsData,
    isLoading: isLoadingAccounts,
  } = useQuery({
    queryKey: ['bank-accounts'],
    queryFn: () => reportApi.getBankAccounts(),
  });

  const { data: chartOfAccounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts({ is_active: true }),
  });

  // Fetch transactions for selected account
  const {
    data: transactionsData,
    isLoading: isLoadingTransactions,
    error: transactionsError,
    refetch: refetchTransactions,
  } = useQuery({
    queryKey: ['reconciliation-transactions', selectedBankAccount, filters],
    queryFn: () =>
      reportApi.getReconciliationTransactions(selectedBankAccount!, {
        status: filters.status === 'all' ? undefined : filters.status,
        search: filters.search || undefined,
      }),
    enabled: selectedBankAccount !== null,
  });

  // Complete reconciliation mutation
  const completeReconciliationMutation = useMutation({
    mutationFn: (data: {
      bank_account_id: number;
      statement_date: string;
      statement_balance: number;
      reconciled_transaction_ids: number[];
    }) => reportApi.completeReconciliation(data),
    onSuccess: () => {
      toast({
        title: 'Reconciliation Complete',
        description: 'Bank reconciliation has been saved successfully.',
      });
      // Reset state
      setReconciledTransactions(new Set());
      setStatementDate('');
      setStatementBalance('');
      // Refetch data
      queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions'] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-history'] });
    },
    onError: (error: any) => {
      toast({
        title: 'Reconciliation Failed',
        description: error.message || 'Failed to complete reconciliation. Please try again.',
        variant: 'destructive',
      });
    },
  });

  // Add adjusting entry mutation
  const addAdjustingEntryMutation = useMutation({
    mutationFn: (data: AddTransactionRequest) => reportApi.addAdjustingEntry(data),
    onSuccess: () => {
      toast({
        title: 'Transaction Added',
        description: 'Adjusting entry has been created successfully.',
      });
      setShowAddTransaction(false);
      // Reset form
      setNewTransaction({
        bank_account_id: selectedBankAccount!,
        date: '',
        description: '',
        amount: 0,
        type: 'debit',
        contra_account_id: undefined,
      });
      // Refetch transactions
      refetchTransactions();
    },
    onError: (error: any) => {
      toast({
        title: 'Failed to Add Transaction',
        description: error.message || 'Failed to create adjusting entry. Please try again.',
        variant: 'destructive',
      });
    },
  });

  // Handle bank account selection
  const handleBankAccountChange = (accountId: string) => {
    if (accountId === 'select' || accountId === 'loading' || accountId === 'no-accounts') {
      return;
    }
    const id = parseInt(accountId);
    if (!isNaN(id)) {
      setSelectedBankAccount(id);
      setReconciledTransactions(new Set());
      setStatementDate('');
      setStatementBalance('');
    }
  };

  // Handle transaction checkbox toggle
  const handleToggleReconciled = (transactionId: number) => {
    setReconciledTransactions((prev) => {
      const newSet = new Set(prev);
      if (newSet.has(transactionId)) {
        newSet.delete(transactionId);
      } else {
        newSet.add(transactionId);
      }
      return newSet;
    });
  };

  // Calculate reconciled balance
  const reconciledBalance = useMemo(() => {
    if (!transactionsData) return 0;

    const openingBalance = parseFloat(String(transactionsData.opening_balance || 0));
    const transactions = transactionsData.data || [];

    let balance = openingBalance;
    transactions.forEach((transaction) => {
      if (reconciledTransactions.has(transaction.id)) {
        balance += (parseFloat(String(transaction.debit || 0)) - parseFloat(String(transaction.credit || 0)));
      }
    });

    return balance;
  }, [transactionsData, reconciledTransactions]);

  // Calculate difference
  const difference = useMemo(() => {
    const statement = parseFloat(statementBalance) || 0;
    return reconciledBalance - statement;
  }, [reconciledBalance, statementBalance]);

  // Handle reconciliation completion
  const handleComplete = () => {
    if (!selectedBankAccount) {
      toast({
        title: 'No Account Selected',
        description: 'Please select a bank account.',
        variant: 'destructive',
      });
      return;
    }

    if (!statementDate) {
      toast({
        title: 'Missing Statement Date',
        description: 'Please enter the statement ending date.',
        variant: 'destructive',
      });
      return;
    }

    if (!statementBalance) {
      toast({
        title: 'Missing Statement Balance',
        description: 'Please enter the statement ending balance.',
        variant: 'destructive',
      });
      return;
    }

    if (Math.abs(difference) > 0.01) {
      toast({
        title: 'Balance Mismatch',
        description: `The reconciled balance does not match the statement balance. Difference: ${formatCurrency(Math.abs(difference))}`,
        variant: 'destructive',
      });
      return;
    }

    completeReconciliationMutation.mutate({
      bank_account_id: selectedBankAccount,
      statement_date: statementDate,
      statement_balance: parseFloat(statementBalance),
      reconciled_transaction_ids: Array.from(reconciledTransactions),
    });
  };

  // Handle add transaction
  const handleAddTransaction = () => {
    if (!newTransaction.date) {
      toast({
        title: 'Missing Date',
        description: 'Please enter a transaction date.',
        variant: 'destructive',
      });
      return;
    }

    if (!newTransaction.description) {
      toast({
        title: 'Missing Description',
        description: 'Please enter a transaction description.',
        variant: 'destructive',
      });
      return;
    }

    if (newTransaction.amount <= 0) {
      toast({
        title: 'Invalid Amount',
        description: 'Please enter a valid amount greater than zero.',
        variant: 'destructive',
      });
      return;
    }

    if (!newTransaction.contra_account_id) {
      toast({
        title: 'Missing GL Account',
        description: 'Please select the offsetting GL account for this adjusting entry.',
        variant: 'destructive',
      });
      return;
    }

    addAdjustingEntryMutation.mutate({
      ...newTransaction,
      bank_account_id: selectedBankAccount!,
    });
  };

  // Filter transactions based on filters
  const filteredTransactions = useMemo(() => {
    if (!transactionsData?.data) return [];

    let filtered = transactionsData.data;

    // Apply status filter
    if (filters.status === 'reconciled') {
      filtered = filtered.filter((t) => reconciledTransactions.has(t.id));
    } else if (filters.status === 'unreconciled') {
      filtered = filtered.filter((t) => !reconciledTransactions.has(t.id));
    }

    // Apply search filter
    if (filters.search) {
      const searchLower = filters.search.toLowerCase();
      filtered = filtered.filter(
        (t) =>
          t.description.toLowerCase().includes(searchLower) ||
          t.reference.toLowerCase().includes(searchLower)
      );
    }

    return filtered;
  }, [transactionsData, filters, reconciledTransactions]);

  const selectedAccount = bankAccountsData?.data?.find((acc) => acc.id === selectedBankAccount);
  const contraAccountOptions = chartOfAccounts.filter(
    (account) => account.is_active && account.id !== selectedAccount?.gl_account_id
  );

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Bank Reconciliation</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Match bank statement transactions with accounting records
          </p>
        </div>
        <Button
          onClick={() => navigate('/accounting/core/reconciliation/history')}
          variant="outline"
        >
          <Icon icon="solar:history-bold" className="mr-2 h-4 w-4" />
          View History
        </Button>
      </div>

      {/* Bank Account Selection */}
      <Card className="p-6">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <Label htmlFor="bank-account">Bank Account</Label>
            <Select
              value={selectedBankAccount?.toString() || 'select'}
              onValueChange={handleBankAccountChange}
            >
              <SelectTrigger id="bank-account">
                <SelectValue placeholder="Select a bank account" />
              </SelectTrigger>
              <SelectContent>
                {isLoadingAccounts ? (
                  <SelectItem value="loading" disabled>
                    Loading accounts...
                  </SelectItem>
                ) : bankAccountsData?.data && bankAccountsData.data.length > 0 ? (
                  bankAccountsData.data.map((account) => (
                    <SelectItem key={account.id} value={account.id.toString()}>
                      {account.name} ({account.account_number})
                    </SelectItem>
                  ))
                ) : (
                  <SelectItem value="no-accounts" disabled>
                    No bank accounts found
                  </SelectItem>
                )}
              </SelectContent>
            </Select>
          </div>

          {selectedAccount && (
            <>
              <div>
                <Label>Last Reconciliation</Label>
                <p className="text-sm text-gray-600 dark:text-gray-400 mt-2">
                  {selectedAccount.last_reconciliation_date || 'Never'}
                </p>
                <p className="text-sm font-medium mt-1">
                  {selectedAccount.last_reconciliation_balance
                    ? formatCurrency(selectedAccount.last_reconciliation_balance)
                    : 'N/A'}
                </p>
              </div>
              <div>
                <Label>Current Balance</Label>
                <p className="text-lg font-bold mt-2">
                  {formatCurrency(selectedAccount.current_balance)}
                </p>
              </div>
            </>
          )}
        </div>
      </Card>

      {/* Statement Information */}
      {selectedBankAccount && (
        <Card className="p-6">
          <h2 className="text-lg font-semibold mb-4">Statement Information</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <Label htmlFor="statement-date">Statement Ending Date</Label>
              <Input
                id="statement-date"
                type="date"
                value={statementDate}
                onChange={(e) => setStatementDate(e.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="statement-balance">Statement Ending Balance</Label>
              <Input
                id="statement-balance"
                type="number"
                step="0.01"
                placeholder="0.00"
                value={statementBalance}
                onChange={(e) => setStatementBalance(e.target.value)}
              />
            </div>
          </div>
        </Card>
      )}

      {/* Reconciliation Summary */}
      {selectedBankAccount && transactionsData && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <Card className="p-4">
            <div>
              <p className="text-sm text-gray-600 dark:text-gray-400">Opening Balance</p>
              <p className="text-xl font-bold mt-1">
                {formatCurrency(parseFloat(String(transactionsData.opening_balance || 0)))}
              </p>
            </div>
          </Card>

          <Card className="p-4">
            <div>
              <p className="text-sm text-gray-600 dark:text-gray-400">Reconciled Balance</p>
              <p className="text-xl font-bold mt-1">{formatCurrency(reconciledBalance)}</p>
            </div>
          </Card>

          <Card className="p-4">
            <div>
              <p className="text-sm text-gray-600 dark:text-gray-400">Statement Balance</p>
              <p className="text-xl font-bold mt-1">
                {statementBalance ? formatCurrency(parseFloat(statementBalance)) : '-'}
              </p>
            </div>
          </Card>

          <Card className={`p-4 ${Math.abs(difference) < 0.01 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'}`}>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm text-gray-600 dark:text-gray-400">Difference</p>
                <p className={`text-xl font-bold mt-1 ${Math.abs(difference) < 0.01 ? 'text-green-600' : 'text-red-600'}`}>
                  {formatCurrency(Math.abs(difference))}
                </p>
              </div>
              {Math.abs(difference) < 0.01 ? (
                <Icon icon="solar:check-circle-bold" className="h-8 w-8 text-green-600" />
              ) : (
                <Icon icon="solar:close-circle-bold" className="h-8 w-8 text-red-600" />
              )}
            </div>
          </Card>
        </div>
      )}

      {/* Transactions List */}
      {selectedBankAccount && (
        <Card>
          <div className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold">Transactions</h2>
              <Button onClick={() => setShowAddTransaction(true)} size="sm">
                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                Add Adjusting Entry
              </Button>
            </div>

            {/* Filters */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
              <div>
                <Label htmlFor="search">Search</Label>
                <Input
                  id="search"
                  placeholder="Search by description or reference..."
                  value={filters.search}
                  onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
                />
              </div>
              <div>
                <Label htmlFor="status-filter">Status</Label>
                <Select
                  value={filters.status}
                  onValueChange={(value: any) =>
                    setFilters((prev) => ({ ...prev, status: value }))
                  }
                >
                  <SelectTrigger id="status-filter">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Transactions</SelectItem>
                    <SelectItem value="reconciled">Reconciled</SelectItem>
                    <SelectItem value="unreconciled">Unreconciled</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-end">
                <Button
                  variant="outline"
                  onClick={() => setFilters({ status: 'all', search: '' })}
                  className="w-full"
                >
                  <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                  Clear Filters
                </Button>
              </div>
            </div>

            {/* Loading State */}
            {isLoadingTransactions && (
              <div className="flex items-center justify-center py-12">
                <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
              </div>
            )}

            {/* Error State */}
            {transactionsError && (
              <div className="flex flex-col items-center justify-center py-12">
                <Icon
                  icon="solar:danger-triangle-bold"
                  className="h-12 w-12 text-red-500 mb-4"
                />
                <p className="text-red-600 font-medium">Failed to load transactions</p>
                <p className="text-gray-600 text-sm mt-1">
                  {transactionsError instanceof Error
                    ? transactionsError.message
                    : 'Unknown error'}
                </p>
                <Button onClick={() => refetchTransactions()} className="mt-4">
                  Try Again
                </Button>
              </div>
            )}

            {/* Transactions Table */}
            {!isLoadingTransactions && !transactionsError && transactionsData && (
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b">
                      <th className="text-left py-3 px-4 font-semibold w-12">
                        <Checkbox
                          checked={
                            filteredTransactions.length > 0 &&
                            filteredTransactions.every((t) => reconciledTransactions.has(t.id))
                          }
                          onCheckedChange={(checked) => {
                            if (checked) {
                              setReconciledTransactions(
                                new Set([...reconciledTransactions, ...filteredTransactions.map((t) => t.id)])
                              );
                            } else {
                              const newSet = new Set(reconciledTransactions);
                              filteredTransactions.forEach((t) => newSet.delete(t.id));
                              setReconciledTransactions(newSet);
                            }
                          }}
                        />
                      </th>
                      <th className="text-left py-3 px-4 font-semibold">Date</th>
                      <th className="text-left py-3 px-4 font-semibold">Description</th>
                      <th className="text-left py-3 px-4 font-semibold">Reference</th>
                      <th className="text-right py-3 px-4 font-semibold">Debit</th>
                      <th className="text-right py-3 px-4 font-semibold">Credit</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredTransactions.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="text-center py-12">
                          <Icon
                            icon="solar:inbox-bold-duotone"
                            className="h-12 w-12 text-gray-400 mx-auto mb-4"
                          />
                          <p className="text-gray-600">No transactions available</p>
                        </td>
                      </tr>
                    ) : (
                      filteredTransactions.map((transaction) => (
                        <tr
                          key={transaction.id}
                          className="border-b hover:bg-gray-50 dark:hover:bg-gray-800"
                        >
                          <td className="py-3 px-4">
                            <Checkbox
                              checked={reconciledTransactions.has(transaction.id)}
                              onCheckedChange={() => handleToggleReconciled(transaction.id)}
                            />
                          </td>
                          <td className="py-3 px-4 text-sm">{transaction.date}</td>
                          <td className="py-3 px-4">{transaction.description}</td>
                          <td className="py-3 px-4 text-sm text-gray-600">
                            {transaction.reference}
                          </td>
                          <td className="text-right py-3 px-4 text-green-600">
                            {transaction.debit > 0 ? formatCurrency(transaction.debit) : '-'}
                          </td>
                          <td className="text-right py-3 px-4 text-red-600">
                            {transaction.credit > 0 ? formatCurrency(transaction.credit) : '-'}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </Card>
      )}

      {/* Complete Reconciliation Button */}
      {selectedBankAccount && transactionsData && (
        <div className="flex justify-end gap-4">
          <Button
            variant="outline"
            onClick={() => {
              setReconciledTransactions(new Set());
              setStatementDate('');
              setStatementBalance('');
            }}
          >
            Reset
          </Button>
          <Button
            onClick={handleComplete}
            disabled={
              !statementDate ||
              !statementBalance ||
              Math.abs(difference) > 0.01 ||
              completeReconciliationMutation.isPending
            }
          >
            {completeReconciliationMutation.isPending ? (
              <>
                <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                Completing...
              </>
            ) : (
              <>
                <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                Complete Reconciliation
              </>
            )}
          </Button>
        </div>
      )}

      {/* Add Transaction Dialog */}
      <Dialog open={showAddTransaction} onOpenChange={setShowAddTransaction}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Adjusting Entry</DialogTitle>
            <DialogDescription>
              Add a manual transaction to account for bank fees or other adjustments.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div>
              <Label htmlFor="new-date">Date</Label>
              <Input
                id="new-date"
                type="date"
                value={newTransaction.date}
                onChange={(e) =>
                  setNewTransaction((prev) => ({ ...prev, date: e.target.value }))
                }
              />
            </div>

            <div>
              <Label htmlFor="new-description">Description</Label>
              <Input
                id="new-description"
                placeholder="e.g., Bank Fee, Interest"
                value={newTransaction.description}
                onChange={(e) =>
                  setNewTransaction((prev) => ({ ...prev, description: e.target.value }))
                }
              />
            </div>

            <div>
              <Label htmlFor="new-amount">Amount</Label>
              <Input
                id="new-amount"
                type="number"
                step="0.01"
                placeholder="0.00"
                value={newTransaction.amount || ''}
                onChange={(e) =>
                  setNewTransaction((prev) => ({
                    ...prev,
                    amount: parseFloat(e.target.value) || 0,
                  }))
                }
              />
            </div>

            <div>
              <Label htmlFor="new-type">Type</Label>
              <Select
                value={newTransaction.type}
                onValueChange={(value: 'debit' | 'credit') =>
                  setNewTransaction((prev) => ({ ...prev, type: value }))
                }
              >
                <SelectTrigger id="new-type">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="debit">Debit (Money In)</SelectItem>
                  <SelectItem value="credit">Credit (Money Out)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div>
              <Label htmlFor="contra-account">Offsetting GL Account</Label>
              <Select
                value={newTransaction.contra_account_id?.toString() || 'select'}
                onValueChange={(value) =>
                  setNewTransaction((prev) => ({
                    ...prev,
                    contra_account_id: value === 'select' ? undefined : parseInt(value, 10),
                  }))
                }
              >
                <SelectTrigger id="contra-account">
                  <SelectValue placeholder="Select offsetting account" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="select" disabled>
                    Select offsetting account
                  </SelectItem>
                  {contraAccountOptions.map((account) => (
                    <SelectItem key={account.id} value={account.id.toString()}>
                      {account.code} - {account.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground mt-1">
                Choose the expense, income, or other GL account to offset the bank entry.
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddTransaction(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleAddTransaction}
              disabled={addAdjustingEntryMutation.isPending}
            >
              {addAdjustingEntryMutation.isPending ? (
                <>
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                  Adding...
                </>
              ) : (
                'Add Transaction'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
