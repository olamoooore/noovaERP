import { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Icon } from '@iconify/react';
import { Card, CardContent } from '@/components/ui/card';
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  SettingsSectionHeader,
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/hooks/use-toast';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import api from '@/lib/api';
import chartOfAccountsApi from '@/lib/chartOfAccountsApi';
import { CountryPluginSettings } from '@/pages/Settings/CountryPluginSettings';
import ChartOfAccountsPage from '@/pages/accounting/ChartOfAccountsPage';
import MultiCurrencyPage from '@/pages/accounting/MultiCurrencyPage';
import AccountingOnboardingPage from '@/pages/accounting/AccountingOnboardingPage';
import AccountingEntityManagementPage from '@/pages/accounting/AccountingEntityManagementPage';
import AnalyticAccountsPage from '@/pages/accounting/AnalyticAccountsPage';

const COMPANY_SECTIONS: SettingsSectionDef[] = [
  {
    key: 'company',
    title: 'Company Info',
    description: 'Legal name, tax ID, contact details and logo',
    icon: 'solar:buildings-bold-duotone',
    component: () => null,
  },
  {
    key: 'fiscal',
    title: 'Fiscal Year',
    description: 'Year start and end dates plus the posting lock date',
    icon: 'solar:calendar-bold-duotone',
    component: () => null,
  },
  {
    key: 'currency',
    title: 'Currency & Formats',
    description: 'Base currency, date and number formatting',
    icon: 'solar:dollar-minimalistic-bold-duotone',
    component: () => null,
  },
  {
    key: 'accounts',
    title: 'Default Accounts',
    description: 'GL accounts used by automatic postings',
    icon: 'solar:wallet-money-bold-duotone',
    component: () => null,
  },
  {
    key: 'country',
    title: 'Country Plugin',
    description: 'Country-specific tax and compliance rules',
    icon: 'solar:globus-bold-duotone',
    component: () => null,
  },
  {
    key: 'chart-of-accounts',
    title: 'Chart of Accounts',
    description: 'Create and organise the account tree',
    icon: 'solar:list-check-bold-duotone',
    component: () => null,
  },
  {
    key: 'multi-currency',
    title: 'Multi-Currency',
    description: 'Exchange rates and foreign-currency setup',
    icon: 'solar:refresh-circle-bold-duotone',
    component: () => null,
  },
  {
    key: 'entities',
    title: 'Entities',
    description: 'Legal entities and their accounting scope',
    icon: 'solar:buildings-2-bold-duotone',
    component: () => null,
  },
  {
    key: 'analytic-accounts',
    title: 'Analytic Accounts',
    description: 'Cost centres, departments, projects and reporting dimensions',
    icon: 'solar:pie-chart-2-bold-duotone',
    component: () => null,
  },
];

/**
 * Issue #885 (UX3): the Default Accounts screen is per entity. Each row is an
 * account role the posting engine resolves; `role` is the entity-scoped role
 * the back end writes to entity_account_roles (null = tenant-wide only), and
 * `usedBy` tells the operator which documents post to it.
 */
const ACCOUNT_FIELDS: Array<{
  field: string;
  label: string;
  accountType: string;
  usedBy: string;
  role: string | null;
}> = [
  { field: 'accounts_receivable', label: 'Accounts Receivable', accountType: 'asset', usedBy: 'Customer invoices and receipts (AR control)', role: 'AR_CONTROL' },
  { field: 'accounts_payable', label: 'Accounts Payable', accountType: 'liability', usedBy: 'Vendor bills and payments (AP control)', role: 'AP_CONTROL' },
  { field: 'bank_account', label: 'Bank Account', accountType: 'asset', usedBy: 'Payments, refunds and bank transactions', role: 'BANK_MAIN' },
  { field: 'sales_revenue', label: 'Sales Revenue', accountType: 'revenue', usedBy: 'Invoice and credit-note revenue lines', role: null },
  { field: 'shipping_revenue', label: 'Shipping Revenue', accountType: 'revenue', usedBy: 'Shipping charges on invoices', role: null },
  { field: 'sales_tax', label: 'Sales Tax', accountType: 'liability', usedBy: 'Output tax on sales documents', role: 'TAX_OUTPUT' },
  { field: 'purchase_expense', label: 'Purchase Expense', accountType: 'expense', usedBy: 'Default expense on vendor bills', role: null },
  { field: 'purchase_tax', label: 'Purchase Tax', accountType: 'asset', usedBy: 'Input tax on purchases', role: 'TAX_INPUT' },
  { field: 'inventory', label: 'Inventory', accountType: 'asset', usedBy: 'Goods issues, receipts and the COGS credit leg', role: 'INVENTORY' },
  { field: 'cost_of_goods_sold', label: 'Cost of Goods Sold', accountType: 'expense', usedBy: 'COGS debit when goods are issued', role: 'COGS' },
  { field: 'retained_earnings', label: 'Retained Earnings', accountType: 'equity', usedBy: 'Year-end close', role: 'RETAINED_EARNINGS' },
  { field: 'bank_charges', label: 'Bank Charges', accountType: 'expense', usedBy: 'Bank fees on statements and payments', role: null },
];

