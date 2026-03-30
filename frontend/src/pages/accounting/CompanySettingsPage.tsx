import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
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

export default function CompanySettingsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('company');

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
  const { data: currencies } = useQuery({
    queryKey: ['currencies'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/currencies');
      return response.data.data;
    },
  });

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
    const formData = new FormData(e.currentTarget);
    const thousandSep = formData.get('thousand_separator') as string;
    const data = {
      base_currency: formData.get('base_currency'),
      date_format: formData.get('date_format'),
      number_format: formData.get('number_format'),
      decimal_places: parseInt(formData.get('decimal_places') as string),
      thousand_separator: thousandSep === 'none' ? '' : thousandSep,
      decimal_separator: formData.get('decimal_separator'),
    };
    updateCurrenciesMutation.mutate(data);
  };

  const handleAccountsSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const formData = new FormData(e.currentTarget);
    const data = {
      accounts_receivable: formData.get('accounts_receivable') && formData.get('accounts_receivable') !== 'none' ? parseInt(formData.get('accounts_receivable') as string) : null,
      accounts_payable: formData.get('accounts_payable') && formData.get('accounts_payable') !== 'none' ? parseInt(formData.get('accounts_payable') as string) : null,
      sales_revenue: formData.get('sales_revenue') && formData.get('sales_revenue') !== 'none' ? parseInt(formData.get('sales_revenue') as string) : null,
      sales_tax: formData.get('sales_tax') && formData.get('sales_tax') !== 'none' ? parseInt(formData.get('sales_tax') as string) : null,
      purchase_expense: formData.get('purchase_expense') && formData.get('purchase_expense') !== 'none' ? parseInt(formData.get('purchase_expense') as string) : null,
      purchase_tax: formData.get('purchase_tax') && formData.get('purchase_tax') !== 'none' ? parseInt(formData.get('purchase_tax') as string) : null,
      inventory: formData.get('inventory') && formData.get('inventory') !== 'none' ? parseInt(formData.get('inventory') as string) : null,
      cost_of_goods_sold: formData.get('cost_of_goods_sold') && formData.get('cost_of_goods_sold') !== 'none' ? parseInt(formData.get('cost_of_goods_sold') as string) : null,
      retained_earnings: formData.get('retained_earnings') && formData.get('retained_earnings') !== 'none' ? parseInt(formData.get('retained_earnings') as string) : null,
      bank_charges: formData.get('bank_charges') && formData.get('bank_charges') !== 'none' ? parseInt(formData.get('bank_charges') as string) : null,
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
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="company">Company Info</TabsTrigger>
          <TabsTrigger value="fiscal">Fiscal Year</TabsTrigger>
          <TabsTrigger value="currency">Currency & Formats</TabsTrigger>
          <TabsTrigger value="accounts">Default Accounts</TabsTrigger>
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
                  <Select name="base_currency" defaultValue={currencies?.base_currency || 'USD'}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="USD">USD - US Dollar</SelectItem>
                      <SelectItem value="EUR">EUR - Euro</SelectItem>
                      <SelectItem value="GBP">GBP - British Pound</SelectItem>
                      <SelectItem value="JPY">JPY - Japanese Yen</SelectItem>
                      <SelectItem value="CAD">CAD - Canadian Dollar</SelectItem>
                      <SelectItem value="AUD">AUD - Australian Dollar</SelectItem>
                      <SelectItem value="INR">INR - Indian Rupee</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="date_format">Date Format</Label>
                  <Select name="date_format" defaultValue={currencies?.date_format || 'MM/DD/YYYY'}>
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
                  <Select name="number_format" defaultValue={currencies?.number_format || 'en-US'}>
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
                  <Select name="decimal_places" defaultValue={currencies?.decimal_places?.toString() || '2'}>
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
                  <Select name="thousand_separator" defaultValue={currencies?.thousand_separator || ','}>
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
                  <Select name="decimal_separator" defaultValue={currencies?.decimal_separator || '.'}>
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
                  <Select name="accounts_receivable" defaultValue={defaultAccounts?.accounts_receivable?.toString() || 'none'}>
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
                  <Select name="accounts_payable" defaultValue={defaultAccounts?.accounts_payable?.toString() || 'none'}>
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
                  <Select name="sales_revenue" defaultValue={defaultAccounts?.sales_revenue?.toString() || 'none'}>
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
                  <Select name="sales_tax" defaultValue={defaultAccounts?.sales_tax?.toString() || 'none'}>
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
                  <Select name="purchase_expense" defaultValue={defaultAccounts?.purchase_expense?.toString() || 'none'}>
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
                  <Select name="purchase_tax" defaultValue={defaultAccounts?.purchase_tax?.toString() || 'none'}>
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
                  <Select name="inventory" defaultValue={defaultAccounts?.inventory?.toString() || 'none'}>
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
                  <Select name="cost_of_goods_sold" defaultValue={defaultAccounts?.cost_of_goods_sold?.toString() || 'none'}>
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
                  <Select name="retained_earnings" defaultValue={defaultAccounts?.retained_earnings?.toString() || 'none'}>
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
                  <Select name="bank_charges" defaultValue={defaultAccounts?.bank_charges?.toString() || 'none'}>
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
      </Tabs>
    </div>
  );
}
