import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useSearchParams } from 'react-router-dom'
import { WarehousesPage } from './WarehousesPage'
import {
  SettingsLoadingCard,
  SettingsRow,
  SettingsSaveBar,
  SettingsSectionHeader,
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui'

interface InventorySettings {
  id: number
  allow_negative_stock: boolean
  auto_generate_sku: boolean
  sku_prefix: string | null
  sku_length: number
  track_batch_numbers: boolean
  track_expiry_dates: boolean
  require_adjustment_approval: boolean
  low_stock_threshold_days: number
  enable_barcode_scanning: boolean
  sme_mode: boolean
}

const DEFAULT_SETTINGS: InventorySettings = {
  id: 0,
  allow_negative_stock: false,
  auto_generate_sku: true,
  sku_prefix: 'SKU',
  sku_length: 8,
  track_batch_numbers: false,
  track_expiry_dates: false,
  require_adjustment_approval: false,
  low_stock_threshold_days: 7,
  enable_barcode_scanning: true,
  sme_mode: false,
}

// ─── Section components ─────────────────────────────────────────────────────

interface SectionProps {
  settings: InventorySettings
  local: Partial<InventorySettings>
  setLocal: (patch: Partial<InventorySettings>) => void
  disabled: boolean
  save: {
    hasChanges: boolean
    isPending: boolean
    onSave: () => void
    onCancel: () => void
  }
}

function SmeModeSection({ settings, local, setLocal, disabled, save }: SectionProps) {
  const sme = (local.sme_mode ?? settings.sme_mode) as boolean

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:shop-bold-duotone"
        title="SME Mode"
        description="Allow direct stock entry (stock-in, stock-out, stock adjustment) without a Purchase Order or Goods Receipt. Recommended for small businesses that receive stock informally."
        accentClass="bg-amber-100 dark:bg-amber-950/50"
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Enable SME Mode"
          description="Unlocks the Stock Operations page for direct inventory entry."
        >
          <Switch
            checked={sme}
            onCheckedChange={(v) => setLocal({ sme_mode: v })}
            disabled={disabled}
          />
        </SettingsRow>

        {sme && (
          <div className="py-4">
            <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
              <Icon icon="solar:info-circle-bold-duotone" className="mt-0.5 h-4 w-4 shrink-0" />
              <span>
                Direct stock entries do not generate accounting journal entries. For formal vendor
                purchases with AP posting, keep using the Purchase Order workflow.
              </span>
            </div>
          </div>
        )}
      </CardContent>
      <SettingsSaveBar
        hasChanges={save.hasChanges}
        isPending={save.isPending}
        onSave={save.onSave}
        onCancel={save.onCancel}
        label="Save inventory settings"
      />
    </Card>
  )
}

function StockBehaviourSection({ settings, local, setLocal, disabled, save }: SectionProps) {
  const value = (key: keyof InventorySettings) => (local[key] ?? settings[key]) as boolean

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:box-bold-duotone"
        title="Stock Behaviour"
        description="Control how stock levels behave and what has to be captured on every movement."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Allow Negative Stock"
          description="Permit stock levels to fall below zero (overselling)."
        >
          <Switch
            checked={value('allow_negative_stock')}
            onCheckedChange={(v) => setLocal({ allow_negative_stock: v })}
            disabled={disabled}
          />
        </SettingsRow>

        <SettingsRow
          title="Track Batch Numbers"
          description="Record batch/lot numbers on stock movements."
        >
          <Switch
            checked={value('track_batch_numbers')}
            onCheckedChange={(v) => setLocal({ track_batch_numbers: v })}
            disabled={disabled}
          />
        </SettingsRow>

        <SettingsRow
          title="Track Expiry Dates"
          description="Record expiry dates on stock-in movements."
        >
          <Switch
            checked={value('track_expiry_dates')}
            onCheckedChange={(v) => setLocal({ track_expiry_dates: v })}
            disabled={disabled}
          />
        </SettingsRow>

        <SettingsRow
          title="Require Adjustment Approval"
          description="Stock adjustments must be approved by a manager before they are committed."
        >
          <Switch
            checked={value('require_adjustment_approval')}
            onCheckedChange={(v) => setLocal({ require_adjustment_approval: v })}
            disabled={disabled}
          />
        </SettingsRow>

        <SettingsRow
          title="Enable Barcode Scanning"
          description="Show barcode scan inputs on stock entry forms."
        >
          <Switch
            checked={value('enable_barcode_scanning')}
            onCheckedChange={(v) => setLocal({ enable_barcode_scanning: v })}
            disabled={disabled}
          />
        </SettingsRow>
      </CardContent>
      <SettingsSaveBar
        hasChanges={save.hasChanges}
        isPending={save.isPending}
        onSave={save.onSave}
        onCancel={save.onCancel}
        label="Save inventory settings"
      />
    </Card>
  )
}

