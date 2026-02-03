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

interface AccountDetailField {
  key: string
  label: string
  value: string
}

export function OfflinePaymentMethodsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingMethod, setEditingMethod] = useState<OfflinePaymentMethod | null>(null)
  
  // Form state
  const [methodName, setMethodName] = useState('')
  const [instructions, setInstructions] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [displayOrder, setDisplayOrder] = useState(0)
  
  // Account details fields
  const [accountFields, setAccountFields] = useState<AccountDetailField[]>([
    { key: 'bank_name', label: 'Bank Name', value: '' },
    { key: 'account_name', label: 'Account Name', value: '' },
    { key: 'account_number', label: 'Account Number', value: '' },
    { key: 'branch', label: 'Branch (Optional)', value: '' },
    { key: 'swift_code', label: 'SWIFT Code (Optional)', value: '' },
  ])

  const { data: methods, isLoading } = useQuery({
    queryKey: ['offline-payment-methods'],
    queryFn: async () => {
      const response = await api.get('/offline-payment-methods')
      return response.data as OfflinePaymentMethod[]
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/offline-payment-methods', data)
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
        description: error.response?.data?.message || error.message,
        variant: 'destructive',
      })
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: any }) => {
      const response = await api.put(`/offline-payment-methods/${id}`, data)
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
        description: error.response?.data?.message || error.message,
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
    setMethodName('')
    setInstructions('')
    setIsActive(true)
    setDisplayOrder(0)
    setAccountFields([
      { key: 'bank_name', label: 'Bank Name', value: '' },
      { key: 'account_name', label: 'Account Name', value: '' },
      { key: 'account_number', label: 'Account Number', value: '' },
      { key: 'branch', label: 'Branch (Optional)', value: '' },
      { key: 'swift_code', label: 'SWIFT Code (Optional)', value: '' },
    ])
  }

  const handleEdit = (method: OfflinePaymentMethod) => {
    setEditingMethod(method)
    setMethodName(method.method_name)
    setInstructions(method.instructions || '')
    setIsActive(method.is_active)
    setDisplayOrder(method.display_order)
    
    // Populate account fields from existing data
    const details = method.account_details || {}
    setAccountFields([
      { key: 'bank_name', label: 'Bank Name', value: details.bank_name || '' },
      { key: 'account_name', label: 'Account Name', value: details.account_name || '' },
      { key: 'account_number', label: 'Account Number', value: details.account_number || '' },
      { key: 'branch', label: 'Branch (Optional)', value: details.branch || '' },
      { key: 'swift_code', label: 'SWIFT Code (Optional)', value: details.swift_code || '' },
    ])
    
    setDialogOpen(true)
  }

  const updateAccountField = (index: number, value: string) => {
    const updated = [...accountFields]
    updated[index].value = value
    setAccountFields(updated)
  }

  const handleSubmit = () => {
    if (!methodName.trim()) {
      toast({ title: 'Method name is required', variant: 'destructive' })
      return
    }

    // Build account_details object from fields
    const accountDetails: Record<string, string> = {}
    accountFields.forEach(field => {
      if (field.value.trim()) {
        accountDetails[field.key] = field.value.trim()
      }
    })

    const payload = {
      method_name: methodName.trim(),
      instructions: instructions.trim() || null,
      account_details: Object.keys(accountDetails).length > 0 ? accountDetails : null,
      is_active: isActive,
      display_order: displayOrder,
    }

    if (editingMethod) {
      updateMutation.mutate({ id: editingMethod.id, data: payload })
    } else {
      createMutation.mutate(payload)
    }
  }

  const openAddDialog = () => {
    resetForm()
    setEditingMethod(null)
    setDialogOpen(true)
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
        <Button onClick={openAddDialog}>
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
                <div className="mt-4 bg-muted p-3 rounded-lg text-sm space-y-1">
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

      {methods?.length === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Icon icon="mdi:bank-outline" className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No offline payment methods</h3>
            <p className="text-sm text-muted-foreground mb-4">
              Add bank transfer or other offline payment options for your customers.
            </p>
            <Button onClick={openAddDialog}>
              <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
              Add Your First Method
            </Button>
          </CardContent>
        </Card>
      )}

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="sm:max-w-lg max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingMethod ? 'Edit' : 'Add'} Offline Payment Method</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="method-name">Method Name *</Label>
              <Input
                id="method-name"
                placeholder="e.g., Bank Transfer - Maybank"
                value={methodName}
                onChange={(e) => setMethodName(e.target.value)}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="instructions">Instructions for Customer</Label>
              <Textarea
                id="instructions"
                placeholder="e.g., Please transfer to the following account and enter the reference number..."
                value={instructions}
                onChange={(e) => setInstructions(e.target.value)}
                rows={3}
              />
              <p className="text-xs text-muted-foreground">
                These instructions will be shown to customers during checkout.
              </p>
            </div>

            <div className="border rounded-lg p-4 space-y-4">
              <h4 className="font-medium text-sm">Account Details</h4>
              
              {accountFields.map((field, index) => (
                <div key={field.key} className="space-y-2">
                  <Label htmlFor={`field-${field.key}`}>{field.label}</Label>
                  <Input
                    id={`field-${field.key}`}
                    placeholder={field.label.includes('Optional') ? 'Optional' : `Enter ${field.label.toLowerCase()}`}
                    value={field.value}
                    onChange={(e) => updateAccountField(index, e.target.value)}
                  />
                </div>
              ))}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="display-order">Display Order</Label>
                <Input
                  id="display-order"
                  type="number"
                  value={displayOrder}
                  onChange={(e) => setDisplayOrder(parseInt(e.target.value) || 0)}
                />
              </div>
              
              <div className="flex items-center gap-2 pt-8">
                <Switch
                  id="is-active"
                  checked={isActive}
                  onCheckedChange={setIsActive}
                />
                <Label htmlFor="is-active">Active</Label>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={handleSubmit} 
              disabled={!methodName.trim() || createMutation.isPending || updateMutation.isPending}
            >
              {createMutation.isPending || updateMutation.isPending ? (
                <Icon icon="svg-spinners:180-ring-with-bg" className="h-4 w-4 mr-2" />
              ) : null}
              {editingMethod ? 'Update' : 'Create'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
