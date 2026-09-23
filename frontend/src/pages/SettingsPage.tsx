import { GeneralSettings } from './Settings/GeneralSettings'
import { PaymentGatewaySettings } from './Settings/PaymentGatewaySettings'
import { FulfillmentSettings } from './Settings/FulfillmentSettings'
import { MailSettings } from './Settings/MailSettings'
import { WhatsAppSettings } from './Settings/WhatsAppSettings'
import { ModulesPage } from './Modules/ModulesPage'
import TenantUsersPage from './TenantUsersPage'
import { useAuthStore } from '@/store/authStore'

// ... existing imports ...
import { useEffect, useMemo, useState } from 'react'
import { Icon } from '@iconify/react'
import { useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { useSettingsStore, type CurrencyDisplayFormat } from '@/store/settingsStore'
import {
  SettingsSectionHeader,
  SettingsSectionNav,
} from '@/components/settings/settings-ui'

type TaxType = 'gst' | 'vat' | 'sales' | 'service' | 'withholding' | 'other'

interface Tax {
  id: number
  name: string
  code?: string | null
  type: TaxType
  rate: number
  is_active: boolean
  is_inclusive: boolean
}

const currencyOptions = [
  { code: 'USD', label: 'USD — US Dollar' },
  { code: 'SGD', label: 'SGD — Singapore Dollar' },
  { code: 'AED', label: 'AED — UAE Dirham' },
  { code: 'SAR', label: 'SAR — Saudi Riyal' },
  { code: 'QAR', label: 'QAR — Qatari Riyal' },
  { code: 'KWD', label: 'KWD — Kuwaiti Dinar' },
  { code: 'BHD', label: 'BHD — Bahraini Dinar' },
  { code: 'OMR', label: 'OMR — Omani Rial' },
]

function currencyDisplayPreview(code: string, format: CurrencyDisplayFormat): string {
  if (code === 'SGD') {
    if (format === 'narrow_symbol') return '$1,234.56'
    if (format === 'code') return 'SGD 1,234.56'
    return 'S$1,234.56'
  }

  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: code,
    currencyDisplay: format === 'narrow_symbol' ? 'narrowSymbol' : format,
  }).format(1234.56)
}

const taxTypeOptions: Array<{ value: TaxType; label: string }> = [
  { value: 'gst', label: 'GST' },
  { value: 'vat', label: 'VAT' },
  { value: 'sales', label: 'Sales Tax' },
  { value: 'service', label: 'Service Tax' },
  { value: 'withholding', label: 'Withholding Tax' },
  { value: 'other', label: 'Other' },
]

const TAX_SECTIONS = [
  {
    key: 'currency',
    title: 'Currency',
    description: 'Default currency and display format',
    icon: 'solar:dollar-minimalistic-bold-duotone',
    component: () => null,
  },
  {
    key: 'settings',
    title: 'Tax Settings',
    description: 'Enable tax and choose the default rate',
    icon: 'solar:sale-bold-duotone',
    component: () => null,
  },
  {
    key: 'rates',
    title: 'Tax Rates',
    description: 'Manage every available tax rate',
    icon: 'solar:list-check-bold-duotone',
    component: () => null,
  },
]