function SkuSection({ settings, local, setLocal, disabled, save }: SectionProps) {
  const autoGenerate = (local.auto_generate_sku ?? settings.auto_generate_sku) as boolean
  const prefix = (local.sku_prefix ?? settings.sku_prefix ?? '') as string
  const length = (local.sku_length ?? settings.sku_length ?? 8) as number

  const previewPrefix = prefix || 'SKU'
  const preview = `${previewPrefix}-${'0'.repeat(Math.max(0, Math.min(length, 12) - 4))}${'1234'}`

  return (
    <Card className="overflow-hidden">
      <SettingsSectionHeader
        icon="solar:tag-bold-duotone"
        title="SKU Generation"
        description="Decide how product SKUs are created and what they look like."
      />
      <CardContent className="divide-y py-0">
        <SettingsRow
          title="Auto-generate SKU"
          description="Automatically assign a SKU when a new product is created."
        >
          <Switch
            checked={autoGenerate}
            onCheckedChange={(v) => setLocal({ auto_generate_sku: v })}
            disabled={disabled}
          />
        </SettingsRow>

        <div className="grid gap-6 py-4 md:grid-cols-2">
          <div className="space-y-2">
            <Label htmlFor="sku_prefix" className="text-sm font-medium">
              SKU prefix
            </Label>
            <Input
              id="sku_prefix"
              value={prefix}
              onChange={(e) => setLocal({ sku_prefix: e.target.value })}
              placeholder="SKU"
              disabled={disabled || !autoGenerate}
              maxLength={20}
            />
            <p className="text-xs text-muted-foreground">
              Leading text for generated SKUs (e.g. <code className="font-mono">SKU</code>,{' '}
              <code className="font-mono">PRD</code>).
            </p>
          </div>

          <div className="space-y-2">
            <Label htmlFor="sku_length" className="text-sm font-medium">
              Total SKU length
            </Label>
            <Input
              id="sku_length"
              type="number"
              min={4}
              max={32}
              value={length}
              onChange={(e) => setLocal({ sku_length: parseInt(e.target.value, 10) || 8 })}
              disabled={disabled || !autoGenerate}
            />
            <p className="text-xs text-muted-foreground">
              Zero-padded numeric body length, between 4 and 32.
            </p>
          </div>

          <div className="md:col-span-2">
            <div className="flex items-center gap-3 rounded-lg border border-dashed bg-muted/30 px-4 py-3">
              <Icon icon="solar:eye-bold-duotone" className="h-4 w-4 shrink-0 text-primary" />
              <span className="text-sm text-muted-foreground">Next SKU will look like</span>
              <code className="rounded bg-background px-2 py-1 font-mono text-sm ring-1 ring-border">
                {preview}
              </code>
            </div>
          </div>
        </div>
      </CardContent>
      <SettingsSaveBar
        hasChanges={save.hasChanges}
        isPending={save.isPending}
        onSave={save.onSave}
        onCancel={save.onCancel}
        label="Save inventory settings"
      />
    </Card>
  )
}

// ─── Page ───────────────────────────────────────────────────────────────────

