import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface OfflinePaymentMethod {
  id: number
  method_name: string
  method_code: string
  instructions: string | null
  account_details: Record<string, string> | null
  is_active: boolean
  display_order: number
}

export function OfflinePaymentMethodsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingMethod, setEditingMethod] = useState<OfflinePaymentMethod | null>(null)
  const [formData, setFormData] = useState({
    method_name: '',
    instructions: '',
    account_details: '',
    is_active: true,
    display_order: 0,
  })

  const { data: methods, isLoading } = useQuery({
    queryKey: ['offline-payment-methods'],
    queryFn: async () => {
      const response = await api.get('/offline-payment-methods')
      return response.data as OfflinePaymentMethod[]
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      const response = await api.post('/offline-payment-methods', {
        ...data,
        account_details: data.account_details ? JSON.parse(data.account_details) : null,
      })
      return response.data
    },
    onSuccess: () => {
      toast({ title: 'Payment method created' })
      queryClient.invalidateQueries({ queryKey: ['offline-payment-methods'] })
      setDialogOpen(false)
      resetForm()
    },
    onError: (error: any) => {
      toast({
        title: 'Failed to create',
        description: error.response?.data?.message,
        variant: 'destructive',
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: typeof formData }) => {
      const response = await api.put(`/offline-payment-methods/${id}`, {
        ...data,
        account_details: data.account_details ? JSON.parse(data.account_details) : null,
      })
      return response.data
    },
    onSuccess: () => {
      toast({ title: 'Payment method updated' })
      queryClient.invalidateQueries({ queryKey: ['offline-payment-methods'] })
      setDialogOpen(false)
      setEditingMethod(null)
      resetForm()
    },
    onError: (error: any) => {
      toast({
        title: 'Failed to update',
        description: error.response?.data?.message,
        variant: 'destructive',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/offline-payment-methods/${id}`)
    },
    onSuccess: () => {
      toast({ title: 'Payment method deleted' })
      queryClient.invalidateQueries({ queryKey: ['offline-payment-methods'] })
    },
    onError: () => {
      toast({ title: 'Failed to delete', variant: 'destructive' })
    },
  })

  const resetForm = () => {
    setFormData({
      method_name: '',
      instructions: '',
      account_details: '',
      is_active: true,
      display_order: 0,
    })
  }

  const handleEdit = (method: OfflinePaymentMethod) => {
    setEditingMethod(method)
    setFormData({
      method_name: method.method_name,
      instructions: method.instructions || '',
      account_details: method.account_details ? JSON.stringify(method.account_details, null, 2) : '',
      is_active: method.is_active,
      display_order: method.display_order,
    })
    setDialogOpen(true)
  }

  const handleSubmit = () => {
    if (editingMethod) {
      updateMutation.mutate({ id: editingMethod.id, data: formData })
    } else {
      createMutation.mutate(formData)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Offline Payment Methods</h1>
          <p className="text-muted-foreground">
            Configure bank transfers and other offline payment options for POS
          </p>
        </div>
        <Button onClick={() => { resetForm(); setEditingMethod(null); setDialogOpen(true); }}>
          <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
          Add Method
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {methods?.map((method) => (
          <Card key={method.id}>
            <CardContent className="p-6">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-lg bg-primary/10 flex items-center justify-center">
                    <Icon icon="mdi:bank" className="h-5 w-5 text-primary" />
                  </div>
                  <div>
                    <h3 className="font-medium">{method.method_name}</h3>
                    <p className="text-sm text-muted-foreground">{method.method_code}</p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {method.is_active ? (
                    <span className="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">Active</span>
                  ) : (
                    <span className="text-xs bg-gray-100 text-gray-700 px-2 py-1 rounded-full">Inactive</span>
                  )}
                </div>
              </div>

              {method.instructions && (
                <div className="mt-4 text-sm text-muted-foreground">
                  <p className="line-clamp-2">{method.instructions}</p>
                </div>
              )}

              {method.account_details && (
                <div className="mt-4 bg-muted p-3 rounded-lg text-sm">
                  {Object.entries(method.account_details).map(([key, value]) => (
                    <div key={key} className="flex justify-between">
                      <span className="text-muted-foreground capitalize">{key.replace('_', ' ')}:</span>
                      <span className="font-medium">{value}</span>
                    </div>
                  ))}
                </div>
              )}

              <div className="mt-4 flex gap-2">
                <Button variant="outline" size="sm" onClick={() => handleEdit(method)}>
                  Edit
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="text-destructive hover:text-destructive"
                  onClick={() => deleteMutation.mutate(method.id)}
                >
                  Delete
                </Button>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>{editingMethod ? 'Edit' : 'Add'} Payment Method</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label>Method Name *</Label>
              <Input
                placeholder="e.g., Bank Transfer - Maybank"
                value={formData.method_name}
                onChange={(e) => setFormData({ ...formData, method_name: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Instructions for Customer</Label>
              <Textarea
                placeholder="e.g., Please transfer to the following account and enter the reference number..."
                value={formData.instructions}
                onChange={(e) => setFormData({ ...formData, instructions: e.target.value })}
              />
            </div>

            <div className="space-y-2">
              <Label>Account Details (JSON)</Label>
              <Textarea
                placeholder='{"bank_name": "Maybank", "account_number": "1234567890", "account_name": "ABC Sdn Bhd"}'
                value={formData.account_details}
                onChange={(e) => setFormData({ ...formData, account_details: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                Enter account details as JSON. These will be shown to customers during payment.
              </p>
            </div>

            <div className="space-y-2">
              <Label>Display Order</Label>
              <Input
                type="number"
                value={formData.display_order}
                onChange={(e) => setFormData({ ...formData, display_order: parseInt(e.target.value) || 0 })}
              />
            </div>

            <div className="flex items-center gap-2">
              <Switch
                checked={formData.is_active}
                onCheckedChange={(checked) => setFormData({ ...formData, is_active: checked })}
              />
              <Label>Active</Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleSubmit} disabled={!formData.method_name}>
              {editingMethod ? 'Update' : 'Create'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
