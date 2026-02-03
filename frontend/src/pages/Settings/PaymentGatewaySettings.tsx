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

interface OfflinePaymentMethod {
  id: number
  method_name: string
  method_code: string
  instructions: string | null
  account_details: Record<string, string> | null
  is_active: boolean
  display_order: number
}

export function PaymentGatewaySettings() {
  const { toast } = useToast()
  const [gateways, setGateways] = useState<PaymentGateway[]>([])
  const [availableGateways, setAvailableGateways] = useState<AvailableGateway[]>([])
  const [offlineMethods, setOfflineMethods] = useState<OfflinePaymentMethod[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingOffline, setLoadingOffline] = useState(true)
  
  // Online gateway dialog state
  const [dialogOpen, setDialogOpen] = useState(false)
  const [selectedGateway, setSelectedGateway] = useState<string>('')
  const [formData, setFormData] = useState<any>({})
  const [testingConnection, setTestingConnection] = useState<number | null>(null)
  
  // Offline method dialog state
  const [offlineDialogOpen, setOfflineDialogOpen] = useState(false)
  const [editingOfflineMethod, setEditingOfflineMethod] = useState<OfflinePaymentMethod | null>(null)
  const [offlineFormData, setOfflineFormData] = useState({
    method_name: '',
    instructions: '',
    bank_name: '',
    account_name: '',
    account_number: '',
    branch: '',
    swift_code: '',
    is_active: true,
    display_order: 0,
  })

  useEffect(() => {
    loadGateways()
    loadAvailableGateways()
    loadOfflineMethods()
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

  const loadOfflineMethods = async () => {
    try {
      const response = await api.get('/offline-payment-methods')
      setOfflineMethods(response.data)
    } catch (error) {
      console.error('Failed to load offline payment methods', error)
    } finally {
      setLoadingOffline(false)
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

  const handleAddOfflineMethod = async () => {
    try {
      // Build account_details object from individual fields
      const accountDetails: Record<string, string> = {}
      if (offlineFormData.bank_name) accountDetails.bank_name = offlineFormData.bank_name
      if (offlineFormData.account_name) accountDetails.account_name = offlineFormData.account_name
      if (offlineFormData.account_number) accountDetails.account_number = offlineFormData.account_number
      if (offlineFormData.branch) accountDetails.branch = offlineFormData.branch
      if (offlineFormData.swift_code) accountDetails.swift_code = offlineFormData.swift_code

      const payload = {
        method_name: offlineFormData.method_name,
        instructions: offlineFormData.instructions,
        account_details: Object.keys(accountDetails).length > 0 ? accountDetails : null,
        is_active: offlineFormData.is_active,
        display_order: offlineFormData.display_order,
      }

      if (editingOfflineMethod) {
        await api.put(`/offline-payment-methods/${editingOfflineMethod.id}`, payload)
        toast({ title: 'Payment method updated' })
      } else {
        await api.post('/offline-payment-methods', payload)
        toast({ title: 'Payment method added' })
      }
      
      setOfflineDialogOpen(false)
      setEditingOfflineMethod(null)
      resetOfflineForm()
      loadOfflineMethods()
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to save',
        description: error.response?.data?.message || 'An error occurred',
      })
    }
  }

  const handleDeleteOfflineMethod = async (id: number) => {
    if (!confirm('Are you sure you want to delete this payment method?')) return

    try {
      await api.delete(`/offline-payment-methods/${id}`)
      toast({ title: 'Payment method deleted' })
      loadOfflineMethods()
    } catch (error: any) {
      toast({
        variant: 'destructive',
        title: 'Failed to delete',
        description: error.response?.data?.message || 'An error occurred',
      })
    }
  }

  const resetOfflineForm = () => {
    setOfflineFormData({
      method_name: '',
      instructions: '',
      bank_name: '',
      account_name: '',
      account_number: '',
      branch: '',
      swift_code: '',
      is_active: true,
      display_order: 0,
    })
  }

  const openEditOfflineMethod = (method: OfflinePaymentMethod) => {
    setEditingOfflineMethod(method)
    setOfflineFormData({
      method_name: method.method_name,
      instructions: method.instructions || '',
      bank_name: method.account_details?.bank_name || '',
      account_name: method.account_details?.account_name || '',
      account_number: method.account_details?.account_number || '',
      branch: method.account_details?.branch || '',
      swift_code: method.account_details?.swift_code || '',
      is_active: method.is_active,
      display_order: method.display_order,
    })
    setOfflineDialogOpen(true)
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
      paypal: 'simple-icons:paypal',
      hitpay: 'material-symbols:payment',
    }
    return icons[name] || 'material-symbols:payment'
  }

  const selectedGatewayConfig = availableGateways.find(g => g.key === selectedGateway)

  return (
    <Tabs defaultValue="online" className="space-y-6">
      <TabsList>
        <TabsTrigger value="online">Online Gateways</TabsTrigger>
        <TabsTrigger value="offline">Offline Methods</TabsTrigger>
      </TabsList>

      <TabsContent value="online" className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-medium">Online Payment Gateways</h3>
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
      </TabsContent>

      <TabsContent value="offline" className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-lg font-medium">Offline Payment Methods</h3>
            <p className="text-sm text-muted-foreground">
              Configure bank transfers, cash on delivery, and other offline payment options.
            </p>
          </div>
          
          <Dialog open={offlineDialogOpen} onOpenChange={setOfflineDialogOpen}>
            <DialogTrigger asChild>
              <Button onClick={() => { resetOfflineForm(); setEditingOfflineMethod(null); }}>
                <Icon icon="material-symbols:add" className="mr-2 h-4 w-4" />
                Add Method
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[600px]">
              <DialogHeader>
                <DialogTitle>{editingOfflineMethod ? 'Edit' : 'Add'} Offline Payment Method</DialogTitle>
                <DialogDescription>
                  Configure a bank transfer or other offline payment method for POS.
                </DialogDescription>
              </DialogHeader>
              
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>Method Name *</Label>
                  <Input
                    placeholder="e.g., Bank Transfer - Maybank"
                    value={offlineFormData.method_name}
                    onChange={(e) => setOfflineFormData({ ...offlineFormData, method_name: e.target.value })}
                  />
                </div>

                <div className="space-y-2">
                  <Label>Instructions for Customer</Label>
                  <Textarea
                    placeholder="e.g., Please transfer to the following account and enter the reference number..."
                    value={offlineFormData.instructions}
                    onChange={(e) => setOfflineFormData({ ...offlineFormData, instructions: e.target.value })}
                    rows={3}
                  />
                  <p className="text-xs text-muted-foreground">
                    These instructions will be shown to customers during checkout.
                  </p>
                </div>

                <div className="space-y-3 border rounded-lg p-4 bg-muted/50">
                  <Label className="font-semibold">Account Details</Label>
                  
                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label className="text-sm">Bank Name</Label>
                      <Input
                        placeholder="e.g., Maybank"
                        value={offlineFormData.bank_name}
                        onChange={(e) => setOfflineFormData({ ...offlineFormData, bank_name: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label className="text-sm">Account Name</Label>
                      <Input
                        placeholder="e.g., ABC Sdn Bhd"
                        value={offlineFormData.account_name}
                        onChange={(e) => setOfflineFormData({ ...offlineFormData, account_name: e.target.value })}
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm">Account Number</Label>
                    <Input
                      placeholder="e.g., 1234567890"
                      value={offlineFormData.account_number}
                      onChange={(e) => setOfflineFormData({ ...offlineFormData, account_number: e.target.value })}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label className="text-sm">Branch (Optional)</Label>
                      <Input
                        placeholder="e.g., Kuala Lumpur Main"
                        value={offlineFormData.branch}
                        onChange={(e) => setOfflineFormData({ ...offlineFormData, branch: e.target.value })}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label className="text-sm">SWIFT Code (Optional)</Label>
                      <Input
                        placeholder="e.g., MBBEMYKL"
                        value={offlineFormData.swift_code}
                        onChange={(e) => setOfflineFormData({ ...offlineFormData, swift_code: e.target.value })}
                      />
                    </div>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>Display Order</Label>
                    <Input
                      type="number"
                      value={offlineFormData.display_order}
                      onChange={(e) => setOfflineFormData({ ...offlineFormData, display_order: parseInt(e.target.value) || 0 })}
                    />
                  </div>
                  
                  <div className="flex items-center gap-2 pt-8">
                    <Switch
                      checked={offlineFormData.is_active}
                      onCheckedChange={(checked) => setOfflineFormData({ ...offlineFormData, is_active: checked })}
                    />
                    <Label>Active</Label>
                  </div>
                </div>

                <Button 
                  onClick={handleAddOfflineMethod} 
                  className="w-full"
                  disabled={!offlineFormData.method_name}
                >
                  {editingOfflineMethod ? 'Update Method' : 'Add Method'}
                </Button>
              </div>
            </DialogContent>
          </Dialog>
        </div>

        {loadingOffline ? (
          <div className="flex items-center justify-center py-12">
            <Icon icon="svg-spinners:180-ring-with-bg" className="h-8 w-8" />
          </div>
        ) : offlineMethods.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-12">
              <Icon icon="mdi:bank-outline" className="h-12 w-12 text-muted-foreground mb-4" />
              <h3 className="text-lg font-semibold mb-2">No offline payment methods</h3>
              <p className="text-sm text-muted-foreground mb-4">
                Add bank transfer or other offline payment options for your customers.
              </p>
              <Button onClick={() => setOfflineDialogOpen(true)}>
                <Icon icon="material-symbols:add" className="mr-2 h-4 w-4" />
                Add Your First Method
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 md:grid-cols-2">
            {offlineMethods.map((method) => (
              <Card key={method.id}>
                <CardHeader>
                  <div className="flex items-start justify-between">
                    <div className="flex items-center gap-3">
                      <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                        <Icon icon="mdi:bank" className="h-5 w-5 text-primary" />
                      </div>
                      <div>
                        <CardTitle className="text-base">{method.method_name}</CardTitle>
                        <Badge variant={method.is_active ? 'default' : 'secondary'} className="mt-1">
                          {method.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                      </div>
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="space-y-3">
                  {method.instructions && (
                    <p className="text-sm text-muted-foreground line-clamp-2">
                      {method.instructions}
                    </p>
                  )}
                  
                  {method.account_details && (
                    <div className="bg-muted p-3 rounded-lg text-sm space-y-1">
                      {Object.entries(method.account_details).map(([key, value]) => (
                        <div key={key} className="flex justify-between">
                          <span className="text-muted-foreground capitalize">
                            {key.replace(/_/g, ' ')}:
                          </span>
                          <span className="font-medium">{value}</span>
                        </div>
                      ))}
                    </div>
                  )}

                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => openEditOfflineMethod(method)}
                    >
                      <Icon icon="material-symbols:edit" className="mr-2 h-4 w-4" />
                      Edit
                    </Button>

                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handleDeleteOfflineMethod(method.id)}
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
      </TabsContent>
    </Tabs>
  )
}
