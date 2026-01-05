import { GeneralSettings } from './Settings/GeneralSettings'

// ... existing imports ...
import { useEffect, useMemo, useState } from 'react'
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
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import { useSettingsStore, type PricingMode } from '@/store/settingsStore'

type TaxType = 'gst' | 'vat' | 'sales' | 'service' | 'withholding' | 'other'

interface Tax {
  id: number
  name: string
  code?: string | null
  type: TaxType
  rate: number
  is_active: boolean
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

const taxTypeOptions: Array<{ value: TaxType; label: string }> = [
  { value: 'gst', label: 'GST' },
  { value: 'vat', label: 'VAT' },
  { value: 'sales', label: 'Sales Tax' },
  { value: 'service', label: 'Service Tax' },
  { value: 'withholding', label: 'Withholding Tax' },
  { value: 'other', label: 'Other' },
]

export function SettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const currencyCode = useSettingsStore((s) => s.currencyCode)
  const taxConfig = useSettingsStore((s) => s.tax)
  const updateSettings = useSettingsStore((s) => s.updateSettings)

  const [selectedCurrency, setSelectedCurrency] = useState(currencyCode)
  const [pricingMode, setPricingMode] = useState<PricingMode>(taxConfig.pricing_mode)
  const [defaultTaxId, setDefaultTaxId] = useState<string>(taxConfig.default_tax_id ? String(taxConfig.default_tax_id) : 'none')

  useEffect(() => {
    setSelectedCurrency(currencyCode)
  }, [currencyCode])

  useEffect(() => {
    setPricingMode(taxConfig.pricing_mode)
    setDefaultTaxId(taxConfig.default_tax_id ? String(taxConfig.default_tax_id) : 'none')
  }, [taxConfig.default_tax_id, taxConfig.pricing_mode])

  const { data: taxes = [] } = useQuery<Tax[]>({
    queryKey: ['taxes'],
    queryFn: async () => {
      const res = await api.get('/taxes')
      return Array.isArray(res.data) ? res.data : (res.data?.data || [])
    },
  })

  const taxesById = useMemo(() => {
    const map = new Map<number, Tax>()
    for (const t of taxes) map.set(t.id, t)
    return map
  }, [taxes])

  const saveGeneral = useMutation({
    mutationFn: async () => {
      await updateSettings({ currencyCode: selectedCurrency })
    },
    onSuccess: () => toast({ title: 'Saved', description: 'Currency updated' }),
    onError: () => toast({ title: 'Failed', variant: 'destructive', description: 'Could not update currency' }),
  })

  const saveTaxSettings = useMutation({
    mutationFn: async () => {
      await updateSettings({
        tax: {
          pricing_mode: pricingMode,
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
  })

  const upsertTax = useMutation({
    mutationFn: async () => {
      const payload = {
        name: taxForm.name,
        code: taxForm.code || null,
        type: taxForm.type,
        rate: Number(taxForm.rate),
        is_active: taxForm.is_active,
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
      setTaxForm({ name: '', code: '', type: 'gst', rate: '0', is_active: true })
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
    setTaxForm({ name: '', code: '', type: 'gst', rate: '0', is_active: true })
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

      <Tabs defaultValue="general">
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="tax">Tax & Currency</TabsTrigger>
        </TabsList>

        <TabsContent value="general">
          <GeneralSettings />
        </TabsContent>

        <TabsContent value="tax">
          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Currency</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <div className="text-sm text-muted-foreground">Default currency used across POS and reports.</div>
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
                </div>

                <div>
                  <Button onClick={() => saveGeneral.mutate()} disabled={saveGeneral.isPending}>
                    Save Currency
                  </Button>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Tax Settings</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <div className="text-sm font-medium">Pricing Mode</div>
                  <Select value={pricingMode} onValueChange={(v) => setPricingMode(v as PricingMode)}>
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="exclusive">Tax exclusive (add tax on checkout)</SelectItem>
                      <SelectItem value="inclusive">Tax inclusive (prices already include tax)</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <div className="text-sm font-medium">Default Tax</div>
                  <Select value={defaultTaxId} onValueChange={setDefaultTaxId}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Select default tax" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No default</SelectItem>
                      {taxes.map((t) => (
                        <SelectItem key={t.id} value={String(t.id)}>
                          {t.name} ({t.rate}%)
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {selectedDefaultTax && (
                    <div className="text-xs text-muted-foreground">
                      Default: {selectedDefaultTax.name} ({selectedDefaultTax.type.toUpperCase()} {selectedDefaultTax.rate}%)
                    </div>
                  )}
                </div>

                <div>
                  <Button onClick={() => saveTaxSettings.mutate()} disabled={saveTaxSettings.isPending}>
                    Save Tax Settings
                  </Button>
                </div>

                <div className="text-xs text-muted-foreground">
                  Product-level options: use default tax, no tax, custom rate, or select a specific tax.
                </div>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <CardTitle>Taxes</CardTitle>
                  <Button onClick={openCreateTax}>Add Tax</Button>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Rate</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {taxes.map((t) => (
                      <TableRow key={t.id}>
                        <TableCell className="font-medium">{t.name}</TableCell>
                        <TableCell>{t.type.toUpperCase()}</TableCell>
                        <TableCell>{t.rate}%</TableCell>
                        <TableCell>
                          <Badge variant={t.is_active ? 'secondary' : 'outline'}>
                            {t.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex justify-end gap-2">
                            <Button variant="outline" size="sm" onClick={() => openEditTax(t)}>
                              Edit
                            </Button>
                            <Button
                              variant="destructive"
                              size="sm"
                              onClick={() => deleteTax.mutate(t.id)}
                              disabled={deleteTax.isPending}
                            >
                              Delete
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                    {taxes.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={5} className="text-center text-muted-foreground py-10">
                          No taxes created yet
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
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
