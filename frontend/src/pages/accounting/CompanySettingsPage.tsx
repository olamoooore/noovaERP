import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import api from '@/lib/api';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { CountryPluginSettings } from '@/pages/Settings/CountryPluginSettings';
import ChartOfAccountsPage from '@/pages/accounting/ChartOfAccountsPage';
import MultiCurrencyPage from '@/pages/accounting/MultiCurrencyPage';
import AccountingOnboardingPage from '@/pages/accounting/AccountingOnboardingPage';
import AccountingEntityManagementPage from '@/pages/accounting/AccountingEntityManagementPage';

export default function CompanySettingsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState(searchParams.get('tab') || 'company');

  // Acme tenants — Acme has its own country plugin configuration managed
  // elsewhere, so the Country Plugin tab is suppressed here for that tenant.
  // Both Acme tenant IDs (legacy + new production) are covered.
  const tenantId = localStorage.getItem('tenant_id') || '';
  const isAcmeTenant =
    tenantId === '00000000-0000-0000-0000-000000000001' ||
    tenantId === '00000000-0000-0000-0000-000000000002';

  // Redirect away from the country tab on Acme if a deep link brings them in.
  useEffect(() => {
    if (isAcmeTenant && activeTab === 'country') {
      setActiveTab('company');
      setSearchParams({ tab: 'company' }, { replace: true });
    }
  }, [isAcmeTenant, activeTab, setSearchParams]);

  // Controlled state for Currency form (Radix Select doesn't reliably emit to FormData)
  const [currencyForm, setCurrencyForm] = useState({
    base_currency: 'SGD',
    date_format: 'MM/DD/YYYY',
    number_format: 'en-US',
    decimal_places: '2',
    thousand_separator: ',',
    decimal_separator: '.',
  });

  // Controlled state for Default Accounts form
  const [accountsForm, setAccountsForm] = useState<Record<string, string>>({
    accounts_receivable: 'none',
    accounts_payable: 'none',
    sales_revenue: 'none',
    sales_tax: 'none',
    purchase_expense: 'none',
    purchase_tax: 'none',
    inventory: 'none',
    cost_of_goods_sold: 'none',
    retained_earnings: 'none',
    bank_charges: 'none',
  });

  // Fetch company settings
  const { data: settings, isLoading } = useQuery({
    queryKey: ['company-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings');
      return response.data.data;
    },
  });

  // Fetch fiscal year settings
  const { data: fiscalYear } = useQuery({
    queryKey: ['fiscal-year'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/fiscal-year');
      return response.data.data;
    },
  });

  // Fetch currency settings
  const { data: currenciesResponse } = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/currencies');
      return response.data;
    },
  });

  const currencies = currenciesResponse?.data || {};
  const availableCurrencies = currenciesResponse?.available_currencies || [];

  // Fetch default accounts
  const { data: defaultAccounts } = useQuery({
    queryKey: ['default-accounts'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/accounts');
      return response.data.data;
    },
  });

  // Fetch chart of accounts for dropdowns
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  const getAccountsByType = (type: string) =>
    accounts.filter((account: any) => account.type === type);

  // Sync controlled state with fetched data
  useEffect(() => {
    if (currenciesResponse && currenciesResponse.data) {
      const data = currenciesResponse.data;
      setCurrencyForm({
        base_currency: data.base_currency || 'SGD',
        date_format: data.date_format || 'MM/DD/YYYY',
        number_format: data.number_format || 'en-US',
        decimal_places: data.decimal_places?.toString() || '2',
        thousand_separator: data.thousand_separator ?? ',',
        decimal_separator: data.decimal_separator || '.',
      });
    }
  }, [currenciesResponse]);

  useEffect(() => {
    if (defaultAccounts) {
      setAccountsForm({
        accounts_receivable: defaultAccounts.accounts_receivable?.toString() || 'none',
        accounts_payable: defaultAccounts.accounts_payable?.toString() || 'none',
        sales_revenue: defaultAccounts.sales_revenue?.toString() || 'none',
        sales_tax: defaultAccounts.sales_tax?.toString() || 'none',
        purchase_expense: defaultAccounts.purchase_expense?.toString() || 'none',
        purchase_tax: defaultAccounts.purchase_tax?.toString() || 'none',
        inventory: defaultAccounts.inventory?.toString() || 'none',
        cost_of_goods_sold: defaultAccounts.cost_of_goods_sold?.toString() || 'none',
        retained_earnings: defaultAccounts.retained_earnings?.toString() || 'none',
        bank_charges: defaultAccounts.bank_charges?.toString() || 'none',
      });
    }
  }, [defaultAccounts]);

  // Mutation for updating company info
  const updateCompanyMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put('/accounting/settings', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company-settings'] });
      toast({
        title: 'Success',
        description: 'Company information updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update company information',
        variant: 'destructive',
      });
    },
  });

  // Mutation for updating fiscal year
  const updateFiscalYearMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put('/accounting/settings/fiscal-year', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['fiscal-year'] });
      toast({
        title: 'Success',
        description: 'Fiscal year settings updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update fiscal year settings',
        variant: 'destructive',
      });
    },
  });

  // Mutation for updating currencies
  const updateCurrenciesMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put('/accounting/settings/currencies', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['currencies'] });
      toast({
        title: 'Success',
        description: 'Currency settings updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update currency settings',
        variant: 'destructive',
      });
    },
  });

  // Mutation for updating default accounts
  const updateAccountsMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.put('/accounting/settings/accounts', data);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['default-accounts'] });
      toast({
        title: 'Success',
        description: 'Default accounts updated successfully',
      });
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update default accounts',
        variant: 'destructive',
      });
    },
  });

  // Form handlers
  const handleCompanySubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      company_name: formData.get('company_name'),
      company_logo_url: formData.get('company_logo_url'),
      tax_id: formData.get('tax_id'),
      address: formData.get('address'),
      phone: formData.get('phone'),
      email: formData.get('email'),
      website: formData.get('website'),
    };
    updateCompanyMutation.mutate(data);
  };

  const handleFiscalYearSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      fiscal_year_start: formData.get('fiscal_year_start'),
      fiscal_year_end: formData.get('fiscal_year_end'),
      lock_date: formData.get('lock_date') || null,
    };
    updateFiscalYearMutation.mutate(data);
  };

  const handleCurrencySubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const thousandSep = currencyForm.thousand_separator;
    const data = {
      base_currency: currencyForm.base_currency,
      date_format: currencyForm.date_format,
      number_format: currencyForm.number_format,
      decimal_places: parseInt(currencyForm.decimal_places),
      thousand_separator: thousandSep === 'none' ? '' : thousandSep,
      decimal_separator: currencyForm.decimal_separator,
    };
    updateCurrenciesMutation.mutate(data);
  };

  const handleAccountsSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const parseAccount = (val: string) => val && val !== 'none' ? parseInt(val) : null;
    const data = {
      accounts_receivable: parseAccount(accountsForm.accounts_receivable),
      accounts_payable: parseAccount(accountsForm.accounts_payable),
      sales_revenue: parseAccount(accountsForm.sales_revenue),
      sales_tax: parseAccount(accountsForm.sales_tax),
      purchase_expense: parseAccount(accountsForm.purchase_expense),
      purchase_tax: parseAccount(accountsForm.purchase_tax),
      inventory: parseAccount(accountsForm.inventory),
      cost_of_goods_sold: parseAccount(accountsForm.cost_of_goods_sold),
      retained_earnings: parseAccount(accountsForm.retained_earnings),
      bank_charges: parseAccount(accountsForm.bank_charges),
    };
    updateAccountsMutation.mutate(data);
  };

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="text-center py-12">
          <Icon icon="svg-spinners:ring-resize" className="w-8 h-8 mx-auto" />
          <p className="text-muted-foreground mt-2">Loading settings...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Company Settings</h1>
          <p className="text-muted-foreground mt-1">
            Configure company information and fiscal year
          </p>
        </div>
        <Button onClick={() => navigate('/accounting')}>
          <Icon icon="solar:arrow-left-linear" className="w-4 h-4 mr-2" />
          Back to Accounting
        </Button>
      </div>

      {/* Tabs */}
      <Tabs
        value={activeTab}
        onValueChange={(tab) => {
          setActiveTab(tab);
          setSearchParams({ tab }, { replace: true });
        }}
      >
        <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1">
          <TabsTrigger value="company">Company Info</TabsTrigger>
          <TabsTrigger value="fiscal">Fiscal Year</TabsTrigger>
          <TabsTrigger value="currency">Currency & Formats</TabsTrigger>
          <TabsTrigger value="accounts">Default Accounts</TabsTrigger>
          {!isAcmeTenant && (
            <TabsTrigger value="country">Country Plugin</TabsTrigger>
          )}
          <TabsTrigger value="chart-of-accounts">Chart of Accounts</TabsTrigger>
          <TabsTrigger value="multi-currency">Multi-Currency</TabsTrigger>
          {/* <TabsTrigger value="onboarding">Onboarding</TabsTrigger> */}
          <TabsTrigger value="entities">Entities</TabsTrigger>
        </TabsList>

        {/* Company Info Tab */}
        <TabsContent value="company" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Company Information</h2>
            <form onSubmit={handleCompanySubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="company_name">Company Name</Label>
                  <Input
                    id="company_name"
                    name="company_name"
                    defaultValue={settings?.company_name || ''}
                    placeholder="Enter company name"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="tax_id">Tax ID</Label>
                  <Input
                    id="tax_id"
                    name="tax_id"
                    defaultValue={settings?.tax_id || ''}
                    placeholder="Enter tax ID"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="email">Email</Label>
                  <Input
                    id="email"
                    name="email"
                    type="email"
                    defaultValue={settings?.email || ''}
                    placeholder="company@example.com"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="phone">Phone</Label>
                  <Input
                    id="phone"
                    name="phone"
                    defaultValue={settings?.phone || ''}
                    placeholder="+1 (555) 123-4567"
                  />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <Label htmlFor="address">Address</Label>
                  <Input
                    id="address"
                    name="address"
                    defaultValue={settings?.address || ''}
                    placeholder="Enter company address"
                  />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <Label htmlFor="website">Website</Label>
                  <Input
                    id="website"
                    name="website"
                    type="url"
                    defaultValue={settings?.website || ''}
                    placeholder="https://www.example.com"
                  />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <Label htmlFor="company_logo_url">Company Logo</Label>
                  <div className="flex items-center gap-3">
                    <Input
                      id="company_logo_url"
                      name="company_logo_url"
                      type="url"
                      defaultValue={settings?.company_logo_url || ''}
                      placeholder="https://www.example.com/logo.png"
                      className="flex-1"
                    />
                    <span className="text-xs text-muted-foreground">or</span>
                    <label className="flex items-center gap-2 px-3 py-2 border rounded-md cursor-pointer hover:bg-muted transition-colors">
                      <Icon icon="solar:upload-linear" className="w-4 h-4" />
                      <span className="text-sm">Upload</span>
                      <input
                        type="file"
                        accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                        className="hidden"
                        onChange={async (e) => {
                          const file = e.target.files?.[0];
                          if (!file) return;
                          const fd = new FormData();
                          fd.append('file', file);
                          fd.append('folder', 'logos');
                          try {
                            const res = await api.post('/upload', fd, {
                              headers: { 'Content-Type': 'multipart/form-data' },
                            });
                            const url = res.data.url;
                            const input = document.getElementById('company_logo_url') as HTMLInputElement;
                            if (input) input.value = url;
                            // Trigger change so form picks it up
                            input?.dispatchEvent(new Event('input', { bubbles: true }));
                            toast({ title: 'Uploaded', description: 'Logo uploaded successfully' });
                          } catch {
                            toast({ title: 'Error', description: 'Failed to upload logo', variant: 'destructive' });
                          }
                        }}
                      />
                    </label>
                    {settings?.company_logo_url && (
                      <img
                        src={settings.company_logo_url}
                        alt="Logo preview"
                        className="h-10 w-10 rounded border object-contain"
                      />
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Enter a public URL or upload an image file. This appears on invoices, quotations, and other documents.
                  </p>
                </div>
              </div>
              <Button type="submit" disabled={updateCompanyMutation.isPending}>
                {updateCompanyMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Settings
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Fiscal Year Tab */}
        <TabsContent value="fiscal" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Fiscal Year Configuration</h2>
            <form onSubmit={handleFiscalYearSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="fiscal_year_start">Fiscal Year Start Date</Label>
                  <Input
                    id="fiscal_year_start"
                    name="fiscal_year_start"
                    type="date"
                    defaultValue={fiscalYear?.fiscal_year_start || ''}
                    required
                  />
                  <p className="text-sm text-muted-foreground">
                    The first day of your fiscal year
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="fiscal_year_end">Fiscal Year End Date</Label>
                  <Input
                    id="fiscal_year_end"
                    name="fiscal_year_end"
                    type="date"
                    defaultValue={fiscalYear?.fiscal_year_end || ''}
                    required
                  />
                  <p className="text-sm text-muted-foreground">
                    The last day of your fiscal year
                  </p>
                </div>
                <div className="space-y-2 md:col-span-2">
                  <Label htmlFor="lock_date">Lock Date (Optional)</Label>
                  <Input
                    id="lock_date"
                    name="lock_date"
                    type="date"
                    defaultValue={fiscalYear?.lock_date || ''}
                  />
                  <p className="text-sm text-muted-foreground">
                    Transactions before this date cannot be modified
                  </p>
                </div>
              </div>
              <Button type="submit" disabled={updateFiscalYearMutation.isPending}>
                {updateFiscalYearMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Fiscal Year
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Currency & Formats Tab */}
        <TabsContent value="currency" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Currency & Format Settings</h2>
            <form onSubmit={handleCurrencySubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="base_currency">Base Currency</Label>
                  <Select value={currencyForm.base_currency} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, base_currency: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {availableCurrencies.length > 0 ? (
                        availableCurrencies
                          .map((c: any) => (
                            <SelectItem key={c.code} value={c.code}>
                              {c.code} - {c.name}
                            </SelectItem>
                          ))
                      ) : (
                        <>
                          <SelectItem value="SGD">SGD - Singapore Dollar</SelectItem>
                          <SelectItem value="MYR">MYR - Malaysian Ringgit</SelectItem>
                          <SelectItem value="USD">USD - US Dollar</SelectItem>
                          <SelectItem value="EUR">EUR - Euro</SelectItem>
                          <SelectItem value="GBP">GBP - British Pound</SelectItem>
                          <SelectItem value="JPY">JPY - Japanese Yen</SelectItem>
                          <SelectItem value="CAD">CAD - Canadian Dollar</SelectItem>
                          <SelectItem value="AUD">AUD - Australian Dollar</SelectItem>
                          <SelectItem value="CNY">CNY - Chinese Yuan</SelectItem>
                          <SelectItem value="THB">THB - Thai Baht</SelectItem>
                        </>
                      )}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="date_format">Date Format</Label>
                  <Select value={currencyForm.date_format} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, date_format: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="MM/DD/YYYY">MM/DD/YYYY (12/31/2025)</SelectItem>
                      <SelectItem value="DD/MM/YYYY">DD/MM/YYYY (31/12/2025)</SelectItem>
                      <SelectItem value="YYYY-MM-DD">YYYY-MM-DD (2025-12-31)</SelectItem>
                      <SelectItem value="DD-MMM-YYYY">DD-MMM-YYYY (31-Dec-2025)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="number_format">Number Format</Label>
                  <Select value={currencyForm.number_format} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, number_format: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="en-US">US (1,234.56)</SelectItem>
                      <SelectItem value="en-GB">UK (1,234.56)</SelectItem>
                      <SelectItem value="de-DE">German (1.234,56)</SelectItem>
                      <SelectItem value="fr-FR">French (1 234,56)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="decimal_places">Decimal Places</Label>
                  <Select value={currencyForm.decimal_places} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, decimal_places: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="0">0 (1,234)</SelectItem>
                      <SelectItem value="2">2 (1,234.56)</SelectItem>
                      <SelectItem value="3">3 (1,234.567)</SelectItem>
                      <SelectItem value="4">4 (1,234.5678)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="thousand_separator">Thousand Separator</Label>
                  <Select value={currencyForm.thousand_separator} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, thousand_separator: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value=",">, (comma)</SelectItem>
                      <SelectItem value=".">. (period)</SelectItem>
                      <SelectItem value=" "> (space)</SelectItem>
                      <SelectItem value="none">None</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="decimal_separator">Decimal Separator</Label>
                  <Select value={currencyForm.decimal_separator} onValueChange={(v) => setCurrencyForm(prev => ({ ...prev, decimal_separator: v }))}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value=".">. (period)</SelectItem>
                      <SelectItem value=",">, (comma)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <Button type="submit" disabled={updateCurrenciesMutation.isPending}>
                {updateCurrenciesMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Currency Settings
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Default Accounts Tab */}
        <TabsContent value="accounts" className="space-y-4">
          <Card className="p-6">
            <h2 className="text-xl font-semibold mb-4">Default Accounts</h2>
            <p className="text-sm text-muted-foreground mb-4">
              Configure default accounts for common transactions. These accounts will be automatically selected when creating new transactions.
            </p>
            <form onSubmit={handleAccountsSubmit} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="accounts_receivable">Accounts Receivable</Label>
                  <Select value={accountsForm.accounts_receivable} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, accounts_receivable: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('asset').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="accounts_payable">Accounts Payable</Label>
                  <Select value={accountsForm.accounts_payable} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, accounts_payable: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('liability').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="sales_revenue">Sales Revenue</Label>
                  <Select value={accountsForm.sales_revenue} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, sales_revenue: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('revenue').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="sales_tax">Sales Tax</Label>
                  <Select value={accountsForm.sales_tax} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, sales_tax: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('liability').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="purchase_expense">Purchase Expense</Label>
                  <Select value={accountsForm.purchase_expense} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, purchase_expense: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('expense').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="purchase_tax">Purchase Tax</Label>
                  <Select value={accountsForm.purchase_tax} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, purchase_tax: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('asset').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="inventory">Inventory</Label>
                  <Select value={accountsForm.inventory} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, inventory: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('asset').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="cost_of_goods_sold">Cost of Goods Sold</Label>
                  <Select value={accountsForm.cost_of_goods_sold} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, cost_of_goods_sold: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('expense').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="retained_earnings">Retained Earnings</Label>
                  <Select value={accountsForm.retained_earnings} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, retained_earnings: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('equity').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="bank_charges">Bank Charges</Label>
                  <Select value={accountsForm.bank_charges} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, bank_charges: v }))}>
                    <SelectTrigger>
                      <SelectValue placeholder="Select account" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None</SelectItem>
                      {getAccountsByType('expense').map((acc: any) => (
                        <SelectItem key={acc.id} value={acc.id.toString()}>
                          {acc.code} - {acc.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <Button type="submit" disabled={updateAccountsMutation.isPending}>
                {updateAccountsMutation.isPending ? (
                  <>
                    <Icon icon="svg-spinners:ring-resize" className="w-4 h-4 mr-2" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:diskette-linear" className="w-4 h-4 mr-2" />
                    Save Default Accounts
                  </>
                )}
              </Button>
            </form>
          </Card>
        </TabsContent>

        {/* Country Plugin Tab — suppressed for Acme (managed elsewhere). */}
        {!isAcmeTenant && (
          <TabsContent value="country" className="space-y-4">
            <CountryPluginSettings />
          </TabsContent>
        )}

        <TabsContent value="chart-of-accounts" className="space-y-4">
          <ChartOfAccountsPage embedded />
        </TabsContent>

        <TabsContent value="multi-currency" className="space-y-4">
          <MultiCurrencyPage embedded />
        </TabsContent>

        <TabsContent value="onboarding" className="space-y-4">
          <AccountingOnboardingPage />
        </TabsContent>

        <TabsContent value="entities" className="space-y-4">
          <AccountingEntityManagementPage embedded />
        </TabsContent>
      </Tabs>
    </div>
  );
}
