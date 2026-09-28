import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'

interface Supplier {
  id: number
  name: string
  code: string | null
  email: string | null
  phone: string | null
  contact_person: string | null
  address: string | null
  city: string | null
  country: string | null
  payment_terms: number
  is_active: boolean
  purchase_orders_count?: number
  // New fields
  website: string | null
  tax_number: string | null
  currency: string | null
  default_lead_time_days: number | null
  minimum_order_value: number | null
  quality_rating: number | null
  delivery_rating: number | null
  price_rating: number | null
  notes: string | null
  products_count?: number
}

const defaultFormData = {
  name: '',
  code: '',
  email: '',
  phone: '',
  contact_person: '',
  address: '',
  city: '',
  country: '',
  payment_terms: 30,
  is_active: true,
  // New fields
  website: '',
  tax_number: '',
  currency: 'USD',
  default_lead_time_days: '',
  minimum_order_value: '',
  quality_rating: '',
  delivery_rating: '',
  price_rating: '',
  notes: '',
}

export function SuppliersPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [formData, setFormData] = useState(defaultFormData)

  const { data: suppliersData, isLoading } = useQuery({
    queryKey: ['suppliers', search],
    queryFn: async () => {
      const params: Record<string, any> = { per_page: 50 }
      if (search) params.search = search
      const res = await api.get('/suppliers', { params })
      return res.data
    },
  })

  const mutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      if (editingId) {
        return api.put(`/suppliers/${editingId}`, data)
      }
      return api.post('/suppliers', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      setIsDialogOpen(false)
      resetForm()
      toast({ title: editingId ? 'Supplier updated' : 'Supplier created' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: getApiErrorMessage(error, 'Something went wrong'),
        variant: 'destructive',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/suppliers/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      toast({ title: 'Supplier deleted' })
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: getApiErrorMessage(error, 'Cannot delete supplier'),
        variant: 'destructive',
      })
    },
  })

  const resetForm = () => {
    setEditingId(null)
    setFormData(defaultFormData)
  }

  const handleEdit = (supplier: Supplier) => {
    setEditingId(supplier.id)
    setFormData({
      name: supplier.name,
      code: supplier.code || '',
      email: supplier.email || '',
      phone: supplier.phone || '',
      contact_person: supplier.contact_person || '',
      address: supplier.address || '',
      city: supplier.city || '',
      country: supplier.country || '',
      payment_terms: supplier.payment_terms,
      is_active: supplier.is_active,
      // New fields
      website: supplier.website || '',
      tax_number: supplier.tax_number || '',
      currency: supplier.currency || 'USD',
      default_lead_time_days: supplier.default_lead_time_days != null ? String(supplier.default_lead_time_days) : '',
      minimum_order_value: supplier.minimum_order_value != null ? String(supplier.minimum_order_value) : '',
      quality_rating: supplier.quality_rating != null ? String(supplier.quality_rating) : '',
      delivery_rating: supplier.delivery_rating != null ? String(supplier.delivery_rating) : '',
      price_rating: supplier.price_rating != null ? String(supplier.price_rating) : '',
      notes: supplier.notes || '',
    })
    setIsDialogOpen(true)
  }

  const handleSubmit = () => {
    if (!formData.name) return
    const payload = {
      ...formData,
      default_lead_time_days: formData.default_lead_time_days ? parseInt(formData.default_lead_time_days as string) : null,
      minimum_order_value: formData.minimum_order_value ? parseFloat(formData.minimum_order_value as string) : null,
      quality_rating: formData.quality_rating ? parseFloat(formData.quality_rating as string) : null,
      delivery_rating: formData.delivery_rating ? parseFloat(formData.delivery_rating as string) : null,
      price_rating: formData.price_rating ? parseFloat(formData.price_rating as string) : null,
    }
    mutation.mutate(payload)
  }

  const getRatingColor = (rating: number | null) => {
    if (!rating) return 'bg-gray-100 text-gray-800'
    if (rating >= 4) return 'bg-green-100 text-green-800'
    if (rating >= 3) return 'bg-yellow-100 text-yellow-800'
    return 'bg-red-100 text-red-800'
  }

  const suppliers = suppliersData?.data || []

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Suppliers</h1>
          <p className="text-muted-foreground">Manage your product suppliers and vendor relationships</p>
        </div>
        <HeaderActionButton icon="solar:add-circle-bold" onClick={() => { resetForm(); setIsDialogOpen(true) }}>
          Add Supplier
        </HeaderActionButton>
      </div>

      <Card>
        <CardHeader>
          <div className="flex items-center gap-4">
            <Input
              placeholder="Search suppliers..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="max-w-sm"
            />
          </div>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Supplier</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead>Lead Time</TableHead>
                <TableHead>Rating</TableHead>
                <TableHead>Products</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center py-8">Loading...</TableCell>
                </TableRow>
              ) : suppliers.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                    No suppliers found
                  </TableCell>
                </TableRow>
              ) : (
                suppliers.map((supplier: Supplier) => (
                  <TableRow key={supplier.id}>
                    <TableCell>
                      <div className="font-medium">{supplier.name}</div>
                      <div className="text-xs text-muted-foreground">
                        {supplier.code && <span className="mr-2">{supplier.code}</span>}
                        {supplier.city && supplier.country && `${supplier.city}, ${supplier.country}`}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="text-sm">{supplier.contact_person || '-'}</div>
                      <div className="text-xs text-muted-foreground">{supplier.email || supplier.phone || ''}</div>
                    </TableCell>
                    <TableCell>
                      {supplier.default_lead_time_days ? (
                        <span className="text-sm">{supplier.default_lead_time_days} days</span>
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {supplier.quality_rating || supplier.delivery_rating || supplier.price_rating ? (
                        <div className="flex gap-1">
                          {supplier.quality_rating && (
                            <Badge variant="outline" className={getRatingColor(supplier.quality_rating)}>
                              Q:{supplier.quality_rating}
                            </Badge>
                          )}
                          {supplier.delivery_rating && (
                            <Badge variant="outline" className={getRatingColor(supplier.delivery_rating)}>
                              D:{supplier.delivery_rating}
                            </Badge>
                          )}
                        </div>
                      ) : (
                        <span className="text-muted-foreground">-</span>
                      )}
                    </TableCell>
                    <TableCell>
                      <span className="text-sm">{supplier.products_count ?? '-'}</span>
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${
                        supplier.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                      }`}>
                        {supplier.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </TableCell>
                    <TableCell className="text-right">
                      <HeaderActionButton icon="solar:pen-bold" variant="ghost" size="icon" onClick={() => handleEdit(supplier)}>
                      </HeaderActionButton>
                      <Button
                        variant="ghost"
                        size="icon"
                        onClick={() => {
                          if (confirm('Delete this supplier?')) deleteMutation.mutate(supplier.id)
                        }}
                      >
                        <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4 text-red-500" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={isDialogOpen} onOpenChange={(open) => { setIsDialogOpen(open); if (!open) resetForm() }}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editingId ? 'Edit Supplier' : 'Add Supplier'}</DialogTitle>
          </DialogHeader>
          
          <Tabs defaultValue="basic" className="w-full">
            <TabsList className="w-fit max-w-full">
              <TabsTrigger value="basic">Basic Info</TabsTrigger>
              <TabsTrigger value="business">Business Details</TabsTrigger>
              <TabsTrigger value="ratings">Ratings & Notes</TabsTrigger>
            </TabsList>

            <TabsContent value="basic" className="space-y-4 pt-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Name *</Label>
                  <Input
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="Supplier name"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Code</Label>
                  <Input
                    value={formData.code}
                    onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                    placeholder="SUP-001 (auto-generated if empty)"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Email</Label>
                  <Input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="supplier@example.com"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Phone</Label>
                  <Input
                    value={formData.phone}
                    onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    placeholder="+1234567890"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Contact Person</Label>
                  <Input
                    value={formData.contact_person}
                    onChange={(e) => setFormData({ ...formData, contact_person: e.target.value })}
                    placeholder="John Doe"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Website</Label>
                  <Input
                    value={formData.website}
                    onChange={(e) => setFormData({ ...formData, website: e.target.value })}
                    placeholder="https://supplier.com"
                  />
                </div>
              </div>
              <div className="space-y-2">
                <Label>Address</Label>
                <Textarea
                  value={formData.address}
                  onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                  placeholder="Full address"
                  rows={2}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>City</Label>
                  <Input
                    value={formData.city}
                    onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label>Country</Label>
                  <Input
                    value={formData.country}
                    onChange={(e) => setFormData({ ...formData, country: e.target.value })}
                  />
                </div>
              </div>
            </TabsContent>

            <TabsContent value="business" className="space-y-4 pt-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Tax Number / VAT ID</Label>
                  <Input
                    value={formData.tax_number}
                    onChange={(e) => setFormData({ ...formData, tax_number: e.target.value })}
                    placeholder="VAT123456789"
                  />
                </div>
                <div className="space-y-2">
                  <Label>Currency</Label>
                  <Select
                    value={formData.currency}
                    onValueChange={(v) => setFormData({ ...formData, currency: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="USD">USD - US Dollar</SelectItem>
                      <SelectItem value="EUR">EUR - Euro</SelectItem>
                      <SelectItem value="GBP">GBP - British Pound</SelectItem>
                      <SelectItem value="NGN">NGN - Nigerian Naira</SelectItem>
                      <SelectItem value="CAD">CAD - Canadian Dollar</SelectItem>
                      <SelectItem value="AUD">AUD - Australian Dollar</SelectItem>
                      <SelectItem value="JPY">JPY - Japanese Yen</SelectItem>
                      <SelectItem value="CNY">CNY - Chinese Yuan</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Payment Terms (days)</Label>
                  <Input
                    type="number"
                    value={formData.payment_terms}
                    onChange={(e) => setFormData({ ...formData, payment_terms: parseInt(e.target.value) || 0 })}
                  />
                  <p className="text-xs text-muted-foreground">Number of days for payment after invoice</p>
                </div>
                <div className="space-y-2">
                  <Label>Default Lead Time (days)</Label>
                  <Input
                    type="number"
                    value={formData.default_lead_time_days}
                    onChange={(e) => setFormData({ ...formData, default_lead_time_days: e.target.value })}
                    placeholder="7"
                  />
                  <p className="text-xs text-muted-foreground">Typical delivery time from order</p>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Minimum Order Value</Label>
                <Input
                  type="number"
                  step="0.01"
                  value={formData.minimum_order_value}
                  onChange={(e) => setFormData({ ...formData, minimum_order_value: e.target.value })}
                  placeholder="0.00"
                />
                <p className="text-xs text-muted-foreground">Minimum order value required by supplier</p>
              </div>
              <div className="flex items-center gap-2 pt-2">
                <Switch
                  checked={formData.is_active}
                  onCheckedChange={(checked) => setFormData({ ...formData, is_active: checked })}
                />
                <Label>Active Supplier</Label>
              </div>
            </TabsContent>

            <TabsContent value="ratings" className="space-y-4 pt-4">
              <p className="text-sm text-muted-foreground">Rate this supplier (1-5 scale)</p>
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-2">
                  <Label>Quality Rating</Label>
                  <Select
                    value={formData.quality_rating || 'none'}
                    onValueChange={(v) => setFormData({ ...formData, quality_rating: v === 'none' ? '' : v })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select rating" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">Not rated</SelectItem>
                      <SelectItem value="1">1 - Poor</SelectItem>
                      <SelectItem value="2">2 - Fair</SelectItem>
                      <SelectItem value="3">3 - Good</SelectItem>
                      <SelectItem value="4">4 - Very Good</SelectItem>
                      <SelectItem value="5">5 - Excellent</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">Product quality consistency</p>
                </div>
                <div className="space-y-2">
                  <Label>Delivery Rating</Label>
                  <Select
                    value={formData.delivery_rating || 'none'}
                    onValueChange={(v) => setFormData({ ...formData, delivery_rating: v === 'none' ? '' : v })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select rating" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">Not rated</SelectItem>
                      <SelectItem value="1">1 - Poor</SelectItem>
                      <SelectItem value="2">2 - Fair</SelectItem>
                      <SelectItem value="3">3 - Good</SelectItem>
                      <SelectItem value="4">4 - Very Good</SelectItem>
                      <SelectItem value="5">5 - Excellent</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">On-time delivery performance</p>
                </div>
                <div className="space-y-2">
                  <Label>Price Rating</Label>
                  <Select
                    value={formData.price_rating || 'none'}
                    onValueChange={(v) => setFormData({ ...formData, price_rating: v === 'none' ? '' : v })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select rating" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">Not rated</SelectItem>
                      <SelectItem value="1">1 - Poor</SelectItem>
                      <SelectItem value="2">2 - Fair</SelectItem>
                      <SelectItem value="3">3 - Good</SelectItem>
                      <SelectItem value="4">4 - Very Good</SelectItem>
                      <SelectItem value="5">5 - Excellent</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">Competitive pricing</p>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Notes</Label>
                <Textarea
                  value={formData.notes}
                  onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                  placeholder="Internal notes about this supplier..."
                  rows={4}
                />
              </div>
            </TabsContent>
          </Tabs>

          <DialogFooter className="mt-4">
            <Button onClick={handleSubmit} disabled={mutation.isPending}>
              {mutation.isPending ? 'Saving...' : 'Save'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
