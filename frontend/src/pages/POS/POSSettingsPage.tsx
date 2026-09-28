import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import { cn } from '@/lib/utils'
import iotApi, { type DeviceConfiguration } from '@/lib/iotApi'

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface PosSettings {
  // General
  default_warehouse_id: number | null
  order_number_prefix: string
  tax_mode: 'inclusive' | 'exclusive' | 'none'
  rounding_method: 'none' | 'round_up' | 'round_down' | 'round_nearest'
  rounding_precision: number
  allow_negative_stock: boolean
  auto_open_cash_drawer: boolean
  require_customer: boolean
  default_customer_id: number | null
  idle_timeout_minutes: number

  // Receipt
  receipt_header: string
  receipt_footer: string
  receipt_show_logo: boolean
  receipt_logo_url: string
  receipt_show_tax_breakdown: boolean
  receipt_show_cashier: boolean
  receipt_paper_size: '58mm' | '80mm' | 'a4'
  auto_print_receipt: boolean

  // Barcode
  barcode_type: string
  barcode_format: string
  barcode_show_price: boolean
  barcode_show_name: boolean
  barcode_show_sku: boolean
  barcode_bar_width: number
  barcode_bar_height: number

  // Display
  show_product_images: boolean
  products_per_page: number
  default_product_view: 'grid' | 'list'
  enable_customer_display: boolean
  customer_display_message: string
}

interface Terminal {
  id: number
  name: string
  code: string
  warehouse_id: number | null
  receipt_header: string | null
  receipt_footer: string | null
  is_active: boolean
  settings: {
    receipt_print_mode?: 'web' | 'direct'
    printer_agent_id?: string
    receipt_printer_device_id?: string
    label_printer_device_id?: string
    [key: string]: unknown
  } | null
  has_open_session?: boolean
  warehouse?: { id: number; name: string }
}

interface PaymentMethod {
  id: number
  name: string
  code: string
  type: string
  icon: string | null
  opens_cash_drawer: boolean
  requires_approval: boolean
  surcharge_percent: number
  surcharge_fixed: number
  is_active: boolean
  sort_order: number
}

interface Warehouse {
  id: number
  name: string
}

/* ------------------------------------------------------------------ */
/*  Main Component                                                     */
/* ------------------------------------------------------------------ */

export function POSSettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  // ----- Fetch POS settings -----
  const {
    data: settingsData,
    isLoading: settingsLoading,
  } = useQuery({
    queryKey: ['pos-settings'],
    queryFn: async () => {
      const res = await api.get('/pos/settings')
      return (res.data?.settings ?? res.data ?? {}) as PosSettings
    },
    retry: 1,
  })

  // ----- Fetch warehouses -----
  const { data: warehouses, isLoading: warehousesLoading } = useQuery({
    queryKey: ['pos-warehouses'],
    queryFn: async () => {
      try {
        const res = await api.get('/pos/warehouses')
        const raw = res.data?.data ?? res.data?.warehouses ?? res.data
        
        // Ensure we always return an array
        if (!raw) return []
        if (!Array.isArray(raw)) return []
        
        return raw.filter((w: Warehouse) => w && w.id && w.name).map((w: Warehouse) => ({
          id: w.id,
          name: w.name,
        }))
      } catch (e) {
        console.error('Failed to fetch warehouses:', e)
        return []
      }
    },
    retry: 1,
    staleTime: 0,
  })

  // ----- Fetch terminals -----
  const { data: terminalsData, isLoading: terminalsLoading } = useQuery({
    queryKey: ['pos-terminals'],
    queryFn: async () => {
      const res = await api.get('/pos/terminals')
      return (res.data?.terminals ?? res.data ?? []) as Terminal[]
    },
  })

  // ----- Fetch payment methods -----
  const { data: paymentMethodsData, isLoading: pmLoading } = useQuery({
    queryKey: ['pos-payment-methods'],
    queryFn: async () => {
      const res = await api.get('/pos/payment-methods')
      return (res.data?.payment_methods ?? res.data ?? []) as PaymentMethod[]
    },
  })

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">POS Settings</h1>
          <p className="text-sm text-muted-foreground">
            Configure your Point of Sale system — terminals, receipts, payment methods, and display options.
          </p>
        </div>
        <HeaderActionButton icon="solar:arrow-left-bold" variant="secondary" asChild>
          <Link to="/pos">
            Back to POS
          </Link>
        </HeaderActionButton>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="general" className="w-full">
        <TabsList className="w-fit max-w-full">
          <TabsTrigger value="general">
            <Icon icon="solar:settings-bold-duotone" className="mr-1.5 h-4 w-4 hidden md:inline" />
            General
          </TabsTrigger>
          <TabsTrigger value="terminals">
            <Icon icon="solar:monitor-bold-duotone" className="mr-1.5 h-4 w-4 hidden md:inline" />
            Terminals
          </TabsTrigger>
          <TabsTrigger value="payment-methods">
            <Icon icon="solar:card-bold-duotone" className="mr-1.5 h-4 w-4 hidden md:inline" />
            Payment Methods
          </TabsTrigger>
          <TabsTrigger value="receipt">
            <Icon icon="solar:document-text-bold-duotone" className="mr-1.5 h-4 w-4 hidden md:inline" />
            Receipt
          </TabsTrigger>
          <TabsTrigger value="barcode">
            <Icon icon="solar:qr-code-bold-duotone" className="mr-1.5 h-4 w-4 hidden md:inline" />
            Barcode
          </TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="mt-4">
          <GeneralSettingsTab
            settings={settingsData}
            loading={settingsLoading}
            warehouses={warehouses ?? []}
          />
        </TabsContent>

        <TabsContent value="terminals" className="mt-4">
          <TerminalsTab
            terminals={terminalsData ?? []}
            loading={terminalsLoading}
            warehouses={warehouses ?? []}
          />
        </TabsContent>

        <TabsContent value="payment-methods" className="mt-4">
          <PaymentMethodsTab
            methods={paymentMethodsData ?? []}
            loading={pmLoading}
          />
        </TabsContent>

        <TabsContent value="receipt" className="mt-4">
          <ReceiptSettingsTab
            settings={settingsData}
            loading={settingsLoading}
          />
        </TabsContent>

        <TabsContent value="barcode" className="mt-4">
          <BarcodeSettingsTab
            settings={settingsData}
            loading={settingsLoading}
          />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/* ================================================================== */
