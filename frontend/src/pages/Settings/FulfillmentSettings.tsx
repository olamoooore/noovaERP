import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import api from '@/lib/api'
import { cn } from '@/lib/utils'

interface FulfillmentProvider {
  id: string
  carrier_code: string
  name: string
  is_active: boolean
  is_default: boolean
  settings: Record<string, any> | null
  sort_order: number
  created_at: string
}

interface AvailableCarrier {
  code: string
  name: string
  description: string
  services: string[]
  supports_domestic: boolean
  supports_international: boolean
  simulated?: boolean
}

export function FulfillmentSettings() {
  const { toast } = useToast()
  const [providers, setProviders] = useState<FulfillmentProvider[]>([])
  const [availableCarriers, setAvailableCarriers] = useState<AvailableCarrier[]>([])
  const [loading, setLoading] = useState(true)
  
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedCarrier, setSelectedCarrier] = useState<string>('')
  const [formData, setFormData] = useState({
    name: '',
    carrier_code: '',
    is_active: true,
    is_default: false,
    config: {
      api_key: '',
      api_secret: '',
      account_number: '',
      customer_code: '',
    },
    settings: {
      default_service: '',
      label_format: 'pdf',
      shipping_cost_override: '',
    },
    sort_order: 0,
  })
  const [testingConnection, setTestingConnection] = useState<string | null>(null)

  useEffect(() => {
    loadProviders()
    loadAvailableCarriers()
  }, [])

  const loadProviders = async () => {
    try {
      const response = await api.get('/admin/fulfillment/providers')
      setProviders(response.data.data || [])
    } catch (error) {
      console.error('Failed to load fulfillment providers', error)
    } finally {
      setLoading(false)
    }
  }

  const loadAvailableCarriers = async () => {
    try {
      const response = await api.get('/admin/fulfillment/providers/available')
      setAvailableCarriers(response.data.data || [])
    } catch (error) {
      console.error('Failed to load available carriers', error)
    }
  }

  const handleSelectCarrier = (carrierCode: string) => {
    const carrier = availableCarriers.find(c => c.code === carrierCode)
    if (carrier) {
      setFormData({
        ...formData,
        carrier_code: carrierCode,
        name: carrier.name,
      })
    }
  }

  const handleSubmit = async () => {
    try {
      const payload = {
        ...formData,
        settings: {
          ...formData.settings,
          shipping_cost_override: formData.settings.shipping_cost_override 
            ? parseFloat(formData.settings.shipping_cost_override) 
            : null,
        },
      }
      
      await api.post('/admin/fulfillment/providers', payload)
      
      toast({
        title: 'Success',
        description: 'Fulfillment provider created successfully',
      })
      
      setDialogOpen(false)
      resetForm()
      loadProviders()
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create provider',
        variant: 'destructive',
      })
    }
  }

  const handleToggleProvider = async (provider: FulfillmentProvider) => {
    try {
      await api.post(`/admin/fulfillment/providers/${provider.id}/toggle`)
      loadProviders()
      toast({
        title: 'Success',
        description: `Provider ${provider.is_active ? 'deactivated' : 'activated'} successfully`,
      })
    } catch (error) {
      toast({
        title: 'Error',
        description: 'Failed to update provider',
        variant: 'destructive',
      })
    }
  }

  const handleDeleteProvider = async (provider: FulfillmentProvider) => {
    if (!confirm(`Are you sure you want to delete ${provider.name}?`)) return
    
    try {
      await api.delete(`/admin/fulfillment/providers/${provider.id}`)
      loadProviders()
      toast({
        title: 'Success',
        description: 'Provider deleted successfully',
      })
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete provider',
        variant: 'destructive',
      })
    }
  }

  const handleTestConnection = async (providerId: string) => {
    setTestingConnection(providerId)
    try {
      const response = await api.post(`/admin/fulfillment/providers/${providerId}/test`)
      toast({
        title: response.data.success ? 'Success' : 'Failed',
        description: response.data.message,
      })
    } catch (error) {
      toast({
        title: 'Error',
        description: 'Failed to test connection',
        variant: 'destructive',
      })
    } finally {
      setTestingConnection(null)
    }
  }

  const resetForm = () => {
    setFormData({
      name: '',
      carrier_code: '',
      is_active: true,
      is_default: false,
      config: {
        api_key: '',
        api_secret: '',
        account_number: '',
        customer_code: '',
      },
      settings: {
        default_service: '',
        label_format: 'pdf',
        shipping_cost_override: '',
      },
      sort_order: 0,
    })
    setSelectedCarrier('')
  }

  const getCarrierLogo = (code: string) => {
    const logos: Record<string, string> = {
      dhl: 'solar:delivery-bold-duotone',
      fedex: 'solar:plain-2-bold-duotone',
      aramex: 'solar:box-bold-duotone',
      gdex: 'solar:delivery-bold-duotone',
    }
    return logos[code] || 'solar:box-bold-duotone'
  }

  const getCarrierTile = (code: string) => {
    const tiles: Record<string, string> = {
      dhl: 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
      fedex: 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
      aramex: 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
      gdex: 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
    }
    return tiles[code] || 'bg-slate-100 text-slate-700 dark:bg-slate-800/70 dark:text-slate-300'
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Fulfillment Settings</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Configure shipping providers for your store.
          </p>
        </div>
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button onClick={resetForm}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Add Provider
            </Button>
          </DialogTrigger>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>Add Fulfillment Provider</DialogTitle>
              <DialogDescription>Configure a new shipping carrier for your store</DialogDescription>
            </DialogHeader>
            
            <div className="space-y-4">
              <div>
                <Label>Select Carrier</Label>
                <Select value={selectedCarrier} onValueChange={(val) => {
                  setSelectedCarrier(val)
                  handleSelectCarrier(val)
                }}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select a carrier" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableCarriers.map((carrier) => (
                      <SelectItem key={carrier.code} value={carrier.code}>
                        <div className="flex items-center gap-2">
                          <Icon icon={getCarrierLogo(carrier.code)} className="h-4 w-4" />
                          {carrier.name}
                          {carrier.simulated && (
                            <span className="text-xs text-destructive">(sandbox)</span>
                          )}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {selectedCarrier && (
                <>
                  <div>
                    <Label>Display Name</Label>
                    <Input 
                      value={formData.name}
                      onChange={(e) => setFormData({...formData, name: e.target.value})}
                      placeholder="e.g., DHL Express Malaysia"
                    />
                  </div>

                  <div className="space-y-4">
                    <div>
                      <Label>API Key</Label>
                      <Input 
                        type="password"
                        value={formData.config.api_key}
                        onChange={(e) => setFormData({
                          ...formData, 
                          config: {...formData.config, api_key: e.target.value}
                        })}
                        placeholder="Enter API key"
                      />
                    </div>
                    {selectedCarrier === 'dhl' && (
                      <div>
                        <Label>API Secret</Label>
                        <Input 
                          type="password"
                          value={formData.config.api_secret || ''}
                          onChange={(e) => setFormData({
                            ...formData, 
                            config: {...formData.config, api_secret: e.target.value}
                          })}
                          placeholder="Enter API secret"
                        />
                      </div>
                    )}
                    {selectedCarrier === 'fedex' && (
                      <>
                        <div>
                          <Label>API Secret</Label>
                          <Input 
                            type="password"
                            value={formData.config.api_secret || ''}
                            onChange={(e) => setFormData({
                              ...formData, 
                              config: {...formData.config, api_secret: e.target.value}
                            })}
                            placeholder="Enter API secret"
                          />
                        </div>
                        <div>
                          <Label>Account Number</Label>
                          <Input 
                            value={formData.config.account_number || ''}
                            onChange={(e) => setFormData({
                              ...formData, 
                              config: {...formData.config, account_number: e.target.value}
                            })}
                            placeholder="FedEx account number"
                          />
                        </div>
                      </>
                    )}
                    {selectedCarrier === 'aramex' && (
                      <div>
                        <Label>Customer Code</Label>
                        <Input 
                          value={formData.config.customer_code || ''}
                          onChange={(e) => setFormData({
                            ...formData, 
                            config: {...formData.config, customer_code: e.target.value}
                          })}
                          placeholder="Aramex customer code"
                        />
                      </div>
                    )}
                    {selectedCarrier === 'gdex' && (
                      <div>
                        <Label>Customer Code</Label>
                        <Input 
                          value={formData.config.customer_code || ''}
                          onChange={(e) => setFormData({
                            ...formData, 
                            config: {...formData.config, customer_code: e.target.value}
                          })}
                          placeholder="GDEX customer code"
                        />
                      </div>
                    )}

                  </div>
                  <div>
                    <Label>Default Service</Label>
                    <Select 
                      value={formData.settings.default_service} 
                      onValueChange={(val) => setFormData({
                        ...formData,
                        settings: {...formData.settings, default_service: val}
                      })}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select default service" />
                      </SelectTrigger>
                      <SelectContent>
                        {availableCarriers.find(c => c.code === selectedCarrier)?.services.map((service) => (
                          <SelectItem key={service} value={service}>{service}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="flex items-center space-x-2">
                    <Switch 
                      checked={formData.is_default}
                      onCheckedChange={(checked) => setFormData({...formData, is_default: checked})}
                    />
                    <Label>Set as default provider</Label>
                  </div>

                  <div className="flex items-center space-x-2">
                    <Switch 
                      checked={formData.is_active}
                      onCheckedChange={(checked) => setFormData({...formData, is_active: checked})}
                    />
                    <Label>Active</Label>
                  </div>
                </>
              )}
            </div>

            <div className="flex justify-end gap-2 mt-4">
              <Button variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button onClick={handleSubmit} disabled={!selectedCarrier}>Create Provider</Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-16">
          <Icon icon="eos-icons:loading" className="h-8 w-8 animate-spin text-primary" />
        </div>
      ) : providers.length === 0 ? (
        <Card className="overflow-hidden">
          <CardContent className="flex flex-col items-center justify-center py-16">
            <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
              <Icon icon="solar:delivery-bold-duotone" className="h-8 w-8 text-primary" />
            </div>
            <h3 className="text-base font-semibold">No fulfillment providers yet</h3>
            <p className="mb-5 mt-1 max-w-sm text-center text-sm text-muted-foreground">
              Connect a carrier to quote rates and print labels for your deliveries.
            </p>
            <Button onClick={() => setDialogOpen(true)}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Add Your First Provider
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {providers.map((provider) => (
            <Card
              key={provider.id}
              className={cn(
                'overflow-hidden transition-all hover:shadow-md',
                !provider.is_active && 'opacity-60 hover:opacity-100',
              )}
            >
              <CardHeader className="border-b bg-muted/20 pb-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <div
                      className={cn(
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        getCarrierTile(provider.carrier_code),
                      )}
                    >
                      <Icon icon={getCarrierLogo(provider.carrier_code)} className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <CardTitle className="truncate text-base">{provider.name}</CardTitle>
                      <p className="font-mono text-[11px] uppercase tracking-wide text-muted-foreground">
                        {provider.carrier_code}
                      </p>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-1">
                    {provider.is_default && (
                      <Badge variant="outline" className="border-primary/40 text-[10px] text-primary">
                        Default
                      </Badge>
                    )}
                    <span
                      className={cn(
                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                        provider.is_active
                          ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                          : 'bg-muted text-muted-foreground',
                      )}
                    >
                      <span
                        className={cn(
                          'h-1.5 w-1.5 rounded-full',
                          provider.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/60',
                        )}
                      />
                      {provider.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-3 p-4">
                <div className="space-y-2">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Sort order</span>
                    <span className="font-medium tabular-nums">{provider.sort_order}</span>
                  </div>
                  {provider.settings?.default_service && (
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Default service</span>
                      <span className="font-mono text-xs font-medium">{provider.settings.default_service}</span>
                    </div>
                  )}
                </div>

                <div className="flex gap-2 border-t pt-3">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => handleTestConnection(provider.id)}
                    disabled={testingConnection === provider.id}
                  >
                    {testingConnection === provider.id ? (
                      <Icon icon="eos-icons:loading" className="mr-1.5 h-4 w-4" />
                    ) : (
                      <Icon icon="solar:test-tube-bold-duotone" className="mr-1.5 h-4 w-4" />
                    )}
                    Test
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => handleToggleProvider(provider)}
                  >
                    <Icon
                      icon={provider.is_active ? 'solar:pause-circle-bold-duotone' : 'solar:play-circle-bold-duotone'}
                      className="mr-1.5 h-4 w-4"
                    />
                    {provider.is_active ? 'Disable' : 'Enable'}
                  </Button>
                  <Button
                    variant="outline"
                    size="icon"
                    className="h-9 w-9 shrink-0 text-destructive hover:text-destructive"
                    onClick={() => handleDeleteProvider(provider)}
                    title="Remove provider"
                  >
                    <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Card className="overflow-hidden">
        <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
            <Icon icon="solar:layers-minimalistic-bold-duotone" className="h-5 w-5 text-primary" />
          </div>
          <div>
            <h3 className="text-base font-semibold leading-none">Available Carriers</h3>
            <p className="mt-1 text-xs text-muted-foreground">
              Carriers supported by the platform — connect one to start shipping.
            </p>
          </div>
        </div>
        <CardContent className="p-6">
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {availableCarriers.map((carrier) => (
              <div
                key={carrier.code}
                className="group flex flex-col rounded-xl border p-4 transition-all hover:-translate-y-0.5 hover:shadow-md"
              >
                <div className="flex items-start justify-between gap-3">
                  <div
                    className={cn(
                      'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition-transform group-hover:scale-105',
                      getCarrierTile(carrier.code),
                    )}
                  >
                    <Icon icon={getCarrierLogo(carrier.code)} className="h-6 w-6" />
                  </div>
                  {carrier.simulated && (
                    <span className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">
                      Sandbox
                    </span>
                  )}
                </div>

                <p className="mt-3 text-sm font-semibold leading-tight">{carrier.name}</p>
                <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                  {carrier.description}
                </p>

                <div className="mt-auto flex flex-wrap gap-1 pt-3">
                  {carrier.supports_domestic && (
                    <span className="rounded-full border px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                      Domestic
                    </span>
                  )}
                  {carrier.supports_international && (
                    <span className="rounded-full border px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                      International
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}