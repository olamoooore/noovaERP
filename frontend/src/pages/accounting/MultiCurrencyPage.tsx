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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import currenciesApi, {
  type ConversionResult,
  type Currency,
  type CreateCurrencyRequest,
  type CreateExchangeRateRequest,
  type ExchangeRate,
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
  // NOTE: this key must stay distinct from CompanySettingsPage's
  // ['currency-settings'] (different endpoint, different payload). Both
  // previously used ['currencies'] and, because this page is embedded inside
  // Company Settings, the shared cache entry made this list render empty.
  const { data: currenciesResponse, isLoading, isFetching, isError } = useQuery({
    queryKey: ['accounting-currencies'],
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

  // Invalidate every cache fed by currency data: this page, the company
  // settings form (base currency), and the bank-account active list.
  const invalidateCurrencyCaches = () => {
    queryClient.invalidateQueries({ queryKey: ['accounting-currencies'] });
    queryClient.invalidateQueries({ queryKey: ['currencies'] });
    queryClient.invalidateQueries({ queryKey: ['currency-settings'] });
  };

  // ── Exchange rates ───────────────────────────────────────────────────
  const [rateFormOpen, setRateFormOpen] = useState(false);
  const [syncBaseCurrency, setSyncBaseCurrency] = useState('SGD');
  const [rateForm, setRateForm] = useState<CreateExchangeRateRequest>({
    from_currency: '',
    to_currency: '',
    rate: 0,
    effective_date: new Date().toISOString().slice(0, 10),
  });

  const { data: ratesResponse, isLoading: ratesLoading } = useQuery({
    queryKey: ['exchange-rates'],
    queryFn: () => currenciesApi.getExchangeRates({ per_page: 200 }),
    refetchOnWindowFocus: false,
  });

  const rates: ExchangeRate[] = Array.isArray(ratesResponse)
    ? ratesResponse
    : (ratesResponse?.data ?? []);

  const createRateMutation = useMutation({
    mutationFn: currenciesApi.createExchangeRate,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      toast({ title: 'Success', description: 'Exchange rate saved' });
      setRateFormOpen(false);
      setRateForm({
        from_currency: '',
        to_currency: '',
        rate: 0,
        effective_date: new Date().toISOString().slice(0, 10),
      });
    },
    onError: (error: any) => {
      const errors = error.response?.data?.errors;
      const first = errors ? Object.values(errors).flat()[0] : null;
      toast({
        title: 'Error',
        description: (first as string) || error.response?.data?.message || 'Failed to save exchange rate',
        variant: 'destructive',
      });
    },
  });

  const syncRatesMutation = useMutation({
    mutationFn: () => {
      const active = currencies.filter((c) => c.is_active).map((c) => c.code);
      const base = active.includes(syncBaseCurrency) ? syncBaseCurrency : (active[0] ?? 'SGD');
      const targets = active.filter((c) => c !== base);
      return currenciesApi.syncExchangeRates({ base_currency: base, target_currencies: targets });
    },
    onSuccess: (data: any) => {
      queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      const results = data?.results ?? {};
      const ok = Object.values(results).filter((r: any) => r?.success).length;
      toast({ title: 'Rates synced', description: `${ok} rate(s) updated.` });
    },
    onError: (error: any) => {
      toast({
        title: 'Sync failed',
        description: error.response?.data?.error || 'Could not fetch exchange rates',
        variant: 'destructive',
      });
    },
  });

  const deleteRateMutation = useMutation({
    mutationFn: (id: number) => currenciesApi.deleteExchangeRate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['exchange-rates'] });
      toast({ title: 'Success', description: 'Exchange rate deleted' });
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to delete exchange rate', variant: 'destructive' });
    },
  });

  // ── Currency converter ───────────────────────────────────────────────
  const [converterForm, setConverterForm] = useState({
    amount: '1',
    from_currency: 'USD',
    to_currency: 'SGD',
  });
  const [conversion, setConversion] = useState<ConversionResult | null>(null);

  const convertMutation = useMutation({
    mutationFn: () =>
      currenciesApi.convertCurrency({
        amount: parseFloat(converterForm.amount) || 0,
        from_currency: converterForm.from_currency,
        to_currency: converterForm.to_currency,
      }),
    onSuccess: (data) => setConversion(data),
    onError: (error: any) => {
      setConversion(null);
      toast({
        title: 'Conversion failed',
        description: error.response?.data?.error || 'No exchange rate found for this pair',
        variant: 'destructive',
      });
    },
  });

  // While any request is in flight (initial or background refetch) and we
  // don't have data yet, we are still loading — show the spinner.
  const isStillLoading = isLoading || (isFetching && currencies.length === 0);

  // Create currency mutation
  const createMutation = useMutation({
    mutationFn: currenciesApi.createCurrency,
    onSuccess: () => {
      invalidateCurrencyCaches();
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
      invalidateCurrencyCaches();
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
      invalidateCurrencyCaches();
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
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
              <h2 className="text-xl font-semibold">Exchange Rates</h2>
              <div className="flex items-center gap-2">
                <Select value={syncBaseCurrency} onValueChange={setSyncBaseCurrency}>
                  <SelectTrigger className="w-40 h-9">
                    <SelectValue placeholder="Base currency" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.filter((c) => c.is_active).map((c) => (
                      <SelectItem key={c.code} value={c.code}>
                        {c.code} — {c.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <HeaderActionButton
                  icon="solar:refresh-linear"
                  onClick={() => syncRatesMutation.mutate()}
                >
                  {syncRatesMutation.isPending ? 'Syncing...' : 'Sync Rates'}
                </HeaderActionButton>
                <HeaderActionButton icon="solar:add-circle-linear" onClick={() => setRateFormOpen(true)}>
                  Add Rate
                </HeaderActionButton>
              </div>
            </div>

            {ratesLoading ? (
              <div className="text-center py-12">
                <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
                <p className="text-muted-foreground mt-2">Loading exchange rates...</p>
              </div>
            ) : rates.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <Icon icon="solar:chart-2-bold-duotone" className="w-16 h-16 mx-auto mb-4 opacity-50" />
                <p>No exchange rates yet</p>
                <p className="text-xs mt-2">
                  Click "Sync Rates" to fetch today's rates, or "Add Rate" to enter one manually.
                </p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Effective Date</TableHead>
                    <TableHead>From</TableHead>
                    <TableHead>To</TableHead>
                    <TableHead>Rate</TableHead>
                    <TableHead>Source</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rates.map((rate) => (
                    <TableRow key={rate.id}>
                      <TableCell>{rate.effective_date?.slice(0, 10)}</TableCell>
                      <TableCell className="font-mono">{rate.from_currency}</TableCell>
                      <TableCell className="font-mono">{rate.to_currency}</TableCell>
                      <TableCell className="font-mono">{Number(rate.rate).toFixed(6)}</TableCell>
                      <TableCell>
                        <Badge variant="secondary">{rate.source || 'manual'}</Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          title="Delete rate"
                          onClick={() => {
                            if (confirm(`Delete ${rate.from_currency} → ${rate.to_currency} (${rate.effective_date?.slice(0, 10)})?`)) {
                              deleteRateMutation.mutate(rate.id);
                            }
                          }}
                        >
                          <Icon icon="solar:trash-bin-trash-linear" className="w-4 h-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="converter" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Currency Converter</h2>
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
              <div className="space-y-2">
                <Label htmlFor="converter-amount">Amount</Label>
                <Input
                  id="converter-amount"
                  type="number"
                  min="0"
                  step="0.01"
                  value={converterForm.amount}
                  onChange={(e) => setConverterForm({ ...converterForm, amount: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>From</Label>
                <Select
                  value={converterForm.from_currency}
                  onValueChange={(value) => setConverterForm({ ...converterForm, from_currency: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="From" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.map((c) => (
                      <SelectItem key={c.code} value={c.code}>{c.code}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>To</Label>
                <Select
                  value={converterForm.to_currency}
                  onValueChange={(value) => setConverterForm({ ...converterForm, to_currency: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="To" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.map((c) => (
                      <SelectItem key={c.code} value={c.code}>{c.code}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <Button
                onClick={() => convertMutation.mutate()}
                disabled={convertMutation.isPending}
              >
                {convertMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Converting...
                  </>
                ) : (
                  'Convert'
                )}
              </Button>
            </div>

            {conversion && (
              <div className="mt-6 rounded-lg border p-4 bg-muted/30">
                <div className="text-2xl font-semibold">
                  {Number(conversion.original_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })} {conversion.original_currency}
                  {' = '}
                  {Number(conversion.converted_amount).toLocaleString(undefined, { minimumFractionDigits: 2 })} {conversion.converted_currency}
                </div>
                <div className="text-sm text-muted-foreground mt-1">
                  Rate: 1 {conversion.original_currency} = {Number(conversion.exchange_rate).toFixed(6)} {conversion.converted_currency}
                  {' · '}Effective {conversion.effective_date?.slice(0, 10)}
                </div>
              </div>
            )}
            {!conversion && (
              <p className="text-xs text-muted-foreground mt-4">
                Conversions use the latest effective exchange rate (inverse pairs are derived automatically).
              </p>
            )}
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

      {/* Exchange Rate Form Dialog */}
      <Dialog open={rateFormOpen} onOpenChange={setRateFormOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Exchange Rate</DialogTitle>
            <DialogDescription>
              Enter the rate manually, or use "Sync Rates" to fetch today's rates automatically.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>From Currency *</Label>
                <Select
                  value={rateForm.from_currency}
                  onValueChange={(value) => setRateForm({ ...rateForm, from_currency: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="From" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.map((c) => (
                      <SelectItem key={c.code} value={c.code}>{c.code} — {c.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>To Currency *</Label>
                <Select
                  value={rateForm.to_currency}
                  onValueChange={(value) => setRateForm({ ...rateForm, to_currency: value })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="To" />
                  </SelectTrigger>
                  <SelectContent>
                    {currencies.map((c) => (
                      <SelectItem key={c.code} value={c.code}>{c.code} — {c.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="rate-value">Rate *</Label>
              <Input
                id="rate-value"
                type="number"
                min="0.000001"
                step="0.000001"
                placeholder="e.g. 1.3452"
                value={rateForm.rate || ''}
                onChange={(e) => setRateForm({ ...rateForm, rate: parseFloat(e.target.value) || 0 })}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="rate-date">Effective Date *</Label>
              <Input
                id="rate-date"
                type="date"
                value={rateForm.effective_date}
                onChange={(e) => setRateForm({ ...rateForm, effective_date: e.target.value })}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRateFormOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => createRateMutation.mutate(rateForm)}
              disabled={
                createRateMutation.isPending ||
                !rateForm.from_currency ||
                !rateForm.to_currency ||
                rateForm.from_currency === rateForm.to_currency ||
                rateForm.rate <= 0 ||
                !rateForm.effective_date
              }
            >
              {createRateMutation.isPending ? (
                <>
                  <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                  Saving...
                </>
              ) : (
                'Save Rate'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
