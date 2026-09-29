import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

interface TaxFormState {
  name: string;
  code: string;
  rate: string;
  type: string;
  tax_account_id: string;
  is_compound: boolean;
  description: string;
}

const EMPTY_FORM: TaxFormState = {
  name: '',
  code: '',
  rate: '',
  type: 'sales',
  tax_account_id: '',
  is_compound: false,
  description: '',
};

const TYPE_META: Record<string, { label: string; className: string }> = {
  sales: { label: 'Sales Tax', className: 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300' },
  purchase: { label: 'Purchase Tax', className: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300' },
  vat: { label: 'VAT', className: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300' },
  gst: { label: 'GST', className: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300' },
  withholding: { label: 'Withholding Tax', className: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300' },
};

export default function TaxConfigurationPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingTax, setEditingTax] = useState<TaxRate | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [form, setForm] = useState<TaxFormState>(EMPTY_FORM);

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
        title: 'Tax rate created',
        description: 'The tax rate is now available on documents.',
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
        title: 'Tax rate updated',
        description: 'The changes apply to future documents.',
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
        title: 'Tax rate deleted',
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

  // Hydrate the controlled form whenever the dialog opens for create/edit.
  useEffect(() => {
    if (!isDialogOpen) return;
    setForm(editingTax ? {
      name: editingTax.name ?? '',
      code: editingTax.code ?? '',
      rate: String(editingTax.rate ?? ''),
      type: editingTax.type || 'sales',
      tax_account_id: editingTax.tax_account_id ? String(editingTax.tax_account_id) : '',
      is_compound: Boolean(editingTax.is_compound),
      description: editingTax.description ?? '',
    } : EMPTY_FORM);
  }, [isDialogOpen, editingTax]);

  const isSaving = createMutation.isPending || updateMutation.isPending;

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    // Guard the payloads the old FormData flow could send as NaN / always-false
    // (an empty account produced `parseInt('')`, and the Radix Switch never
    // carried a form value so compound was silently stored as false).
    if (!form.tax_account_id) {
      toast({
        title: 'Tax account required',
        description: 'Select the liability account where tax amounts are recorded.',
        variant: 'destructive',
      });
      return;
    }

    const rate = parseFloat(form.rate);
    if (Number.isNaN(rate)) {
      toast({ title: 'Rate required', description: 'Enter a valid tax rate percentage.', variant: 'destructive' });
      return;
    }

    const data = {
      name: form.name,
      code: form.code,
      rate,
      type: form.type,
      tax_account_id: parseInt(form.tax_account_id, 10),
      is_compound: form.is_compound,
      description: form.description,
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

  const getTypeMeta = (type: string) =>
    TYPE_META[type] ?? { label: type, className: 'bg-muted text-muted-foreground' };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <HeaderActionButton
          variant="outline"
          icon="solar:alt-arrow-left-bold-duotone"
          onClick={() => navigate('/accounting')}
          className="mb-3"
        >
          Back to Accounting
        </HeaderActionButton>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Tax Configuration</h1>
            <p className="mt-1 text-muted-foreground">Configure tax rates and accounts</p>
          </div>
          <HeaderActionButton
            icon="solar:add-circle-bold-duotone"
            onClick={() => {
              setEditingTax(null);
              setIsDialogOpen(true);
            }}
          >
            Add Tax Rate
          </HeaderActionButton>
        </div>
      </div>

      {/* Explainer */}
      <div className="flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50/70 p-4 dark:border-blue-900 dark:bg-blue-950/40">
        <Icon icon="solar:info-circle-bold-duotone" className="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" />
        <p className="text-sm text-blue-900 dark:text-blue-200">
          Configure tax rates for sales, purchases, VAT, GST, and withholding taxes. Each tax rate
          must be linked to a liability account where tax amounts will be recorded.
        </p>
      </div>

      {/* Tax rates */}
      <Card className="overflow-hidden rounded-xl">
        <CardHeader className="border-b">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                <Icon icon="solar:tag-price-bold-duotone" className="h-5 w-5 text-primary" />
              </div>
              <div>
                <CardTitle className="text-base">Tax Rates</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  {filteredTaxRates.length} of {taxRates.length} rates
                </p>
              </div>
            </div>
            <div className="relative w-full sm:w-72">
              <Icon
                icon="solar:magnifer-bold-duotone"
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
              />
              <Input
                placeholder="Search tax rates..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="h-8 rounded-full pl-9 pr-3 text-xs"
              />
            </div>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          {isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Icon icon="eos-icons:loading" className="h-7 w-7 animate-spin text-primary" />
            </div>
          ) : filteredTaxRates.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-16 text-center text-muted-foreground">
              <Icon icon="solar:tag-price-bold-duotone" className="h-10 w-10 opacity-30" />
              <p className="text-sm font-medium">
                {searchQuery ? 'No tax rates match your search' : 'No tax rates configured yet'}
              </p>
              <p className="text-xs">
                {searchQuery ? 'Try a different name or code.' : 'Add your first tax rate to start charging tax on documents.'}
              </p>
              {!searchQuery && (
                <HeaderActionButton
                  variant="outline"
                  icon="solar:add-circle-bold-duotone"
                  className="mt-2"
                  onClick={() => {
                    setEditingTax(null);
                    setIsDialogOpen(true);
                  }}
                >
                  Add Tax Rate
                </HeaderActionButton>
              )}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/50">
                    <TableHead>Name</TableHead>
                    <TableHead>Code</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Rate</TableHead>
                    <TableHead>Tax Account</TableHead>
                    <TableHead>Compound</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredTaxRates.map((tax) => {
                    const typeMeta = getTypeMeta(tax.type);
                    return (
                      <TableRow key={tax.id} className="hover:bg-muted/50">
                        <TableCell>
                          <div className="font-medium">{tax.name}</div>
                          {tax.description && (
                            <div className="max-w-[280px] truncate text-xs text-muted-foreground" title={tax.description}>
                              {tax.description}
                            </div>
                          )}
                        </TableCell>
                        <TableCell className="font-mono text-xs">{tax.code}</TableCell>
                        <TableCell>
                          <Badge variant="secondary" className={`whitespace-nowrap ${typeMeta.className}`}>
                            {typeMeta.label}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right font-semibold tabular-nums">{tax.rate}%</TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {tax.tax_account_name || `Account #${tax.tax_account_id}`}
                        </TableCell>
                        <TableCell>
                          {tax.is_compound ? (
                            <Badge variant="secondary">Yes</Badge>
                          ) : (
                            <span className="text-sm text-muted-foreground">No</span>
                          )}
                        </TableCell>
                        <TableCell>
                          <Badge
                            variant="secondary"
                            className={tax.is_active
                              ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                              : 'bg-muted text-muted-foreground'}
                          >
                            {tax.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-7 w-7 p-0"
                              onClick={() => handleEdit(tax)}
                              title="Edit tax rate"
                              aria-label={`Edit ${tax.name}`}
                            >
                              <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-7 w-7 p-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                              onClick={() => handleDelete(tax.id)}
                              title="Delete tax rate"
                              aria-label={`Delete ${tax.name}`}
                            >
                              <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Create/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={(open) => {
        setIsDialogOpen(open);
        if (!open) setEditingTax(null);
      }}>
        <DialogContent className="max-h-[90vh] overflow-y-auto max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:tag-price-bold-duotone" className="h-5 w-5 text-primary" />
              {editingTax ? 'Edit Tax Rate' : 'Add Tax Rate'}
            </DialogTitle>
            <DialogDescription>
              {editingTax
                ? 'Update the tax rate details below.'
                : 'Create a new tax rate for sales, purchases, or other tax types.'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="tax-name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="tax-name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="Sales Tax 10%"
                  required
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="tax-code">
                  Code <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="tax-code"
                  value={form.code}
                  onChange={(e) => setForm({ ...form, code: e.target.value })}
                  placeholder="ST10"
                  required
                />
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="tax-type">
                  Type <span className="text-destructive">*</span>
                </Label>
                <Select value={form.type} onValueChange={(value) => setForm({ ...form, type: value })}>
                  <SelectTrigger id="tax-type">
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
                <Label htmlFor="tax-rate">
                  Rate (%) <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="tax-rate"
                  type="number"
                  step="0.01"
                  min="0"
                  max="100"
                  value={form.rate}
                  onChange={(e) => setForm({ ...form, rate: e.target.value })}
                  placeholder="10.00"
                  required
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="tax-account">
                Tax Account <span className="text-destructive">*</span>
              </Label>
              <Select
                value={form.tax_account_id}
                onValueChange={(value) => setForm({ ...form, tax_account_id: value })}
              >
                <SelectTrigger id="tax-account">
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
              <p className="text-xs text-muted-foreground">
                Select the liability account where tax amounts will be recorded
              </p>
            </div>

            <div className="flex items-center justify-between gap-3 rounded-xl border bg-muted/30 px-3 py-2.5">
              <div>
                <Label htmlFor="is_compound" className="cursor-pointer">Compound Tax</Label>
                <p className="text-xs text-muted-foreground">Calculated on top of other taxes.</p>
              </div>
              <Switch
                id="is_compound"
                checked={form.is_compound}
                onCheckedChange={(checked) => setForm({ ...form, is_compound: checked })}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="tax-description">Description</Label>
              <Textarea
                id="tax-description"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                placeholder="Optional description or notes..."
                rows={3}
              />
            </div>

            <div className="rounded-xl border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-900 dark:bg-amber-950/40">
              <h4 className="mb-2 text-sm font-semibold text-amber-900 dark:text-amber-200">Tax Types</h4>
              <ul className="space-y-1 text-xs text-amber-800 dark:text-amber-300">
                <li><strong>Sales Tax:</strong> Tax collected on sales to customers</li>
                <li><strong>Purchase Tax:</strong> Tax paid on purchases from vendors</li>
                <li><strong>VAT:</strong> Value Added Tax (input and output)</li>
                <li><strong>GST:</strong> Goods and Services Tax</li>
                <li><strong>Withholding Tax:</strong> Tax withheld from payments</li>
              </ul>
            </div>

            <DialogFooter>
              <HeaderActionButton
                type="button"
                variant="outline"
                icon="solar:close-circle-bold-duotone"
                onClick={() => {
                  setIsDialogOpen(false);
                  setEditingTax(null);
                }}
              >
                Cancel
              </HeaderActionButton>
              <HeaderActionButton
                type="submit"
                icon="solar:diskette-bold-duotone"
                loading={isSaving}
                disabled={isSaving}
              >
                {editingTax ? 'Update Tax Rate' : 'Create Tax Rate'}
              </HeaderActionButton>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}