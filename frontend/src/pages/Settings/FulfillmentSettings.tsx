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
      dhl: 'mdi:truck-fast',
      fedex: 'mdi:airplane',
      aramex: 'mdi:truck-delivery',
      gdex: 'mdi:truck-fast',
    }
    return logos[code] || 'mdi:truck'
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold">Fulfillment Settings</h2>
          <p className="text-muted-foreground">Configure shipping providers for your store</p>
        </div>
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button onClick={resetForm}>
              <Icon icon="mdi:plus" className="mr-2" />
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
        <div className="flex items-center justify-center py-12">
          <Icon icon="mdi:loading" className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      ) : providers.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="mdi:truck-delivery-outline" className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No Fulfillment Providers</h3>
            <p className="text-muted-foreground text-center mb-4">
              Add shipping providers to enable delivery options for your store
            </p>
            <Button onClick={() => setDialogOpen(true)}>
              <Icon icon="mdi:plus" className="mr-2" />
              Add Your First Provider
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {providers.map((provider) => (
            <Card key={provider.id} className={!provider.is_active ? 'opacity-60' : ''}>
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <Icon icon={getCarrierLogo(provider.carrier_code)} className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <CardTitle className="text-base">{provider.name}</CardTitle>
                      <p className="text-xs text-muted-foreground">{provider.carrier_code.toUpperCase()}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-1">
                    {provider.is_default && (
                      <Badge variant="secondary" className="text-xs">Default</Badge>
                    )}
                    <Badge variant={provider.is_active ? 'default' : 'secondary'} className="text-xs">
                      {provider.is_active ? 'Active' : 'Inactive'}
                    </Badge>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-3">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Sort Order</span>
                    <span className="font-medium">{provider.sort_order}</span>
                  </div>
                  {provider.settings?.default_service && (
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">Default Service</span>
                      <span className="font-medium">{provider.settings.default_service}</span>
                    </div>
                  )}
                  
                  <div className="flex gap-2 pt-2">
                    <Button 
                      variant="outline" 
                      size="sm" 
                      className="flex-1"
                      onClick={() => handleTestConnection(provider.id)}
                      disabled={testingConnection === provider.id}
                    >
                      {testingConnection === provider.id ? (
                        <Icon icon="mdi:loading" className="mr-1 h-4 w-4 animate-spin" />
                      ) : (
                        <Icon icon="mdi:connection" className="mr-1" />
                      )}
                      Test
                    </Button>
                    <Button 
                      variant="outline" 
                      size="sm"
                      className="flex-1"
                      onClick={() => handleToggleProvider(provider)}
                    >
                      <Icon icon={provider.is_active ? 'mdi:pause' : 'mdi:play'} className="mr-1" />
                      {provider.is_active ? 'Disable' : 'Enable'}
                    </Button>
                    <Button 
                      variant="outline" 
                      size="sm"
                      className="text-red-500 hover:text-red-600"
                      onClick={() => handleDeleteProvider(provider)}
                    >
                      <Icon icon="mdi:delete" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Card className="mt-6">
        <CardHeader>
          <CardTitle className="text-lg">Available Carriers</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {availableCarriers.map((carrier) => (
              <div key={carrier.code} className="flex items-start gap-3 p-3 rounded-lg border">
                <Icon icon={getCarrierLogo(carrier.code)} className="h-6 w-6 text-primary mt-0.5" />
                <div>
                  <p className="font-medium">{carrier.name}</p>
                  <p className="text-xs text-muted-foreground">{carrier.description}</p>
                  <div className="flex gap-1 mt-2 flex-wrap">
                    {carrier.simulated && (
                      <Badge variant="destructive" className="text-xs">Sandbox — not connected</Badge>
                    )}
                    {carrier.supports_domestic && (
                      <Badge variant="outline" className="text-xs">Domestic</Badge>
                    )}
                    {carrier.supports_international && (
                      <Badge variant="outline" className="text-xs">International</Badge>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}