export function InventorySettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const activeTab = searchParams.get('tab') || 'settings'

  const [activeSection, setActiveSection] = useState('sme')
  const [local, setLocalState] = useState<Partial<InventorySettings>>({})

  const { data: settings, error, isLoading, refetch } = useQuery<InventorySettings>({
    queryKey: ['inventory-settings'],
    queryFn: async () => {
      const res = await api.get('/inventory/settings')
      return res.data
    },
    retry: false,
  })

  const resolvedSettings = settings ?? DEFAULT_SETTINGS
  const isReadOnly = !settings

  const updateMutation = useMutation({
    mutationFn: (patch: Partial<InventorySettings>) => api.put('/inventory/settings', patch),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inventory-settings'] })
      setLocalState({})
      toast({ title: 'Inventory settings saved' })
    },
    onError: () => {
      toast({ title: 'Failed to save settings', variant: 'destructive' })
    },
  })

  const setLocal = (patch: Partial<InventorySettings>) => {
    if (isReadOnly) {
      toast({
        title: 'Inventory settings unavailable',
        description: 'The server failed to load inventory settings. Retry after the backend error is fixed.',
        variant: 'destructive',
      })
      return
    }
    setLocalState((prev) => ({ ...prev, ...patch }))
  }

  const hasChanges = Object.keys(local).length > 0

  const sectionProps = {
    settings: resolvedSettings,
    local,
    setLocal,
    disabled: updateMutation.isPending || isReadOnly,
    save: {
      hasChanges,
      isPending: updateMutation.isPending,
      onSave: () => updateMutation.mutate(local),
      onCancel: () => setLocalState({}),
    },
  }

  const sections: SettingsSectionDef[] = [
    {
      key: 'sme',
      title: 'SME Mode',
      description: 'Direct stock entry without PO/GRN',
      icon: 'solar:shop-bold-duotone',
      component: () => <SmeModeSection {...sectionProps} />,
    },
    {
      key: 'stock',
      title: 'Stock Behaviour',
      description: 'Negative stock, batches, expiry & approvals',
      icon: 'solar:box-bold-duotone',
      component: () => <StockBehaviourSection {...sectionProps} />,
    },
    {
      key: 'sku',
      title: 'SKU Generation',
      description: 'Automatic product code formatting',
      icon: 'solar:tag-bold-duotone',
      component: () => <SkuSection {...sectionProps} />,
    },
  ]

  const ActiveSection =
    sections.find((s) => s.key === activeSection)?.component ?? sections[0].component

  if (isLoading) {
    return (
      <div className="space-y-6">
        <SettingsLoadingCard />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold tracking-tight">Inventory Settings</h1>
        <p className="mt-1 text-muted-foreground">
          Configure inventory behaviour for your business.
        </p>
      </div>

      <Tabs
        value={activeTab}
        onValueChange={(tab) => setSearchParams({ tab }, { replace: true })}
      >
        <TabsList className="h-10 w-fit max-w-full justify-start gap-1 bg-muted p-1">
          <TabsTrigger value="settings" className="gap-2 px-5">
            <Icon icon="solar:settings-bold-duotone" className="h-4 w-4" />
            <span>Inventory Settings</span>
          </TabsTrigger>
          <TabsTrigger value="warehouses" className="gap-2 px-5">
            <Icon icon="solar:buildings-2-bold-duotone" className="h-4 w-4" />
            <span>Warehouses</span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="mt-6">
          <div className="space-y-6">
            {error && (
              <Alert variant="destructive">
                <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
                <AlertTitle>Inventory settings failed to load</AlertTitle>
                <AlertDescription className="space-y-3">
                  <p>
                    The API returned a server error for <code>/inventory/settings</code>. The form is
                    shown with safe defaults, but saving is disabled until the backend issue is resolved.
                  </p>
                  <button
                    type="button"
                    onClick={() => refetch()}
                    className="text-sm font-medium underline underline-offset-4"
                  >
                    Retry loading
                  </button>
                </AlertDescription>
              </Alert>
            )}

            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
              <SettingsSectionNav
                sections={sections}
                activeKey={activeSection}
                onSelect={setActiveSection}
              />
              <div className="min-w-0 flex-1">
                <ActiveSection />
              </div>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="warehouses" className="mt-6 space-y-4">
          <WarehousesPage />
        </TabsContent>
      </Tabs>
    </div>
  )
}
