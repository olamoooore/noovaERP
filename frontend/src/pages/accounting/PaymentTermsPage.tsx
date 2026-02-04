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
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface PaymentTerm {
  id: number;
  name: string;
  code: string;
  type: string;
  days: number;
  discount_percentage: number;
  discount_days: number;
  is_default: boolean;
  is_active: boolean;
  description?: string;
  usage_count?: number;
}

export default function PaymentTermsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingTerm, setEditingTerm] = useState<PaymentTerm | null>(null);
  const [searchQuery, setSearchQuery] = useState('');

  // Fetch payment terms
  const { data: terms = [], isLoading } = useQuery({
    queryKey: ['payment-terms'],
    queryFn: async () => {
      const response = await api.get('/accounting/payment-terms');
      // Backend returns array directly, not wrapped in data property
      return Array.isArray(response.data) ? response.data as PaymentTerm[] : [];
    },
  });

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (data: Partial<PaymentTerm>) => {
      const response = await api.post('/accounting/payment-terms', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
      setIsDialogOpen(false);
      setEditingTerm(null);
      toast({
        title: 'Success',
        description: 'Payment term created successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to create payment term',
        variant: 'destructive',
      });
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<PaymentTerm> }) => {
      const response = await api.put(`/accounting/payment-terms/${id}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
      setIsDialogOpen(false);
      setEditingTerm(null);
      toast({
        title: 'Success',
        description: 'Payment term updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update payment term',
        variant: 'destructive',
      });
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/accounting/payment-terms/${id}`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
      toast({
        title: 'Success',
        description: 'Payment term deleted successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to delete payment term',
        variant: 'destructive',
      });
    },
  });

  // Set default mutation
  const setDefaultMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/accounting/payment-terms/${id}/set-default`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
      toast({
        title: 'Success',
        description: 'Default payment term updated',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to set default payment term',
        variant: 'destructive',
      });
    },
  });

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    
    const data = {
      name: formData.get('name') as string,
      code: formData.get('code') as string,
      type: formData.get('type') as string,
      days: parseInt(formData.get('days') as string) || 0,
      discount_percentage: parseFloat(formData.get('discount_percentage') as string) || 0,
      discount_days: parseInt(formData.get('discount_days') as string) || 0,
      description: formData.get('description') as string,
    };

    if (editingTerm) {
      updateMutation.mutate({ id: editingTerm.id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  const handleEdit = (term: PaymentTerm) => {
    setEditingTerm(term);
    setIsDialogOpen(true);
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this payment term?')) {
      deleteMutation.mutate(id);
    }
  };

  const handleSetDefault = (id: number) => {
    setDefaultMutation.mutate(id);
  };

  const filteredTerms = terms.filter((term) =>
    term.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    term.code.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const getTypeLabel = (type: string) => {
    switch (type) {
      case 'net_days':
        return 'Net Days';
      case 'end_of_month':
        return 'End of Month';
      case 'immediate':
        return 'Immediate';
      default:
        return type;
    }
  };

  const getTermDescription = (term: PaymentTerm) => {
    if (term.type === 'immediate') {
      return 'Payment due immediately';
    }
    if (term.type === 'end_of_month') {
      return `Payment due at end of month + ${term.days} days`;
    }
    if (term.discount_percentage > 0) {
      return `${term.discount_percentage}% discount if paid within ${term.discount_days} days, net ${term.days} days`;
    }
    return `Payment due in ${term.days} days`;
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
          <p className="text-muted-foreground mt-2">Loading payment terms...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Payment Terms</h1>
          <p className="text-muted-foreground mt-1">
            Define payment terms and discounts
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Actions Bar */}
      <Card className="p-4">
        <div className="flex items-center justify-between gap-4">
          <div className="flex-1 max-w-md">
            <Input
              placeholder="Search payment terms..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full"
            />
          </div>
          <Button
            onClick={() => {
              setEditingTerm(null);
              setIsDialogOpen(true);
            }}
          >
            <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
            Add Payment Term
          </Button>
        </div>
      </Card>

      {/* Payment Terms Table */}
      <Card>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Code</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Terms</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Usage</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredTerms.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                  No payment terms found
                </TableCell>
              </TableRow>
            ) : (
              filteredTerms.map((term) => (
                <TableRow key={term.id}>
                  <TableCell className="font-medium">
                    {term.name}
                    {term.is_default && (
                      <Badge variant="secondary" className="ml-2">
                        Default
                      </Badge>
                    )}
                  </TableCell>
                  <TableCell>{term.code}</TableCell>
                  <TableCell>{getTypeLabel(term.type)}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {getTermDescription(term)}
                  </TableCell>
                  <TableCell>
                    <Badge variant={term.is_active ? 'default' : 'secondary'}>
                      {term.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {term.usage_count || 0} {term.usage_count === 1 ? 'use' : 'uses'}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      {!term.is_default && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => handleSetDefault(term.id)}
                          title="Set as default"
                        >
                          <Icon icon="solar:star-linear" className="w-4 h-4" />
                        </Button>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleEdit(term)}
                      >
                        <Icon icon="solar:pen-linear" className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDelete(term.id)}
                        disabled={term.usage_count && term.usage_count > 0}
                        title={term.usage_count && term.usage_count > 0 ? 'Cannot delete term in use' : 'Delete'}
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
              {editingTerm ? 'Edit Payment Term' : 'Add Payment Term'}
            </DialogTitle>
            <DialogDescription>
              {editingTerm
                ? 'Update the payment term details below.'
                : 'Create a new payment term for invoices and bills.'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="name">Name *</Label>
                <Input
                  id="name"
                  name="name"
                  defaultValue={editingTerm?.name}
                  placeholder="Net 30"
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="code">Code *</Label>
                <Input
                  id="code"
                  name="code"
                  defaultValue={editingTerm?.code}
                  placeholder="NET30"
                  required
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="type">Type *</Label>
              <Select name="type" defaultValue={editingTerm?.type || 'net_days'}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="net_days">Net Days</SelectItem>
                  <SelectItem value="end_of_month">End of Month</SelectItem>
                  <SelectItem value="immediate">Immediate</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="days">Days</Label>
              <Input
                id="days"
                name="days"
                type="number"
                defaultValue={editingTerm?.days || 0}
                placeholder="30"
                min="0"
              />
              <p className="text-sm text-muted-foreground">
                Number of days until payment is due
              </p>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="discount_percentage">Discount %</Label>
                <Input
                  id="discount_percentage"
                  name="discount_percentage"
                  type="number"
                  step="0.01"
                  defaultValue={editingTerm?.discount_percentage || 0}
                  placeholder="2.00"
                  min="0"
                  max="100"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="discount_days">Discount Days</Label>
                <Input
                  id="discount_days"
                  name="discount_days"
                  type="number"
                  defaultValue={editingTerm?.discount_days || 0}
                  placeholder="10"
                  min="0"
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Textarea
                id="description"
                name="description"
                defaultValue={editingTerm?.description}
                placeholder="Optional description or notes..."
                rows={3}
              />
            </div>

            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <h4 className="font-semibold text-blue-900 mb-2">Examples</h4>
              <ul className="space-y-1 text-sm text-blue-800">
                <li><strong>Net 30:</strong> Payment due in 30 days</li>
                <li><strong>2/10 Net 30:</strong> 2% discount if paid within 10 days, otherwise due in 30 days</li>
                <li><strong>EOM:</strong> Payment due at end of month</li>
                <li><strong>Immediate:</strong> Payment due upon receipt</li>
              </ul>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsDialogOpen(false);
                  setEditingTerm(null);
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
                    {editingTerm ? 'Update' : 'Create'}
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
