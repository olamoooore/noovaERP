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
import lockDatesApi, { type LockDate, type CreateLockDateRequest } from '@/lib/lockDatesApi';

export default function LockDatesPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('lock-dates');
  const [formOpen, setFormOpen] = useState(false);
  const [editingLockDate, setEditingLockDate] = useState<LockDate | null>(null);
  const [formData, setFormData] = useState<CreateLockDateRequest>({
    lock_type: 'all',
    lock_date: '',
    reason: '',
  });

  const { data: lockDates = [], isLoading } = useQuery({
    queryKey: ['lock-dates'],
    queryFn: () => lockDatesApi.getLockDates(),
  });

  const { data: overrides = [], isLoading: isLoadingOverrides } = useQuery({
    queryKey: ['lock-date-overrides'],
    queryFn: () => lockDatesApi.getOverrides(),
    enabled: activeTab === 'overrides',
  });

  const createMutation = useMutation({
    mutationFn: lockDatesApi.createLockDate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lock-dates'] });
      toast({ title: 'Success', description: 'Lock date set successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to set lock date',
        variant: 'destructive',
      });
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<CreateLockDateRequest> }) =>
      lockDatesApi.updateLockDate(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lock-dates'] });
      toast({ title: 'Success', description: 'Lock date updated successfully' });
      setFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update lock date',
        variant: 'destructive',
      });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: lockDatesApi.deleteLockDate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lock-dates'] });
      toast({ title: 'Success', description: 'Lock date deactivated successfully' });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to deactivate lock date',
        variant: 'destructive',
      });
    },
  });

  const resetForm = () => {
    setFormData({
      lock_type: 'all',
      lock_date: '',
      reason: '',
    });
    setEditingLockDate(null);
  };

  const handleOpenForm = (lockDate?: LockDate) => {
    if (lockDate) {
      setEditingLockDate(lockDate);
      setFormData({
        lock_type: lockDate.lock_type,
        lock_date: lockDate.lock_date,
        reason: lockDate.reason || '',
      });
    } else {
      resetForm();
    }
    setFormOpen(true);
  };

  const handleSubmit = () => {
    if (editingLockDate) {
      updateMutation.mutate({ id: editingLockDate.id, data: formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to deactivate this lock date?')) {
      deleteMutation.mutate(id);
    }
  };

  const getLockTypeBadge = (type: string) => {
    const colors: Record<string, string> = {
      all: 'bg-red-100 text-red-800',
      sales: 'bg-blue-100 text-blue-800',
      purchases: 'bg-purple-100 text-purple-800',
      inventory: 'bg-green-100 text-green-800',
      payroll: 'bg-orange-100 text-orange-800',
      journal_entries: 'bg-yellow-100 text-yellow-800',
      assets: 'bg-pink-100 text-pink-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Lock Dates</h1>
          <p className="text-muted-foreground mt-1">
            Prevent modifications to closed accounting periods
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="lock-dates">Lock Dates</TabsTrigger>
          <TabsTrigger value="overrides">Override History</TabsTrigger>
        </TabsList>

        <TabsContent value="lock-dates" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Period Lock Dates</h2>
              <Button onClick={() => handleOpenForm()}>
                <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
                Add Lock Date
              </Button>
            </div>

            {isLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading lock dates...</p>
              </div>
            ) : lockDates.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:lock-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No lock dates configured</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Type</TableHead>
                    <TableHead>Lock Date</TableHead>
                    <TableHead>Reason</TableHead>
                    <TableHead>Set At</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lockDates.map((lockDate) => (
                    <TableRow key={lockDate.id}>
                      <TableCell>
                        <Badge className={getLockTypeBadge(lockDate.lock_type)}>
                          {lockDate.lock_type.replace('_', ' ')}
                        </Badge>
                      </TableCell>
                      <TableCell className="font-mono">{lockDate.lock_date}</TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {lockDate.reason || '-'}
                      </TableCell>
                      <TableCell className="text-sm">
                        {new Date(lockDate.set_at).toLocaleDateString()}
                      </TableCell>
                      <TableCell>
                        <Badge variant={lockDate.is_active ? 'default' : 'secondary'}>
                          {lockDate.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleOpenForm(lockDate)}
                          >
                            <Icon icon="solar:pen-linear" className="w-4 h-4" />
                          </Button>
                          {lockDate.is_active && (
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleDelete(lockDate.id)}
                            >
                              <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
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

        <TabsContent value="overrides" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Override History</h2>
            {isLoadingOverrides ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading overrides...</p>
              </div>
            ) : overrides.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:history-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No override history found</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Transaction Type</TableHead>
                    <TableHead>Transaction Date</TableHead>
                    <TableHead>Reason</TableHead>
                    <TableHead>Requested At</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {overrides.map((override) => (
                    <TableRow key={override.id}>
                      <TableCell className="font-medium">{override.transaction_type}</TableCell>
                      <TableCell className="font-mono">{override.transaction_date}</TableCell>
                      <TableCell className="text-sm">{override.override_reason}</TableCell>
                      <TableCell className="text-sm">
                        {new Date(override.created_at).toLocaleString()}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>
      </Tabs>

      <Dialog open={formOpen} onOpenChange={setFormOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingLockDate ? 'Edit Lock Date' : 'Add Lock Date'}</DialogTitle>
            <DialogDescription>
              {editingLockDate
                ? 'Update lock date configuration'
                : 'Set a new lock date to prevent modifications'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="lock_type">Lock Type *</Label>
              <Select
                value={formData.lock_type}
                onValueChange={(value: any) => setFormData({ ...formData, lock_type: value })}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Transactions</SelectItem>
                  <SelectItem value="sales">Sales</SelectItem>
                  <SelectItem value="purchases">Purchases</SelectItem>
                  <SelectItem value="inventory">Inventory</SelectItem>
                  <SelectItem value="payroll">Payroll</SelectItem>
                  <SelectItem value="journal_entries">Journal Entries</SelectItem>
                  <SelectItem value="assets">Fixed Assets</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="lock_date">Lock Date *</Label>
              <Input
                id="lock_date"
                type="date"
                value={formData.lock_date}
                onChange={(e) => setFormData({ ...formData, lock_date: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                Transactions on or before this date will be locked
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="reason">Reason</Label>
              <Input
                id="reason"
                placeholder="e.g., End of fiscal year"
                value={formData.reason}
                onChange={(e) => setFormData({ ...formData, reason: e.target.value })}
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
                'Save Lock Date'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