/*  GENERAL SETTINGS TAB                                               */
/* ================================================================== */

function GeneralSettingsTab({
  settings,
  loading,
  warehouses,
}: {
  settings?: PosSettings
  loading: boolean
  warehouses: Warehouse[]
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<Partial<PosSettings>>({})

  useEffect(() => {
    if (settings) setForm(settings)
  }, [settings])

  const mutation = useMutation({
    mutationFn: async (data: Partial<PosSettings>) => {
      const res = await api.put('/pos/settings', data)
      return res.data.settings
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-settings'] })
      toast({ title: 'Settings saved', description: 'General POS settings updated.' })
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save settings.', variant: 'destructive' })
    },
  })

  const update = (key: keyof PosSettings, value: unknown) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  if (loading) return <SettingsSkeleton />

  return (
    <div className="space-y-6">
      {/* Order Settings */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:bag-4-bold-duotone" className="h-5 w-5 text-primary" />
            Order Settings
          </CardTitle>
          <CardDescription>Configure order numbering and checkout behavior.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 md:grid-cols-2">
          <div className="space-y-2">
            <Label>Order Number Prefix</Label>
            <Input
              value={form.order_number_prefix ?? ''}
              onChange={(e) => update('order_number_prefix', e.target.value)}
              placeholder="POS"
              maxLength={10}
            />
            <p className="text-xs text-muted-foreground">Prefix for POS order numbers (e.g. POS-0001)</p>
          </div>

          <div className="space-y-2">
            <Label>Default Warehouse</Label>
            <Select
              value={form.default_warehouse_id?.toString() ?? ''}
              onValueChange={(v) => update('default_warehouse_id', v ? Number(v) : null)}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select warehouse" />
              </SelectTrigger>
              <SelectContent>
                {(Array.isArray(warehouses) ? warehouses : []).map((w: Warehouse) => (
                  <SelectItem key={w.id} value={w.id.toString()}>
                    {w.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Tax Mode</Label>
            <Select
              value={form.tax_mode ?? 'inclusive'}
              onValueChange={(v) => update('tax_mode', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="inclusive">Tax Inclusive</SelectItem>
                <SelectItem value="exclusive">Tax Exclusive</SelectItem>
                <SelectItem value="none">No Tax</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">How product prices are treated at the POS.</p>
          </div>

          <div className="space-y-2">
            <Label>Rounding Method</Label>
            <Select
              value={form.rounding_method ?? 'none'}
              onValueChange={(v) => update('rounding_method', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">No Rounding</SelectItem>
                <SelectItem value="round_up">Round Up</SelectItem>
                <SelectItem value="round_down">Round Down</SelectItem>
                <SelectItem value="round_nearest">Round to Nearest</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Rounding Precision (Decimal Places)</Label>
            <Input
              type="number"
              min={0}
              max={4}
              value={form.rounding_precision ?? 2}
              onChange={(e) => update('rounding_precision', Number(e.target.value))}
            />
          </div>

          <div className="space-y-2">
            <Label>Idle Timeout (Minutes)</Label>
            <Input
              type="number"
              min={0}
              max={480}
              value={form.idle_timeout_minutes ?? 0}
              onChange={(e) => update('idle_timeout_minutes', Number(e.target.value))}
            />
            <p className="text-xs text-muted-foreground">0 = no timeout. Lock POS after inactivity.</p>
          </div>
        </CardContent>
      </Card>

      {/* Behavior */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:tuning-2-bold-duotone" className="h-5 w-5 text-primary" />
            Behavior
          </CardTitle>
          <CardDescription>Toggle POS features on or off.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <SwitchRow
            label="Allow Negative Stock"
            description="Allow selling items even when stock is zero or negative."
            checked={!!form.allow_negative_stock}
            onCheckedChange={(v) => update('allow_negative_stock', v)}
          />
          <Separator />
          <SwitchRow
            label="Auto-Open Cash Drawer"
            description="Automatically trigger cash drawer on cash payments."
            checked={form.auto_open_cash_drawer !== false}
            onCheckedChange={(v) => update('auto_open_cash_drawer', v)}
          />
          <Separator />
          <SwitchRow
            label="Require Customer"
            description="Force cashier to select a customer before completing a sale."
            checked={!!form.require_customer}
            onCheckedChange={(v) => update('require_customer', v)}
          />
        </CardContent>
      </Card>

      {/* Display */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:monitor-bold-duotone" className="h-5 w-5 text-primary" />
            Display
          </CardTitle>
          <CardDescription>Product listing and customer display settings.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 md:grid-cols-2">
          <SwitchRow
            label="Show Product Images"
            description="Display product thumbnails in the POS grid."
            checked={form.show_product_images !== false}
            onCheckedChange={(v) => update('show_product_images', v)}
          />

          <div className="space-y-2">
            <Label>Products Per Page</Label>
            <Input
              type="number"
              min={10}
              max={100}
              value={form.products_per_page ?? 20}
              onChange={(e) => update('products_per_page', Number(e.target.value))}
            />
          </div>

          <div className="space-y-2">
            <Label>Default Product View</Label>
            <Select
              value={form.default_product_view ?? 'grid'}
              onValueChange={(v) => update('default_product_view', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="grid">Grid</SelectItem>
                <SelectItem value="list">List</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <SwitchRow
            label="Enable Customer Display"
            description="Show a secondary customer-facing screen."
            checked={!!form.enable_customer_display}
            onCheckedChange={(v) => update('enable_customer_display', v)}
          />

          {form.enable_customer_display && (
            <div className="space-y-2 md:col-span-2">
              <Label>Customer Display Welcome Message</Label>
              <Input
                value={form.customer_display_message ?? ''}
                onChange={(e) => update('customer_display_message', e.target.value)}
                placeholder="Welcome!"
                maxLength={200}
              />
            </div>
          )}
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <HeaderActionButton icon="svg-spinners:ring-resize" onClick={() => mutation.mutate(form)} disabled={mutation.isPending}>
          Save General Settings
        </HeaderActionButton>
      </div>
    </div>
  )
}

/* ================================================================== */
/*  TERMINALS TAB                                                      */
/* ================================================================== */

function TerminalsTab({
  terminals,
  loading,
  warehouses,
}: {
  terminals: Terminal[]
  loading: boolean
  warehouses: Warehouse[]
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<Terminal | null>(null)
  const [form, setForm] = useState({
    name: '',
    warehouse_id: '' as string,
    receipt_header: '',
    receipt_footer: '',
    receipt_print_mode: 'web' as 'web' | 'direct',
    printer_agent_id: '',
    receipt_printer_device_id: '',
    label_printer_device_id: '',
    is_active: true,
  })

  const { data: linkedAgent } = useQuery({
    queryKey: ['iot-linked-agent-for-pos-terminal-settings'],
    queryFn: async () => {
      try {
        return await iotApi.getLinkedAgent()
      } catch {
        return { success: false, data: null as { agent_id: string } | null }
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  })

  const linkedAgentId = linkedAgent?.success ? linkedAgent.data?.agent_id ?? null : null

  const { data: printerDevices = [] } = useQuery<DeviceConfiguration[]>({
    queryKey: ['iot-printer-devices-for-pos-terminal-settings', linkedAgentId],
    queryFn: async () => {
      if (!linkedAgentId) return []
      try {
        const res = await iotApi.getDeviceConfigs(linkedAgentId)
        return (res.data || []).filter((device) => {
          const type = device.deviceType || device.device_type
          return type === 'printer' && (device.enabled ?? true)
        })
      } catch {
        return []
      }
    },
    enabled: !!linkedAgentId,
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  })

  const receiptPrinters = printerDevices.filter((device) => {
    const driver = (device.driverType || device.driver_type || '').toLowerCase()
    return driver.includes('receipt') || driver.includes('kpos')
  })

  const labelPrinters = printerDevices.filter((device) => {
    const driver = (device.driverType || device.driver_type || '').toLowerCase()
    return driver.includes('label') || driver.includes('gprinter')
  })

  useEffect(() => {
    if (!linkedAgentId) return
    setForm((prev) => ({
      ...prev,
      printer_agent_id: prev.printer_agent_id || linkedAgentId,
    }))
  }, [linkedAgentId])

  const resetForm = () => {
    setForm({
      name: '',
      warehouse_id: '',
      receipt_header: '',
      receipt_footer: '',
      receipt_print_mode: 'web',
      printer_agent_id: linkedAgentId || '',
      receipt_printer_device_id: '',
      label_printer_device_id: '',
      is_active: true,
    })
    setEditing(null)
  }

  const openCreate = () => {
    resetForm()
    setEditorOpen(true)
  }

  const openEdit = (t: Terminal) => {
    const settings = t.settings || {}
    setEditing(t)
    setForm({
      name: t.name,
      warehouse_id: t.warehouse_id?.toString() ?? '',
      receipt_header: t.receipt_header ?? '',
      receipt_footer: t.receipt_footer ?? '',
      receipt_print_mode: settings.receipt_print_mode === 'direct' ? 'direct' : 'web',
      printer_agent_id: settings.printer_agent_id || linkedAgentId || '',
      receipt_printer_device_id: settings.receipt_printer_device_id || '',
      label_printer_device_id: settings.label_printer_device_id || '',
      is_active: t.is_active,
    })
    setEditorOpen(true)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : null,
        receipt_header: form.receipt_header || null,
        receipt_footer: form.receipt_footer || null,
        settings: {
          receipt_print_mode: form.receipt_print_mode,
          printer_agent_id: form.printer_agent_id || null,
          receipt_printer_device_id: form.receipt_printer_device_id || null,
          label_printer_device_id: form.label_printer_device_id || null,
        },
        is_active: form.is_active,
      }
      if (editing) {
        return api.put(`/pos/terminals/${editing.id}`, payload)
      }
      return api.post('/pos/terminals', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      toast({ title: editing ? 'Terminal updated' : 'Terminal created' })
      setEditorOpen(false)
      resetForm()
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save terminal.', variant: 'destructive' })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/pos/terminals/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      toast({ title: 'Terminal deleted' })
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to delete terminal.', variant: 'destructive' })
    },
  })

  if (loading) return <SettingsSkeleton />

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-medium">POS Terminals</h3>
          <p className="text-sm text-muted-foreground">Manage your point-of-sale terminal devices.</p>
        </div>
        <HeaderActionButton icon="solar:add-circle-bold" onClick={openCreate}>
          {editorOpen ? 'New Terminal' : 'Add Terminal'}
        </HeaderActionButton>
      </div>

      <Card>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Code</TableHead>
                <TableHead>Warehouse</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Session</TableHead>
                <TableHead className="w-[100px]">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {terminals.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                    No terminals configured. Add your first terminal to get started.
                  </TableCell>
                </TableRow>
              ) : (
                terminals.map((t) => (
                  <TableRow key={t.id}>
                    <TableCell className="font-medium">{t.name}</TableCell>
                    <TableCell className="font-mono text-xs">{t.code}</TableCell>
                    <TableCell>{t.warehouse?.name ?? '—'}</TableCell>
                    <TableCell>
                      <Badge variant={t.is_active ? 'default' : 'secondary'}>
                        {t.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {t.has_open_session ? (
                        <Badge variant="outline" className="border-green-500 text-green-600">
                          <Icon icon="solar:play-circle-bold" className="mr-1 h-3 w-3" />
                          Open
                        </Badge>
                      ) : (
                        <span className="text-xs text-muted-foreground">Closed</span>
                      )}
                    </TableCell>
                    <TableCell>
                      <div className="flex gap-1">
                        <HeaderActionButton icon="solar:pen-bold" variant="ghost" size="icon" onClick={() => openEdit(t)}>
                        </HeaderActionButton>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            if (confirm(`Delete terminal "${t.name}"?`)) {
                              deleteMutation.mutate(t.id)
                            }
                          }}
                          disabled={t.has_open_session}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {editorOpen && (
        <Card>
          <CardHeader>
            <CardTitle>{editing ? 'Edit Terminal' : 'Add Terminal'}</CardTitle>
            <CardDescription>
              {editing ? 'Update terminal details and printer routing.' : 'Create a new POS terminal and configure printing.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2 md:col-span-2">
                <Label>Terminal Name *</Label>
                <Input
                  value={form.name}
                  onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
                  placeholder="Counter 1"
                />
              </div>
              <div className="space-y-2">
                <Label>Warehouse</Label>
                <Select
                  value={form.warehouse_id}
                  onValueChange={(v) => setForm((p) => ({ ...p, warehouse_id: v }))}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select warehouse" />
                  </SelectTrigger>
                  <SelectContent>
                    {(Array.isArray(warehouses) ? warehouses : []).map((w: Warehouse) => (
                      <SelectItem key={w.id} value={w.id.toString()}>
                        {w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Receipt Print Mode</Label>
                <Select
                  value={form.receipt_print_mode}
                  onValueChange={(v) => setForm((p) => ({ ...p, receipt_print_mode: v as 'web' | 'direct' }))}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="web">Web Print (Browser Popup)</SelectItem>
                    <SelectItem value="direct">Direct Print (IoT Printer)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <Label>Receipt Header</Label>
                <Textarea
                  value={form.receipt_header}
                  onChange={(e) => setForm((p) => ({ ...p, receipt_header: e.target.value }))}
                  placeholder="Your Store Name&#10;123 Main Street"
                  rows={4}
                />
              </div>
              <div className="space-y-2">
                <Label>Receipt Footer</Label>
                <Textarea
                  value={form.receipt_footer}
                  onChange={(e) => setForm((p) => ({ ...p, receipt_footer: e.target.value }))}
                  placeholder="Thank you for shopping with us!"
                  rows={4}
                />
              </div>
            </div>

            {form.receipt_print_mode === 'direct' && (
              <>
                <Separator />
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-2 md:col-span-2">
                    <Label>Linked Agent</Label>
                    <Input
                      value={form.printer_agent_id}
                      onChange={(e) => setForm((p) => ({ ...p, printer_agent_id: e.target.value }))}
                      placeholder="Agent ID"
                    />
                    {!linkedAgentId && (
                      <p className="text-xs text-amber-600">No IoT agent is currently linked for this user.</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label>Receipt Printer (IoT)</Label>
                    <Select
                      value={form.receipt_printer_device_id || '__none__'}
                      onValueChange={(v) => setForm((p) => ({
                        ...p,
                        receipt_printer_device_id: v === '__none__' ? '' : v,
                      }))}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select receipt printer" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__none__">Not selected</SelectItem>
                        {receiptPrinters.map((device) => (
                          <SelectItem key={device.id} value={device.id}>
                            {device.name} ({device.driverType || device.driver_type || 'printer'})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label>Label/Barcode Printer (IoT)</Label>
                    <Select
                      value={form.label_printer_device_id || '__none__'}
                      onValueChange={(v) => setForm((p) => ({
                        ...p,
                        label_printer_device_id: v === '__none__' ? '' : v,
                      }))}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select label printer" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__none__">Not selected</SelectItem>
                        {labelPrinters.map((device) => (
                          <SelectItem key={device.id} value={device.id}>
                            {device.name} ({device.driverType || device.driver_type || 'printer'})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </>
            )}

            <SwitchRow
              label="Active"
              description="Inactive terminals cannot start new sessions."
              checked={form.is_active}
              onCheckedChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />

            <div className="flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setEditorOpen(false)
                  resetForm()
                }}
              >
                Cancel
              </Button>
              <HeaderActionButton icon="svg-spinners:ring-resize" onClick={() => saveMutation.mutate()} disabled={!form.name || saveMutation.isPending}>
                {editing ? 'Update Terminal' : 'Create Terminal'}
              </HeaderActionButton>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

/* ================================================================== */
/*  PAYMENT METHODS TAB                                                */
/* ================================================================== */

function PaymentMethodsTab({
  methods,
  loading,
}: {
  methods: PaymentMethod[]
  loading: boolean
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<PaymentMethod | null>(null)
  const [form, setForm] = useState({
    name: '',
    code: '',
    type: 'cash',
    icon: '',
    opens_cash_drawer: false,
    requires_approval: false,
    surcharge_percent: 0,
    surcharge_fixed: 0,
    is_active: true,
    sort_order: 0,
  })

  const resetForm = () => {
    setForm({
      name: '', code: '', type: 'cash', icon: '',
      opens_cash_drawer: false, requires_approval: false,
      surcharge_percent: 0, surcharge_fixed: 0,
      is_active: true, sort_order: 0,
    })
    setEditing(null)
  }

  const openCreate = () => {
    resetForm()
    setDialogOpen(true)
  }

  const openEdit = (m: PaymentMethod) => {
    setEditing(m)
    setForm({
      name: m.name,
      code: m.code,
      type: m.type,
      icon: m.icon ?? '',
      opens_cash_drawer: m.opens_cash_drawer,
      requires_approval: m.requires_approval,
      surcharge_percent: m.surcharge_percent,
      surcharge_fixed: m.surcharge_fixed,
      is_active: m.is_active,
      sort_order: m.sort_order,
    })
    setDialogOpen(true)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (editing) {
        return api.put(`/pos/payment-methods/${editing.id}`, form)
      }
      return api.post('/pos/payment-methods', form)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-payment-methods'] })
      toast({ title: editing ? 'Payment method updated' : 'Payment method created' })
      setDialogOpen(false)
      resetForm()
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save payment method.', variant: 'destructive' })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => api.delete(`/pos/payment-methods/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-payment-methods'] })
      toast({ title: 'Payment method deleted' })
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to delete.', variant: 'destructive' })
    },
  })

  if (loading) return <SettingsSkeleton />

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-medium">Payment Methods</h3>
          <p className="text-sm text-muted-foreground">Configure payment options available at the POS.</p>
        </div>
        <HeaderActionButton icon="solar:add-circle-bold" onClick={openCreate}>
          Add Method
        </HeaderActionButton>
      </div>

      <Card>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Code</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Surcharge</TableHead>
                <TableHead>Cash Drawer</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-[100px]">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {methods.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                    No payment methods configured.
                  </TableCell>
                </TableRow>
              ) : (
                methods.map((m) => (
                  <TableRow key={m.id}>
                    <TableCell className="font-medium">
                      <div className="flex items-center gap-2">
                        {m.icon && <Icon icon={m.icon} className="h-4 w-4" />}
                        {m.name}
                      </div>
                    </TableCell>
                    <TableCell className="font-mono text-xs">{m.code}</TableCell>
                    <TableCell>
                      <Badge variant="outline" className="capitalize">{m.type}</Badge>
                    </TableCell>
                    <TableCell>
                      {m.surcharge_percent > 0 || m.surcharge_fixed > 0
                        ? `${m.surcharge_percent}% + ${m.surcharge_fixed}`
                        : '—'}
                    </TableCell>
                    <TableCell>
                      {m.opens_cash_drawer ? (
                        <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-500" />
                      ) : (
                        <Icon icon="solar:close-circle-bold" className="h-4 w-4 text-muted-foreground" />
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge variant={m.is_active ? 'default' : 'secondary'}>
                        {m.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      <div className="flex gap-1">
                        <HeaderActionButton icon="solar:pen-bold" variant="ghost" size="icon" onClick={() => openEdit(m)}>
                        </HeaderActionButton>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => {
                            if (confirm(`Delete payment method "${m.name}"?`)) {
                              deleteMutation.mutate(m.id)
                            }
                          }}
                        >
                          <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Payment Method Dialog */}
      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit Payment Method' : 'Add Payment Method'}</DialogTitle>
            <DialogDescription>
              {editing ? 'Update payment method details.' : 'Create a new POS payment method.'}
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Name *</Label>
                <Input
                  value={form.name}
                  onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
                  placeholder="Cash"
                />
              </div>
              <div className="space-y-2">
                <Label>Code *</Label>
                <Input
                  value={form.code}
                  onChange={(e) => setForm((p) => ({ ...p, code: e.target.value }))}
                  placeholder="cash"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Type</Label>
                <Select
                  value={form.type}
                  onValueChange={(v) => setForm((p) => ({ ...p, type: v }))}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="cash">Cash</SelectItem>
                    <SelectItem value="card">Card</SelectItem>
                    <SelectItem value="mobile">Mobile</SelectItem>
                    <SelectItem value="bank_transfer">Bank Transfer</SelectItem>
                    <SelectItem value="voucher">Voucher</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Icon (Iconify)</Label>
                <Input
                  value={form.icon}
                  onChange={(e) => setForm((p) => ({ ...p, icon: e.target.value }))}
                  placeholder="solar:wallet-bold"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Surcharge %</Label>
                <Input
                  type="number"
                  min={0}
                  step={0.01}
                  value={form.surcharge_percent}
                  onChange={(e) => setForm((p) => ({ ...p, surcharge_percent: Number(e.target.value) }))}
                />
              </div>
              <div className="space-y-2">
                <Label>Surcharge Fixed</Label>
                <Input
                  type="number"
                  min={0}
                  step={0.01}
                  value={form.surcharge_fixed}
                  onChange={(e) => setForm((p) => ({ ...p, surcharge_fixed: Number(e.target.value) }))}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label>Sort Order</Label>
              <Input
                type="number"
                min={0}
                value={form.sort_order}
                onChange={(e) => setForm((p) => ({ ...p, sort_order: Number(e.target.value) }))}
              />
            </div>

            <Separator />

            <SwitchRow
              label="Opens Cash Drawer"
              description="Trigger the cash drawer when this method is used."
              checked={form.opens_cash_drawer}
              onCheckedChange={(v) => setForm((p) => ({ ...p, opens_cash_drawer: v }))}
            />
            <SwitchRow
              label="Requires Approval"
              description="Manager approval needed before accepting this payment."
              checked={form.requires_approval}
              onCheckedChange={(v) => setForm((p) => ({ ...p, requires_approval: v }))}
            />
            <SwitchRow
              label="Active"
              description="Inactive methods are hidden from the POS."
              checked={form.is_active}
              onCheckedChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <HeaderActionButton icon="svg-spinners:ring-resize" onClick={() => saveMutation.mutate()} disabled={!form.name || !form.code || saveMutation.isPending}>
              {editing ? 'Update' : 'Create'}
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/* ================================================================== */
/*  RECEIPT SETTINGS TAB                                               */
/* ================================================================== */

function ReceiptSettingsTab({
  settings,
  loading,
}: {
  settings?: PosSettings
  loading: boolean
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<Partial<PosSettings>>({})

  useEffect(() => {
    if (settings) setForm(settings)
  }, [settings])

  const mutation = useMutation({
    mutationFn: async (data: Partial<PosSettings>) => {
      const res = await api.put('/pos/settings', data)
      return res.data.settings
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-settings'] })
      toast({ title: 'Settings saved', description: 'Receipt settings updated.' })
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save receipt settings.', variant: 'destructive' })
    },
  })

  const update = (key: keyof PosSettings, value: unknown) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  if (loading) return <SettingsSkeleton />

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:document-text-bold-duotone" className="h-5 w-5 text-primary" />
            Receipt Layout
          </CardTitle>
          <CardDescription>Customize what appears on printed receipts.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 md:grid-cols-2">
          <div className="space-y-2 md:col-span-2">
            <Label>Receipt Header</Label>
            <Textarea
              value={form.receipt_header ?? ''}
              onChange={(e) => update('receipt_header', e.target.value)}
              placeholder="Your Business Name&#10;123 Business Street&#10;Phone: (555) 123-4567"
              rows={4}
            />
            <p className="text-xs text-muted-foreground">This text appears at the top of every receipt.</p>
          </div>

          <div className="space-y-2 md:col-span-2">
            <Label>Receipt Footer</Label>
            <Textarea
              value={form.receipt_footer ?? ''}
              onChange={(e) => update('receipt_footer', e.target.value)}
              placeholder="Thank you for your purchase!&#10;Return policy: 30 days with receipt"
              rows={3}
            />
            <p className="text-xs text-muted-foreground">This text appears at the bottom of every receipt.</p>
          </div>

          <div className="space-y-2">
            <Label>Logo URL</Label>
            <Input
              value={form.receipt_logo_url ?? ''}
              onChange={(e) => update('receipt_logo_url', e.target.value)}
              placeholder="https://example.com/logo.png"
            />
          </div>

          <div className="space-y-2">
            <Label>Paper Size</Label>
            <Select
              value={form.receipt_paper_size ?? '80mm'}
              onValueChange={(v) => update('receipt_paper_size', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="58mm">58mm (Narrow)</SelectItem>
                <SelectItem value="80mm">80mm (Standard)</SelectItem>
                <SelectItem value="a4">A4 (Full page)</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:eye-bold-duotone" className="h-5 w-5 text-primary" />
            Receipt Options
          </CardTitle>
          <CardDescription>Control what information is shown on receipts.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <SwitchRow
            label="Show Logo"
            description="Display the business logo on receipts."
            checked={form.receipt_show_logo !== false}
            onCheckedChange={(v) => update('receipt_show_logo', v)}
          />
          <Separator />
          <SwitchRow
            label="Show Tax Breakdown"
            description="Display individual tax amounts on the receipt."
            checked={form.receipt_show_tax_breakdown !== false}
            onCheckedChange={(v) => update('receipt_show_tax_breakdown', v)}
          />
          <Separator />
          <SwitchRow
            label="Show Cashier Name"
            description="Print the cashier's name on receipts."
            checked={form.receipt_show_cashier !== false}
            onCheckedChange={(v) => update('receipt_show_cashier', v)}
          />
          <Separator />
          <SwitchRow
            label="Auto-Print Receipt"
            description="Automatically print a receipt after every completed sale."
            checked={!!form.auto_print_receipt}
            onCheckedChange={(v) => update('auto_print_receipt', v)}
          />
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <HeaderActionButton icon="svg-spinners:ring-resize" onClick={() => mutation.mutate(form)} disabled={mutation.isPending}>
          Save Receipt Settings
        </HeaderActionButton>
      </div>
    </div>
  )
}

/* ================================================================== */
/*  BARCODE SETTINGS TAB                                               */
/* ================================================================== */

function BarcodeSettingsTab({
  settings,
  loading,
}: {
  settings?: PosSettings
  loading: boolean
}) {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [form, setForm] = useState<Partial<PosSettings>>({})

  useEffect(() => {
    if (settings) setForm(settings)
  }, [settings])

  const mutation = useMutation({
    mutationFn: async (data: Partial<PosSettings>) => {
      const res = await api.put('/pos/settings', data)
      return res.data.settings
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-settings'] })
      toast({ title: 'Settings saved', description: 'Barcode settings updated.' })
    },
    onError: () => {
      toast({ title: 'Error', description: 'Failed to save barcode settings.', variant: 'destructive' })
    },
  })

  const update = (key: keyof PosSettings, value: unknown) =>
    setForm((prev) => ({ ...prev, [key]: value }))

  if (loading) return <SettingsSkeleton />

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:qr-code-bold-duotone" className="h-5 w-5 text-primary" />
            Barcode Generation
          </CardTitle>
          <CardDescription>Configure how barcodes are generated and printed.</CardDescription>
        </CardHeader>
        <CardContent className="grid gap-6 md:grid-cols-2">
          <div className="space-y-2">
            <Label>Barcode Type</Label>
            <Select
              value={form.barcode_type ?? 'code128'}
              onValueChange={(v) => update('barcode_type', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="code128">Code 128</SelectItem>
                <SelectItem value="code39">Code 39</SelectItem>
                <SelectItem value="ean13">EAN-13</SelectItem>
                <SelectItem value="ean8">EAN-8</SelectItem>
                <SelectItem value="upc">UPC</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Render Format</Label>
            <Select
              value={form.barcode_format ?? 'svg'}
              onValueChange={(v) => update('barcode_format', v)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="svg">SVG</SelectItem>
                <SelectItem value="png">PNG</SelectItem>
                <SelectItem value="canvas">Canvas</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <Label>Bar Width (px)</Label>
            <Input
              type="number"
              min={1}
              max={5}
              value={form.barcode_bar_width ?? 2}
              onChange={(e) => update('barcode_bar_width', Number(e.target.value))}
            />
          </div>

          <div className="space-y-2">
            <Label>Bar Height (px)</Label>
            <Input
              type="number"
              min={20}
              max={100}
              value={form.barcode_bar_height ?? 50}
              onChange={(e) => update('barcode_bar_height', Number(e.target.value))}
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:tag-bold-duotone" className="h-5 w-5 text-primary" />
            Label Content
          </CardTitle>
          <CardDescription>Choose what information appears on barcode labels.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <SwitchRow
            label="Show Product Name"
            description="Print the product name above the barcode."
            checked={form.barcode_show_name !== false}
            onCheckedChange={(v) => update('barcode_show_name', v)}
          />
          <Separator />
          <SwitchRow
            label="Show Price"
            description="Print the product price on the label."
            checked={form.barcode_show_price !== false}
            onCheckedChange={(v) => update('barcode_show_price', v)}
          />
          <Separator />
          <SwitchRow
            label="Show SKU"
            description="Print the SKU code on the label."
            checked={form.barcode_show_sku !== false}
            onCheckedChange={(v) => update('barcode_show_sku', v)}
          />
        </CardContent>
      </Card>

      <div className="flex justify-end">
        <HeaderActionButton icon="svg-spinners:ring-resize" onClick={() => mutation.mutate(form)} disabled={mutation.isPending}>
          Save Barcode Settings
        </HeaderActionButton>
      </div>
    </div>
  )
}

/* ================================================================== */
/*  SHARED COMPONENTS                                                  */
/* ================================================================== */

function SwitchRow({
  label,
  description,
  checked,
  onCheckedChange,
}: {
  label: string
  description: string
  checked: boolean
  onCheckedChange: (v: boolean) => void
}) {
  return (
    <div className="flex items-center justify-between gap-4">
      <div className="space-y-0.5">
        <Label className="text-sm font-medium">{label}</Label>
        <p className="text-xs text-muted-foreground">{description}</p>
      </div>
      <Switch checked={checked} onCheckedChange={onCheckedChange} />
    </div>
  )
}

function SettingsSkeleton() {
  return (
    <div className="space-y-4">
      {[1, 2, 3].map((i) => (
        <Card key={i}>
          <CardContent className="p-6">
            <div className="space-y-3">
              <div className="h-4 w-1/3 animate-pulse rounded bg-muted" />
              <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
              <div className="h-10 w-full animate-pulse rounded bg-muted" />
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
