import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'

interface Warehouse {
  id: number
  name: string
  code: string
  address: string
  phone: string | null
  email: string | null
  manager_name: string | null
  is_active: boolean
  is_default: boolean
}

export function WarehousesPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [formData, setFormData] = useState({
    name: '',
    code: '',
    address: '',
    phone: '',
    email: '',
    manager_name: '',
    is_active: true,
    is_default: false,
  })

  const { data: warehouses = [], isLoading } = useQuery<Warehouse[]>({
    queryKey: ['warehouses'],
    queryFn: async () => {
      const res = await api.get('/warehouses')
      return res.data
    },
  })

  const filteredWarehouses = warehouses.filter(w => 
    w.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
    w.code?.toLowerCase().includes(searchQuery.toLowerCase()) ||
    w.address?.toLowerCase().includes(searchQuery.toLowerCase())
  )

  const mutation = useMutation({
    mutationFn: async (data: typeof formData) => {
      if (editingId) {
        return api.put(`/warehouses/${editingId}`, data)
      }
      return api.post('/warehouses', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] })
      setIsDialogOpen(false)
      resetForm()
      toast({
        title: editingId ? 'Warehouse updated' : 'Warehouse created',
      })
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Something went wrong',
        variant: 'destructive',
      })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      return api.delete(`/warehouses/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['warehouses'] })
      toast({ title: 'Warehouse deleted' })
    },
    onError: (error: any) => {
      toast({ 
        title: 'Cannot delete warehouse', 
        description: error.response?.data?.message || 'This warehouse may have stock or be in use',
        variant: 'destructive' 
      })
    },
  })

  const resetForm = () => {
    setEditingId(null)
    setFormData({ name: '', code: '', address: '', phone: '', email: '', manager_name: '', is_active: true, is_default: false })
  }

  const handleEdit = (w: Warehouse) => {
    setEditingId(w.id)
    setFormData({
      name: w.name,
      code: w.code || '',
      address: w.address || '',
      phone: w.phone || '',
      email: w.email || '',
      manager_name: w.manager_name || '',
      is_active: w.is_active,
      is_default: w.is_default,
    })
    setIsDialogOpen(true)
  }

  const handleSubmit = () => {
    if (!formData.name) return
    mutation.mutate(formData)
  }

  const activeCount = warehouses.filter(w => w.is_active).length
  const defaultWarehouse = warehouses.find(w => w.is_default)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Warehouses</h1>
          <p className="text-muted-foreground mt-1">Manage your storage locations and facilities</p>
        </div>
        <Button onClick={() => { resetForm(); setIsDialogOpen(true); }}>
          <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
          Add Warehouse
        </Button>
      </div>

      {/* Stats Cards */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card className="relative overflow-hidden">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-primary/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription>Total Warehouses</CardDescription>
            <CardTitle className="text-3xl font-bold">{warehouses.length}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Storage locations</p>
          </CardContent>
        </Card>

        <Card className="relative overflow-hidden">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-green-500/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription>Active</CardDescription>
            <CardTitle className="text-3xl font-bold text-green-600">{activeCount}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Currently operational</p>
          </CardContent>
        </Card>

        <Card className="relative overflow-hidden">
          <div className="absolute right-0 top-0 h-24 w-24 translate-x-8 -translate-y-4">
            <div className="absolute h-full w-full rounded-full bg-blue-500/10" />
          </div>
          <CardHeader className="pb-2">
            <CardDescription>Default Warehouse</CardDescription>
            <CardTitle className="text-xl font-bold truncate">{defaultWarehouse?.name || 'Not set'}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground">Primary location</p>
          </CardContent>
        </Card>
      </div>

      {/* Search */}
      <div className="flex items-center gap-4">
        <div className="relative flex-1 max-w-md">
          <Icon icon="solar:magnifer-bold" className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search warehouses..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="pl-9"
          />
        </div>
      </div>

      {/* Warehouses Grid */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="flex items-center gap-2 text-muted-foreground">
            <Icon icon="solar:refresh-bold" className="h-5 w-5 animate-spin" />
            Loading warehouses...
          </div>
        </div>
      ) : filteredWarehouses.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-muted mb-4">
              <Icon icon="solar:buildings-bold-duotone" className="h-8 w-8 text-muted-foreground" />
            </div>
            <h3 className="text-lg font-semibold">No Warehouses Found</h3>
            <p className="text-muted-foreground mt-1">
              {searchQuery ? 'Try a different search term' : 'Add your first warehouse to get started'}
            </p>
            {!searchQuery && (
              <Button onClick={() => { resetForm(); setIsDialogOpen(true); }} className="mt-4">
                <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
                Add Warehouse
              </Button>
            )}
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {filteredWarehouses.map((warehouse) => (
            <Card key={warehouse.id} className={`group hover:shadow-lg transition-all ${!warehouse.is_active ? 'opacity-60' : ''} ${warehouse.is_default ? 'border-primary/50' : ''}`}>
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${warehouse.is_default ? 'bg-primary/10' : 'bg-muted'}`}>
                      <Icon icon="solar:buildings-bold-duotone" className={`h-6 w-6 ${warehouse.is_default ? 'text-primary' : 'text-muted-foreground'}`} />
                    </div>
                    <div>
                      <CardTitle className="text-lg flex items-center gap-2">
                        {warehouse.name}
                        {warehouse.is_default && (
                          <Badge className="bg-primary/10 text-primary border-primary/20">Default</Badge>
                        )}
                      </CardTitle>
                      <p className="text-sm text-muted-foreground">{warehouse.code || 'No code'}</p>
                    </div>
                  </div>
                  <Badge variant={warehouse.is_active ? 'default' : 'secondary'} className={warehouse.is_active ? 'bg-green-100 text-green-800' : ''}>
                    {warehouse.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                {warehouse.address && (
                  <div className="flex items-start gap-2 text-sm">
                    <Icon icon="solar:map-point-bold" className="h-4 w-4 text-muted-foreground mt-0.5" />
                    <span className="text-muted-foreground">{warehouse.address}</span>
                  </div>
                )}
                {warehouse.manager_name && (
                  <div className="flex items-center gap-2 text-sm">
                    <Icon icon="solar:user-bold" className="h-4 w-4 text-muted-foreground" />
                    <span className="text-muted-foreground">{warehouse.manager_name}</span>
                  </div>
                )}
                {warehouse.phone && (
                  <div className="flex items-center gap-2 text-sm">
                    <Icon icon="solar:phone-bold" className="h-4 w-4 text-muted-foreground" />
                    <span className="text-muted-foreground">{warehouse.phone}</span>
                  </div>
                )}
                {warehouse.email && (
                  <div className="flex items-center gap-2 text-sm">
                    <Icon icon="solar:letter-bold" className="h-4 w-4 text-muted-foreground" />
                    <span className="text-muted-foreground">{warehouse.email}</span>
                  </div>
                )}
                
                <div className="flex items-center gap-2 pt-3 border-t">
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => handleEdit(warehouse)}>
                    <Icon icon="solar:pen-bold" className="mr-1 h-4 w-4" />
                    Edit
                  </Button>
                  <Button 
                    variant="outline" 
                    size="sm"
                    className="text-destructive hover:text-destructive hover:bg-destructive/10"
                    onClick={() => {
                      if (confirm('Are you sure you want to delete this warehouse?')) {
                        deleteMutation.mutate(warehouse.id)
                      }
                    }}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="h-4 w-4" />
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Add/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={(open) => {
        setIsDialogOpen(open)
        if (!open) resetForm()
      }}>
        <DialogContent className="sm:max-w-[500px]">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10">
                <Icon icon="solar:buildings-bold" className="h-4 w-4 text-primary" />
              </div>
              {editingId ? 'Edit Warehouse' : 'Add Warehouse'}
            </DialogTitle>
            <DialogDescription>
              {editingId ? 'Update warehouse details' : 'Create a new storage location'}
            </DialogDescription>
          </DialogHeader>
          
          <div className="space-y-4 py-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Name *</label>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Main Warehouse"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Code</label>
                <Input
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  placeholder="WH-001"
                />
              </div>
            </div>
            
            <div className="space-y-2">
              <label className="text-sm font-medium">Address</label>
              <Input
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                placeholder="123 Storage Lane, City"
              />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Phone</label>
                <Input
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  placeholder="+1234567890"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Email</label>
                <Input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="warehouse@example.com"
                />
              </div>
            </div>
            
            <div className="space-y-2">
              <label className="text-sm font-medium">Manager Name</label>
              <Input
                value={formData.manager_name}
                onChange={(e) => setFormData({ ...formData, manager_name: e.target.value })}
                placeholder="John Doe"
              />
            </div>
            
            <div className="flex items-center gap-6 pt-2">
              <div className="flex items-center gap-3">
                <Switch
                  checked={formData.is_active}
                  onCheckedChange={(c: boolean) => setFormData({ ...formData, is_active: c })}
                />
                <label className="text-sm font-medium">Active</label>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  checked={formData.is_default}
                  onCheckedChange={(c: boolean) => setFormData({ ...formData, is_default: c })}
                />
                <label className="text-sm font-medium">Default Warehouse</label>
              </div>
            </div>
          </div>
          
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleSubmit} disabled={mutation.isPending}>
              {mutation.isPending ? (
                <>
                  <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                  Saving...
                </>
              ) : (
                'Save Warehouse'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
