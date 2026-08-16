import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { useSearchParams } from 'react-router-dom'
import { WarehousesPage } from './WarehousesPage'

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

export function InventorySettingsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const activeTab = searchParams.get('tab') || 'settings'

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
    mutationFn: (patch: Partial<InventorySettings>) =>
      api.put('/inventory/settings', patch),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inventory-settings'] })
      toast({ title: 'Settings saved' })
    },
    onError: () => {
      toast({ title: 'Failed to save settings', variant: 'destructive' })
    },
  })

  const toggle = (field: keyof InventorySettings) => {
    if (!settings) {
      toast({
        title: 'Inventory settings unavailable',
        description: 'The server failed to load inventory settings. Retry after the backend error is fixed.',
        variant: 'destructive',
      })
      return
    }

    updateMutation.mutate({ [field]: !settings[field] })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-12">
        <Icon icon="solar:spinner-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <Tabs
        value={activeTab}
        onValueChange={(tab) => setSearchParams({ tab }, { replace: true })}
      >
        <TabsList>
          <TabsTrigger value="settings">Inventory Settings</TabsTrigger>
          <TabsTrigger value="warehouses">Warehouses</TabsTrigger>
        </TabsList>

        <TabsContent value="settings">
          <div className="space-y-6 max-w-2xl">
      <div>
        <h1 className="text-3xl font-bold">Inventory Settings</h1>
        <p className="text-muted-foreground">Configure inventory behaviour for your business</p>
      </div>

      {error && (
        <Alert variant="destructive">
          <Icon icon="solar:danger-triangle-bold" className="h-4 w-4" />
          <AlertTitle>Inventory settings failed to load</AlertTitle>
          <AlertDescription className="space-y-3">
            <p>The API returned a server error for `/inventory/settings`. The form below is shown with safe defaults, but saving is disabled until the backend issue is resolved.</p>
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

      {/* SME Mode — top card, most prominent */}
      <Card className="border-amber-200 dark:border-amber-800">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Icon icon="solar:shop-bold" className="h-5 w-5 text-amber-500" />
            SME Mode
          </CardTitle>
          <CardDescription>
            Allow direct stock entry (stock-in, stock-out, and stock adjustment) without requiring a
            Purchase Order or Goods Receipt. Recommended for small businesses that receive stock
            informally.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="sme_mode" className="text-base font-medium">
                Enable SME Mode
              </Label>
              <p className="text-sm text-muted-foreground">
                Unlocks the Stock Operations page for direct inventory entry
              </p>
            </div>
            <Switch
              id="sme_mode"
              checked={resolvedSettings.sme_mode}
              onCheckedChange={() => toggle('sme_mode')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>

          {resolvedSettings.sme_mode && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
              <div className="flex items-start gap-2">
                <Icon icon="solar:info-circle-bold" className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                  Direct stock entries do not generate accounting journal entries. For formal vendor
                  purchases with AP posting, continue to use the Purchase Order workflow.
                </span>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Stock behaviour */}
      <Card>
        <CardHeader>
          <CardTitle>Stock Behaviour</CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="allow_negative_stock" className="text-base font-medium">
                Allow Negative Stock
              </Label>
              <p className="text-sm text-muted-foreground">
                Permit stock levels to fall below zero (overselling)
              </p>
            </div>
            <Switch
              id="allow_negative_stock"
              checked={resolvedSettings.allow_negative_stock}
              onCheckedChange={() => toggle('allow_negative_stock')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="track_batch_numbers" className="text-base font-medium">
                Track Batch Numbers
              </Label>
              <p className="text-sm text-muted-foreground">
                Record batch/lot numbers on stock movements
              </p>
            </div>
            <Switch
              id="track_batch_numbers"
              checked={resolvedSettings.track_batch_numbers}
              onCheckedChange={() => toggle('track_batch_numbers')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="track_expiry_dates" className="text-base font-medium">
                Track Expiry Dates
              </Label>
              <p className="text-sm text-muted-foreground">
                Record expiry dates on stock-in movements
              </p>
            </div>
            <Switch
              id="track_expiry_dates"
              checked={resolvedSettings.track_expiry_dates}
              onCheckedChange={() => toggle('track_expiry_dates')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="require_adjustment_approval" className="text-base font-medium">
                Require Adjustment Approval
              </Label>
              <p className="text-sm text-muted-foreground">
                Stock adjustments must be approved by a manager before they are committed
              </p>
            </div>
            <Switch
              id="require_adjustment_approval"
              checked={resolvedSettings.require_adjustment_approval}
              onCheckedChange={() => toggle('require_adjustment_approval')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>

          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="enable_barcode_scanning" className="text-base font-medium">
                Enable Barcode Scanning
              </Label>
              <p className="text-sm text-muted-foreground">
                Show barcode scan inputs on stock entry forms
              </p>
            </div>
            <Switch
              id="enable_barcode_scanning"
              checked={resolvedSettings.enable_barcode_scanning}
              onCheckedChange={() => toggle('enable_barcode_scanning')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>
        </CardContent>
      </Card>

      {/* SKU settings */}
      <Card>
        <CardHeader>
          <CardTitle>SKU Generation</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="space-y-1">
              <Label htmlFor="auto_generate_sku" className="text-base font-medium">
                Auto-generate SKU
              </Label>
              <p className="text-sm text-muted-foreground">
                Automatically assign a SKU when a new product is created
              </p>
            </div>
            <Switch
              id="auto_generate_sku"
              checked={resolvedSettings.auto_generate_sku}
              onCheckedChange={() => toggle('auto_generate_sku')}
              disabled={updateMutation.isPending || isReadOnly}
            />
          </div>
        </CardContent>
      </Card>
          </div>
        </TabsContent>

        <TabsContent value="warehouses" className="space-y-4">
          <WarehousesPage />
        </TabsContent>
      </Tabs>
    </div>
  )
}
