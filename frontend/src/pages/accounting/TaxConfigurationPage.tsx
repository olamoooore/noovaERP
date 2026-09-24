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
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';

interface TaxRate {
  id: number;
  name: string;
  code: string;
  rate: number;
  type: string;
  tax_account_id: number;
  tax_account_name?: string;
  is_compound: boolean;
  is_active: boolean;
  description?: string;
}

export default function TaxConfigurationPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingTax, setEditingTax] = useState<TaxRate | null>(null);
  const [searchQuery, setSearchQuery] = useState('');

  // Fetch tax rates
  const { data: taxRates = [], isLoading } = useQuery({
    queryKey: ['tax-rates'],
    queryFn: async () => {
      const response = await api.get('/accounting/tax-rates');
      return Array.isArray(response.data) ? response.data as TaxRate[] : [];
    },
  });

  // Fetch accounts for dropdown
  const { data: accounts = [] } = useQuery({
    queryKey: ['accounts-liability'],
    queryFn: async () => {
      const response = await api.get('/accounting/chart-of-accounts?type=liability');
      // Extract data from response
      const accountsData = response.data?.data || response.data || [];
      return Array.isArray(accountsData) ? accountsData : [];
    },
  });

  // Create mutation
  const createMutation = useMutation({
    mutationFn: async (data: Partial<TaxRate>) => {
      const response = await api.post('/accounting/tax-rates', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tax-rates'] });
      setIsDialogOpen(false);
      setEditingTax(null);
      toast({
        title: 'Success',
        description: 'Tax rate created successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to create tax rate',
        variant: 'destructive',
      });
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<TaxRate> }) => {
      const response = await api.put(`/accounting/tax-rates/${id}`, data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tax-rates'] });
      setIsDialogOpen(false);
      setEditingTax(null);
      toast({
        title: 'Success',
        description: 'Tax rate updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update tax rate',
        variant: 'destructive',
      });
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.delete(`/accounting/tax-rates/${id}`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tax-rates'] });
      toast({
        title: 'Success',
        description: 'Tax rate deleted successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to delete tax rate',
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
      rate: parseFloat(formData.get('rate') as string),
      type: formData.get('type') as string,
      tax_account_id: parseInt(formData.get('tax_account_id') as string),
      is_compound: formData.get('is_compound') === 'on',
      description: formData.get('description') as string,
    };

    if (editingTax) {
      updateMutation.mutate({ id: editingTax.id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  const handleEdit = (tax: TaxRate) => {
    setEditingTax(tax);
    setIsDialogOpen(true);
  };

  const handleDelete = (id: number) => {
    if (confirm('Are you sure you want to delete this tax rate?')) {
      deleteMutation.mutate(id);
    }
  };

  const filteredTaxRates = taxRates.filter((tax) =>
    tax.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    tax.code.toLowerCase().includes(searchQuery.toLowerCase())
  );

  const getTypeLabel = (type: string) => {
    switch (type) {
      case 'sales':
        return 'Sales Tax';
      case 'purchase':
        return 'Purchase Tax';
      case 'vat':
        return 'VAT';
      case 'gst':
        return 'GST';
      case 'withholding':
        return 'Withholding Tax';
      default:
        return type;
    }
  };

  const getTypeBadgeColor = (type: string) => {
    switch (type) {
      case 'sales':
        return 'bg-blue-100 text-blue-800';
      case 'purchase':
        return 'bg-green-100 text-green-800';
      case 'vat':
        return 'bg-purple-100 text-purple-800';
      case 'gst':
        return 'bg-orange-100 text-orange-800';
      case 'withholding':
        return 'bg-red-100 text-red-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
          <p className="text-muted-foreground mt-2">Loading tax rates...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Tax Configuration</h1>
          <p className="text-muted-foreground mt-1">
            Configure tax rates and accounts
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      {/* Info Card */}
      <Card className="p-4 bg-blue-50 border-blue-200">
        <div className="flex items-start gap-3">
          <Icon icon="solar:info-circle-bold" className="w-5 h-5 text-blue-600 mt-0.5" />
          <div className="flex-1">
            <h3 className="font-semibold text-blue-900">Tax Configuration</h3>
            <p className="text-sm text-blue-800 mt-1">
              Configure tax rates for sales, purchases, VAT, GST, and withholding taxes. 
              Each tax rate must be linked to a liability account where tax amounts will be recorded.
            </p>
          </div>
        </div>
      </Card>

      {/* Actions Bar */}
      <Card className="p-4">
        <div className="flex items-center justify-between gap-4">
          <div className="flex-1 max-w-md">
            <Input
              placeholder="Search tax rates..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full"
            />
          </div>
          <Button
            onClick={() => {
              setEditingTax(null);
              setIsDialogOpen(true);
            }}
          >
            <Icon icon="solar:add-circle-linear" className="w-4 h-4 mr-2" />
            Add Tax Rate
          </Button>
        </div>
      </Card>

      {/* Tax Rates Table */}
      <Card>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Code</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Rate</TableHead>
              <TableHead>Tax Account</TableHead>
              <TableHead>Compound</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {filteredTaxRates.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                  No tax rates found
                </TableCell>
              </TableRow>
            ) : (
              filteredTaxRates.map((tax) => (
                <TableRow key={tax.id}>
                  <TableCell className="font-medium">{tax.name}</TableCell>
                  <TableCell>{tax.code}</TableCell>
                  <TableCell>
                    <Badge className={getTypeBadgeColor(tax.type)}>
                      {getTypeLabel(tax.type)}
                    </Badge>
                  </TableCell>
                  <TableCell className="font-semibold">{tax.rate}%</TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {tax.tax_account_name || `Account #${tax.tax_account_id}`}
                  </TableCell>
                  <TableCell>
                    {tax.is_compound ? (
                      <Badge variant="secondary">Yes</Badge>
                    ) : (
                      <span className="text-muted-foreground text-sm">No</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <Badge variant={tax.is_active ? 'default' : 'secondary'}>
                      {tax.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex items-center justify-end gap-2">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleEdit(tax)}
                      >
                        <Icon icon="solar:pen-linear" className="w-4 h-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDelete(tax.id)}
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
              {editingTax ? 'Edit Tax Rate' : 'Add Tax Rate'}
            </DialogTitle>
            <DialogDescription>
              {editingTax
                ? 'Update the tax rate details below.'
                : 'Create a new tax rate for sales, purchases, or other tax types.'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="name">Name *</Label>
                <Input
                  id="name"
                  name="name"
                  defaultValue={editingTax?.name}
                  placeholder="Sales Tax 10%"
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="code">Code *</Label>
                <Input
                  id="code"
                  name="code"
                  defaultValue={editingTax?.code}
                  placeholder="ST10"
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="type">Type *</Label>
                <Select name="type" defaultValue={editingTax?.type || 'sales'}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="sales">Sales Tax</SelectItem>
                    <SelectItem value="purchase">Purchase Tax</SelectItem>
                    <SelectItem value="vat">VAT</SelectItem>
                    <SelectItem value="gst">GST</SelectItem>
                    <SelectItem value="withholding">Withholding Tax</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="rate">Rate (%) *</Label>
                <Input
                  id="rate"
                  name="rate"
                  type="number"
                  step="0.01"
                  defaultValue={editingTax?.rate}
                  placeholder="10.00"
                  min="0"
                  max="100"
                  required
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="tax_account_id">Tax Account *</Label>
              <Select 
                name="tax_account_id" 
                defaultValue={editingTax?.tax_account_id?.toString()}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select tax liability account" />
                </SelectTrigger>
                <SelectContent>
                  {accounts.map((account: any) => (
                    <SelectItem key={account.id} value={account.id.toString()}>
                      {account.code} - {account.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-sm text-muted-foreground">
                Select the liability account where tax amounts will be recorded
              </p>
            </div>

            <div className="flex items-center space-x-2">
              <Switch
                id="is_compound"
                name="is_compound"
                defaultChecked={editingTax?.is_compound}
              />
              <Label htmlFor="is_compound" className="cursor-pointer">
                Compound Tax (calculated on top of other taxes)
              </Label>
            </div>

            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Textarea
                id="description"
                name="description"
                defaultValue={editingTax?.description}
                placeholder="Optional description or notes..."
                rows={3}
              />
            </div>

            <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
              <h4 className="font-semibold text-amber-900 mb-2">Tax Types</h4>
              <ul className="space-y-1 text-sm text-amber-800">
                <li><strong>Sales Tax:</strong> Tax collected on sales to customers</li>
                <li><strong>Purchase Tax:</strong> Tax paid on purchases from vendors</li>
                <li><strong>VAT:</strong> Value Added Tax (input and output)</li>
                <li><strong>GST:</strong> Goods and Services Tax</li>
                <li><strong>Withholding Tax:</strong> Tax withheld from payments</li>
              </ul>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsDialogOpen(false);
                  setEditingTax(null);
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
                    {editingTax ? 'Update' : 'Create'}
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
