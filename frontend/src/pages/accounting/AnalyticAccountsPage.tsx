import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/hooks/use-toast';
import analyticAccountsApi, { type AnalyticAccount, type CreateAnalyticAccountRequest } from '@/lib/analyticAccountsApi';

export default function AnalyticAccountsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('list');
  const [accountType, setAccountType] = useState<'cost_center' | 'project' | 'department' | 'location'>('department');
  const [formOpen, setFormOpen] = useState(false);
  const [editingAccount, setEditingAccount] = useState<AnalyticAccount | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [formData, setFormData] = useState<CreateAnalyticAccountRequest>({
    code: '',
    name: '',
    type: 'department',
    parent_id: undefined,
    budget_amount: undefined,
    description: '',
  });

  // Fetch accounts
  const { data: accounts = [], isLoading } = useQuery({
    queryKey: ['analytic-accounts', { type: accountType, search: searchTerm }],
    queryFn: () => analyticAccountsApi.getAccounts({ type: accountType, search: searchTerm }),
  });

  // Fetch account tree
  const { data: accountTree = [] } = useQuery({
    queryKey: ['analytic-accounts-tree', accountType],
    queryFn: () => analyticAccountsApi.getAccountTree(accountType),
    enabled: activeTab === 'tree',
  });

  // Create account mutation
  const createMutation = useMutation({
    mutationFn: analyticAccountsApi.createAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['analytic-accounts'] });
      toast({ title: 'Success', description: 'Analytic account created successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to create account',
        variant: 'destructive',
      });
    },
  });

  // Update account mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<CreateAnalyticAccountRequest> }) =>
      analyticAccountsApi.updateAccount(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['analytic-accounts'] });
      toast({ title: 'Success', description: 'Analytic account updated successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to update account',
        variant: 'destructive',
      });
    },
  });

  // Delete account mutation
  const deleteMutation = useMutation({
    mutationFn: analyticAccountsApi.deleteAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['analytic-accounts'] });
      toast({ title: 'Success', description: 'Analytic account deactivated successfully' });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to deactivate account',
        variant: 'destructive',
      });
    },
  });

  const resetForm = () => {
    setFormData({
      code: '',
      name: '',
      type: accountType,
      parent_id: undefined,
      budget_amount: undefined,
      description: '',
    });
    setEditingAccount(null);
  };

  const handleOpenForm = (account?: AnalyticAccount) => {
    if (account) {
      setEditingAccount(account);
      setFormData({
        code: account.code,
        name: account.name,
        type: account.type,
        parent_id: account.parent_id,
        budget_amount: account.budget_amount,
        description: account.description || '',
      });
    } else {
      resetForm();
    }
    setFormOpen(true);
  };

  const handleSubmit = () => {
    if (!formData.code || !formData.name) {
      toast({
        title: 'Validation Error',
        description: 'Please fill in all required fields (Code, Name)',
        variant: 'destructive',
      });
      return;
    }

    if (editingAccount) {
      updateMutation.mutate({ id: editingAccount.id, data: formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to deactivate this analytic account?')) {
      deleteMutation.mutate(id);
    }
  };

  const getTypeBadge = (type: string) => {
    const colors: Record<string, string> = {
      department: 'bg-blue-100 text-blue-800',
      project: 'bg-green-100 text-green-800',
      location: 'bg-purple-100 text-purple-800',
      product_line: 'bg-orange-100 text-orange-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  const renderAccountTree = (accounts: AnalyticAccount[], level = 0) => {
    return accounts.map((account) => (
      <div key={account.id}>
        <div
          className={`flex items-center justify-between p-3 hover:bg-muted/50 rounded-lg`}
          style={{ paddingLeft: `${level * 24 + 12}px` }}
        >
          <div className="flex items-center gap-3 flex-1">
            {account.children && account.children.length > 0 && (
              <Icon icon="solar:alt-arrow-right-linear" className="w-4 h-4" />
            )}
            <div className="flex-1">
              <div className="flex items-center gap-2">
                <span className="font-mono text-sm text-muted-foreground">{account.code}</span>
                <span className="font-medium">{account.name}</span>
                <Badge className={getTypeBadge(account.type)}>{account.type}</Badge>
              </div>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {account.budget_amount && (
              <span className="font-mono text-sm">
                Budget: ${account.budget_amount.toLocaleString()}
              </span>
            )}
            <div className="flex gap-1">
              <Button
                size="sm"
                variant="ghost"
                onClick={() => handleOpenForm(account)}
              >
                <Icon icon="solar:pen-linear" className="w-4 h-4" />
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => handleDelete(account.id)}
              >
                <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
              </Button>
            </div>
          </div>
        </div>
        {account.children && account.children.length > 0 && renderAccountTree(account.children, level + 1)}
      </div>
    ));
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Analytic Accounts</h1>
          <p className="text-muted-foreground mt-1">
            Track costs and revenues by project, department, or cost center
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Type Selector */}
      <Card className="p-4">
        <div className="flex items-center gap-4">
          <Label>Account Type:</Label>
          <Select value={accountType} onValueChange={(value: any) => setAccountType(value)}>
            <SelectTrigger className="w-[200px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="department">Departments</SelectItem>
              <SelectItem value="project">Projects</SelectItem>
              <SelectItem value="location">Locations</SelectItem>
              <SelectItem value="product_line">Product Lines</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </Card>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="list">List View</TabsTrigger>
          <TabsTrigger value="tree">Tree View</TabsTrigger>
        </TabsList>

        <TabsContent value="list" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Analytic Accounts</h2>
              <div className="flex gap-2">
                <Input
                  placeholder="Search accounts..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-64"
                />
                <HeaderActionButton icon="solar:add-circle-linear" onClick={() => handleOpenForm()}>
                  Add Account
                </HeaderActionButton>
              </div>
            </div>

            {isLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading accounts...</p>
              </div>
            ) : accounts.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:pie-chart-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No analytic accounts found</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Parent</TableHead>
                    <TableHead className="text-right">Budget</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {accounts.map((account) => (
                    <TableRow key={account.id}>
                      <TableCell className="font-mono">{account.code}</TableCell>
                      <TableCell className="font-medium">{account.name}</TableCell>
                      <TableCell>
                        <Badge className={getTypeBadge(account.type)}>
                          {account.type}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {account.parent?.name || '-'}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {account.budget_amount ? `$${account.budget_amount.toLocaleString()}` : '-'}
                      </TableCell>
                      <TableCell>
                        <Badge variant={account.is_active ? 'default' : 'secondary'}>
                          {account.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleOpenForm(account)}
                          >
                            <Icon icon="solar:pen-linear" className="w-4 h-4" />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleDelete(account.id)}
                          >
                            <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="tree" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Hierarchy Tree</h2>
            {accountTree.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:hierarchy-square-3-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No accounts found</p>
              </div>
            ) : (
              <div className="space-y-1">{renderAccountTree(accountTree)}</div>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      {/* Account Form Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingAccount ? 'Edit Analytic Account' : 'Add Analytic Account'}</DialogTitle>
            <DialogDescription>
              {editingAccount ? 'Update account details' : 'Create a new analytic account for tracking'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="code">Account Code *</Label>
                <Input
                  id="code"
                  placeholder="DEPT001"
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="type">Type *</Label>
                <Select
                  value={formData.type}
                  onValueChange={(value: any) => setFormData({ ...formData, type: value })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="department">Department</SelectItem>
                    <SelectItem value="project">Project</SelectItem>
                    <SelectItem value="location">Location</SelectItem>
                    <SelectItem value="product_line">Product Line</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">Account Name *</Label>
              <Input
                id="name"
                placeholder="Marketing Department"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="parent">Parent Account</Label>
                <Select
                  value={formData.parent_id?.toString() || 'none'}
                  onValueChange={(value) =>
                    setFormData({ ...formData, parent_id: value === 'none' ? undefined : parseInt(value) })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="None" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">None</SelectItem>
                    {accounts
                      .filter((a) => a.id !== editingAccount?.id)
                      .map((account) => (
                        <SelectItem key={account.id} value={account.id.toString()}>
                          {account.code} - {account.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="budget_amount">Budget Amount</Label>
                <Input
                  id="budget_amount"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                  value={formData.budget_amount || ''}
                  onChange={(e) =>
                    setFormData({ ...formData, budget_amount: parseFloat(e.target.value) || undefined })
                  }
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Input
                id="description"
                placeholder="Account description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              {createMutation.isPending || updateMutation.isPending ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Saving...
                </>
              ) : (
                'Save Account'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
