import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Pagination } from '@/components/ui/Pagination';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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

type StatementTransactionStatus = 'all' | 'unmatched' | 'suggested' | 'partially_matched' | 'matched' | 'reconciled' | 'excluded' | 'reversed';

const statementMappingFields = [
  ['transaction_date', 'Transaction date'], ['description', 'Description'], ['amount', 'Signed amount'],
  ['debit', 'Debit (money out)'], ['credit', 'Credit (money in)'], ['bank_reference', 'Bank reference'],
  ['customer_reference', 'Customer reference'], ['customer_code', 'Customer code'],
  ['invoice_reference', 'Invoice reference'], ['allocation_amount', 'Allocation amount'],
  ['running_balance', 'Running balance'],
] as const;

const normalizeMatchKey = (value: unknown) => String(value ?? '').toLowerCase().replace(/[^a-z0-9]/g, '');

const statementIdentityValue = (transaction: any, field: 'bank_reference' | 'customer_code'): string => {
  const persisted = transaction[field];
  if (persisted) return String(persisted);

  const aliases = field === 'bank_reference'
    ? new Set(['ourreference', 'bankreference', 'reference'])
    : new Set(['customercode']);
  const rawEntry = Object.entries(transaction.raw_data ?? {}).find(([header, value]) => aliases.has(normalizeMatchKey(header)) && String(value ?? '').trim() !== '');

  return rawEntry ? String(rawEntry[1]) : '';
};

interface InvoiceEvidence {
  id: number | null;
  number: string;
}

const invoiceEvidenceFor = (transaction: any): InvoiceEvidence[] => {
  const evidence = new Map<string, InvoiceEvidence>();

  // Accepted matches are the authoritative evidence after reconciliation.
  // Their match reasons capture the invoice linked to the matched payment.
  (transaction.matches ?? [])
    .filter((match: any) => match.status === 'accepted')
    .forEach((match: any) => {
      const invoiceId = Number(match.match_reasons?.invoice_id) || null;
      const invoiceNumber = String(match.match_reasons?.invoice_number ?? '').trim();
      if (!invoiceNumber && !invoiceId) return;

      const number = invoiceNumber || `Invoice #${invoiceId}`;
      evidence.set(String(invoiceId ?? number), { id: invoiceId, number });
    });

  // Before a match is accepted, retain imported invoice-reference evidence.
  if (evidence.size === 0) {
    (transaction.allocations ?? [])
      .filter((allocation: any) => allocation.allocation_type === 'invoice_reference')
      .forEach((allocation: any) => {
        const number = String(allocation.external_reference ?? '').trim();
        if (number) evidence.set(number, { id: null, number });
      });
  }

  return Array.from(evidence.values());
};

