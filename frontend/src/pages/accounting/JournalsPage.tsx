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
import { useToast } from '@/hooks/use-toast';
import journalsApi, { type Journal, type CreateJournalRequest } from '@/lib/journalsApi';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';

export default function JournalsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editingJournal, setEditingJournal] = useState<Journal | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [formData, setFormData] = useState<CreateJournalRequest>({
    code: '',
    name: '',
    type: 'general',
    default_debit_account_id: undefined,
    default_credit_account_id: undefined,
    sequence_prefix: '',
    sequence_number: 1,
    is_active: true,
  });

  // Fetch journals
  const { data: journals = [], isLoading } = useQuery({
    queryKey: ['journals', { search: searchTerm }],
    queryFn: () => journalsApi.getJournals({ search: searchTerm }),
  });

  // Fetch accounts for dropdowns
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  // Create journal mutation
  const createMutation = useMutation({
    mutationFn: journalsApi.createJournal,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      toast({ title: 'Success', description: 'Journal created successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create journal',
        variant: 'destructive',
      });
    },
  });

  // Update journal mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: CreateJournalRequest }) =>
      journalsApi.updateJournal(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      toast({ title: 'Success', description: 'Journal updated successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update journal',
        variant: 'destructive',
      });
    },
  });

  // Delete journal mutation
  const deleteMutation = useMutation({
    mutationFn: journalsApi.deleteJournal,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journals'] });
      toast({ title: 'Success', description: 'Journal deleted successfully' });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete journal',
        variant: 'destructive',
      });
    },
  });

  const resetForm = () => {
    setFormData({
      code: '',
      name: '',
      type: 'general',
      default_debit_account_id: undefined,
      default_credit_account_id: undefined,
      sequence_prefix: '',
      sequence_number: 1,
      is_active: true,
    });
    setEditingJournal(null);
  };

  const handleOpenForm = (journal?: Journal) => {
    if (journal) {
      setEditingJournal(journal);
      setFormData({
        code: journal.code,
        name: journal.name,
        type: journal.type,
        default_debit_account_id: journal.default_debit_account_id,
        default_credit_account_id: journal.default_credit_account_id,
        sequence_prefix: journal.sequence_prefix,
        sequence_number: journal.sequence_number,
        is_active: journal.is_active,
      });
    } else {
      resetForm();
    }
    setFormOpen(true);
  };

  const handleSubmit = () => {
    if (editingJournal) {
      updateMutation.mutate({ id: editingJournal.id, data: formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this journal?')) {
      deleteMutation.mutate(id);
    }
  };

  const getJournalTypeBadge = (type: string) => {
    const colors: Record<string, string> = {
      general: 'bg-gray-100 text-gray-800',
      sales: 'bg-green-100 text-green-800',
      purchase: 'bg-blue-100 text-blue-800',
      cash: 'bg-yellow-100 text-yellow-800',
      bank: 'bg-purple-100 text-purple-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Journals</h1>
          <p className="text-muted-foreground mt-1">
            Configure different types of accounting journals
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Main Content */}
      <Card className="p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold">Journal Types</h2>
          <div className="flex gap-2">
            <Input
              placeholder="Search journals..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="h-8 rounded-full px-3 text-xs w-64"
            />
            <HeaderActionButton icon="solar:add-circle-linear" onClick={() => handleOpenForm()}>
              Add Journal
            </HeaderActionButton>
          </div>
        </div>

        {isLoading ? (
          <div className="text-center py-12">
            <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
            <p className="text-muted-foreground mt-2">Loading journals...</p>
          </div>
        ) : journals.length === 0 ? (
          <div className="text-center py-12 text-muted-foreground">
            <Icon icon="solar:notebook-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
            <p>No journals found</p>
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Code</TableHead>
                <TableHead>Name</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Sequence</TableHead>
                <TableHead>Default Debit Account</TableHead>
                <TableHead>Default Credit Account</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {journals.map((journal) => (
                <TableRow key={journal.id}>
                  <TableCell className="font-mono">{journal.code}</TableCell>
                  <TableCell className="font-medium">{journal.name}</TableCell>
                  <TableCell>
                    <Badge className={getJournalTypeBadge(journal.type)}>
                      {journal.type}
                    </Badge>
                  </TableCell>
                  <TableCell className="font-mono text-sm">
                    {journal.sequence_prefix}-{journal.sequence_number}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {journal.defaultDebitAccount
                      ? `${journal.defaultDebitAccount.code} - ${journal.defaultDebitAccount.name}`
                      : '-'}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {journal.defaultCreditAccount
                      ? `${journal.defaultCreditAccount.code} - ${journal.defaultCreditAccount.name}`
                      : '-'}
                  </TableCell>
                  <TableCell>
                    <Badge variant={journal.is_active ? 'default' : 'secondary'}>
                      {journal.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleOpenForm(journal)}
                      >
                        <Icon icon="solar:pen-linear" className="w-4 h-4" />
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => handleDelete(journal.id)}
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

      {/* Journal Form Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{editingJournal ? 'Edit Journal' : 'Add Journal'}</DialogTitle>
            <DialogDescription>
              {editingJournal ? 'Update journal details' : 'Create a new journal type'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="code">Journal Code *</Label>
                <Input
                  id="code"
                  placeholder="SALES"
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
                    <SelectItem value="general">General</SelectItem>
                    <SelectItem value="sales">Sales</SelectItem>
                    <SelectItem value="purchase">Purchase</SelectItem>
                    <SelectItem value="cash">Cash</SelectItem>
                    <SelectItem value="bank">Bank</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">Journal Name *</Label>
              <Input
                id="name"
                placeholder="Sales Journal"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="sequence_prefix">Sequence Prefix *</Label>
                <Input
                  id="sequence_prefix"
                  placeholder="INV"
                  value={formData.sequence_prefix}
                  onChange={(e) => setFormData({ ...formData, sequence_prefix: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="sequence_number">Starting Number</Label>
                <Input
                  id="sequence_number"
                  type="number"
                  value={formData.sequence_number}
                  onChange={(e) =>
                    setFormData({ ...formData, sequence_number: parseInt(e.target.value) || 1 })
                  }
                  disabled={!!editingJournal}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="default_debit">Default Debit Account</Label>
                <Select
                  value={formData.default_debit_account_id?.toString() || 'none'}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      default_debit_account_id: value === 'none' ? undefined : parseInt(value),
                    })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="None" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">None</SelectItem>
                    {accounts.map((account) => (
                      <SelectItem key={account.id} value={account.id.toString()}>
                        {account.code} - {account.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="default_credit">Default Credit Account</Label>
                <Select
                  value={formData.default_credit_account_id?.toString() || 'none'}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      default_credit_account_id: value === 'none' ? undefined : parseInt(value),
                    })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="None" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">None</SelectItem>
                    {accounts.map((account) => (
                      <SelectItem key={account.id} value={account.id.toString()}>
                        {account.code} - {account.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
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
                'Save Journal'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
