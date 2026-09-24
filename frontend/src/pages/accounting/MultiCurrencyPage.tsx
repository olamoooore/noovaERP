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
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/hooks/use-toast';
import currenciesApi, {
  type Currency,
  type CreateCurrencyRequest,
  type UpdateCurrencyRequest,
} from '@/lib/currenciesApi';

export default function MultiCurrencyPage({ embedded = false }: { embedded?: boolean }) {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('currencies');
  const [currencyFormOpen, setCurrencyFormOpen] = useState(false);
  const [editingCurrency, setEditingCurrency] = useState<Currency | null>(null);
  const [formData, setFormData] = useState<CreateCurrencyRequest>({
    code: '',
    name: '',
    symbol: '',
    decimal_places: 2,
  });

  // Fetch currencies. Issue: the page sometimes showed "No currencies found"
  // immediately after a tab switch because isLoading is false (cached data)
  // but the defensive array coercion produced an empty list during the brief
  // refetch window. Fix: track isFetching explicitly and never show
  // "No currencies found" while a request is still in-flight (including
  // background refetches). Also keep previous data on the table render so a
  // transient refetch failure / empty response doesn't blank the UI.
  const { data: currenciesResponse, isLoading, isFetching, isError } = useQuery({
    queryKey: ['currencies'],
    queryFn: () => currenciesApi.getCurrencies(),
    refetchOnWindowFocus: false,
    staleTime: 30_000, // 30s — avoids redundant refetches on tab navigation
  });

  // Safely extract currencies array from response
  const currencies: Currency[] = Array.isArray(currenciesResponse)
    ? currenciesResponse
    : (currenciesResponse?.data && Array.isArray(currenciesResponse.data))
      ? currenciesResponse.data
      : [];

  // While any request is in flight (initial or background refetch) and we
  // don't have data yet, we are still loading — show the spinner.
  const isStillLoading = isLoading || (isFetching && currencies.length === 0);

  // Create currency mutation
  const createMutation = useMutation({
    mutationFn: currenciesApi.createCurrency,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['currencies'] });
      toast({ title: 'Success', description: 'Currency created successfully' });
      setCurrencyFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create currency',
        variant: 'destructive',
      });
    },
  });

  // Update currency mutation
  const updateMutation = useMutation({
    mutationFn: ({ code, data }: { code: string; data: UpdateCurrencyRequest }) =>
      currenciesApi.updateCurrency(code, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['currencies'] });
      toast({ title: 'Success', description: 'Currency updated successfully' });
      setCurrencyFormOpen(false);
      resetForm();
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update currency',
        variant: 'destructive',
      });
    },
  });

  // Toggle currency active/inactive state. Issue: "no where to deactivate
  // currency all is just turned on" — the previous UI only exposed a
  // trash-can icon (easy to miss) and a separate "Activate" button. This
  // toggle gives a single, obvious on/off affordance per row.
  const toggleActiveMutation = useMutation({
    mutationFn: ({ code, isActive }: { code: string; isActive: boolean }) =>
      currenciesApi.updateCurrency(code, { is_active: isActive }),
    onSuccess: (_data, vars) => {
      queryClient.invalidateQueries({ queryKey: ['currencies'] });
      toast({
        title: 'Success',
        description: vars.isActive ? 'Currency activated' : 'Currency deactivated',
      });
    },
    onError: (error: any, vars) => {
      toast({
        title: 'Error',
        description:
          error.response?.data?.message ||
          (vars.isActive ? 'Failed to activate currency' : 'Failed to deactivate currency'),
        variant: 'destructive',
      });
    },
  });

  // Legacy wrappers kept so existing callers (and tests) still compile.
  const activateMutation = {
    mutate: (code: string) => toggleActiveMutation.mutate({ code, isActive: true }),
    isPending: toggleActiveMutation.isPending,
  };
  const deleteMutation = {
    mutate: (code: string) => toggleActiveMutation.mutate({ code, isActive: false }),
    isPending: toggleActiveMutation.isPending,
  };

  const resetForm = () => {
    setFormData({
      code: '',
      name: '',
      symbol: '',
      decimal_places: 2,
    });
    setEditingCurrency(null);
  };

  const handleOpenForm = (currency?: Currency) => {
    if (currency) {
      setEditingCurrency(currency);
      setFormData({
        code: currency.code,
        name: currency.name,
        symbol: currency.symbol || '',
        decimal_places: currency.decimal_places,
      });
    } else {
      resetForm();
    }
    setCurrencyFormOpen(true);
  };

  const handleSubmit = () => {
    if (editingCurrency) {
      updateMutation.mutate({ code: editingCurrency.code, data: formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleDelete = (code: string) => {
    if (confirm('Are you sure you want to deactivate this currency?')) {
      deleteMutation.mutate(code);
    }
  };

  return (
    <div className={embedded ? 'space-y-6' : 'p-6 space-y-6'}>
      {/* Header */}
      {!embedded && <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Multi-currency Management</h1>
          <p className="text-muted-foreground mt-1">
            Manage currencies and exchange rates
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>}

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="currencies">Currencies</TabsTrigger>
          <TabsTrigger value="rates">Exchange Rates</TabsTrigger>
          <TabsTrigger value="converter">Currency Converter</TabsTrigger>
        </TabsList>

        <TabsContent value="currencies" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Currencies</h2>
              <HeaderActionButton icon="solar:add-circle-linear" onClick={() => handleOpenForm()}>
                Add Currency
              </HeaderActionButton>
            </div>

            {isStillLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading currencies...</p>
              </div>
            ) : isError && currencies.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:danger-triangle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>Failed to load currencies. Please retry.</p>
                <Button
                  variant="outline"
                  className="mt-4"
                  onClick={() => window.location.reload()}
                >
                  Retry
                </Button>
              </div>
            ) : currencies.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:dollar-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No currencies found</p>
                <p className="text-xs mt-2">Click "Add Currency" to seed your first currency.</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Symbol</TableHead>
                    <TableHead>Decimal Places</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {currencies.map((currency) => (
                    <TableRow key={currency.code}>
                      <TableCell className="font-mono font-medium">{currency.code}</TableCell>
                      <TableCell>{currency.name}</TableCell>
                      <TableCell className="text-lg">{currency.symbol || '-'}</TableCell>
                      <TableCell>{currency.decimal_places}</TableCell>
                      <TableCell>
                        <Badge variant={currency.is_active ? 'default' : 'secondary'}>
                          {currency.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end items-center gap-3">
                          <div
                            className="flex items-center gap-2"
                            title={currency.is_active ? 'Active — click to deactivate' : 'Inactive — click to activate'}
                          >
                            <Switch
                              checked={currency.is_active}
                              onCheckedChange={(checked) =>
                                toggleActiveMutation.mutate({
                                  code: currency.code,
                                  isActive: checked,
                                })
                              }
                              disabled={toggleActiveMutation.isPending}
                              aria-label={`Toggle ${currency.code} active state`}
                            />
                            <span className="text-xs text-muted-foreground w-12 text-left">
                              {currency.is_active ? 'On' : 'Off'}
                            </span>
                          </div>
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => handleOpenForm(currency)}
                            title="Edit currency details"
                          >
                            <Icon icon="solar:pen-linear" className="w-4 h-4" />
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

        <TabsContent value="rates" className="space-y-4">
          <Card className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-xl font-semibold">Exchange Rates</h2>
            </div>
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:chart-2-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
              <p>Exchange rate management will be available in a future update.</p>
            </div>
          </Card>
        </TabsContent>

        <TabsContent value="converter" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Currency Converter</h2>
            <div className="text-center py-12 text-muted-foreground">
              <Icon icon="solar:refresh-circle-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
              <p>Currency converter will be available in a future update.</p>
            </div>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Currency Form Dialog */}
      <Dialog open={currencyFormOpen} onOpenChange={setCurrencyFormOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingCurrency ? 'Edit Currency' : 'Add Currency'}</DialogTitle>
            <DialogDescription>
              {editingCurrency ? 'Update currency details' : 'Add a new currency to the system'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="code">Currency Code *</Label>
              <Input
                id="code"
                placeholder="USD"
                value={formData.code}
                onChange={(e) => setFormData({ ...formData, code: e.target.value.toUpperCase() })}
                maxLength={3}
                disabled={!!editingCurrency}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="name">Currency Name *</Label>
              <Input
                id="name"
                placeholder="US Dollar"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="symbol">Symbol</Label>
              <Input
                id="symbol"
                placeholder="$"
                value={formData.symbol}
                onChange={(e) => setFormData({ ...formData, symbol: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="decimal_places">Decimal Places</Label>
              <Input
                id="decimal_places"
                type="number"
                min="0"
                max="4"
                value={formData.decimal_places}
                onChange={(e) =>
                  setFormData({ ...formData, decimal_places: parseInt(e.target.value) || 2 })
                }
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCurrencyFormOpen(false)}>
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
                'Save Currency'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
