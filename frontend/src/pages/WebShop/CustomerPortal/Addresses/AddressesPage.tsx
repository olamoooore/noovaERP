import { useEffect, useState } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { useWebShopAuth } from '@/pages/WebShop/hooks/useWebShopAuth'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

interface Address {
  id: number
  type: 'shipping' | 'billing'
  name: string
  company: string | null
  address: string
  city: string
  state: string | null
  postal_code: string
  country: string
  phone: string | null
  is_default: boolean
}

const emptyAddress = {
  type: 'shipping' as const,
  name: '',
  company: '',
  address: '',
  city: '',
  state: '',
  postal_code: '',
  country: '',
  phone: '',
  is_default: false,
}

export function AddressesPage() {
  const { getAuthHeaders } = useWebShopAuth()
  const { toast } = useToast()
  
  const [addresses, setAddresses] = useState<Address[]>([])
  const [loading, setLoading] = useState(true)
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [editingAddress, setEditingAddress] = useState<Address | null>(null)
  const [formData, setFormData] = useState(emptyAddress)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const fetchAddresses = async () => {
    try {
      const response = await api.get('/web-shop/customer/addresses', {
        headers: getAuthHeaders(),
      })
      setAddresses(response.data.addresses || [])
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Error',
        description: 'Failed to load addresses.',
      })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchAddresses()
  }, [])

  const openAddDialog = () => {
    setEditingAddress(null)
    setFormData(emptyAddress)
    setIsDialogOpen(true)
  }

  const openEditDialog = (address: Address) => {
    setEditingAddress(address)
    setFormData({
      type: address.type,
      name: address.name,
      company: address.company || '',
      address: address.address,
      city: address.city,
      state: address.state || '',
      postal_code: address.postal_code,
      country: address.country,
      phone: address.phone || '',
      is_default: address.is_default,
    })
    setIsDialogOpen(true)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setIsSubmitting(true)

    try {
      if (editingAddress) {
        await api.put(`/web-shop/customer/addresses/${editingAddress.id}`, formData, {
          headers: getAuthHeaders(),
        })
        toast({ title: 'Success', description: 'Address updated successfully.' })
      } else {
        await api.post('/web-shop/customer/addresses', formData, {
          headers: getAuthHeaders(),
        })
        toast({ title: 'Success', description: 'Address added successfully.' })
      }
      setIsDialogOpen(false)
      fetchAddresses()
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Error',
        description: 'Failed to save address. Please try again.',
      })
    } finally {
      setIsSubmitting(false)
    }
  }

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this address?')) return

    try {
      await api.delete(`/web-shop/customer/addresses/${id}`, {
        headers: getAuthHeaders(),
      })
      toast({ title: 'Success', description: 'Address deleted successfully.' })
      fetchAddresses()
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Error',
        description: 'Failed to delete address.',
      })
    }
  }

  const handleSetDefault = async (id: number) => {
    try {
      await api.post(`/web-shop/customer/addresses/${id}/default`, {}, {
        headers: getAuthHeaders(),
      })
      toast({ title: 'Success', description: 'Default address updated.' })
      fetchAddresses()
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Error',
        description: 'Failed to set default address.',
      })
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Addresses</h1>
        <Button onClick={openAddDialog}>
          <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
          Add Address
        </Button>
      </div>

      {addresses.length === 0 ? (
        <Card>
          <CardContent className="text-center py-12">
            <Icon icon="solar:map-point-bold-duotone" className="h-16 w-16 mx-auto mb-4 text-muted-foreground" />
            <h3 className="text-lg font-medium mb-2">No addresses saved</h3>
            <p className="text-sm text-muted-foreground mb-4">
              Add your shipping and billing addresses for faster checkout
            </p>
            <Button onClick={openAddDialog}>
              <Icon icon="solar:add-circle-bold-duotone" className="mr-2 h-4 w-4" />
              Add Your First Address
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {addresses.map((address) => (
            <Card key={address.id} className={address.is_default ? 'border-primary' : ''}>
              <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Icon 
                      icon={address.type === 'shipping' ? 'solar:truck-bold-duotone' : 'solar:bill-bold-duotone'} 
                      className="h-5 w-5 text-muted-foreground" 
                    />
                    <CardTitle className="text-base capitalize">{address.type}</CardTitle>
                    {address.is_default && (
                      <span className="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded">
                        Default
                      </span>
                    )}
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="text-sm space-y-1 mb-4">
                  <p className="font-medium">{address.name}</p>
                  {address.company && <p>{address.company}</p>}
                  <p>{address.address}</p>
                  <p>{address.city}, {address.state} {address.postal_code}</p>
                  <p>{address.country}</p>
                  {address.phone && <p className="text-muted-foreground">{address.phone}</p>}
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" size="sm" onClick={() => openEditDialog(address)}>
                    <Icon icon="solar:pen-bold-duotone" className="mr-1 h-3 w-3" />
                    Edit
                  </Button>
                  {!address.is_default && (
                    <Button variant="outline" size="sm" onClick={() => handleSetDefault(address.id)}>
                      <Icon icon="solar:check-circle-bold-duotone" className="mr-1 h-3 w-3" />
                      Set Default
                    </Button>
                  )}
                  <Button variant="outline" size="sm" onClick={() => handleDelete(address.id)} className="text-red-600 hover:text-red-700">
                    <Icon icon="solar:trash-bin-trash-bold-duotone" className="mr-1 h-3 w-3" />
                    Delete
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Add/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingAddress ? 'Edit Address' : 'Add New Address'}</DialogTitle>
            <DialogDescription>
              {editingAddress ? 'Update your address details below.' : 'Enter your address details below.'}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="type">Address Type</Label>
              <Select
                value={formData.type}
                onValueChange={(value) => setFormData(prev => ({ ...prev, type: value as 'shipping' | 'billing' }))}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="shipping">Shipping</SelectItem>
                  <SelectItem value="billing">Billing</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="name">Full Name</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="company">Company (Optional)</Label>
              <Input
                id="company"
                value={formData.company}
                onChange={(e) => setFormData(prev => ({ ...prev, company: e.target.value }))}
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="address">Street Address</Label>
              <Input
                id="address"
                value={formData.address}
                onChange={(e) => setFormData(prev => ({ ...prev, address: e.target.value }))}
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="city">City</Label>
                <Input
                  id="city"
                  value={formData.city}
                  onChange={(e) => setFormData(prev => ({ ...prev, city: e.target.value }))}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="state">State/Province</Label>
                <Input
                  id="state"
                  value={formData.state}
                  onChange={(e) => setFormData(prev => ({ ...prev, state: e.target.value }))}
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="postal_code">Postal Code</Label>
                <Input
                  id="postal_code"
                  value={formData.postal_code}
                  onChange={(e) => setFormData(prev => ({ ...prev, postal_code: e.target.value }))}
                  required
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="country">Country</Label>
                <Input
                  id="country"
                  value={formData.country}
                  onChange={(e) => setFormData(prev => ({ ...prev, country: e.target.value }))}
                  required
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="phone">Phone (Optional)</Label>
              <Input
                id="phone"
                type="tel"
                value={formData.phone}
                onChange={(e) => setFormData(prev => ({ ...prev, phone: e.target.value }))}
              />
            </div>

            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="is_default"
                checked={formData.is_default}
                onChange={(e) => setFormData(prev => ({ ...prev, is_default: e.target.checked }))}
                className="rounded border-gray-300"
              />
              <Label htmlFor="is_default" className="font-normal cursor-pointer">
                Set as default {formData.type} address
              </Label>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setIsDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? (
                  <>
                    <Icon icon="solar:spinner-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                    Saving...
                  </>
                ) : (
                  <>
                    <Icon icon="solar:disk-bold-duotone" className="mr-2 h-4 w-4" />
                    {editingAddress ? 'Update' : 'Save'}
                  </>
                )}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  )
}