import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
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
import { useAuthStore } from '@/store/authStore';
import journalEntriesApi, {
  type JournalEntry,
  type JournalEntryLine,
  type CreateJournalEntryRequest,
} from '@/lib/journalEntriesApi';
import journalsApi from '@/lib/journalsApi';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { formatCurrency, mapCommandError } from '@/lib/utils';

export default function JournalEntriesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<'all' | 'draft' | 'posted' | 'reversed'>('all');
  const [formOpen, setFormOpen] = useState(false);
  const [viewOpen, setViewOpen] = useState(false);
  const [selectedEntry, setSelectedEntry] = useState<JournalEntry | null>(null);
  const [reverseOpen, setReverseOpen] = useState(false);
  const [reverseEntryId, setReverseEntryId] = useState<number | null>(null);
  const [reverseForm, setReverseForm] = useState({ reversal_date: new Date().toISOString().split('T')[0], reason: '' });
  const [reverseType, setReverseType] = useState<'full' | 'partial'>('full');
  const [partialLineAmounts, setPartialLineAmounts] = useState<{ line_id: number; amount: number }[]>([]);
  const [searchParams, setSearchParams] = useSearchParams();
  const [searchTerm, setSearchTerm] = useState('');
  const { hasPermission } = useAuthStore();
  const canPost = hasPermission('accounting.post');
  const canReverse = hasPermission('accounting.reverse');
  const canWrite = hasPermission('accounting.write');
  const [formData, setFormData] = useState<CreateJournalEntryRequest>({
    journal_id: undefined,
    entry_date: new Date().toISOString().split('T')[0],
    reference: '',
    description: '',
    lines: [
      { account_id: 0, debit: 0, credit: 0, description: '' },
      { account_id: 0, debit: 0, credit: 0, description: '' },
    ],
  });

  // Auto-open a specific journal entry when linked from a close task
  useEffect(() => {
    const entryId = searchParams.get('entry_id');
    if (!entryId) return;

    journalEntriesApi.getJournalEntry(Number(entryId))
      .then((entry) => {
        setSelectedEntry(entry);
        setViewOpen(true);
        // Remove the query param so a refresh doesn't reopen the dialog
        const next = new URLSearchParams(searchParams);
        next.delete('entry_id');
        setSearchParams(next, { replace: true });
      })
      .catch(() => {
        toast({ title: 'Journal entry not found', variant: 'destructive' });
      });
  }, [searchParams, setSearchParams, toast]);

  // Fetch journal entries
  const { data: entriesData, isLoading } = useQuery({
    queryKey: ['journal-entries', { status: activeTab === 'all' ? undefined : activeTab === 'reversed' ? undefined : activeTab, is_reversal: activeTab === 'reversed' ? true : undefined, search: searchTerm }],
    queryFn: () =>
      journalEntriesApi.getJournalEntries({
        status: activeTab === 'all' || activeTab === 'reversed' ? undefined : activeTab,
        is_reversal: activeTab === 'reversed' ? true : undefined,
        search: searchTerm,
        per_page: 50,
      }),
  });

  // Fetch journals for dropdown
  const { data: journals = [] } = useQuery({
    queryKey: ['journals'],
    queryFn: () => journalsApi.getJournals(),
  });

  // Fetch accounts for dropdown
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  // Create entry mutation
  const createMutation = useMutation({
    mutationFn: journalEntriesApi.createJournalEntry,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      toast({ title: 'Success', description: 'Journal entry created successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      const err = mapCommandError(error);
      toast(err);
    },
  });

  // Post entry mutation
  const postMutation = useMutation({
    mutationFn: (id: number) => journalEntriesApi.postJournalEntry(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      toast({ title: 'Success', description: 'Journal entry posted successfully' });
      setViewOpen(false);
    },
    onError: (error: any) => {
      toast(mapCommandError(error));
    },
  });

  // Reverse entry mutation (full)
  const reverseMutation = useMutation({
    mutationFn: ({ id, reversal_date, reason }: { id: number; reversal_date: string; reason: string }) =>
      journalEntriesApi.reverseJournalEntry(id, { reversal_date, reason }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      toast({ title: 'Success', description: 'Journal entry reversed successfully' });
      setReverseOpen(false);
      setReverseEntryId(null);
      setReverseType('full');
    },
    onError: (error: any) => {
      toast(mapCommandError(error));
    },
  });

  // Partial reversal mutation
  const partialReverseMutation = useMutation({
    mutationFn: ({ id, reversal_date, reason, line_amounts }: {
      id: number;
      reversal_date: string;
      reason: string;
      line_amounts: { line_id: number; amount: number }[];
    }) => journalEntriesApi.partialReverseJournalEntry(id, { reversal_date, reason, line_amounts }),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      toast({
        title: 'Success',
        description: `Partial reversal posted. Reversed: ${data.reversed_amount}. Remaining: ${data.remaining_amount}`,
      });
      setReverseOpen(false);
      setReverseEntryId(null);
      setReverseType('full');
      setPartialLineAmounts([]);
    },
    onError: (error: any) => {
      toast(mapCommandError(error));
    },
  });

  // Delete entry mutation
  const deleteMutation = useMutation({
    mutationFn: journalEntriesApi.deleteJournalEntry,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
      toast({ title: 'Success', description: 'Journal entry deleted successfully' });
      setViewOpen(false);
    },
    onError: (error: any) => {
      toast(mapCommandError(error));
    },
  });

  const resetForm = () => {
    setFormData({
      journal_id: undefined,
      entry_date: new Date().toISOString().split('T')[0],
      reference: '',
      description: '',
      lines: [
        { account_id: 0, debit: 0, credit: 0, description: '' },
        { account_id: 0, debit: 0, credit: 0, description: '' },
      ],
    });
  };

  const addLine = () => {
    setFormData({
      ...formData,
      lines: [...formData.lines, { account_id: 0, debit: 0, credit: 0, description: '' }],
    });
  };

  const removeLine = (index: number) => {
    if (formData.lines.length > 2) {
      setFormData({
        ...formData,
        lines: formData.lines.filter((_, i) => i !== index),
      });
    }
  };

  const updateLine = (index: number, field: keyof JournalEntryLine, value: any) => {
    const newLines = [...formData.lines];
    newLines[index] = { ...newLines[index], [field]: value };
    setFormData({ ...formData, lines: newLines });
  };

  const calculateTotals = () => {
    const totalDebit = formData.lines.reduce((sum, line) => sum + (parseFloat(String(line.debit)) || 0), 0);
    const totalCredit = formData.lines.reduce((sum, line) => sum + (parseFloat(String(line.credit)) || 0), 0);
    const difference = totalDebit - totalCredit;
    return { totalDebit, totalCredit, difference, isBalanced: Math.abs(difference) < 0.01 };
  };

  const handleSubmit = () => {
    const totals = calculateTotals();
    if (!totals.isBalanced) {
      toast({
        title: 'Error',
        description: 'Entry must balance (total debit must equal total credit)',
        variant: 'destructive',
      });
      return;
    }
    createMutation.mutate(formData);
  };

  const handleViewEntry = async (id: number) => {
    const entry = await journalEntriesApi.getJournalEntry(id);
    setSelectedEntry(entry);
    setViewOpen(true);
  };

  const handlePost = (id: number) => {
    if (confirm('Are you sure you want to post this entry? Posted entries cannot be edited.')) {
      postMutation.mutate(id);
    }
  };

  const handleReverse = async (id: number) => {
    setReverseEntryId(id);
    setReverseForm({ reversal_date: new Date().toISOString().split('T')[0], reason: '' });
    setReverseType('full');
    // Load entry lines for partial reversal
    try {
      const entry = await journalEntriesApi.getJournalEntry(id);
      setSelectedEntry(entry);
      setPartialLineAmounts(
        (entry.lines || []).map((line: any) => ({
          line_id: line.id,
          amount: Math.abs(line.debit || line.credit || 0),
        }))
      );
    } catch {
      setPartialLineAmounts([]);
    }
    setReverseOpen(true);
  };

  const handleConfirmReverse = () => {
    if (!reverseEntryId) return;
    if (!reverseForm.reason.trim()) {
      toast({ title: 'Error', description: 'Reason is required', variant: 'destructive' });
      return;
    }
    if (reverseType === 'partial') {
      const selectedLines = partialLineAmounts.filter(l => l.amount > 0);
      if (selectedLines.length === 0) {
        toast({ title: 'Error', description: 'Select at least one line to reverse', variant: 'destructive' });
        return;
      }
      partialReverseMutation.mutate({
        id: reverseEntryId,
        reversal_date: reverseForm.reversal_date,
        reason: reverseForm.reason,
        line_amounts: selectedLines,
      });
    } else {
      reverseMutation.mutate({
        id: reverseEntryId,
        reversal_date: reverseForm.reversal_date,
        reason: reverseForm.reason,
      });
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this entry?')) {
      deleteMutation.mutate(id);
    }
  };

  const entries = entriesData?.data || [];
  const totals = calculateTotals();

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Journal Entries</h1>
          <p className="text-muted-foreground mt-1">
            Create and manage manual journal entries
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={(v: any) => setActiveTab(v)}>
        <div className="flex items-center justify-between">
          <TabsList>
            <TabsTrigger value="all">All Entries</TabsTrigger>
            <TabsTrigger value="draft">Draft</TabsTrigger>
            <TabsTrigger value="posted">Posted</TabsTrigger>
            <TabsTrigger value="reversed">Reversed</TabsTrigger>
          </TabsList>
          <div className="flex gap-2">
            <Input
              placeholder="Search entries..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-64"
            />
            <ImportButton 
              importType="journal-entries"
              onImportComplete={() => queryClient.invalidateQueries({ queryKey: ['journal-entries'] })}
              variant="outline"
            />
            {canWrite && (
              <Button onClick={() => setFormOpen(true)}>
                <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                New Entry
              </Button>
            )}
          </div>
        </div>

        <TabsContent value={activeTab} className="space-y-4">
          <Card className="p-6">
            {isLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading entries...</p>
              </div>
            ) : entries.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:document-text-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No journal entries found</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Entry Number</TableHead>
                    <TableHead>Date</TableHead>
                    <TableHead>Journal</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Reference</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {entries.map((entry) => (
                    <TableRow key={entry.id} className="cursor-pointer" onClick={() => handleViewEntry(entry.id)}>
                      <TableCell className="font-mono">{entry.entry_number}</TableCell>
                      <TableCell>{new Date(entry.entry_date).toLocaleDateString()}</TableCell>
                      <TableCell className="text-sm">{entry.journal?.name || '-'}</TableCell>
                      <TableCell>{entry.description}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">{entry.reference || '-'}</TableCell>
                      <TableCell>
                        <Badge variant={entry.status === 'posted' ? 'default' : 'secondary'}>
                          {entry.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right" onClick={(e) => e.stopPropagation()}>
                        <div className="flex justify-end gap-1">
                          {entry.status === 'draft' && (
                            <>
                              {canPost && (
                                <Button size="sm" variant="ghost" onClick={() => handlePost(entry.id)}>
                                  <Icon icon="solar:check-circle-linear" className="w-4 h-4" />
                                </Button>
                              )}
                              {canWrite && (
                                <Button size="sm" variant="ghost" onClick={() => handleDelete(entry.id)}>
                                  <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                                </Button>
                              )}
                            </>
                          )}
                          {entry.status === 'posted' && !entry.is_reversal && canReverse && (
                            <Button size="sm" variant="ghost" onClick={() => handleReverse(entry.id)}>
                              <Icon icon="solar:refresh-linear" className="w-4 h-4" />
                            </Button>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      {/* Create Entry Dialog */}
      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>New Journal Entry</DialogTitle>
            <DialogDescription>Create a new manual journal entry</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label htmlFor="journal">Journal</Label>
                <Select
                  value={formData.journal_id?.toString() || 'none'}
                  onValueChange={(value) =>
                    setFormData({ ...formData, journal_id: value === 'none' ? undefined : parseInt(value) })
                  }
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select journal" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No Journal</SelectItem>
                    {journals.map((journal) => (
                      <SelectItem key={journal.id} value={journal.id.toString()}>
                        {journal.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="entry_date">Entry Date *</Label>
                <Input
                  id="entry_date"
                  type="date"
                  value={formData.entry_date}
                  onChange={(e) => setFormData({ ...formData, entry_date: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="reference">Reference</Label>
                <Input
                  id="reference"
                  placeholder="REF-001"
                  value={formData.reference}
                  onChange={(e) => setFormData({ ...formData, reference: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="description">Description *</Label>
              <Input
                id="description"
                placeholder="Entry description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              />
            </div>

            {/* Lines */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label>Entry Lines</Label>
                <Button size="sm" variant="outline" onClick={addLine}>
                  <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                  Add Line
                </Button>
              </div>
              <div className="border rounded-lg overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-[300px]">Account</TableHead>
                      <TableHead className="w-[200px]">Description</TableHead>
                      <TableHead className="w-[120px] text-right">Debit</TableHead>
                      <TableHead className="w-[120px] text-right">Credit</TableHead>
                      <TableHead className="w-[50px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {formData.lines.map((line, index) => (
                      <TableRow key={index}>
                        <TableCell>
                          <Select
                            value={line.account_id?.toString() || 'select'}
                            onValueChange={(value) => value !== 'select' && updateLine(index, 'account_id', parseInt(value))}
                          >
                            <SelectTrigger>
                              <SelectValue placeholder="Select account" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="select" disabled>Select account</SelectItem>
                              {accounts.map((account) => (
                                <SelectItem key={account.id} value={account.id.toString()}>
                                  {account.code} - {account.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </TableCell>
                        <TableCell>
                          <Input
                            placeholder="Line description"
                            value={line.description || ''}
                            onChange={(e) => updateLine(index, 'description', e.target.value)}
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={line.debit || ''}
                            onChange={(e) => {
                              const newLines = [...formData.lines];
                              newLines[index] = { ...newLines[index], debit: parseFloat(e.target.value) || 0, credit: 0 };
                              setFormData({ ...formData, lines: newLines });
                            }}
                            className="text-right"
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            type="number"
                            step="0.01"
                            placeholder="0.00"
                            value={line.credit || ''}
                            onChange={(e) => {
                              const newLines = [...formData.lines];
                              newLines[index] = { ...newLines[index], credit: parseFloat(e.target.value) || 0, debit: 0 };
                              setFormData({ ...formData, lines: newLines });
                            }}
                            className="text-right"
                          />
                        </TableCell>
                        <TableCell>
                          {formData.lines.length > 2 && (
                            <Button size="sm" variant="ghost" onClick={() => removeLine(index)}>
                              <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                            </Button>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                    <TableRow className="font-semibold bg-muted/50">
                      <TableCell colSpan={2} className="text-right">
                        Total:
                      </TableCell>
                      <TableCell className="text-right">${totals.totalDebit.toFixed(2)}</TableCell>
                      <TableCell className="text-right">${totals.totalCredit.toFixed(2)}</TableCell>
                      <TableCell></TableCell>
                    </TableRow>
                    {!totals.isBalanced && (
                      <TableRow>
                        <TableCell colSpan={5} className="text-center text-red-600">
                          <Icon icon="solar:danger-triangle-linear" className="w-4 h-4 inline mr-2" />
                          Entry is not balanced. Difference: ${Math.abs(totals.difference).toFixed(2)}
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleSubmit} disabled={createMutation.isPending || !totals.isBalanced}>
              {createMutation.isPending ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Creating...
                </>
              ) : (
                'Create Entry'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* View Entry Dialog */}
      <Dialog open={viewOpen} onOpenChange={setViewOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Journal Entry Details</DialogTitle>
            <DialogDescription>Entry #{selectedEntry?.entry_number}</DialogDescription>
          </DialogHeader>
          {selectedEntry && (
            <div className="space-y-4 py-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label className="text-muted-foreground">Date</Label>
                  <p>{new Date(selectedEntry.entry_date).toLocaleDateString()}</p>
                </div>
                <div>
                  <Label className="text-muted-foreground">Status</Label>
                  <p>
                    <Badge variant={selectedEntry.status === 'posted' ? 'default' : 'secondary'}>
                      {selectedEntry.status}
                    </Badge>
                  </p>
                </div>
                <div>
                  <Label className="text-muted-foreground">Journal</Label>
                  <p>{selectedEntry.journal?.name || '-'}</p>
                </div>
                <div>
                  <Label className="text-muted-foreground">Reference</Label>
                  <p>{selectedEntry.reference || '-'}</p>
                </div>
              </div>
              <div>
                <Label className="text-muted-foreground">Description</Label>
                <p>{selectedEntry.description}</p>
              </div>
              <div>
                <Label className="text-muted-foreground">Lines</Label>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Account</TableHead>
                      <TableHead>Description</TableHead>
                      <TableHead className="text-right">Debit</TableHead>
                      <TableHead className="text-right">Credit</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {selectedEntry.lines.map((line) => (
                      <TableRow key={line.id}>
                        <TableCell>
                          {line.account?.code} - {line.account?.name}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {line.description || '-'}
                        </TableCell>
                        <TableCell className="text-right font-mono">
                          {line.debit > 0 ? `$${parseFloat(String(line.debit)).toFixed(2)}` : '-'}
                        </TableCell>
                        <TableCell className="text-right font-mono">
                          {line.credit > 0 ? `$${parseFloat(String(line.credit)).toFixed(2)}` : '-'}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}
          <DialogFooter>
            {selectedEntry?.status === 'draft' && canPost && (
              <Button
                onClick={() => postMutation.mutate(selectedEntry.id)}
                disabled={postMutation.isPending}
              >
                {postMutation.isPending ? 'Posting...' : 'Post'}
              </Button>
            )}
            <Button variant="outline" onClick={() => setViewOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reversal Dialog */}
      <Dialog open={reverseOpen} onOpenChange={(open) => { if (!open) { setReverseOpen(false); setReverseEntryId(null); setReverseType('full'); } }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Reverse Journal Entry</DialogTitle>
            <DialogDescription>
              Create a reversing entry. Choose full reversal (entire entry) or partial reversal (specific lines).
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            {/* Reversal Type Toggle */}
            <div className="space-y-2">
              <Label>Reversal Type *</Label>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant={reverseType === 'full' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setReverseType('full')}
                >
                  Full Reversal
                </Button>
                <Button
                  type="button"
                  variant={reverseType === 'partial' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => setReverseType('partial')}
                >
                  Partial Reversal
                </Button>
              </div>
            </div>

            {/* Partial Reversal Line Items */}
            {reverseType === 'partial' && selectedEntry?.lines && (
              <div className="space-y-2">
                <Label>Select lines and amounts to reverse</Label>
                <div className="border rounded-md max-h-60 overflow-y-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Account</TableHead>
                        <TableHead>Description</TableHead>
                        <TableHead className="text-right">Original</TableHead>
                        <TableHead className="text-right w-32">Reverse Amount</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectedEntry.lines.map((line) => {
                        const originalAmount = Math.abs(line.debit || line.credit || 0);
                        const lineAmount = partialLineAmounts.find(l => l.line_id === line.id);
                        return (
                          <TableRow key={line.id}>
                            <TableCell className="text-sm">{line.account_code || line.account_name || `Line ${line.id}`}</TableCell>
                            <TableCell className="text-sm text-muted-foreground">{line.description || '-'}</TableCell>
                            <TableCell className="text-sm text-right">{formatCurrency(originalAmount)}</TableCell>
                            <TableCell className="text-right">
                              <Input
                                type="number"
                                min="0"
                                max={originalAmount}
                                step="0.01"
                                className="w-32 text-right h-8"
                                value={lineAmount?.amount ?? 0}
                                onChange={(e) => {
                                  const val = parseFloat(e.target.value) || 0;
                                  setPartialLineAmounts(prev =>
                                    prev.map(l =>
                                      l.line_id === line.id ? { ...l, amount: Math.min(val, originalAmount) } : l
                                    )
                                  );
                                }}
                              />
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </div>
                <p className="text-xs text-muted-foreground">
                  Set amount to 0 to skip a line. Total reversed must not exceed original entry.
                </p>
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="reversal_date">Reversal Date *</Label>
              <Input
                id="reversal_date"
                type="date"
                value={reverseForm.reversal_date}
                onChange={(e) => setReverseForm((prev) => ({ ...prev, reversal_date: e.target.value }))}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="reversal_reason">Reason *</Label>
              <textarea
                id="reversal_reason"
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-800 text-sm resize-none"
                placeholder="Enter reason for reversal..."
                value={reverseForm.reason}
                onChange={(e) => setReverseForm((prev) => ({ ...prev, reason: e.target.value }))}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => { setReverseOpen(false); setReverseEntryId(null); setReverseType('full'); }}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleConfirmReverse}
              disabled={reverseMutation.isPending || partialReverseMutation.isPending || !reverseForm.reason.trim()}
            >
              {(reverseMutation.isPending || partialReverseMutation.isPending) ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Reversing...
                </>
              ) : (
                reverseType === 'partial' ? 'Confirm Partial Reversal' : 'Confirm Full Reversal'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