export function BankReconciliationPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();

  // State management
  const [selectedBankAccount, setSelectedBankAccount] = useState<number | null>(null);
  const [statementDate, setStatementDate] = useState<string>('');
  const [statementBalance, setStatementBalance] = useState<string>('');
  const [statementOpeningBalance, setStatementOpeningBalance] = useState<string>('');
  const [statementFile, setStatementFile] = useState<File | null>(null);
  const [statementPreview, setStatementPreview] = useState<any>(null);
  const [statementMapping, setStatementMapping] = useState<Record<string, string>>({});
  const [statementStartDate, setStatementStartDate] = useState('');
  const [matchSelections, setMatchSelections] = useState<Record<string, { glLineId: string; amount: string; reason: string }>>({});
  const [statementException, setStatementException] = useState<{ transactionId: string; mode: 'exclude' | 'restore' } | null>(null);
  const [exceptionType, setExceptionType] = useState<'duplicate' | 'non_transaction'>('duplicate');
  const [exceptionReason, setExceptionReason] = useState('');
  const [exceptionEvidence, setExceptionEvidence] = useState('');
  const [statementValidation, setStatementValidation] = useState<any>(null);
  const [statementTransactionPage, setStatementTransactionPage] = useState(1);
  const [statementTransactionPerPage, setStatementTransactionPerPage] = useState(20);
  const [statementTransactionStatus, setStatementTransactionStatus] = useState<StatementTransactionStatus>('all');
  const [groupStatementIds, setGroupStatementIds] = useState<Set<string>>(new Set());
  const [groupGlIds, setGroupGlIds] = useState<Set<number>>(new Set());
  const [groupMatchReason, setGroupMatchReason] = useState('');
  const [reconciledTransactions, setReconciledTransactions] = useState<Set<number>>(new Set());
  const [showAddTransaction, setShowAddTransaction] = useState(false);
  const [statementAdjustmentTransactionId, setStatementAdjustmentTransactionId] = useState<string | null>(null);
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

  // Pre-select bank account from query param, or auto-select the first
  // account when none is specified. Without this the detail cards
  // (currency / current balance / last reconciliation) render empty until
  // the user manually picks an account, which reads as "data missing"
  // even when the API returned balances (production report 2026-09-24).
  const accountParam = searchParams.get('account');
  const statementTransactionParam = searchParams.get('statement_transaction');
  useEffect(() => {
    if (!bankAccountsData?.data || bankAccountsData.data.length === 0) {
      return;
    }

    // Explicit ?account= param wins.
    if (accountParam) {
      const accId = parseInt(accountParam, 10);
      const exists = bankAccountsData.data.find((a: any) => a.id === accId);
      if (exists) {
        setSelectedBankAccount(accId);
      }
      return;
    }

    // Don't override a manual selection the user has already made.
    if (selectedBankAccount !== null) {
      return;
    }

    // Auto-select the first account so the summary cards populate immediately.
    setSelectedBankAccount(bankAccountsData.data[0].id);
  }, [accountParam, bankAccountsData, selectedBankAccount]);

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
        status: filters.status,
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
    mutationFn: (data: AddTransactionRequest) => statementAdjustmentTransactionId
      ? reportApi.proposeBankStatementAdjustment(statementAdjustmentTransactionId, { contra_account_id: Number(data.contra_account_id), amount: data.type === 'debit' ? data.amount : -data.amount, description: data.description, reason: data.description })
      : reportApi.addAdjustingEntry(data),
    onSuccess: () => {
      toast({
        title: statementAdjustmentTransactionId ? 'Adjustment proposed' : 'Transaction Added',
        description: statementAdjustmentTransactionId ? 'A different authorized user must approve and post this adjustment.' : 'Adjusting entry has been created successfully.',
      });
      setShowAddTransaction(false);
      setStatementAdjustmentTransactionId(null);
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
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
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

    if (statementBalance === '') {
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
      filtered = filtered.filter((t) => t.reconciled);
    } else if (filters.status === 'unreconciled') {
      filtered = filtered.filter((t) => !t.reconciled);
    }

    // Apply search filter
    if (filters.search) {
      const searchLower = filters.search.toLowerCase();
      filtered = filtered.filter(
        (t) =>
          t.description.toLowerCase().includes(searchLower) ||
          (t.reference ?? '').toLowerCase().includes(searchLower)
      );
    }

    return filtered;
  }, [transactionsData, filters]);

  const selectedAccount = bankAccountsData?.data?.find((acc) => acc.id === selectedBankAccount);
  const contraAccountOptions = chartOfAccounts.filter(
    (account) => account.is_active && account.id !== selectedAccount?.gl_account_id
  );

  const statementImportsQuery = useQuery({
    queryKey: ['bank-statement-imports', selectedBankAccount],
    queryFn: () => reportApi.getBankStatementImports(selectedBankAccount!),
    enabled: selectedBankAccount !== null,
  });
  const activeStatementImport = (statementImportsQuery.data?.data ?? []).find(
    (batch: any) => batch.status === 'imported'
  );

  const statementTransactionsQuery = useQuery({
    queryKey: ['bank-statement-transactions', selectedBankAccount, statementTransactionPage, statementTransactionPerPage, statementTransactionStatus, filters.search, statementTransactionParam],
    queryFn: () => reportApi.getBankStatementTransactions(selectedBankAccount!, undefined, statementTransactionPage, statementTransactionPerPage, statementTransactionStatus, filters.search, statementTransactionParam ? [statementTransactionParam] : undefined),
    enabled: selectedBankAccount !== null,
  });

  useEffect(() => setStatementTransactionPage(1), [selectedBankAccount, statementTransactionStatus, filters.search]);

  const filteredStatementTransactions = useMemo(() => {
    const transactions = statementTransactionsQuery.data?.data ?? [];

    return transactions.filter((transaction: any) => {
      const isReconciled = (transaction.matches ?? []).some((match: any) => match.status === 'accepted' && match.reconciliation_id);
      if (statementTransactionStatus === 'reconciled' && !isReconciled) return false;
      if (statementTransactionStatus !== 'all' && statementTransactionStatus !== 'reconciled' && transaction.status !== statementTransactionStatus) return false;
      if (filters.search) {
        const search = filters.search.toLowerCase();
        return String(transaction.description ?? '').toLowerCase().includes(search)
          || String(transaction.bank_reference ?? '').toLowerCase().includes(search)
          || invoiceEvidenceFor(transaction).some((invoice) => invoice.number.toLowerCase().includes(search))
          || (transaction.allocations ?? []).some((allocation: any) => String(allocation.external_reference ?? '').toLowerCase().includes(search));
      }
      return true;
    });
  }, [statementTransactionsQuery.data, filters.search, statementTransactionStatus]);

  const previewStatementMutation = useMutation({
    mutationFn: () => reportApi.previewBankStatement(statementFile!, selectedBankAccount!),
    onSuccess: (preview) => {
      setStatementPreview(preview);
      setStatementMapping(preview.proposed_mapping ?? {});
      toast({ title: 'Statement preview ready', description: `${preview.source_rows} source rows detected.` });
    },
    onError: (error: any) => toast({ title: 'Statement preview failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const importStatementMutation = useMutation({
    mutationFn: () => reportApi.confirmBankStatementImport({
      preview_token: statementPreview.preview_token,
      mapping: statementMapping,
      currency_code: selectedAccount!.currency_code,
      statement_start_date: statementStartDate || undefined,
      statement_end_date: statementDate,
      opening_balance: Number(statementOpeningBalance),
      closing_balance: Number(statementBalance),
    }),
    onSuccess: (batch) => {
      setStatementPreview(null); setStatementFile(null); setStatementOpeningBalance('');
      queryClient.invalidateQueries({ queryKey: ['bank-statement-imports', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      toast({ title: 'Statement imported', description: `${batch.transaction_count} unique bank transactions created.` });
    },
    onError: (error: any) => toast({ title: 'Statement import failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const matchStatementMutation = useMutation({
    mutationFn: ({ transactionId, glLineId, amount, reason }: { transactionId: string; glLineId: number; amount: number; reason: string }) =>
      reportApi.createBankStatementMatch({
        bank_statement_transaction_id: transactionId,
        journal_entry_line_id: glLineId,
        matched_amount: amount,
        idempotency_key: crypto.randomUUID(),
        review_reason: reason,
      }),
    onSuccess: (_, variables) => {
      setMatchSelections((current) => { const next = { ...current }; delete next[variables.transactionId]; return next; });
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', selectedBankAccount] });
      toast({ title: 'Transaction matched' });
      validateStatementReconciliationMutation.mutate();
    },
    onError: (error: any) => toast({ title: 'Match failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const unmatchStatementMutation = useMutation({
    mutationFn: (matchId: string) => reportApi.removeBankStatementMatch(matchId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', selectedBankAccount] });
      toast({ title: 'Match removed' });
      validateStatementReconciliationMutation.mutate();
    },
    onError: (error: any) => toast({ title: 'Unmatch failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const suggestMatchesMutation = useMutation({
    mutationFn: (importId: string) => reportApi.suggestBankStatementMatches(importId),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      toast({ title: 'Suggestions generated', description: `${result.suggestions_created} candidate match(es) scored.` });
    },
    onError: (error: any) => toast({ title: 'Suggestion generation failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const bulkAcceptMatchesMutation = useMutation({
    mutationFn: (importId: string) => reportApi.bulkAcceptBankStatementMatches(importId),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', selectedBankAccount] });
      toast({ title: 'High-confidence matches accepted', description: `${result.accepted} unique match(es) accepted.` });
      validateStatementReconciliationMutation.mutate();
    },
    onError: (error: any) => toast({ title: 'Bulk acceptance failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const groupedMatchMutation = useMutation({
    mutationFn: () => {
      const statements = filteredStatementTransactions.filter((transaction: any) => groupStatementIds.has(transaction.id));
      const remainingByGl = new Map<number, number>();
      (transactionsData?.data ?? [])
        .filter((line) => groupGlIds.has(line.id))
        .forEach((line) => remainingByGl.set(
          line.id,
          Number(line.available_amount ?? (Number(line.debit) - Number(line.credit))),
        ));
      const allocations: Array<{ bank_statement_transaction_id: string; journal_entry_line_id: number; matched_amount: number; review_reason: string }> = [];
      for (const statement of statements) {
        let residual = Number(statement.residual_amount);
        for (const line of transactionsData?.data ?? []) {
          const glRemaining = remainingByGl.get(line.id) ?? 0;
          const statementCustomerCode = normalizeMatchKey(statementIdentityValue(statement, 'customer_code'));
          const statementReference = normalizeMatchKey(statementIdentityValue(statement, 'bank_reference'));
          const paymentReferences = [line.payment?.reference, line.payment?.payment_number, line.reference].map(normalizeMatchKey).filter(Boolean);
          const exactEvidence = statementCustomerCode !== '' && statementCustomerCode === normalizeMatchKey(line.payment?.customer_code)
            && statementReference !== '' && paymentReferences.includes(statementReference);
          if (!groupGlIds.has(line.id) || !exactEvidence || residual * glRemaining <= 0 || Math.abs(residual) < 0.00000001) continue;
          const amount = Math.sign(residual) * Math.min(Math.abs(residual), Math.abs(glRemaining));
          allocations.push({ bank_statement_transaction_id: statement.id, journal_entry_line_id: line.id, matched_amount: amount, review_reason: groupMatchReason });
          residual -= amount;
          remainingByGl.set(line.id, glRemaining - amount);
        }
        if (Math.abs(residual) > 0.01) throw new Error(`Selected exact GL lines do not cover statement ${statement.bank_reference ?? statement.id}.`);
      }
      if (allocations.length === 0) throw new Error('Select compatible statement and GL transactions.');
      return reportApi.createGroupedBankStatementMatches(allocations);
    },
    onSuccess: () => {
      setGroupStatementIds(new Set()); setGroupGlIds(new Set()); setGroupMatchReason('');
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', selectedBankAccount] });
      toast({ title: 'Grouped match completed' });
      validateStatementReconciliationMutation.mutate();
    },
    onError: (error: any) => toast({ title: 'Grouped match failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const statementExceptionMutation = useMutation({
    mutationFn: () => {
      if (!statementException) throw new Error('No statement transaction selected.');
      return statementException.mode === 'exclude'
        ? reportApi.excludeBankStatementTransaction(statementException.transactionId, { type: exceptionType, reason: exceptionReason, evidence: { reviewer_note: exceptionEvidence } })
        : reportApi.restoreBankStatementTransaction(statementException.transactionId, exceptionReason);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      toast({ title: statementException?.mode === 'exclude' ? 'Transaction excluded' : 'Transaction restored' });
      setStatementException(null); setExceptionReason(''); setExceptionEvidence('');
      validateStatementReconciliationMutation.mutate();
    },
    onError: (error: any) => toast({ title: 'Statement action failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const validateStatementReconciliationMutation = useMutation({
    mutationFn: () => {
      const latestImport = (statementImportsQuery.data?.data ?? []).find((batch: any) => batch.status === 'imported');
      if (!selectedBankAccount || !latestImport) throw new Error('Select an imported statement batch first.');
      return reportApi.validateBankStatementReconciliation(selectedBankAccount, [latestImport.id]);
    },
    onSuccess: (result) => setStatementValidation(result),
    onError: (error: any) => toast({ title: 'Statement validation failed', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  const completeStatementReconciliationMutation = useMutation({
    mutationFn: () => reportApi.completeBankStatementReconciliation(Number(selectedBankAccount), statementValidation.import_ids),
    onSuccess: () => {
      setStatementValidation(null);
      queryClient.invalidateQueries({ queryKey: ['bank-statement-transactions', selectedBankAccount] });
      queryClient.invalidateQueries({ queryKey: ['reconciliation-transactions', selectedBankAccount] });
      toast({ title: 'Statement reconciliation completed' });
    },
    onError: (error: any) => toast({ title: 'Completion blocked', description: error?.response?.data?.message ?? error.message, variant: 'destructive' }),
  });

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold">Bank Reconciliation</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Match bank statement transactions with accounting records
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <HeaderActionButton icon="solar:document-add-bold" onClick={() => navigate(`/accounting/core/reconciliation/import-history${selectedBankAccount ? `?account=${selectedBankAccount}` : ''}`)} variant="outline">
            Import History
          </HeaderActionButton>
          <HeaderActionButton icon="solar:history-bold" onClick={() => navigate('/accounting/core/reconciliation/history')} variant="outline">
            View History
          </HeaderActionButton>
        </div>
      </div>

      {/* Filters — one row, below the header (was crammed into the title row
          and wrapped/stacked on narrower screens) */}
      <Card className="rounded-xl p-4">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-full sm:w-80">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              id="search"
              placeholder="Search by description or reference..."
              value={filters.search}
              onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>
          <Select
            value={filters.status}
            onValueChange={(value: any) =>
              setFilters((prev) => ({ ...prev, status: value }))
            }
          >
            <SelectTrigger id="status-filter" className="h-8 w-44 rounded-full text-xs">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Transactions</SelectItem>
              <SelectItem value="reconciled">Reconciled</SelectItem>
              <SelectItem value="unreconciled">Unreconciled</SelectItem>
            </SelectContent>
          </Select>
          <Button
            variant="outline"
            size="icon"
            className="h-8 w-8 rounded-full"
            title="Clear filters"
            aria-label="Clear filters"
            onClick={() => setFilters({ status: 'all', search: '' })}
          >
            <Icon icon="solar:close-circle-bold" className="h-4 w-4" />
          </Button>
        </div>
      </Card>

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
                      {account.name}
                      {account.account_number ? ` (${account.account_number})` : ''}
                      {account.currency_code ? ` · ${account.currency_code}` : ''}
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
                  {selectedAccount.last_reconciliation_balance !== null
                    && selectedAccount.last_reconciliation_balance !== undefined
                    ? formatCurrency(
                        Number(selectedAccount.last_reconciliation_balance),
                        selectedAccount.currency_code,
                      )
                    : 'N/A'}
                </p>
              </div>
              <div>
                <Label>Current Balance</Label>
                <p className="text-lg font-bold mt-2">
                  {formatCurrency(
                    Number(selectedAccount.current_balance ?? 0),
                    selectedAccount.currency_code,
                  )}
                </p>
                <p className="text-sm text-muted-foreground mt-1">
                  Currency: {selectedAccount.currency_code || '—'}
                  {selectedAccount.bank_name ? ` · ${selectedAccount.bank_name}` : ''}
                  {selectedAccount.gl_account_name ? ` · GL: ${selectedAccount.gl_account_name}` : ''}
                </p>
              </div>
            </>
          )}
        </div>
      </Card>

      {selectedBankAccount && (
        <Card className="rounded-xl p-6 space-y-5">
          <div className="flex items-start gap-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">1</span>
            <div>
              <h2 className="text-base font-semibold">Import bank statement</h2>
              <p className="text-sm text-muted-foreground">CSV or XLSX only. Importing creates external statement records and never creates payments or journal entries.</p>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-end">
            <div className="space-y-1.5 md:col-span-2">
              <Label htmlFor="statement-file">Statement file</Label>
              <Input
                id="statement-file"
                type="file"
                accept=".csv,.xlsx"
                onChange={(e) => { setStatementFile(e.target.files?.[0] ?? null); setStatementPreview(null); }}
                className="h-9 cursor-pointer rounded-lg file:mr-3 file:rounded-full file:border-0 file:bg-primary/10 file:px-3 file:py-1 file:text-xs file:font-medium file:text-primary hover:file:bg-primary/20"
              />
              {statementFile && (
                <p className="text-xs text-muted-foreground">Selected: {statementFile.name}</p>
              )}
            </div>
            <HeaderActionButton
              icon="solar:document-add-bold-duotone"
              className="md:justify-self-end"
              disabled={!statementFile || previewStatementMutation.isPending}
              onClick={() => previewStatementMutation.mutate()}
            >
              {previewStatementMutation.isPending ? 'Inspecting…' : 'Map & Preview'}
            </HeaderActionButton>
          </div>
          {statementPreview && (
            <div className="rounded-xl border p-4 space-y-4">
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div><span className="text-muted-foreground">Rows</span><p className="font-semibold">{statementPreview.source_rows}</p></div>
                <div><span className="text-muted-foreground">Columns</span><p className="font-semibold">{statementPreview.headers.length}</p></div>
                <div><span className="text-muted-foreground">Date column</span><p className="font-semibold">{statementMapping.transaction_date ?? 'Not detected'}</p></div>
                <div><span className="text-muted-foreground">Amount columns</span><p className="font-semibold">{statementMapping.amount ?? `Debit: ${statementMapping.debit ?? '?'} / Credit: ${statementMapping.credit ?? '?'}`}</p></div>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {statementMappingFields.map(([field, label]) => (
                  <div key={field}>
                    <Label>{label}</Label>
                    <Select value={statementMapping[field] ?? 'unmapped'} onValueChange={(value) => setStatementMapping((current) => { const next = { ...current }; if (value === 'unmapped') delete next[field]; else next[field] = value; return next; })}>
                      <SelectTrigger><SelectValue placeholder="Not mapped" /></SelectTrigger>
                      <SelectContent><SelectItem value="unmapped">Not mapped</SelectItem>{statementPreview.headers.map((header: string) => <SelectItem key={header} value={header}>{header}</SelectItem>)}</SelectContent>
                    </Select>
                  </div>
                ))}
              </div>
              {(!statementMapping.transaction_date || !statementMapping.description || (!statementMapping.amount && (!statementMapping.debit || !statementMapping.credit))) ? (
                <p className="text-sm text-destructive">Required columns were not detected. Configure the source headers before importing.</p>
              ) : (
                <div className="space-y-4">
                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                      <Label htmlFor="import-statement-opening-balance">Statement Opening Balance</Label>
                      <Input id="import-statement-opening-balance" type="number" step="0.01" placeholder="0.00" value={statementOpeningBalance} onChange={(e) => setStatementOpeningBalance(e.target.value)} />
                    </div>
                    <div>
                      <Label htmlFor="import-statement-start-date">Statement Start Date (optional)</Label>
                      <Input id="import-statement-start-date" type="date" value={statementStartDate} onChange={(e) => setStatementStartDate(e.target.value)} />
                    </div>
                    <div>
                      <Label htmlFor="import-statement-end-date">Statement Ending Date</Label>
                      <Input id="import-statement-end-date" type="date" value={statementDate} onChange={(e) => setStatementDate(e.target.value)} />
                    </div>
                    <div>
                      <Label htmlFor="import-statement-balance">Statement Ending Balance</Label>
                      <Input id="import-statement-balance" type="number" step="0.01" placeholder="0.00" value={statementBalance} onChange={(e) => setStatementBalance(e.target.value)} />
                    </div>
                  </div>
                  {(!statementDate || statementOpeningBalance === '' || statementBalance === '') && (
                    <p className="text-sm text-amber-600">Enter the statement ending date, opening balance, and ending balance to enable import.</p>
                  )}
                  <HeaderActionButton
                    icon="solar:check-circle-bold-duotone"
                    disabled={!statementDate || statementOpeningBalance === '' || statementBalance === '' || importStatementMutation.isPending}
                    onClick={() => importStatementMutation.mutate()}
                  >
                    {importStatementMutation.isPending ? 'Importing…' : 'Confirm Import'}
                  </HeaderActionButton>
                </div>
              )}
            </div>
          )}
          <div className="space-y-3 border-t pt-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-start gap-3">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">2</span>
                <div>
                  <h3 className="text-base font-semibold">Imported bank transactions</h3>
                  <p className="text-sm text-muted-foreground">Match each statement line to a posted GL line.</p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Select value={statementTransactionStatus} onValueChange={(value) => setStatementTransactionStatus(value as StatementTransactionStatus)}>
                  <SelectTrigger className="h-8 w-[190px] rounded-full text-xs" aria-label="Imported transaction status">
                    <SelectValue placeholder="Filter by status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="unmatched">Unmatched</SelectItem>
                    <SelectItem value="suggested">Suggested</SelectItem>
                    <SelectItem value="partially_matched">Partially matched</SelectItem>
                    <SelectItem value="matched">Matched</SelectItem>
                    <SelectItem value="reconciled">Reconciled</SelectItem>
                    <SelectItem value="excluded">Excluded</SelectItem>
                    <SelectItem value="reversed">Reversed</SelectItem>
                  </SelectContent>
                </Select>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:magic-stick-3-bold-duotone"
                  title={activeStatementImport ? 'Generate match suggestions' : 'Import a statement before generating suggestions'}
                  disabled={!activeStatementImport || suggestMatchesMutation.isPending}
                  onClick={() => activeStatementImport && suggestMatchesMutation.mutate(activeStatementImport.id)}
                >
                  {suggestMatchesMutation.isPending ? 'Scoring…' : 'Suggest Matches'}
                </HeaderActionButton>
                <HeaderActionButton
                  variant="outline"
                  icon="solar:check-circle-bold-duotone"
                  title={activeStatementImport ? 'Accept unique suggestions scoring at least 95%' : 'Import a statement before accepting suggestions'}
                  disabled={!activeStatementImport || bulkAcceptMatchesMutation.isPending}
                  onClick={() => activeStatementImport && bulkAcceptMatchesMutation.mutate(activeStatementImport.id)}
                >
                  {bulkAcceptMatchesMutation.isPending ? 'Accepting…' : 'Accept 95%+ unique'}
                </HeaderActionButton>
                <HeaderActionButton
                  variant="ghost"
                  icon="solar:refresh-bold-duotone"
                  loading={statementTransactionsQuery.isFetching}
                  onClick={() => statementTransactionsQuery.refetch()}
                  disabled={statementTransactionsQuery.isFetching}
                >
                  Refresh
                </HeaderActionButton>
              </div>
            </div>
            {groupStatementIds.size > 0 && (
              <div className="mb-3 rounded-lg border bg-muted/20 p-3 space-y-3">
                <div className="font-medium">Grouped match · {groupStatementIds.size} statement transaction(s)</div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                  {(transactionsData?.data ?? []).filter((line) => {
                    const available = Number(line.available_amount ?? (Number(line.debit) - Number(line.credit)));
                    return Math.abs(available) > 0.00000001 && filteredStatementTransactions.some((statement: any) => groupStatementIds.has(statement.id)
                      && available * Number(statement.residual_amount) > 0
                      && normalizeMatchKey(statementIdentityValue(statement, 'customer_code')) !== ''
                      && normalizeMatchKey(statementIdentityValue(statement, 'customer_code')) === normalizeMatchKey(line.payment?.customer_code)
                      && normalizeMatchKey(statementIdentityValue(statement, 'bank_reference')) !== ''
                      && [line.payment?.reference, line.payment?.payment_number, line.reference]
                        .map(normalizeMatchKey)
                        .filter(Boolean)
                        .includes(normalizeMatchKey(statementIdentityValue(statement, 'bank_reference'))));
                  }).map((line) => (
                    <label key={line.id} className="flex items-center gap-2 rounded border p-2 text-sm">
                      <Checkbox checked={groupGlIds.has(line.id)} onCheckedChange={() => setGroupGlIds((current) => { const next = new Set(current); if (next.has(line.id)) next.delete(line.id); else next.add(line.id); return next; })} />
                      {line.date} · {line.payment?.reference ?? line.reference} · {line.payment?.customer_code} · {formatCurrency(Number(line.available_amount ?? (Number(line.debit) - Number(line.credit))))}
                    </label>
                  ))}
                </div>
                <Input value={groupMatchReason} onChange={(event) => setGroupMatchReason(event.target.value)} placeholder="Grouped match review reason" />
                <div className="flex gap-2"><Button type="button" disabled={groupGlIds.size === 0 || !groupMatchReason.trim() || groupedMatchMutation.isPending} onClick={() => groupedMatchMutation.mutate()}>{groupedMatchMutation.isPending ? 'Matching…' : 'Match selected group'}</Button><Button type="button" variant="ghost" onClick={() => { setGroupStatementIds(new Set()); setGroupGlIds(new Set()); }}>Cancel</Button></div>
              </div>
            )}
            {statementTransactionsQuery.isLoading ? (
              <p className="text-sm text-muted-foreground">Loading imported transactions…</p>
            ) : statementTransactionsQuery.error ? (
              <p className="text-sm text-destructive">Unable to load imported transactions.</p>
            ) : filteredStatementTransactions.length === 0 ? (
              <div className="rounded-xl border border-dashed py-10 text-center text-muted-foreground">
                <Icon icon="solar:inbox-bold-duotone" className="mx-auto mb-3 h-10 w-10 opacity-50" />
                <p className="text-sm font-medium">No imported statement transactions</p>
                <p className="mt-1 text-xs">Nothing matches the selected status and search filters.</p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b bg-muted/40">
                      <th className="px-3 py-2"><span className="sr-only">Select for grouped match</span></th>
                      <th className="text-left px-3 py-2">Date</th>
                      <th className="text-left px-3 py-2">Description</th>
                      <th className="text-left px-3 py-2">Bank reference</th>
                      <th className="text-left px-3 py-2">Invoice evidence</th>
                      <th className="text-right px-3 py-2">Amount</th>
                      <th className="text-left px-3 py-2">Status</th>
                      <th className="text-right px-3 py-2">Residual</th>
                      <th className="text-left px-3 py-2 min-w-[320px]">Match to posted GL line</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredStatementTransactions.map((transaction: any) => {
                      const invoiceEvidence = invoiceEvidenceFor(transaction);

                      return (
                      <tr key={transaction.id} className="border-b last:border-0">
                        <td className="px-3 py-2"><Checkbox disabled={!['unmatched', 'suggested', 'partially_matched'].includes(transaction.status)} checked={groupStatementIds.has(transaction.id)} onCheckedChange={() => setGroupStatementIds((current) => { const next = new Set(current); if (next.has(transaction.id)) next.delete(transaction.id); else next.add(transaction.id); return next; })} /></td>
                        <td className="px-3 py-2 whitespace-nowrap">{String(transaction.transaction_date).slice(0, 10)}</td>
                        <td className="px-3 py-2">{transaction.description}</td>
                        <td className="px-3 py-2">{statementIdentityValue(transaction, 'bank_reference') || '—'}</td>
                        <td className="px-3 py-2">
                          {invoiceEvidence.length > 0 ? (
                            <div className="flex flex-col items-start gap-1">
                              {invoiceEvidence.map((invoice) => invoice.id ? (
                                <Button
                                  key={`${invoice.id}-${invoice.number}`}
                                  type="button"
                                  variant="link"
                                  className="h-auto p-0 text-left font-medium"
                                  onClick={() => navigate(`/accounting/customers/bills/${invoice.id}`)}
                                >
                                  {invoice.number}
                                </Button>
                              ) : (
                                <span key={invoice.number}>{invoice.number}</span>
                              ))}
                            </div>
                          ) : '—'}
                        </td>
                        <td className={`px-3 py-2 text-right font-medium ${Number(transaction.amount) < 0 ? 'text-red-600' : 'text-green-600'}`}>
                          {formatCurrency(Number(transaction.amount))}
                        </td>
                        <td className="px-3 py-2 capitalize">
                          {(transaction.matches ?? []).some((match: any) => match.status === 'accepted' && match.reconciliation_id)
                            ? `Reconciled #${(transaction.matches ?? []).find((match: any) => match.status === 'accepted' && match.reconciliation_id)?.reconciliation_id} · ${String((transaction.matches ?? []).find((match: any) => match.status === 'accepted' && match.reconciliation_id)?.reconciliation?.statement_date ?? '').slice(0, 10)}`
                            : String(transaction.status).replace('_', ' ')}
                        </td>
                        <td className="px-3 py-2 text-right">{formatCurrency(Number(transaction.residual_amount))}</td>
                        <td className="px-3 py-2">
                          {transaction.status === 'excluded' ? (
                            <div className="space-y-1">
                              <p className="text-xs text-muted-foreground">{transaction.exclusion_type}: {transaction.exclusion_reason}</p>
                              <Button type="button" size="sm" variant="outline" onClick={() => { setStatementException({ transactionId: transaction.id, mode: 'restore' }); setExceptionReason(''); }}>Restore</Button>
                            </div>
                          ) : transaction.status === 'matched' ? (
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="text-green-600 font-medium">Fully matched</span>
                              {(transaction.matches ?? []).filter((match: any) => match.status === 'accepted' && !match.reconciliation_id).map((match: any) => (
                                <div key={match.id} className="flex items-center gap-2">
                                  {match.match_reasons?.invoice_id ? (
                                    <Button type="button" size="sm" variant="link" onClick={() => navigate(`/invoices/${match.match_reasons.invoice_id}`)}>
                                      Invoice {match.match_reasons.invoice_number ?? `#${match.match_reasons.invoice_id}`}
                                    </Button>
                                  ) : <span className="text-xs text-muted-foreground">No invoice linked to payment</span>}
                                  <Button type="button" size="sm" variant="ghost" onClick={() => unmatchStatementMutation.mutate(match.id)}>Unmatch</Button>
                                </div>
                              ))}
                            </div>
                          ) : (
                            <div className="flex flex-col gap-2">
                              {(transaction.matches ?? []).filter((match: any) => match.status === 'proposed').map((match: any) => (
                                <button
                                  key={match.id}
                                  type="button"
                                  aria-pressed={matchSelections[transaction.id]?.glLineId === String(match.journal_entry_line_id)}
                                  className={`w-full rounded border px-2 py-1 text-left text-xs transition-colors hover:border-primary hover:bg-primary/5 ${matchSelections[transaction.id]?.glLineId === String(match.journal_entry_line_id) ? 'border-primary bg-primary/10 ring-1 ring-primary' : 'bg-muted/30'}`}
                                  onClick={() => {
                                    const gl = transactionsData?.data?.find((line) => line.id === Number(match.journal_entry_line_id));
                                    const available = gl
                                      ? Number(gl.available_amount ?? (Number(gl.debit) - Number(gl.credit)))
                                      : Number(match.matched_amount ?? transaction.residual_amount);
                                    const residual = Number(transaction.residual_amount);
                                    const amount = Math.sign(residual) * Math.min(Math.abs(residual), Math.abs(available));
                                    setMatchSelections((current) => ({
                                      ...current,
                                      [transaction.id]: {
                                        glLineId: String(match.journal_entry_line_id),
                                        amount: String(amount),
                                        reason: current[transaction.id]?.reason ?? '',
                                      },
                                    }));
                                  }}
                                >
                                  <span className="font-medium">Suggested GL #{match.journal_entry_line_id} · {Math.round(Number(match.confidence) * 100)}%</span>
                                  <span className="block text-muted-foreground">
                                    {match.match_reasons?.exact_amount ? 'Amount ✓ ' : 'Amount differs · '}
                                    {match.match_reasons?.reference_match ? 'Reference ✓ ' : ''}
                                    {match.match_reasons?.invoice_match ? 'Invoice ✓ ' : ''}
                                    {(match.match_reasons?.customer_code_match || match.match_reasons?.customer_name_match) ? 'Customer ✓ ' : ''}
                                    {match.match_reasons?.ambiguous ? 'Ambiguous — review required' : ''}
                                  </span>
                                  {(match.match_reasons?.invoice_number || match.match_reasons?.payment_number) && (
                                    <span className="block text-muted-foreground">
                                      {match.match_reasons?.payment_number ? `Payment ${match.match_reasons.payment_number}` : ''}
                                      {match.match_reasons?.invoice_number ? ` · Invoice ${match.match_reasons.invoice_number}` : ''}
                                      {match.match_reasons?.customer_name ? ` · ${match.match_reasons.customer_name}` : ''}
                                    </span>
                                  )}
                                  <span className="mt-1 block font-medium text-primary">
                                    {matchSelections[transaction.id]?.glLineId === String(match.journal_entry_line_id) ? 'Selected for manual review' : 'Select this candidate'}
                                  </span>
                                </button>
                              ))}
                              <Select
                                value={matchSelections[transaction.id]?.glLineId || 'select'}
                                onValueChange={(value) => {
                                  const gl = transactionsData?.data?.find((line) => line.id === Number(value));
                                  const available = gl ? Number(gl.available_amount ?? (Number(gl.debit) - Number(gl.credit))) : Number(transaction.residual_amount);
                                  const residual = Number(transaction.residual_amount);
                                  const amount = Math.sign(residual) * Math.min(Math.abs(residual), Math.abs(available));
                                  setMatchSelections((current) => ({ ...current, [transaction.id]: { glLineId: value, amount: String(amount), reason: current[transaction.id]?.reason ?? '' } }));
                                }}
                              >
                                <SelectTrigger><SelectValue placeholder="Select GL transaction" /></SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="select" disabled>Select GL transaction</SelectItem>
                                  {(transactionsData?.data ?? [])
                                    .filter((line) => {
                                      const available = Number(line.available_amount ?? (Number(line.debit) - Number(line.credit)));
                                      const statementCustomerCode = normalizeMatchKey(statementIdentityValue(transaction, 'customer_code'));
                                      const paymentCustomerCode = normalizeMatchKey(line.payment?.customer_code);
                                      const statementReference = normalizeMatchKey(statementIdentityValue(transaction, 'bank_reference'));
                                      const paymentReferences = [line.payment?.reference, line.payment?.payment_number, line.reference]
                                        .map(normalizeMatchKey)
                                        .filter(Boolean);
                                      return Math.abs(available) > 0.00000001
                                        && available * Number(transaction.residual_amount) > 0
                                        && statementCustomerCode !== ''
                                        && statementCustomerCode === paymentCustomerCode
                                        && statementReference !== ''
                                        && paymentReferences.includes(statementReference);
                                    })
                                    .map((line) => (
                                    <SelectItem key={line.id} value={String(line.id)}>
                                      {line.date} · {line.reference || line.description} · {formatCurrency(Number(line.available_amount ?? (Number(line.debit) - Number(line.credit))))} available
                                      {line.payment?.invoice_number ? ` · Invoice ${line.payment.invoice_number}` : ''}
                                      {line.payment?.customer_name ? ` · ${line.payment.customer_name}` : ''}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                              <p className="text-xs text-muted-foreground">The dropdown lists exact customer-code and bank-reference matches. To review a lower-confidence candidate, select its suggestion card above and enter a reason.</p>
                              <Input
                                aria-label="Match review reason" placeholder="Reason for accepting this match"
                                value={matchSelections[transaction.id]?.reason ?? ''}
                                onChange={(e) => setMatchSelections((current) => ({ ...current, [transaction.id]: { glLineId: current[transaction.id]?.glLineId ?? '', amount: current[transaction.id]?.amount ?? String(transaction.residual_amount), reason: e.target.value } }))}
                              />
                              <div className="flex gap-2">
                                <Input
                                  type="number" step="0.01" aria-label="Match amount"
                                  value={matchSelections[transaction.id]?.amount ?? String(transaction.residual_amount)}
                                  onChange={(e) => setMatchSelections((current) => ({ ...current, [transaction.id]: { glLineId: current[transaction.id]?.glLineId ?? '', amount: e.target.value, reason: current[transaction.id]?.reason ?? '' } }))}
                                />
                                <Button
                                  type="button" size="sm"
                                  disabled={!matchSelections[transaction.id]?.glLineId || !matchSelections[transaction.id]?.reason?.trim() || matchStatementMutation.isPending}
                                  onClick={() => matchStatementMutation.mutate({ transactionId: transaction.id, glLineId: Number(matchSelections[transaction.id].glLineId), amount: Number(matchSelections[transaction.id].amount), reason: matchSelections[transaction.id].reason })}
                                >Match</Button>
                              </div>
                              {(transaction.matches ?? []).filter((match: any) => match.status === 'accepted' && !match.reconciliation_id).map((match: any) => (
                                <Button key={match.id} type="button" size="sm" variant="ghost" onClick={() => unmatchStatementMutation.mutate(match.id)}>Remove existing match</Button>
                              ))}
                            </div>
                          )}
                        </td>
                      </tr>
                      )
                    })}
                  </tbody>
                </table>
                <Pagination
                  page={statementTransactionPage}
                  totalPages={statementTransactionsQuery.data?.last_page ?? 1}
                  onPageChange={setStatementTransactionPage}
                  from={(statementTransactionPage - 1) * statementTransactionPerPage + 1}
                  to={Math.min(statementTransactionPage * statementTransactionPerPage, statementTransactionsQuery.data?.total ?? filteredStatementTransactions.length)}
                  total={statementTransactionsQuery.data?.total ?? filteredStatementTransactions.length}
                  label="transactions"
                  pageSize={statementTransactionPerPage}
                  onPageSizeChange={(size) => { setStatementTransactionPerPage(size); setStatementTransactionPage(1); }}
                />
              </div>
            )}
          </div>
          {(statementImportsQuery.data?.data ?? []).some((batch: any) => batch.status === 'imported') && (
            <div className="rounded-lg border p-4 space-y-3">
              <div className="flex items-center justify-between">
                <div><h3 className="font-medium">Review statement reconciliation</h3><p className="text-xs text-muted-foreground">Automatically recalculated after matching against source and accepted GL totals.</p></div>
                <Button type="button" variant="outline" disabled={validateStatementReconciliationMutation.isPending} onClick={() => validateStatementReconciliationMutation.mutate()}>
                  {validateStatementReconciliationMutation.isPending ? 'Recalculating…' : 'Refresh totals'}
                </Button>
              </div>
              {statementValidation && (
                <>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div><span className="text-muted-foreground">Opening</span><p className="font-medium">{formatCurrency(Number(statementValidation.opening_balance))}</p></div>
                    <div><span className="text-muted-foreground">Credits (money in)</span><p className="font-medium">{formatCurrency(Number(statementValidation.deposits))}</p></div>
                    <div><span className="text-muted-foreground">Debits (money out)</span><p className="font-medium">{formatCurrency(Number(statementValidation.withdrawals))}</p></div>
                    <div><span className="text-muted-foreground">Closing</span><p className="font-medium">{formatCurrency(Number(statementValidation.statement_closing_balance))}</p></div>
                    <div><span className="text-muted-foreground">Matched GL balance</span><p className="font-medium">{formatCurrency(Number(statementValidation.matched_gl_balance))}</p></div>
                    <div><span className="text-muted-foreground">Unmatched</span><p className="font-medium">{statementValidation.unmatched_statement_count} · {formatCurrency(Number(statementValidation.unmatched_statement_amount))}</p></div>
                    <div><span className="text-muted-foreground">Excluded</span><p className="font-medium">{statementValidation.excluded_count}</p></div>
                    <div><span className="text-muted-foreground">Difference</span><p className={statementValidation.valid ? 'font-medium text-green-600' : 'font-medium text-red-600'}>{formatCurrency(Number(statementValidation.difference))}</p></div>
                  </div>
                  {statementValidation.blockers?.length > 0 ? (
                    <div className="space-y-1">{statementValidation.blockers.map((blocker: any) => <p key={blocker.code} className="text-sm text-red-600">{blocker.message}</p>)}</div>
                  ) : <div className="flex items-center justify-between"><p className="text-sm text-green-600">All implemented statement reconciliation controls passed.</p><Button type="button" disabled={completeStatementReconciliationMutation.isPending} onClick={() => completeStatementReconciliationMutation.mutate()}>{completeStatementReconciliationMutation.isPending ? 'Completing…' : 'Complete statement reconciliation'}</Button></div>}
                </>
              )}
            </div>
          )}
        </Card>
      )}

      {/* Statement Information */}
      {selectedBankAccount && (
        <Card className="rounded-xl p-6 space-y-5">
          <div className="flex items-start gap-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">3</span>
            <div>
              <h2 className="text-base font-semibold">Statement Information</h2>
              <p className="text-sm text-muted-foreground">The bank statement's own dates and closing balance, used to compute the reconciliation difference.</p>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div className="space-y-1.5">
              <Label htmlFor="statement-start-date">Statement Start Date</Label>
              <Input id="statement-start-date" type="date" value={statementStartDate} onChange={(e) => setStatementStartDate(e.target.value)} className="h-9 rounded-lg" />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="statement-date">Statement Ending Date</Label>
              <Input
                id="statement-date"
                type="date"
                value={statementDate}
                onChange={(e) => setStatementDate(e.target.value)}
                className="h-9 rounded-lg"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="statement-balance">Statement Ending Balance</Label>
              <Input
                id="statement-balance"
                type="number"
                step="0.01"
                placeholder="0.00"
                value={statementBalance}
                onChange={(e) => setStatementBalance(e.target.value)}
                className="h-9 rounded-lg"
              />
            </div>
          </div>
        </Card>
      )}

      {/* Reconciliation Summary */}
      {selectedBankAccount && transactionsData && !(statementImportsQuery.data?.data ?? []).some((batch: any) => batch.status === 'imported') && (
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
              <HeaderActionButton icon="solar:add-circle-bold" onClick={() => setShowAddTransaction(true)} size="sm">
                Add Adjusting Entry
              </HeaderActionButton>
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
              statementBalance === '' ||
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
            <DialogTitle>{statementAdjustmentTransactionId ? 'Propose Statement Adjustment' : 'Add Adjusting Entry'}</DialogTitle>
            <DialogDescription>
              {statementAdjustmentTransactionId ? 'Create a maker-checker proposal. A different authorized user must approve it before posting and matching.' : 'Add a manual transaction to account for bank fees or other adjustments.'}
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
            <Button variant="outline" onClick={() => { setShowAddTransaction(false); setStatementAdjustmentTransactionId(null); }}>
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
                statementAdjustmentTransactionId ? 'Submit Proposal' : 'Add Transaction'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={statementException !== null} onOpenChange={(open) => { if (!open) setStatementException(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{statementException?.mode === 'exclude' ? 'Exclude statement transaction' : 'Restore statement transaction'}</DialogTitle>
            <DialogDescription>
              {statementException?.mode === 'exclude'
                ? 'Only proven duplicates or non-transaction statement rows may be excluded. Real cash movements must be matched or resolved.'
                : 'Restore this transaction to the unmatched matching queue.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {statementException?.mode === 'exclude' && (
              <>
                <div>
                  <Label>Classification</Label>
                  <Select value={exceptionType} onValueChange={(value: 'duplicate' | 'non_transaction') => setExceptionType(value)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent><SelectItem value="duplicate">Confirmed duplicate</SelectItem><SelectItem value="non_transaction">Non-transaction row</SelectItem></SelectContent>
                  </Select>
                </div>
                <div><Label htmlFor="exception-evidence">Evidence</Label><Input id="exception-evidence" value={exceptionEvidence} onChange={(event) => setExceptionEvidence(event.target.value)} placeholder="Describe the source evidence reviewed" /></div>
              </>
            )}
            <div><Label htmlFor="exception-reason">Reason</Label><Input id="exception-reason" value={exceptionReason} onChange={(event) => setExceptionReason(event.target.value)} /></div>
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setStatementException(null)}>Cancel</Button>
            <Button type="button" disabled={!exceptionReason.trim() || (statementException?.mode === 'exclude' && !exceptionEvidence.trim()) || statementExceptionMutation.isPending} onClick={() => statementExceptionMutation.mutate()}>
              {statementExceptionMutation.isPending ? 'Saving…' : statementException?.mode === 'exclude' ? 'Exclude' : 'Restore'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