export function SettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const tenantId = useAuthStore((state) => state.tenantId)
  const isAcmeTenant =
    tenantId === '00000000-0000-0000-0000-000000000001' ||
    tenantId === '00000000-0000-0000-0000-000000000002'

  const availableTabs = new Set([
    'general',
    'tax',
    'mail',
    'team',
    'modules',
  ])
  if (!isAcmeTenant) {
    availableTabs.add('inventory')
    availableTabs.add('payment-gateways')
    availableTabs.add('fulfillment')
    availableTabs.add('whatsapp')
  }
  const requestedTab = searchParams.get('tab') || 'general'
  const activeTab = availableTabs.has(requestedTab) ? requestedTab : 'general'

  const changeTab = (tab: string) => {
    const next = new URLSearchParams(searchParams)
    if (tab === 'general') {
      next.delete('tab')
    } else {
      next.set('tab', tab)
    }
    setSearchParams(next, { replace: true })
  }

  const currencyCode = useSettingsStore((s) => s.currencyCode)
  const currencyDisplayFormat = useSettingsStore((s) => s.currencyDisplayFormat)
  const taxConfig = useSettingsStore((s) => s.tax)
  const updateSettings = useSettingsStore((s) => s.updateSettings)
  const loadSettings = useSettingsStore((s) => s.loadSettings)
  const settingsLoaded = useSettingsStore((s) => s.isLoaded)

  const [taxSection, setTaxSection] = useState('currency')
  const [selectedCurrency, setSelectedCurrency] = useState(currencyCode)
  const [selectedCurrencyDisplay, setSelectedCurrencyDisplay] = useState<CurrencyDisplayFormat>(currencyDisplayFormat)
  const [taxEnabled, setTaxEnabled] = useState<boolean>(taxConfig.enabled)
  const [defaultTaxId, setDefaultTaxId] = useState<string>(taxConfig.default_tax_id ? String(taxConfig.default_tax_id) : 'none')

  // Hydrate from the server once so every tab (currency, tax, mail) shows
  // what is actually stored instead of the persisted localStorage snapshot.
  useEffect(() => {
    if (!settingsLoaded) {
      loadSettings()
    }
  }, [settingsLoaded, loadSettings])

  useEffect(() => {
    setSelectedCurrency(currencyCode)
    setSelectedCurrencyDisplay(currencyDisplayFormat)
  }, [currencyCode, currencyDisplayFormat])

  useEffect(() => {
    setTaxEnabled(taxConfig.enabled)
    setDefaultTaxId(taxConfig.default_tax_id ? String(taxConfig.default_tax_id) : 'none')
  }, [taxConfig.default_tax_id, taxConfig.enabled])

  const { data: taxes = [] } = useQuery<Tax[]>({
    queryKey: ['taxes'],
    queryFn: async () => {
      const res = await api.get('/taxes')
      return Array.isArray(res.data) ? res.data : (res.data?.data || [])
    },
    retry: false,
  })

  const taxesById = useMemo(() => {
    const map = new Map<number, Tax>()
    for (const t of taxes) map.set(t.id, t)
    return map
  }, [taxes])

  const saveGeneral = useMutation({
    mutationFn: async () => {
      await updateSettings({
        currencyCode: selectedCurrency,
        currencyDisplayFormat: selectedCurrencyDisplay,
      })
    },
    onSuccess: () => toast({ title: 'Saved', description: 'Company currency preferences updated' }),
    onError: () => toast({ title: 'Failed', variant: 'destructive', description: 'Could not update currency' }),
  })

  const saveTaxSettings = useMutation({
    mutationFn: async () => {
      await updateSettings({
        tax: {
          enabled: taxEnabled,
          default_tax_id: defaultTaxId === 'none' ? null : Number(defaultTaxId),
        },
      })
    },
    onSuccess: () => toast({ title: 'Saved', description: 'Tax settings updated' }),
    onError: () => toast({ title: 'Failed', variant: 'destructive', description: 'Could not update tax settings' }),
  })

  const [taxDialogOpen, setTaxDialogOpen] = useState(false)
  const [editingTax, setEditingTax] = useState<Tax | null>(null)
  const [taxForm, setTaxForm] = useState({
    name: '',
    code: '',
    type: 'gst' as TaxType,
    rate: '0',
    is_active: true,
    is_inclusive: false,
  })

  const upsertTax = useMutation({
    mutationFn: async () => {
      const payload = {
        name: taxForm.name,
        code: taxForm.code || null,
        type: taxForm.type,
        rate: Number(taxForm.rate),
        is_active: taxForm.is_active,
        is_inclusive: taxForm.is_inclusive,
      }

      if (editingTax) {
        const res = await api.put(`/taxes/${editingTax.id}`, payload)
        return res.data
      }

      const res = await api.post('/taxes', payload)
      return res.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['taxes'] })
      setTaxDialogOpen(false)
      setEditingTax(null)
      setTaxForm({ name: '', code: '', type: 'gst', rate: '0', is_active: true, is_inclusive: false })
      toast({ title: 'Saved', description: 'Tax saved' })
    },
    onError: () => toast({ title: 'Failed', variant: 'destructive', description: 'Could not save tax' }),
  })

  const deleteTax = useMutation({
    mutationFn: async (taxId: number) => {
      await api.delete(`/taxes/${taxId}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['taxes'] })
      toast({ title: 'Deleted', description: 'Tax removed' })
    },
    onError: () => toast({ title: 'Failed', variant: 'destructive', description: 'Could not delete tax' }),
  })

  const openCreateTax = () => {
    setEditingTax(null)
    setTaxForm({ name: '', code: '', type: 'gst', rate: '0', is_active: true, is_inclusive: false })
    setTaxDialogOpen(true)
  }

  const openEditTax = (tax: Tax) => {
    setEditingTax(tax)
    setTaxForm({
      name: tax.name,
      code: tax.code || '',
      type: tax.type,
      rate: String(tax.rate),
      is_active: tax.is_active,
      is_inclusive: tax.is_inclusive ?? false,
    })
    setTaxDialogOpen(true)
  }

  const selectedDefaultTax = useMemo(() => {
    if (defaultTaxId === 'none') return null
    const id = Number(defaultTaxId)
    return taxesById.get(id) || null
  }, [defaultTaxId, taxesById])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-3xl font-bold">Settings</h1>
      </div>

      <Tabs value={activeTab} onValueChange={changeTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="tax">Tax & Currency</TabsTrigger>
          {!isAcmeTenant && <TabsTrigger value="payment-gateways">Payment Gateways</TabsTrigger>}
          {!isAcmeTenant && <TabsTrigger value="fulfillment">Fulfillment</TabsTrigger>}
          <TabsTrigger value="mail">Email Configuration</TabsTrigger>
          {!isAcmeTenant && <TabsTrigger value="whatsapp">WhatsApp</TabsTrigger>}
          <TabsTrigger value="team">Team</TabsTrigger>
          <TabsTrigger value="modules">Modules</TabsTrigger>
        </TabsList>

        <TabsContent value="general">
          <GeneralSettings />
        </TabsContent>

        <TabsContent value="mail">
          <MailSettings />
        </TabsContent>

        <TabsContent value="whatsapp">
          <WhatsAppSettings />
        </TabsContent>

        <TabsContent value="payment-gateways">
          <PaymentGatewaySettings />
        </TabsContent>

        <TabsContent value="fulfillment">
          <FulfillmentSettings />
        </TabsContent>

        <TabsContent value="team">
          <TenantUsersPage />
        </TabsContent>

        <TabsContent value="modules">
          <ModulesPage />
        </TabsContent>

        <TabsContent value="tax">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
            <SettingsSectionNav
              sections={TAX_SECTIONS}
              activeKey={taxSection}
              onSelect={setTaxSection}
            />

            <div className="min-w-0 flex-1">
              {taxSection === 'currency' && (
                <Card className="overflow-hidden">
                  <SettingsSectionHeader
                    icon="solar:dollar-minimalistic-bold-duotone"
                    title="Currency"
                    description="The default currency and how amounts are displayed across the company."
                  />
                  <CardContent className="space-y-5 py-6">
                    <div className="space-y-2">
                      <Label className="text-sm font-medium">Default currency</Label>
                      <Select value={selectedCurrency} onValueChange={setSelectedCurrency}>
                        <SelectTrigger className="w-full max-w-md">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {currencyOptions.map((c) => (
                            <SelectItem key={c.code} value={c.code}>
                              {c.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <p className="text-xs text-muted-foreground">
                        Used for new documents, reports and the general ledger.
                      </p>
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="currency-display-format" className="text-sm font-medium">
                        Company-wide display format
                      </Label>
                      <Select
                        value={selectedCurrencyDisplay}
                        onValueChange={(value) => setSelectedCurrencyDisplay(value as CurrencyDisplayFormat)}
                      >
                        <SelectTrigger id="currency-display-format" className="w-full max-w-md">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="narrow_symbol">
                            {currencyDisplayPreview(selectedCurrency, 'narrow_symbol')}
                          </SelectItem>
                          <SelectItem value="symbol">
                            {currencyDisplayPreview(selectedCurrency, 'symbol')}
                          </SelectItem>
                          <SelectItem value="code">
                            {currencyDisplayPreview(selectedCurrency, 'code')}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                      <p className="text-xs text-muted-foreground">
                        This format applies to every user in this company.
                      </p>
                    </div>
                  </CardContent>
                  <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
                    <Button onClick={() => saveGeneral.mutate()} disabled={saveGeneral.isPending} size="sm">
                      {saveGeneral.isPending ? (
                        <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                      ) : (
                        <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                      )}
                      Save currency preferences
                    </Button>
                  </div>
                </Card>
              )}

              {taxSection === 'settings' && (
                <Card className="overflow-hidden">
                  <SettingsSectionHeader
                    icon="solar:sale-bold-duotone"
                    title="Tax Settings"
                    description="Turn tax calculation on and pick the rate applied when a product has no specific tax."
                  />
                  <CardContent className="divide-y py-0">
                    <div className="flex items-start justify-between gap-8 py-4">
                      <div className="flex-1 space-y-1">
                        <div className="text-sm font-medium leading-none">Enable tax</div>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                          Apply tax calculations to POS transactions and sales documents.
                        </p>
                      </div>
                      <Switch id="tax-enabled" checked={taxEnabled} onCheckedChange={setTaxEnabled} />
                    </div>

                    <div className="space-y-2 py-4">
                      <div className="text-sm font-medium leading-none">Default tax rate</div>
                      <p className="text-sm leading-relaxed text-muted-foreground">
                        Applied to products without a specific tax rate.
                      </p>
                      <Select value={defaultTaxId} onValueChange={setDefaultTaxId} disabled={!taxEnabled}>
                        <SelectTrigger className="mt-1 w-full max-w-md">
                          <SelectValue placeholder="Select default tax" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="none">No default tax</SelectItem>
                          {taxes.map((t) => (
                            <SelectItem key={t.id} value={String(t.id)}>
                              {t.name} ({t.rate}%)
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {selectedDefaultTax && (
                        <div className="flex items-center gap-2 pt-1 text-xs text-muted-foreground">
                          <Icon icon="solar:check-circle-bold" className="h-3.5 w-3.5 text-emerald-600" />
                          Default: <span className="font-medium text-foreground">{selectedDefaultTax.name}</span>
                          <Badge variant="outline" className="text-[10px]">
                            {selectedDefaultTax.type.toUpperCase()} {selectedDefaultTax.rate}%
                          </Badge>
                        </div>
                      )}
                    </div>
                  </CardContent>
                  <div className="flex items-center justify-end border-t bg-muted/20 px-6 py-3">
                    <Button onClick={() => saveTaxSettings.mutate()} disabled={saveTaxSettings.isPending} size="sm">
                      {saveTaxSettings.isPending ? (
                        <Icon icon="eos-icons:loading" className="mr-2 h-4 w-4" />
                      ) : (
                        <Icon icon="solar:diskette-bold-duotone" className="mr-2 h-4 w-4" />
                      )}
                      Save tax settings
                    </Button>
                  </div>
                </Card>
              )}

              {taxSection === 'rates' && (
                <Card className="overflow-hidden">
                  <SettingsSectionHeader
                    icon="solar:list-check-bold-duotone"
                    title="Tax Rates"
                    description="Every rate available on products, services and POS transactions."
                  />
                  <CardContent className="p-0">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Name</TableHead>
                          <TableHead>Type</TableHead>
                          <TableHead className="text-right">Rate</TableHead>
                          <TableHead>Mode</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead className="text-right">Actions</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {taxes.map((t) => (
                          <TableRow key={t.id}>
                            <TableCell className="font-medium">
                              <div className="flex items-center gap-2">
                                {t.name}
                                {selectedDefaultTax?.id === t.id && (
                                  <Badge variant="secondary" className="text-[10px]">
                                    Default
                                  </Badge>
                                )}
                              </div>
                            </TableCell>
                            <TableCell className="text-muted-foreground">{t.type.toUpperCase()}</TableCell>
                            <TableCell className="text-right font-mono tabular-nums">{t.rate}%</TableCell>
                            <TableCell>
                              <Badge variant={t.is_inclusive ? 'default' : 'outline'} className="text-[10px]">
                                {t.is_inclusive ? 'Inclusive' : 'Exclusive'}
                              </Badge>
                            </TableCell>
                            <TableCell>
                              <Badge variant={t.is_active ? 'secondary' : 'outline'} className="text-[10px]">
                                {t.is_active ? 'Active' : 'Inactive'}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              <div className="flex justify-end gap-1">
                                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => openEditTax(t)}>
                                  <Icon icon="solar:pen-bold-duotone" className="h-4 w-4" />
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="icon"
                                  className="h-8 w-8 text-destructive hover:text-destructive"
                                  onClick={() => deleteTax.mutate(t.id)}
                                  disabled={deleteTax.isPending}
                                >
                                  <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                                </Button>
                              </div>
                            </TableCell>
                          </TableRow>
                        ))}
                        {taxes.length === 0 && (
                          <TableRow>
                            <TableCell colSpan={6} className="py-12 text-center">
                              <div className="flex flex-col items-center gap-2 text-muted-foreground">
                                <Icon icon="solar:list-check-bold-duotone" className="h-10 w-10 opacity-40" />
                                <p className="text-sm font-medium">No tax rates yet</p>
                                <p className="text-xs">Add your first rate to start applying tax.</p>
                              </div>
                            </TableCell>
                          </TableRow>
                        )}
                      </TableBody>
                    </Table>
                  </CardContent>
                  <div className="flex items-center justify-between border-t bg-muted/20 px-6 py-3">
                    <span className="text-xs text-muted-foreground">
                      {taxes.length} rate{taxes.length === 1 ? '' : 's'} configured
                    </span>
                    <Button size="sm" onClick={openCreateTax}>
                      <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
                      Add Tax
                    </Button>
                  </div>
                </Card>
              )}
            </div>
          </div>
        </TabsContent>
      </Tabs>

      <Dialog open={taxDialogOpen} onOpenChange={setTaxDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingTax ? 'Edit Tax' : 'Add Tax'}</DialogTitle>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <div className="text-sm font-medium">Name</div>
              <Input value={taxForm.name} onChange={(e) => setTaxForm({ ...taxForm, name: e.target.value })} />
            </div>

            <div className="space-y-2">
              <div className="text-sm font-medium">Code (optional)</div>
              <Input value={taxForm.code} onChange={(e) => setTaxForm({ ...taxForm, code: e.target.value })} />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <div className="text-sm font-medium">Type</div>
                <Select value={taxForm.type} onValueChange={(v) => setTaxForm({ ...taxForm, type: v as TaxType })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {taxTypeOptions.map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <div className="text-sm font-medium">Rate (%)</div>
                <Input
                  type="number"
                  step="0.01"
                  value={taxForm.rate}
                  onChange={(e) => setTaxForm({ ...taxForm, rate: e.target.value })}
                />
              </div>
            </div>

            <div className="space-y-2">
              <div className="text-sm font-medium">Active</div>
              <Select
                value={taxForm.is_active ? 'yes' : 'no'}
                onValueChange={(v) => setTaxForm({ ...taxForm, is_active: v === 'yes' })}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="yes">Yes</SelectItem>
                  <SelectItem value="no">No</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <div className="text-sm font-medium">Pricing Mode</div>
              <Select
                value={taxForm.is_inclusive ? 'inclusive' : 'exclusive'}
                onValueChange={(v) => setTaxForm({ ...taxForm, is_inclusive: v === 'inclusive' })}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="exclusive">Tax Exclusive (added to price)</SelectItem>
                  <SelectItem value="inclusive">Tax Inclusive (included in price)</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                {taxForm.is_inclusive
                  ? 'Product prices already include this tax'
                  : 'This tax will be added on top of product prices'}
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setTaxDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => upsertTax.mutate()} disabled={upsertTax.isPending || !taxForm.name}>
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