export default function CompanySettingsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const { toast } = useToast();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState(searchParams.get('tab') || 'company');

  const changeTab = (tab: string) => {
    setActiveTab(tab);
    setSearchParams({ tab }, { replace: true });
  };

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
    bank_account: 'none',
    sales_revenue: 'none',
    shipping_revenue: 'none',
    sales_tax: 'none',
    purchase_expense: 'none',
    purchase_tax: 'none',
    inventory: 'none',
    cost_of_goods_sold: 'none',
    retained_earnings: 'none',
    bank_charges: 'none',
  });

  // #885 (UX3): which legal entity the Default Accounts screen is editing.
  // 'all' reads/writes the tenant-wide mappings.
  const [selectedEntityId, setSelectedEntityId] = useState<string>('all');

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

  // Fetch currency settings. Distinct key from MultiCurrencyPage's
  // ['accounting-currencies'] — both used ['currencies'] with different
  // endpoints, and because MultiCurrencyPage is embedded here the two
  // collided in the React Query cache and the currency list rendered empty.
  const { data: currenciesResponse } = useQuery({
    queryKey: ['currency-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings/currencies');
      return response.data;
    },
  });

  const availableCurrencies = currenciesResponse?.available_currencies || [];

  // #885 (UX3): legal entities power the Default Accounts entity selector.
  const { data: legalEntities = [] } = useQuery({
    queryKey: ['legal-entities-for-settings'],
    enabled: activeTab === 'accounts',
    queryFn: async () => {
      const response = await api.get('/accounting/legal-entities');
      return Array.isArray(response.data) ? response.data : [];
    },
  });

  // Fetch default accounts for the selected entity — the full payload carries
  // the values posting resolves, the source scope, the unmapped roles and the
  // shared #775 health check.
  const { data: defaultAccountsPayload } = useQuery({
    queryKey: ['default-accounts', selectedEntityId],
    enabled: activeTab === 'accounts',
    queryFn: async () => {
      const response = await api.get('/accounting/settings/accounts', {
        params: selectedEntityId !== 'all' ? { entity_id: selectedEntityId } : undefined,
      });
      return response.data;
    },
  });

  const defaultAccounts = defaultAccountsPayload?.data as Record<string, number | null> | undefined;

  // Default to an entity once the list loads — the screen is per entity.
  useEffect(() => {
    if (activeTab !== 'accounts' || selectedEntityId !== 'all' || legalEntities.length === 0) {
      return;
    }
    const firstActive = legalEntities.find((entity: any) => (entity.status ?? 'active') === 'active');
    if (firstActive) {
      setSelectedEntityId(String(firstActive.id));
    }
  }, [activeTab, selectedEntityId, legalEntities]);

  // Fetch chart of accounts for dropdowns
  const { data: accounts = [] } = useQuery({
    queryKey: ['chart-of-accounts'],
    queryFn: () => chartOfAccountsApi.getAccounts(),
  });

  const getAccountsByType = (type: string) =>
    accounts.filter((account: any) => account.type === type);

  // #885 (UX3): which values on screen come from the entity's own assignment
  // ("entity") and which are inherited from the tenant-wide mapping.
  const accountScope = (defaultAccountsPayload?.scope ?? {}) as Record<string, 'entity' | 'default'>;
  const unmappedFields = (defaultAccountsPayload?.unmapped ?? []) as string[];
  const selectedEntityName: string | undefined = legalEntities.find(
    (entity: any) => String(entity.id) === selectedEntityId,
  )?.name;
  const healthSummary = defaultAccountsPayload?.health?.summary as
    | { unmapped?: number; wrong_type?: number; inactive_account?: number }
    | undefined;
  const healthIssues =
    (healthSummary?.unmapped ?? 0) +
    (healthSummary?.wrong_type ?? 0) +
    (healthSummary?.inactive_account ?? 0);
  const accountFieldLabel = (field: string) =>
    ACCOUNT_FIELDS.find((definition) => definition.field === field)?.label ?? field;

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
      const next: Record<string, string> = {};
      for (const { field } of ACCOUNT_FIELDS) {
        next[field] = defaultAccounts[field]?.toString() || 'none';
      }
      setAccountsForm(next);
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
      queryClient.invalidateQueries({ queryKey: ['currency-settings'] });
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
    const data: Record<string, number | null> = {};
    for (const { field } of ACCOUNT_FIELDS) {
      data[field] = parseAccount(accountsForm[field]);
    }
    if (selectedEntityId !== 'all') {
      data.entity_id = parseInt(selectedEntityId);
    }
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
          <h1 className="text-3xl font-bold tracking-tight">Company Settings</h1>
          <p className="mt-1 text-muted-foreground">
            Company profile, fiscal year, currencies, default accounts and accounting structure.
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-linear" onClick={() => navigate('/accounting')}>
          Back to Accounting
        </HeaderActionButton>
      </div>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <SettingsSectionNav
          sections={
            isAcmeTenant
              ? COMPANY_SECTIONS.filter((section) => section.key !== 'country')
              : COMPANY_SECTIONS
          }
          activeKey={activeTab}
          onSelect={changeTab}
        />

        <div className="min-w-0 flex-1 space-y-4">

        {activeTab === 'company' && (
          <Card className="overflow-hidden">
            <SettingsSectionHeader
              icon="solar:buildings-bold-duotone"
              title="Company Information"
              description="These details appear on invoices, quotations, delivery orders and other documents."
            />
            <CardContent className="px-6 pb-6 pt-6">
              <form id="company-info-form" onSubmit={handleCompanySubmit} className="space-y-4">
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
              </form>
            </CardContent>
            <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
              <Button
                type="submit"
                form="company-info-form"
                size="sm"
                disabled={updateCompanyMutation.isPending}
              >
                {updateCompanyMutation.isPending ? (
                  <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                ) : (
                  <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Save Settings
              </Button>
            </div>
          </Card>
        )}

        {activeTab === 'fiscal' && (
          <Card className="overflow-hidden">
            <SettingsSectionHeader
              icon="solar:calendar-bold-duotone"
              title="Fiscal Year"
              description="Set the financial year window for reports and the date before which transactions are locked."
            />
            <CardContent className="px-6 pb-6 pt-6">
              <form id="fiscal-year-form" onSubmit={handleFiscalYearSubmit} className="space-y-4">
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
              </form>
            </CardContent>
            <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
              <Button
                type="submit"
                form="fiscal-year-form"
                size="sm"
                disabled={updateFiscalYearMutation.isPending}
              >
                {updateFiscalYearMutation.isPending ? (
                  <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                ) : (
                  <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Save Fiscal Year
              </Button>
            </div>
          </Card>
        )}

        {activeTab === 'currency' && (
          <Card className="overflow-hidden">
            <SettingsSectionHeader
              icon="solar:dollar-minimalistic-bold-duotone"
              title="Currency & Formats"
              description="The base currency amounts are recorded in, and how dates and numbers are displayed."
            />
            <CardContent className="px-6 pb-6 pt-6">
              <form id="currency-form" onSubmit={handleCurrencySubmit} className="space-y-4">
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
              </form>
            </CardContent>
            <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
              <Button
                type="submit"
                form="currency-form"
                size="sm"
                disabled={updateCurrenciesMutation.isPending}
              >
                {updateCurrenciesMutation.isPending ? (
                  <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                ) : (
                  <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Save Currency Settings
              </Button>
            </div>
          </Card>
        )}

        {activeTab === 'accounts' && (
          <Card className="overflow-hidden">
            <SettingsSectionHeader
              icon="solar:wallet-money-bold-duotone"
              title="Default Accounts"
              description="Accounts selected automatically when creating transactions and running automatic postings."
            />
            <CardContent className="px-6 pb-6 pt-6">
              <div className="mb-5 space-y-2 sm:max-w-sm">
                <Label htmlFor="default-accounts-entity">Legal Entity</Label>
                <Select value={selectedEntityId} onValueChange={setSelectedEntityId}>
                  <SelectTrigger id="default-accounts-entity">
                    <SelectValue placeholder="Select entity" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All entities (tenant-wide defaults)</SelectItem>
                    {legalEntities.map((entity: any) => (
                      <SelectItem key={entity.id} value={String(entity.id)}>
                        {entity.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  {selectedEntityId === 'all'
                    ? 'Tenant-wide defaults apply to every entity without its own assignment.'
                    : `Values marked "This entity only" apply only to ${selectedEntityName ?? 'the selected entity'}; the rest inherit the tenant-wide defaults.`}
                </p>
              </div>

              {unmappedFields.length > 0 && (
                <Alert variant="destructive" className="mb-4">
                  <Icon icon="solar:danger-triangle-bold-duotone" className="h-4 w-4" />
                  <AlertTitle>
                    {unmappedFields.length} role{unmappedFields.length === 1 ? '' : 's'}{' '}
                    {unmappedFields.length === 1 ? 'has' : 'have'} no account for{' '}
                    {selectedEntityId === 'all' ? 'this tenant' : (selectedEntityName ?? 'this entity')}
                  </AlertTitle>
                  <AlertDescription>
                    {unmappedFields.map(accountFieldLabel).join(', ')} — postings that need these accounts fail until one is chosen.
                  </AlertDescription>
                </Alert>
              )}

              {healthIssues > 0 && (
                <Alert className="mb-4">
                  <Icon icon="solar:shield-warning-bold-duotone" className="h-4 w-4" />
                  <AlertTitle>Account mapping health needs attention</AlertTitle>
                  <AlertDescription>
                    {healthSummary?.unmapped ?? 0} unmapped, {healthSummary?.wrong_type ?? 0} mapped to the wrong account type, {healthSummary?.inactive_account ?? 0} pointing at an inactive account.
                  </AlertDescription>
                </Alert>
              )}

              <form id="default-accounts-form" onSubmit={handleAccountsSubmit}>
                <div className="overflow-x-auto rounded-md border">
                  <table className="w-full text-sm">
                    <thead className="bg-muted/40 text-left text-xs uppercase text-muted-foreground">
                      <tr>
                        <th className="px-4 py-3 font-medium">Account Role</th>
                        <th className="px-4 py-3 font-medium">Used by</th>
                        <th className="px-4 py-3 font-medium">Account</th>
                        <th className="px-4 py-3 font-medium">Scope</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {ACCOUNT_FIELDS.map(({ field, label, accountType, usedBy, role }) => (
                        <tr key={field}>
                          <td className="px-4 py-3 align-top">
                            <div className="font-medium">{label}</div>
                            {role && <div className="text-[11px] text-muted-foreground">{role}</div>}
                          </td>
                          <td className="px-4 py-3 align-top text-muted-foreground">{usedBy}</td>
                          <td className="px-4 py-3 align-top">
                            <Select value={accountsForm[field] ?? 'none'} onValueChange={(v) => setAccountsForm(prev => ({ ...prev, [field]: v }))}>
                              <SelectTrigger className="w-[220px]">
                                <SelectValue placeholder="Select account" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="none">None</SelectItem>
                                {getAccountsByType(accountType).map((acc: any) => (
                                  <SelectItem key={acc.id} value={acc.id.toString()}>
                                    {acc.code} - {acc.name}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </td>
                          <td className="px-4 py-3 align-top">
                            {accountScope[field] === 'entity' ? (
                              <Badge>This entity only</Badge>
                            ) : (
                              <Badge variant="outline">All entities</Badge>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </form>
            </CardContent>
            <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
              <Button
                type="submit"
                form="default-accounts-form"
                size="sm"
                disabled={updateAccountsMutation.isPending}
              >
                {updateAccountsMutation.isPending ? (
                  <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                ) : (
                  <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                )}
                Save Default Accounts
              </Button>
            </div>
          </Card>
        )}

        {/* Country Plugin — suppressed for Acme (managed elsewhere). */}
        {!isAcmeTenant && activeTab === 'country' && <CountryPluginSettings />}

        {activeTab === 'chart-of-accounts' && <ChartOfAccountsPage embedded />}

        {activeTab === 'multi-currency' && <MultiCurrencyPage embedded />}

        {/* Kept reachable via the /accounting/core/onboarding deep link. */}
        {activeTab === 'onboarding' && <AccountingOnboardingPage />}

        {activeTab === 'entities' && <AccountingEntityManagementPage embedded />}

        {activeTab === 'analytic-accounts' && <AnalyticAccountsPage embedded />}
        </div>
      </div>
    </div>
  );
}
