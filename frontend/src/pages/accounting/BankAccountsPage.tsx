import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface BankAccount {
  id: number;
  account_name: string;
  account_number?: string;
  bank_name?: string;
  currency_code: string;
  gl_account_id: number;
  gl_account_name?: string;
  gl_account_code?: string;
  is_active: boolean;
}

function BankAccountsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingAccount, setEditingAccount] = useState<BankAccount | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [currencyCode, setCurrencyCode] = useState('USD');
  const [glAccountId, setGlAccountId] = useState('');

  // Fetch bank accounts
  const { data: bankAccountsData, isLoading } = useQuery({
    queryKey: ['bank-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/bank-accounts');
      return response.data;
    },
  });

  const bankAccounts = bankAccountsData?.data || [];

  // Fetch GL accounts (asset accounts for bank)
  const { data: glAccountsData } = useQuery({
    queryKey: ['gl-accounts-asset'],
    queryFn: async () => {
      const response = await api.get('/accounting/chart-of-accounts?type=asset');
      return response.data;
    },
  });

  const glAccounts = glAccountsData?.data || [];

  // Fetch currencies
  const { data: currenciesData } = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const response = await api.get('/accounting/currencies');
      return response.data;
    },
  });

  const currencies = currenciesData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (data: Partial<BankAccount>) => {
      const response = await api.post('/accounting/bank-accounts', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
      setIsDialogOpen(false);
      setEditingAccount(null);
      toast({
        title: 'Success',
        description: 'Bank account created successfully',
      });
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors;
      const msg = errors ? Object.values(errors).flat().join(', ') : (error.response?.data?.message || error.response?.data?.error || 'Failed to create bank account');
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<BankAccount> }) => {
      const response = await api.put(`/accounting/bank-accounts/${id}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
      setIsDialogOpen(false);
      setEditingAccount(null);
      toast({
        title: 'Success',
        description: 'Bank account updated successfully',
      });
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors;
      const msg = errors ? Object.values(errors).flat().join(', ') : (error.response?.data?.message || error.response?.data?.error || 'Failed to update bank account');
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/accounting/bank-accounts/${id}`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
      toast({
        title: 'Success',
        description: 'Bank account deactivated successfully',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to deactivate bank account',
        variant: 'destructive',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);

    if (!currencyCode) {
      toast({ title: 'Validation Error', description: 'Please select a currency', variant: 'destructive' });
      return;
    }
    if (!glAccountId) {
      toast({ title: 'Validation Error', description: 'Please select a GL account', variant: 'destructive' });
      return;
    }
    
    const data = {
      account_name: formData.get('account_name') as string,
      account_number: formData.get('account_number') as string || null,
      bank_name: formData.get('bank_name') as string || null,
      currency_code: currencyCode,
      gl_account_id: parseInt(glAccountId),
    };

    if (editingAccount) {
      updateMutation.mutate({ id: editingAccount.id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  const handleEdit = (account: BankAccount) => {
    setEditingAccount(account);
    setCurrencyCode(account.currency_code || 'USD');
    setGlAccountId(account.gl_account_id?.toString() || '');
    setIsDialogOpen(true);
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to deactivate this bank account?')) {
      deleteMutation.mutate(id);
    }
  };

  const filteredBankAccounts = bankAccounts.filter((account: BankAccount) =>
    (account.account_name && account.account_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
    (account.bank_name && account.bank_name.toLowerCase().includes(searchQuery.toLowerCase())) ||
    (account.account_number && account.account_number.includes(searchQuery))
  );

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
          <p className="text-muted-foreground mt-2">Loading bank accounts...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Bank Accounts</h1>
          <p className="text-muted-foreground mt-1">
            Set up bank accounts and link them to GL accounts
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Info Card */}
      <Card className="p-4 bg-blue-50 border-blue-200">
        <div className="flex items-start gap-3">
          <Icon icon="solar:info-circle-bold" className="w-5 h-5 text-blue-600 mt-0.5" />
          <div className="flex-1">
            <h3 className="font-semibold text-blue-900">Bank Account Setup</h3>
            <p className="text-sm text-blue-800 mt-1">
              Configure your bank accounts and link them to GL accounts. Each bank account must be linked to an asset account in your chart of accounts.
            </p>
          </div>
        </div>
      </Card>

      {/* Actions Bar */}
      <Card className="p-4">
        <div className="flex items-center justify-between gap-4">
          <div className="flex-1 max-w-md">
            <Input
              placeholder="Search bank accounts..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full"
            />
          </div>
          <Button
            onClick={() => {
              setEditingAccount(null);
              setCurrencyCode('USD');
              setGlAccountId('');
              setIsDialogOpen(true);
            }}
          >
            <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
            Add Bank Account
          </Button>
        </div>
      </Card>

      {/* Bank Accounts Table */}
      <Card>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Account Name</TableHead>
              <TableHead>Bank</TableHead>
              <TableHead>Account Number</TableHead>
              <TableHead>GL Account</TableHead>
              <TableHead>Currency</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredBankAccounts.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                  No bank accounts found
                </TableCell>
              </TableRow>
            ) : (
              filteredBankAccounts.map((account: BankAccount) => (
                <TableRow key={account.id}>
                  <TableCell className="font-medium">{account.account_name}</TableCell>
                  <TableCell>{account.bank_name || '-'}</TableCell>
                  <TableCell className="font-mono text-sm">{account.account_number || '-'}</TableCell>
                  <TableCell className="text-sm">
                    {account.gl_account_code} - {account.gl_account_name}
                  </TableCell>
                  <TableCell className="font-semibold">{account.currency_code}</TableCell>
                  <TableCell>
                    <Badge variant={account.is_active ? 'default' : 'secondary'}>
                      {account.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleEdit(account)}
                      >
                        <Icon icon="solar:pen-linear" className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDelete(account.id)}
                      >
                        <Icon icon="solar:trash-bin-linear" className="w-4 h-4" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </Card>

      {/* Create/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {editingAccount ? 'Edit Bank Account' : 'Add Bank Account'}
            </DialogTitle>
            <DialogDescription>
              {editingAccount
                ? 'Update the bank account details below.'
                : 'Create a new bank account and link it to a GL account.'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="account_name">Account Name *</Label>
              <Input
                id="account_name"
                name="account_name"
                defaultValue={editingAccount?.account_name}
                placeholder="Main Business Account"
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="bank_name">Bank Name</Label>
                <Input
                  id="bank_name"
                  name="bank_name"
                  defaultValue={editingAccount?.bank_name}
                  placeholder="Bank of America"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="account_number">Account Number</Label>
                <Input
                  id="account_number"
                  name="account_number"
                  defaultValue={editingAccount?.account_number}
                  placeholder="1234567890"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="currency_code">Currency *</Label>
                <Select value={currencyCode} onValueChange={setCurrencyCode}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select currency" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.length > 0 ? (
                      currencies.map((curr: any) => (
                        <SelectItem key={curr.code} value={curr.code}>
                          {curr.code} - {curr.name}
                        </SelectItem>
                      ))
                    ) : (
                      <SelectItem value="USD">USD - US Dollar</SelectItem>
                    )}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="gl_account_id">GL Account (Asset) *</Label>
                <Select 
                  value={glAccountId} 
                  onValueChange={setGlAccountId}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select GL account" />
                  </SelectTrigger>
                  <SelectContent>
                    {glAccounts.map((account: any) => (
                      <SelectItem key={account.id} value={account.id.toString()}>
                        {account.code} - {account.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <p className="text-sm text-blue-800">
                <strong>Note:</strong> The GL account should be an asset account that represents this bank account in your chart of accounts.
              </p>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsDialogOpen(false);
                  setEditingAccount(null);
                }}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={createMutation.isPending || updateMutation.isPending}
              >
                {createMutation.isPending || updateMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    {editingAccount ? 'Update' : 'Create'}
                  </>
                )}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

export default BankAccountsPage;

