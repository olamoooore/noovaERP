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
import api from '@/lib/api'

interface PaymentGateway {
  id: number
  gateway_name: string
  is_active: boolean
  is_test_mode: boolean
  created_at: string
}

interface AvailableGateway {
  key: string
  name: string
  description: string
  logo: string
  fields: {
    [key: string]: {
      label: string
      type: string
      required: boolean
      help: string
    }
  }
}

export function PaymentGatewaySettings() {
  const { toast } = useToast()
  const [gateways, setGateways] = useState<PaymentGateway[]>([])
  const [availableGateways, setAvailableGateways] = useState<AvailableGateway[]>([])
  const [loading, setLoading] = useState(true)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedGateway, setSelectedGateway] = useState<string>('')
  const [formData, setFormData] = useState<any>({})
  const [testingConnection, setTestingConnection] = useState<number | null>(null)

  useEffect(() => {
    loadGateways()
    loadAvailableGateways()
  }, [])

  const loadGateways = async () => {
    try {
      const response = await api.get('/payment-gateways')
      setGateways(response.data)
    } catch (error) {
      console.error('Failed to load payment gateways', error)
    } finally {
      setLoading(false)
    }
  }

  const loadAvailableGateways = async () => {
    try {
      const response = await api.get('/payment-gateways/available')
      setAvailableGateways(response.data)
    } catch (error) {
      console.error('Failed to load available gateways', error)
    }
  }

  const handleAddGateway = async () => {
    try {
      const payload = {
        gateway_name: selectedGateway,
        ...formData,
        is_active: formData.is_active ?? false,
        is_test_mode: formData.is_test_mode ?? true,
      }

      await api.post('/payment-gateways', payload)
      
      toast({
        title: 'Gateway added',
        description: 'Payment gateway has been configured successfully.',
      })
      
      setDialogOpen(false)
      setSelectedGateway('')
      setFormData({})
      loadGateways()
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to add gateway',
        description: error.response?.data?.message || 'An error occurred',
      })
    }
  }

  const handleTestConnection = async (gateway: PaymentGateway) => {
    setTestingConnection(gateway.id)
    try {
      await api.post(`/payment-gateways/${gateway.id}/test`)
      toast({
        title: 'Connection successful',
        description: 'Gateway is properly configured.',
      })
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Connection failed',
        description: 'Unable to connect to payment gateway. Check your credentials.',
      })
    } finally {
      setTestingConnection(null)
    }
  }

  const handleToggleActive = async (gateway: PaymentGateway) => {
    try {
      await api.post(`/payment-gateways/${gateway.id}/toggle`)
      toast({
        title: gateway.is_active ? 'Gateway deactivated' : 'Gateway activated',
      })
      loadGateways()
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Failed to update gateway',
      })
    }
  }

  const handleDeleteGateway = async (gateway: PaymentGateway) => {
    if (!confirm('Are you sure you want to delete this payment gateway?')) return

    try {
      await api.delete(`/payment-gateways/${gateway.id}`)
      toast({
        title: 'Gateway deleted',
      })
      loadGateways()
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to delete gateway',
        description: error.response?.data?.message || 'An error occurred',
      })
    }
  }

  const getGatewayIcon = (name: string) => {
    const icons: { [key: string]: string } = {
      stripe: 'simple-icons:stripe',
      hitpay: 'material-symbols:payment',
    }
    return icons[name] || 'material-symbols:payment'
  }

  const selectedGatewayConfig = availableGateways.find(g => g.key === selectedGateway)

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-medium">Payment Gateways</h3>
          <p className="text-sm text-muted-foreground">
            Configure payment processors to accept online payments from customers.
          </p>
        </div>
        
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="material-symbols:add" className="mr-2 h-4 w-4" />
              Add Gateway
            </Button>
          </DialogTrigger>
          <DialogContent className="sm:max-w-[500px]">
            <DialogHeader>
              <DialogTitle>Add Payment Gateway</DialogTitle>
              <DialogDescription>
                Configure a new payment gateway to accept payments.
              </DialogDescription>
            </DialogHeader>
            
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>Payment Gateway</Label>
                <Select value={selectedGateway} onValueChange={setSelectedGateway}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select a gateway" />
                  </SelectTrigger>
                  <SelectContent>
                    {availableGateways.map((gateway) => (
                      <SelectItem key={gateway.key} value={gateway.key}>
                        <div className="flex items-center gap-2">
                          <Icon icon={getGatewayIcon(gateway.key)} className="h-4 w-4" />
                          {gateway.name}
                        </div>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {selectedGatewayConfig && (
                <>
                  <div className="rounded-lg bg-muted p-3 text-sm">
                    {selectedGatewayConfig.description}
                  </div>

                  {Object.entries(selectedGatewayConfig.fields).map(([key, field]) => (
                    <div key={key} className="space-y-2">
                      <Label>
                        {field.label}
                        {field.required && <span className="text-destructive">*</span>}
                      </Label>
                      <Input
                        type={field.type}
                        placeholder={field.help}
                        onChange={(e) => setFormData({ ...formData, [key]: e.target.value })}
                      />
                      {field.help && (
                        <p className="text-xs text-muted-foreground">{field.help}</p>
                      )}
                    </div>
                  ))}

                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label>Test Mode</Label>
                      <p className="text-xs text-muted-foreground">
                        Use test credentials for development
                      </p>
                    </div>
                    <Switch
                      checked={formData.is_test_mode ?? true}
                      onCheckedChange={(checked) => setFormData({ ...formData, is_test_mode: checked })}
                    />
                  </div>

                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label>Active</Label>
                      <p className="text-xs text-muted-foreground">
                        Enable this gateway for payments
                      </p>
                    </div>
                    <Switch
                      checked={formData.is_active ?? false}
                      onCheckedChange={(checked) => setFormData({ ...formData, is_active: checked })}
                    />
                  </div>

                  <Button onClick={handleAddGateway} className="w-full">
                    Add Gateway
                  </Button>
                </>
              )}
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-12">
          <Icon icon="svg-spinners:180-ring-with-bg" className="h-8 w-8" />
        </div>
      ) : gateways.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="material-symbols:payment-outline" className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No payment gateways configured</h3>
            <p className="text-sm text-muted-foreground mb-4">
              Add a payment gateway to start accepting online payments.
            </p>
            <Button onClick={() => setDialogOpen(true)}>
              <Icon icon="material-symbols:add" className="mr-2 h-4 w-4" />
              Add Your First Gateway
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {gateways.map((gateway) => (
            <Card key={gateway.id}>
              <CardHeader>
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                      <Icon icon={getGatewayIcon(gateway.gateway_name)} className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <CardTitle className="capitalize">{gateway.gateway_name}</CardTitle>
                      <div className="flex items-center gap-2 mt-1">
                        <Badge variant={gateway.is_active ? 'default' : 'secondary'}>
                          {gateway.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        {gateway.is_test_mode && (
                          <Badge variant="outline">Test Mode</Badge>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleTestConnection(gateway)}
                    disabled={testingConnection === gateway.id}
                  >
                    {testingConnection === gateway.id ? (
                      <Icon icon="svg-spinners:180-ring-with-bg" className="mr-2 h-4 w-4" />
                    ) : (
                      <Icon icon="material-symbols:wifi-tethering" className="mr-2 h-4 w-4" />
                    )}
                    Test Connection
                  </Button>
                  
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleToggleActive(gateway)}
                  >
                    <Icon 
                      icon={gateway.is_active ? 'material-symbols:pause' : 'material-symbols:play-arrow'} 
                      className="mr-2 h-4 w-4" 
                    />
                    {gateway.is_active ? 'Deactivate' : 'Activate'}
                  </Button>

                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleDeleteGateway(gateway)}
                    className="text-destructive hover:text-destructive"
                  >
                    <Icon icon="material-symbols:delete-outline" className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
