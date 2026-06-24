import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ImportButton } from '@/components/ImportButton';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import chartOfAccountsApi, { type ChartOfAccount, type CreateAccountRequest } from '@/lib/chartOfAccountsApi';

export default function ChartOfAccountsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('list');
  const [formOpen, setFormOpen] = useState(false);
  const [editingAccount, setEditingAccount] = useState<ChartOfAccount | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [expandedNodes, setExpandedNodes] = useState<Set<number>>(new Set());
  const [formData, setFormData] = useState<CreateAccountRequest>({
    code: '',
    account_group: '',
    name: '',
    type: 'asset',
    subtype: '',
    parent_id: undefined,
    description: '',
    is_active: true,
    opening_balance: 0,
  });

  // Helper function to determine account level based on SFRS code format
  const getAccountLevel = (code: string): number => {
    if (code.endsWith('000')) return 1; // Level 1: X000 (Parents)
    if (code.endsWith('00')) return 2; // Level 2: XX00 (Groups)
    return 3; // Level 3: XXXX (Accounts)
  };

  // Toggle node expansion
  const toggleNode = (id: number) => {
    const newExpanded = new Set(expandedNodes);
    if (newExpanded.has(id)) {
      newExpanded.delete(id);
    } else {
      newExpanded.add(id);
    }
    setExpandedNodes(newExpanded);
  };

  // Fetch accounts
  const { data: accounts = [], isLoading } = useQuery({
    queryKey: ['chart-of-accounts', { search: searchTerm, type: typeFilter }],
    queryFn: () => chartOfAccountsApi.getAccounts({ 
      search: searchTerm, 
      type: typeFilter === 'all' ? undefined : typeFilter 
    }),
  });

  // Fetch account tree
  const { data: accountTree = [] } = useQuery({
    queryKey: ['chart-of-accounts-tree'],
    queryFn: () => chartOfAccountsApi.getAccountTree(),
    enabled: activeTab === 'tree',
  });

  // Auto-expand Level 1 parents when tree loads
  useEffect(() => {
    if (accountTree.length > 0 && expandedNodes.size === 0) {
      const parentIds = accountTree.map(account => account.id);
      setExpandedNodes(new Set(parentIds));
    }
  }, [accountTree]);

  // Create account mutation
  const createMutation = useMutation({
    mutationFn: chartOfAccountsApi.createAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts'] });
      toast({ title: 'Success', description: 'Account created successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      console.error('Create account error:', error.response?.data);
      const errorMessage = error.response?.data?.errors 
        ? Object.values(error.response.data.errors).flat().join(', ')
        : error.response?.data?.message || error.response?.data?.error || 'Failed to create account';
      
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      });
    },
  });

  // Update account mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: CreateAccountRequest }) =>
      chartOfAccountsApi.updateAccount(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts'] });
      toast({ title: 'Success', description: 'Account updated successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      const errorMessage = error.response?.data?.errors 
        ? Object.values(error.response.data.errors).flat().join(', ')
        : error.response?.data?.message || 'Failed to update account';
      
      toast({
        title: 'Error',
        description: errorMessage,
        variant: 'destructive',
      });
    },
  });

  // Delete account mutation
  const deleteMutation = useMutation({
    mutationFn: chartOfAccountsApi.deleteAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['chart-of-accounts'] });
      toast({ title: 'Success', description: 'Account deleted successfully' });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete account',
        variant: 'destructive',
      });
    },
  });

  const resetForm = () => {
    setFormData({
      code: '',
      account_group: '',
      name: '',
      type: 'asset',
      subtype: '',
      parent_id: undefined,
      description: '',
      is_active: true,
      opening_balance: 0,
    });
    setEditingAccount(null);
  };

  const handleOpenForm = (account?: ChartOfAccount) => {
    if (account) {
      setEditingAccount(account);
      setFormData({
        code: account.code,
        account_group: account.account_group || '',
        name: account.name,
        type: account.type,
        subtype: account.subtype || '',
        parent_id: account.parent_id,
        description: account.description || '',
        is_active: account.is_active,
        opening_balance: account.opening_balance || 0,
      });
    } else {
      resetForm();
    }
    setFormOpen(true);
  };

  const handleSubmit = () => {
    // Validate required fields
    if (!formData.code || !formData.name || !formData.type) {
      toast({
        title: 'Validation Error',
        description: 'Please fill in all required fields (Code, Name, Type)',
        variant: 'destructive',
      });
      return;
    }

    // Prepare data for submission - remove empty optional fields
    const submitData: any = {
      code: formData.code.trim(),
      name: formData.name.trim(),
      type: formData.type,
      is_active: formData.is_active,
    };

    // Add optional fields only if they have values
    if (formData.account_group && formData.account_group.trim()) {
      submitData.account_group = formData.account_group.trim();
    }

    if (formData.subtype && formData.subtype.trim()) {
      submitData.subtype = formData.subtype.trim();
    }

    if (formData.parent_id) {
      submitData.parent_id = formData.parent_id;
    }

    if (formData.description && formData.description.trim()) {
      submitData.description = formData.description.trim();
    }

    // Only include opening_balance when creating new account
    if (!editingAccount && formData.opening_balance !== undefined) {
      submitData.opening_balance = formData.opening_balance;
    }

    if (editingAccount) {
      updateMutation.mutate({ id: editingAccount.id, data: submitData });
    } else {
      createMutation.mutate(submitData);
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this account?')) {
      deleteMutation.mutate(id);
    }
  };

  const getAccountTypeBadge = (type: string) => {
    const colors: Record<string, string> = {
      asset: 'bg-blue-100 text-blue-800',
      liability: 'bg-red-100 text-red-800',
      equity: 'bg-purple-100 text-purple-800',
      revenue: 'bg-green-100 text-green-800',
      expense: 'bg-orange-100 text-orange-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  // Get level badge styling
  const getLevelBadge = (level: number) => {
    const badges = {
      1: { label: 'L1', class: 'bg-purple-100 text-purple-800 font-bold' },
      2: { label: 'L2', class: 'bg-blue-100 text-blue-800 font-semibold' },
      3: { label: 'L3', class: 'bg-gray-100 text-gray-600' },
    };
    return badges[level as keyof typeof badges] || badges[3];
  };

  const renderAccountTree = (accounts: ChartOfAccount[], level = 0) => {
    return accounts.map((account) => {
      const accountLevel = getAccountLevel(account.code);
      const levelBadge = getLevelBadge(accountLevel);
      const isParent = accountLevel === 1;
      const isGroup = accountLevel === 2;
      const hasChildren = account.children && account.children.length > 0;
      const isExpanded = expandedNodes.has(account.id);

      return (
        <div key={account.id} className={isParent ? 'mb-4' : ''}>
          <div
            className={`flex items-center justify-between p-3 rounded-lg transition-colors ${
              isParent
                ? 'bg-purple-50 border-l-4 border-purple-500 font-bold'
                : isGroup
                ? 'bg-blue-50 border-l-4 border-blue-400 font-semibold'
                : 'hover:bg-muted/50 border-l-2 border-gray-200'
            }`}
            style={{ 
              paddingLeft: `${level * 32 + 12}px`,
              marginTop: isParent ? '16px' : isGroup ? '8px' : '2px'
            }}
          >
            <div className="flex items-center gap-3 flex-1">
              {hasChildren ? (
                <button
                  onClick={() => toggleNode(account.id)}
                  className="p-0 hover:bg-transparent focus:outline-none"
                  title={isExpanded ? 'Collapse' : 'Expand'}
                >
                  <Icon 
                    icon="solar:alt-arrow-right-linear" 
                    className={`w-4 h-4 transition-transform ${
                      isExpanded ? 'rotate-90' : ''
                    } ${isParent ? 'text-purple-600' : isGroup ? 'text-blue-600' : ''}`}
                  />
                </button>
              ) : (
                <div className="w-4" />
              )}
              <Badge className={`${levelBadge.class} text-xs px-2`}>
                {levelBadge.label}
              </Badge>
              <div className="flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className={`font-mono text-sm ${
                    isParent ? 'text-purple-700 font-bold' : 
                    isGroup ? 'text-blue-700 font-semibold' : 
                    'text-muted-foreground'
                  }`}>
                    {account.code}
                  </span>
                  <span className={`${
                    isParent ? 'text-lg font-bold text-purple-900' : 
                    isGroup ? 'text-base font-semibold text-blue-900' : 
                    'font-medium'
                  }`}>
                    {account.name}
                  </span>
                  {account.account_group && (
                    <span className="text-xs text-muted-foreground bg-gray-100 px-2 py-1 rounded">
                      Group: {account.account_group}
                    </span>
                  )}
                  <Badge className={getAccountTypeBadge(account.type)}>
                    {account.type}
                  </Badge>
                  {account.subtype && (
                    <span className="text-xs text-muted-foreground">
                      {account.subtype.replace(/_/g, ' ')}
                    </span>
                  )}
                </div>
              </div>
            </div>
            <div className="flex items-center gap-3">
              {accountLevel === 3 && (
                <span className={`font-mono text-sm ${
                  (account.balance || 0) < 0 ? 'text-red-600' : 'text-green-600'
                }`}>
                  {account.balance?.toLocaleString('en-US', {
                    style: 'currency',
                    currency: 'USD',
                  })}
                </span>
              )}
              <div className="flex gap-1">
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => handleOpenForm(account)}
                  title="Edit account"
                >
                  <Icon icon="solar:pen-linear" className="w-4 h-4" />
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => handleDelete(account.id)}
                  disabled={account.is_system || hasChildren}
                  title={
                    hasChildren
                      ? 'Cannot delete accounts with children'
                      : account.is_system
                      ? 'System accounts cannot be deleted'
                      : 'Delete account'
                  }
                >
                  <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                </Button>
              </div>
            </div>
          </div>
          {hasChildren && isExpanded && (
            <div className={isParent ? 'ml-4' : 'ml-2'}>
              {renderAccountTree(account.children, level + 1)}
            </div>
          )}
        </div>
      );
    });
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Chart of Accounts</h1>
          <p className="text-muted-foreground mt-1">
            Define and manage your company's account structure
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="list">List View</TabsTrigger>
          <TabsTrigger value="tree">Tree View</TabsTrigger>
        </TabsList>

        <TabsContent value="list" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Accounts</h2>
              <div className="flex gap-2">
                <Input
                  placeholder="Search accounts..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-64"
                />
                <Select value={typeFilter} onValueChange={setTypeFilter}>
                  <SelectTrigger className="w-40">
                    <SelectValue placeholder="All Types" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Types</SelectItem>
                    <SelectItem value="asset">Asset</SelectItem>
                    <SelectItem value="liability">Liability</SelectItem>
                    <SelectItem value="equity">Equity</SelectItem>
                    <SelectItem value="revenue">Revenue</SelectItem>
                    <SelectItem value="expense">Expense</SelectItem>
                  </SelectContent>
                </Select>
                <ImportButton 
                  importType="chart-of-accounts"
                  onImportComplete={() => queryClient.invalidateQueries({ queryKey: ['chart-of-accounts'] })}
                  variant="outline"
                />
                <Button onClick={() => handleOpenForm()}>
                  <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                  Add Account
                </Button>
              </div>
            </div>

            {isLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading accounts...</p>
              </div>
            ) : accounts.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:list-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No accounts found</p>
              </div>
            ) : (
              <>
                <div className="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                  <div className="text-sm text-blue-900 flex items-center flex-wrap gap-1">
                    <Icon icon="solar:info-circle-bold" className="w-4 h-4 mr-1" />
                    <strong>Hierarchy Guide:</strong> 
                    <Badge className="bg-purple-100 text-purple-800 font-bold text-xs px-2">L1</Badge>
                    <span>Parents (X000) •</span>
                    <Badge className="bg-blue-100 text-blue-800 font-semibold text-xs px-2">L2</Badge>
                    <span>Groups (XX00) •</span>
                    <Badge className="bg-gray-100 text-gray-600 text-xs px-2">L3</Badge>
                    <span>Accounts (XXXX)</span>
                  </div>
                </div>
                <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-16">Level</TableHead>
                    <TableHead>Code</TableHead>
                    <TableHead>Account Group</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Subtype</TableHead>
                    <TableHead>Parent</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {accounts.map((account) => {
                    const accountLevel = getAccountLevel(account.code);
                    const levelBadge = getLevelBadge(accountLevel);
                    const isParent = accountLevel === 1;
                    const isGroup = accountLevel === 2;

                    return (
                      <TableRow 
                        key={account.id}
                        className={`
                          ${isParent
                            ? 'bg-purple-50 font-bold border-l-4 border-l-purple-500 border-t-2 border-t-purple-200'
                            : isGroup
                            ? 'bg-blue-50 font-semibold border-l-4 border-l-blue-400 border-t border-t-blue-100'
                            : 'hover:bg-gray-50 border-l-2 border-l-gray-200'
                          }
                        `}
                      >
                        <TableCell>
                          <Badge className={`${levelBadge.class} text-xs`}>
                            {levelBadge.label}
                          </Badge>
                        </TableCell>
                        <TableCell className={`font-mono ${
                          isParent ? 'text-purple-700 font-bold' : 
                          isGroup ? 'text-blue-700 font-semibold' : ''
                        }`}>
                          {account.code}
                        </TableCell>
                        <TableCell className="font-mono text-sm text-muted-foreground">
                          {account.account_group || (
                            <span className="text-xs text-gray-400 italic">
                              {isParent ? 'Parent' : isGroup ? 'Group' : '-'}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className={`font-medium ${
                          isParent ? 'text-purple-900 text-base' : 
                          isGroup ? 'text-blue-900' : ''
                        }`}>
                          {account.name}
                        </TableCell>
                      <TableCell>
                        <Badge className={getAccountTypeBadge(account.type)}>
                          {account.type}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <span className="text-sm text-muted-foreground">
                          {account.subtype ? account.subtype.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : '-'}
                        </span>
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {account.parent?.name || '-'}
                      </TableCell>
                      <TableCell className="text-right font-mono">
                        {account.balance?.toLocaleString('en-US', {
                          style: 'currency',
                          currency: 'USD',
                        })}
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
                              title="Edit account"
                            >
                              <Icon icon="solar:pen-linear" className="w-4 h-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleDelete(account.id)}
                              disabled={account.is_system}
                              title={account.is_system ? 'System accounts cannot be deleted' : 'Delete account'}
                            >
                              <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
              </>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="tree" className="space-y-4">
          <Card className="p-6">
            {/* Hierarchy Legend */}
            <div className="mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
              <h3 className="text-sm font-semibold mb-3 flex items-center gap-2">
                <Icon icon="solar:info-circle-bold" className="w-5 h-5 text-blue-600" />
                SFRS 3-Level Hierarchy Guide
              </h3>
              <div className="grid grid-cols-3 gap-4 text-sm">
                  <div className="flex items-start gap-2">
                    <Badge className="bg-purple-100 text-purple-800 font-bold text-xs px-2 mt-0.5">L1</Badge>
                    <div>
                      <div className="font-semibold text-purple-900">Level 1: Parents</div>
                      <div className="text-xs text-gray-600">Main categories (X000)</div>
                      <div className="text-xs text-gray-500 mt-1">Example: 1000 - Assets</div>
                    </div>
                  </div>
                  <div className="flex items-start gap-2">
                    <Badge className="bg-blue-100 text-blue-800 font-semibold text-xs px-2 mt-0.5">L2</Badge>
                    <div>
                      <div className="font-semibold text-blue-900">Level 2: Groups</div>
                      <div className="text-xs text-gray-600">Sub-categories (XX00)</div>
                      <div className="text-xs text-gray-500 mt-1">Example: 1100 - Cash</div>
                    </div>
                  </div>
                  <div className="flex items-start gap-2">
                    <Badge className="bg-gray-100 text-gray-600 text-xs px-2 mt-0.5">L3</Badge>
                    <div>
                      <div className="font-semibold text-gray-900">Level 3: Accounts</div>
                      <div className="text-xs text-gray-600">Individual accounts (XXXX)</div>
                      <div className="text-xs text-gray-500 mt-1">Example: 1010 - Petty Cash</div>
                    </div>
                  </div>
              </div>
            </div>

            <h2 className="text-xl font-semibold mb-4">Account Hierarchy</h2>
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
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editingAccount ? 'Edit Account' : 'Add Account'}</DialogTitle>
            <DialogDescription>
              {editingAccount ? 'Update account details' : 'Create a new account in the chart of accounts'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="code">Account Code *</Label>
                <Input
                  id="code"
                  placeholder="1010"
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">
                  Use 4-digit SFRS format (e.g., 1010, 1200)
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="account_group">Account Group (SFRS Level 2)</Label>
                <Input
                  id="account_group"
                  placeholder="1200"
                  value={formData.account_group}
                  onChange={(e) => setFormData({ ...formData, account_group: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">
                  SFRS grouping code (e.g., 1100, 1200, 2100)
                </p>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">Account Name *</Label>
              <Input
                id="name"
                placeholder="Trade Receivable - General"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="type">Account Type *</Label>
                <Select
                  value={formData.type}
                  onValueChange={(value: any) => setFormData({ ...formData, type: value })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="asset">Asset</SelectItem>
                    <SelectItem value="liability">Liability</SelectItem>
                    <SelectItem value="equity">Equity</SelectItem>
                    <SelectItem value="revenue">Revenue</SelectItem>
                    <SelectItem value="expense">Expense</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="subtype">Subtype</Label>
                <Select
                  value={formData.subtype || 'none'}
                  onValueChange={(value) => setFormData({ ...formData, subtype: value === 'none' ? '' : value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select subtype" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">None</SelectItem>
                    {formData.type === 'asset' && (
                      <>
                        <SelectItem value="asset_receivable">Asset - Receivable</SelectItem>
                        <SelectItem value="asset_current">Asset - Current</SelectItem>
                        <SelectItem value="asset_cash">Asset - Cash</SelectItem>
                        <SelectItem value="asset_fixed">Asset - Fixed</SelectItem>
                        <SelectItem value="asset_non_current">Asset - Non-current</SelectItem>
                        <SelectItem value="other_asset">Asset - Other</SelectItem>
                      </>
                    )}
                    {formData.type === 'liability' && (
                      <>
                        <SelectItem value="liability_payable">Liability - Payable</SelectItem>
                        <SelectItem value="liability_current">Liability - Current</SelectItem>
                        <SelectItem value="liability_non_current">Liability - Non-current</SelectItem>
                      </>
                    )}
                    {formData.type === 'equity' && (
                      <SelectItem value="equity">Equity</SelectItem>
                    )}
                    {formData.type === 'revenue' && (
                      <>
                        <SelectItem value="income">Income</SelectItem>
                        <SelectItem value="income_other">Income - Other</SelectItem>
                      </>
                    )}
                    {formData.type === 'expense' && (
                      <>
                        <SelectItem value="expense_direct_cost">Expense - Direct Cost</SelectItem>
                        <SelectItem value="expense">Expense - Operating</SelectItem>
                        <SelectItem value="expense_depreciation">Expense - Depreciation</SelectItem>
                        <SelectItem value="other_expense">Expense - Other</SelectItem>
                      </>
                    )}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Subtype helps categorize accounts for financial reporting
                </p>
              </div>
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
                      .filter((a) => a.id !== editingAccount?.id && a.id && a.can_be_parent)
                      .map((account) => (
                        <SelectItem key={account.id} value={account.id.toString()}>
                          {account.code} - {account.name}
                        </SelectItem>
                      ))}
                  </SelectContent>
                </Select>
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
            {!editingAccount && (
              <div className="space-y-2">
                <Label htmlFor="opening_balance">Opening Balance</Label>
                <Input
                  id="opening_balance"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                  value={formData.opening_balance}
                  onChange={(e) =>
                    setFormData({ ...formData, opening_balance: parseFloat(e.target.value) || 0 })
                  }
                />
              </div>
            )}
            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="is_active"
                checked={formData.is_active}
                onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                className="rounded"
              />
              <Label htmlFor="is_active">Active</Label>
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
