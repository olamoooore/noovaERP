import { useState } from 'react';
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
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

function EntitySection({
  title,
  items,
  emptyLabel,
  onAdd,
}: {
  title: string;
  items: any[];
  emptyLabel: string;
  onAdd: () => void;
}) {
  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold">{title}</h2>
        <Button size="sm" onClick={onAdd}>
          <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
          Create
        </Button>
      </div>
      {!items || items.length === 0 ? (
        <div className="text-sm text-muted-foreground">{emptyLabel}</div>
      ) : (
        <div className="space-y-3">
          {items.map((item: any) => (
            <div key={`${title}-${item.id}`} className="border rounded-lg p-4">
              <div className="font-medium">{item.name || item.code || `Record ${item.id}`}</div>
              <div className="text-sm text-muted-foreground">
                {item.code && `Code: ${item.code}`}
                {item.base_currency && ` | Currency: ${item.base_currency}`}
                {item.currency_code && ` | Currency: ${item.currency_code}`}
                {item.country_code && ` | Country: ${item.country_code}`}
                {item.book_type && ` | Type: ${item.book_type}`}
                {!item.code && !item.base_currency && !item.book_type && (item.description || item.type || 'No additional details')}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

type DialogType = 'group' | 'entity' | 'branch' | 'book' | null;

export default function AccountingEntityManagementPage() {
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [dialogType, setDialogType] = useState<DialogType>(null);

  // Form states
  const [groupForm, setGroupForm] = useState({ name: '', code: '', base_currency: 'USD', fiscal_year_start: '01-01' });
  const [entityForm, setEntityForm] = useState({ group_id: '', name: '', code: '', country_code: '', base_currency: 'USD' });
  const [branchForm, setBranchForm] = useState({ legal_entity_id: '', name: '', code: '' });
  const [bookForm, setBookForm] = useState({ legal_entity_id: '', name: '', book_type: 'general', currency_code: 'USD' });

  const { data, isLoading } = useQuery({
    queryKey: ['accounting-entity-management'],
    queryFn: async () => {
      const [groups, entities, branches, books] = await Promise.all([
        api.get('/accounting/groups'),
        api.get('/accounting/legal-entities'),
        api.get('/accounting/branches'),
        api.get('/accounting/entity-books'),
      ]);

      return {
        groups: Array.isArray(groups.data) ? groups.data : (groups.data.data || []),
        entities: Array.isArray(entities.data) ? entities.data : (entities.data.data || []),
        branches: Array.isArray(branches.data) ? branches.data : (branches.data.data || []),
        books: Array.isArray(books.data) ? books.data : (books.data.data || []),
      };
    },
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['accounting-entity-management'] });

  const createGroupMutation = useMutation({
    mutationFn: async (d: typeof groupForm) => (await api.post('/accounting/groups', d)).data,
    onSuccess: () => { invalidate(); toast({ title: 'Success', description: 'Group created' }); setDialogType(null); },
    onError: (e: any) => toast({ title: 'Error', description: e.response?.data?.message || 'Failed', variant: 'destructive' }),
  });

  const createEntityMutation = useMutation({
    mutationFn: async (d: any) => (await api.post('/accounting/legal-entities', { ...d, group_id: parseInt(d.group_id) })).data,
    onSuccess: () => { invalidate(); toast({ title: 'Success', description: 'Legal entity created' }); setDialogType(null); },
    onError: (e: any) => toast({ title: 'Error', description: e.response?.data?.message || 'Failed', variant: 'destructive' }),
  });

  const createBranchMutation = useMutation({
    mutationFn: async (d: any) => (await api.post('/accounting/branches', { ...d, legal_entity_id: parseInt(d.legal_entity_id) })).data,
    onSuccess: () => { invalidate(); toast({ title: 'Success', description: 'Branch created' }); setDialogType(null); },
    onError: (e: any) => toast({ title: 'Error', description: e.response?.data?.message || 'Failed', variant: 'destructive' }),
  });

  const createBookMutation = useMutation({
    mutationFn: async (d: any) => (await api.post('/accounting/entity-books', { ...d, legal_entity_id: parseInt(d.legal_entity_id) })).data,
    onSuccess: () => { invalidate(); toast({ title: 'Success', description: 'Entity book created' }); setDialogType(null); },
    onError: (e: any) => toast({ title: 'Error', description: e.response?.data?.message || 'Failed', variant: 'destructive' }),
  });

  const isPending = createGroupMutation.isPending || createEntityMutation.isPending || createBranchMutation.isPending || createBookMutation.isPending;

  const handleSubmit = () => {
    switch (dialogType) {
      case 'group':
        if (!groupForm.name || !groupForm.code) { toast({ title: 'Validation', description: 'Name and code required', variant: 'destructive' }); return; }
        createGroupMutation.mutate(groupForm);
        break;
      case 'entity':
        if (!entityForm.group_id || !entityForm.name || !entityForm.code) { toast({ title: 'Validation', description: 'Group, name, and code required', variant: 'destructive' }); return; }
        createEntityMutation.mutate(entityForm);
        break;
      case 'branch':
        if (!branchForm.legal_entity_id || !branchForm.name || !branchForm.code) { toast({ title: 'Validation', description: 'Entity, name, and code required', variant: 'destructive' }); return; }
        createBranchMutation.mutate(branchForm);
        break;
      case 'book':
        if (!bookForm.legal_entity_id || !bookForm.name) { toast({ title: 'Validation', description: 'Entity and name required', variant: 'destructive' }); return; }
        createBookMutation.mutate(bookForm);
        break;
    }
  };

  const openDialog = (type: DialogType) => {
    setGroupForm({ name: '', code: '', base_currency: 'USD', fiscal_year_start: '01-01' });
    setEntityForm({ group_id: '', name: '', code: '', country_code: '', base_currency: 'USD' });
    setBranchForm({ legal_entity_id: '', name: '', code: '' });
    setBookForm({ legal_entity_id: '', name: '', book_type: 'general', currency_code: 'USD' });
    setDialogType(type);
  };

  const groups = data?.groups || [];
  const entities = data?.entities || [];

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Multi-Entity Management</h1>
        <p className="text-muted-foreground mt-1">
          Manage legal entities, branches, books, and group structures.
        </p>
      </div>

      {isLoading ? (
        <Card className="p-6 text-sm text-muted-foreground">Loading entity configuration...</Card>
      ) : (
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <EntitySection title="Groups" items={groups} emptyLabel="No groups configured." onAdd={() => openDialog('group')} />
          <EntitySection title="Legal Entities" items={entities} emptyLabel="No legal entities configured." onAdd={() => openDialog('entity')} />
          <EntitySection title="Branches" items={data?.branches || []} emptyLabel="No branches configured." onAdd={() => openDialog('branch')} />
          <EntitySection title="Entity Books" items={data?.books || []} emptyLabel="No entity books configured." onAdd={() => openDialog('book')} />
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={dialogType !== null} onOpenChange={(open) => { if (!open) setDialogType(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {dialogType === 'group' && 'Create Group'}
              {dialogType === 'entity' && 'Create Legal Entity'}
              {dialogType === 'branch' && 'Create Branch'}
              {dialogType === 'book' && 'Create Entity Book'}
            </DialogTitle>
            <DialogDescription>Fill in the details below.</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            {dialogType === 'group' && (
              <>
                <div className="space-y-2">
                  <Label>Name *</Label>
                  <Input value={groupForm.name} onChange={(e) => setGroupForm({ ...groupForm, name: e.target.value })} placeholder="Group name" />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Code *</Label>
                    <Input value={groupForm.code} onChange={(e) => setGroupForm({ ...groupForm, code: e.target.value })} placeholder="GRP-001" />
                  </div>
                  <div className="space-y-2">
                    <Label>Base Currency *</Label>
                    <Input value={groupForm.base_currency} onChange={(e) => setGroupForm({ ...groupForm, base_currency: e.target.value })} placeholder="USD" maxLength={3} />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label>Fiscal Year Start</Label>
                  <Input value={groupForm.fiscal_year_start} onChange={(e) => setGroupForm({ ...groupForm, fiscal_year_start: e.target.value })} placeholder="01-01" />
                </div>
              </>
            )}

            {dialogType === 'entity' && (
              <>
                <div className="space-y-2">
                  <Label>Group *</Label>
                  <Select value={entityForm.group_id} onValueChange={(v) => setEntityForm({ ...entityForm, group_id: v })}>
                    <SelectTrigger><SelectValue placeholder="Select group" /></SelectTrigger>
                    <SelectContent>
                      {groups.map((g: any) => (
                        <SelectItem key={g.id} value={g.id.toString()}>{g.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {groups.length === 0 && <p className="text-xs text-destructive">Create a group first</p>}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Name *</Label>
                    <Input value={entityForm.name} onChange={(e) => setEntityForm({ ...entityForm, name: e.target.value })} placeholder="Entity name" />
                  </div>
                  <div className="space-y-2">
                    <Label>Code *</Label>
                    <Input value={entityForm.code} onChange={(e) => setEntityForm({ ...entityForm, code: e.target.value })} placeholder="ENT-001" />
                  </div>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Country Code</Label>
                    <Input value={entityForm.country_code} onChange={(e) => setEntityForm({ ...entityForm, country_code: e.target.value })} placeholder="US" maxLength={2} />
                  </div>
                  <div className="space-y-2">
                    <Label>Base Currency *</Label>
                    <Input value={entityForm.base_currency} onChange={(e) => setEntityForm({ ...entityForm, base_currency: e.target.value })} placeholder="USD" maxLength={3} />
                  </div>
                </div>
              </>
            )}

            {dialogType === 'branch' && (
              <>
                <div className="space-y-2">
                  <Label>Legal Entity *</Label>
                  <Select value={branchForm.legal_entity_id} onValueChange={(v) => setBranchForm({ ...branchForm, legal_entity_id: v })}>
                    <SelectTrigger><SelectValue placeholder="Select legal entity" /></SelectTrigger>
                    <SelectContent>
                      {entities.map((e: any) => (
                        <SelectItem key={e.id} value={e.id.toString()}>{e.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {entities.length === 0 && <p className="text-xs text-destructive">Create a legal entity first</p>}
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Name *</Label>
                    <Input value={branchForm.name} onChange={(e) => setBranchForm({ ...branchForm, name: e.target.value })} placeholder="Branch name" />
                  </div>
                  <div className="space-y-2">
                    <Label>Code *</Label>
                    <Input value={branchForm.code} onChange={(e) => setBranchForm({ ...branchForm, code: e.target.value })} placeholder="BR-001" />
                  </div>
                </div>
              </>
            )}

            {dialogType === 'book' && (
              <>
                <div className="space-y-2">
                  <Label>Legal Entity *</Label>
                  <Select value={bookForm.legal_entity_id} onValueChange={(v) => setBookForm({ ...bookForm, legal_entity_id: v })}>
                    <SelectTrigger><SelectValue placeholder="Select legal entity" /></SelectTrigger>
                    <SelectContent>
                      {entities.map((e: any) => (
                        <SelectItem key={e.id} value={e.id.toString()}>{e.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {entities.length === 0 && <p className="text-xs text-destructive">Create a legal entity first</p>}
                </div>
                <div className="space-y-2">
                  <Label>Name *</Label>
                  <Input value={bookForm.name} onChange={(e) => setBookForm({ ...bookForm, name: e.target.value })} placeholder="Book name" />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Book Type *</Label>
                    <Select value={bookForm.book_type} onValueChange={(v) => setBookForm({ ...bookForm, book_type: v })}>
                      <SelectTrigger><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="general">General</SelectItem>
                        <SelectItem value="statutory">Statutory</SelectItem>
                        <SelectItem value="management">Management</SelectItem>
                        <SelectItem value="tax">Tax</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <Label>Currency *</Label>
                    <Input value={bookForm.currency_code} onChange={(e) => setBookForm({ ...bookForm, currency_code: e.target.value })} placeholder="USD" maxLength={3} />
                  </div>
                </div>
              </>
            )}
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogType(null)}>Cancel</Button>
            <Button onClick={handleSubmit} disabled={isPending}>
              {isPending ? (
                <><Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />Creating...</>
              ) : (
                'Create'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
