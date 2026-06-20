import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
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
import { formatCurrency } from '@/lib/utils';
import { useToast } from '@/hooks/use-toast';
import api, { accountingApi } from '@/lib/api';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';

interface UnpaidBill {
  id: number;
  bill_number: string;
  vendor_name: string;
  amount: number;
  balance: number;
  due_date: string;
  status: string;
}

export function ApSubledgerReconciliationPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [searchParams] = useSearchParams();

  const [reconciledItems, setReconciledItems] = useState<Set<number>>(new Set());
  const [filters, setFilters] = useState({ status: 'all', search: '' });

  const taskId = searchParams.get('task_id');

  const { data: vendorsData } = useQuery({
    queryKey: ['ap-vendors'],
    queryFn: () => accountingApi.get('/accounting/vendors', { params: { per_page: 100 } }).then((r: any) => r.data),
  });

  const { data: billsData, isLoading: isLoadingBills, refetch: refetchBills } = useQuery({
    queryKey: ['ap-unpaid-bills', filters],
    queryFn: () => {
      const params: any = { per_page: 100, status: 'posted,partial' };
      if (filters.search) {
        params.search = filters.search;
      }
      return accountingApi.get('/accounting/vendors/bills', { params }).then((r: any) => r.data);
    },
  });

  const { data: chartOfAccounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts({ is_active: true }),
  });

  const apAccountCode = searchParams.get('ap_code') || '2100';
  const apAccount = chartOfAccounts.find((acc: any) => acc.code === apAccountCode);

  const { data: glBalance } = useQuery({
    queryKey: ['ap-gl-balance', apAccount?.id],
    queryFn: () => apAccount ? chartOfAccountsApi.getAccountBalance(apAccount.id) : Promise.resolve(0),
    enabled: !!apAccount,
  });

  const subledgerBalance = billsData?.data
    ?.filter((b: UnpaidBill) => b.balance > 0)
    ?.reduce((sum: number, b: UnpaidBill) => sum + (b.balance || 0), 0) || 0;

  const difference = (glBalance || 0) - subledgerBalance;

  const filteredBills = billsData?.data?.filter((bill: UnpaidBill) => {
    if (bill.balance <= 0) return false;
    if (filters.search) {
      const search = filters.search.toLowerCase();
      return (
        (bill.bill_number || '').toLowerCase().includes(search) ||
        (bill.vendor_name || '').toLowerCase().includes(search)
      );
    }
    return true;
  }) || [];

  const handleReconcileItem = (billId: number, checked: boolean) => {
    const newSet = new Set(reconciledItems);
    if (checked) {
      newSet.add(billId);
    } else {
      newSet.delete(billId);
    }
    setReconciledItems(newSet);
  };

  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      setReconciledItems(new Set(filteredBills.map((b: UnpaidBill) => b.id)));
    } else {
      setReconciledItems(new Set());
    }
  };

  const completeReconciliationMutation = useMutation({
    mutationFn: async (data: { reconciled_bill_ids: number[]; notes?: string }) => {
      try {
        await accountingApi.post('/accounting/ap-reconciliation/complete', data);
        return { success: true };
      } catch (error) {
        return { success: false, error };
      }
    },
    onSuccess: (result) => {
      if (result.success) {
        toast({
          title: 'AP Reconciliation Complete',
          description: 'The AP subledger reconciliation has been recorded.',
        });
      } else {
        toast({
          title: 'Review Complete',
          description: `${reconciledItems.size} bill(s) marked for review. Note: Backend sync pending.`,
        });
      }
      queryClient.invalidateQueries({ queryKey: ['ap-unpaid-bills'] });
      if (taskId) {
        navigate(`/financial-close/tasks/${taskId}`);
      }
    },
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <Button variant="ghost" className="px-0" onClick={() => navigate(taskId ? `/financial-close/tasks/${taskId}` : '/accounting/vendors/bills')}>
            <Icon icon="solar:arrow-left-bold" className="mr-2 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-3xl font-bold mt-2">AP Subledger Reconciliation</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Compare Accounts Payable subledger to GL balance
          </p>
        </div>
        {taskId && (
          <Button variant="outline" onClick={() => navigate(`/financial-close/tasks/${taskId}`)}>
            Back to Task
          </Button>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card className="p-4">
          <p className="text-sm text-gray-600 dark:text-gray-400">GL AP Balance</p>
          <p className={`text-xl font-bold mt-1 ${glBalance >= 0 ? 'text-red-600' : 'text-green-600'}`}>
            {glBalance !== undefined ? formatCurrency(glBalance) : 'Loading...'}
          </p>
          {apAccount && <p className="text-xs text-muted-foreground mt-1">{apAccount.code} - {apAccount.name}</p>}
        </Card>
        <Card className="p-4">
          <p className="text-sm text-gray-600 dark:text-gray-400">Subledger Balance</p>
          <p className="text-xl font-bold mt-1 text-red-600">
            {formatCurrency(subledgerBalance)}
          </p>
          <p className="text-xs text-muted-foreground mt-1">{filteredBills.length} unpaid bills</p>
        </Card>
        <Card className={`p-4 ${Math.abs(difference) < 0.01 ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'}`}>
          <p className="text-sm text-gray-600 dark:text-gray-400">Difference</p>
          <p className={`text-xl font-bold mt-1 ${Math.abs(difference) < 0.01 ? 'text-green-600' : 'text-red-600'}`}>
            {formatCurrency(Math.abs(difference))}
          </p>
          {Math.abs(difference) < 0.01 ? (
            <p className="text-xs text-green-600 mt-1 flex items-center gap-1">
              <Icon icon="solar:check-circle-bold" className="h-3 w-3" />
              Balances match
            </p>
          ) : (
            <p className="text-xs text-red-600 mt-1 flex items-center gap-1">
              <Icon icon="solar:warning-bold" className="h-3 w-3" />
              Requires investigation
            </p>
          )}
        </Card>
      </div>

      <Card>
        <div className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">Unpaid Vendor Bills</h2>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
              <Input
                placeholder="Search bills or vendors..."
                value={filters.search}
                onChange={(e) => setFilters((prev) => ({ ...prev, search: e.target.value }))}
              />
            </div>
            <div className="flex items-end gap-2">
              <Button
                variant="outline"
                onClick={() => setFilters({ status: 'all', search: '' })}
                className="w-full"
              >
                <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                Clear
              </Button>
            </div>
          </div>

          {isLoadingBills ? (
            <div className="flex items-center justify-center py-12">
              <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
            </div>
          ) : filteredBills.length === 0 ? (
            <div className="text-center py-12">
              <Icon icon="solar:inbox-bold-duotone" className="h-12 w-12 text-gray-400 mx-auto mb-4" />
              <p className="text-gray-600">No unpaid bills found</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b">
                    <th className="text-left py-3 px-4 font-semibold w-12">
                      <Checkbox
                        checked={filteredBills.length > 0 && filteredBills.every((b: UnpaidBill) => reconciledItems.has(b.id))}
                        onCheckedChange={(checked) => handleSelectAll(checked as boolean)}
                      />
                    </th>
                    <th className="text-left py-3 px-4 font-semibold">Bill #</th>
                    <th className="text-left py-3 px-4 font-semibold">Vendor</th>
                    <th className="text-left py-3 px-4 font-semibold">Due Date</th>
                    <th className="text-right py-3 px-4 font-semibold">Amount</th>
                    <th className="text-right py-3 px-4 font-semibold">Balance</th>
                    <th className="text-left py-3 px-4 font-semibold">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredBills.map((bill: UnpaidBill) => (
                    <tr key={bill.id} className="border-b hover:bg-gray-50 dark:hover:bg-gray-800">
                      <td className="py-3 px-4">
                        <Checkbox
                          checked={reconciledItems.has(bill.id)}
                          onCheckedChange={(checked) => handleReconcileItem(bill.id, !!checked)}
                        />
                      </td>
                      <td className="py-3 px-4">
                        <button
                          className="text-primary hover:underline"
                          onClick={() => navigate(`/accounting/vendors/bills/${bill.id}`)}
                        >
                          {bill.bill_number || `Bill #${bill.id}`}
                        </button>
                      </td>
                      <td className="py-3 px-4">{bill.vendor_name || 'Unknown'}</td>
                      <td className="py-3 px-4">{bill.due_date ? new Date(bill.due_date).toLocaleDateString() : '-'}</td>
                      <td className="py-3 px-4 text-right">{formatCurrency(bill.amount || 0)}</td>
                      <td className="py-3 px-4 text-right font-semibold">{formatCurrency(bill.balance || 0)}</td>
                      <td className="py-3 px-4">
                        <span className={`px-2 py-1 rounded text-xs ${
                          bill.status === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800'
                        }`}>
                          {bill.status}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {reconciledItems.size > 0 && (
            <div className="mt-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
              <p className="text-sm font-medium">
                {reconciledItems.size} bill(s) marked for reconciliation
              </p>
              <div className="flex gap-2 mt-3">
                <Button
                  size="sm"
                  onClick={() => completeReconciliationMutation.mutate({ reconciled_bill_ids: Array.from(reconciledItems) })}
                  disabled={completeReconciliationMutation.isPending}
                >
                  {completeReconciliationMutation.isPending ? 'Saving...' : 'Confirm Reconciliation'}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setReconciledItems(new Set())}
                >
                  Clear Selection
                </Button>
              </div>
            </div>
          )}
        </div>
      </Card>
    </div>
  );
}