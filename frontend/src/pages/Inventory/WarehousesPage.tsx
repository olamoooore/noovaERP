import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
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
import { Label } from '@/components/ui/label'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { StatCard } from '@/components/ui/stat-card'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { cn } from '@/lib/utils'
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
  const [deleteTarget, setDeleteTarget] = useState<Warehouse | null>(null)
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
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative w-full sm:w-64">
            <Icon
              icon="solar:magnifer-bold-duotone"
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              placeholder="Search warehouses…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="h-8 rounded-full pl-9 pr-3 text-xs"
            />
          </div>
          {searchQuery && (
            <span className="text-xs text-muted-foreground">
              {filteredWarehouses.length} result{filteredWarehouses.length !== 1 ? 's' : ''}
            </span>
          )}
          <HeaderActionButton icon="solar:add-circle-bold-duotone" onClick={() => { resetForm(); setIsDialogOpen(true); }}>
            Add Warehouse
          </HeaderActionButton>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
        <StatCard
          label="Total Warehouses"
          value={warehouses.length}
          hint="Storage locations"
          icon="solar:buildings-bold-duotone"
          tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
        />
        <StatCard
          label="Active"
          value={activeCount}
          hint="Currently operational"
          icon="solar:check-circle-bold-duotone"
          tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
        />
        <StatCard
          label="Default Warehouse"
          value={<span className="block truncate">{defaultWarehouse?.name || 'Not set'}</span>}
          hint="Primary location"
          icon="solar:star-bold-duotone"
          tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
        />
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
              <HeaderActionButton className="mt-4" icon="solar:add-circle-bold-duotone" onClick={() => { resetForm(); setIsDialogOpen(true); }}>
                Add Warehouse
              </HeaderActionButton>
            )}
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {filteredWarehouses.map((warehouse) => (
            <Card
              key={warehouse.id}
              className={cn(
                'rounded-xl border-slate-200/80 transition-all hover:shadow-md dark:border-slate-800',
                !warehouse.is_active && 'opacity-60',
                warehouse.is_default && 'border-primary/40',
              )}
            >
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-center gap-3">
                    <div
                      className={cn(
                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl',
                        warehouse.is_default ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground',
                      )}
                    >
                      <Icon icon="solar:buildings-bold-duotone" className="h-5 w-5" />
                    </div>
                    <div className="min-w-0">
                      <CardTitle className="flex items-center gap-2 text-base">
                        <span className="truncate">{warehouse.name}</span>
                        {warehouse.is_default && (
                          <Badge variant="outline" className="border-primary/20 bg-primary/10 text-primary">
                            Default
                          </Badge>
                        )}
                      </CardTitle>
                      <p className="mt-0.5 font-mono text-xs text-muted-foreground">{warehouse.code || 'No code'}</p>
                    </div>
                  </div>
                  <Badge
                    variant="outline"
                    className={cn(
                      'shrink-0 gap-1 whitespace-nowrap border-transparent px-1.5 py-0 text-[10px] font-medium leading-4',
                      warehouse.is_active
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
                        : 'bg-muted text-muted-foreground',
                    )}
                  >
                    <Icon
                      icon={warehouse.is_active ? 'solar:check-circle-bold-duotone' : 'solar:close-circle-bold-duotone'}
                      className="h-3 w-3"
                    />
                    {warehouse.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-2">
                {warehouse.address && (
                  <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Icon icon="solar:map-point-bold-duotone" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    <span className="min-w-0 truncate" title={warehouse.address}>{warehouse.address}</span>
                  </div>
                )}
                {warehouse.manager_name && (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon icon="solar:user-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{warehouse.manager_name}</span>
                  </div>
                )}
                {warehouse.phone && (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon icon="solar:phone-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">{warehouse.phone}</span>
                  </div>
                )}
                {warehouse.email && (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon icon="solar:letter-bold-duotone" className="h-3.5 w-3.5 shrink-0" />
                    <span className="truncate" title={warehouse.email}>{warehouse.email}</span>
                  </div>
                )}

                <div className="flex items-center gap-2 border-t pt-3">
                  <HeaderActionButton
                    variant="outline"
                    icon="solar:pen-bold-duotone"
                    className="flex-1"
                    onClick={() => handleEdit(warehouse)}
                  >
                    Edit
                  </HeaderActionButton>
                  <HeaderActionButton
                    variant="outline"
                    icon="solar:trash-bin-trash-bold-duotone"
                    className="w-9 px-0 border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    aria-label="Delete warehouse"
                    title="Delete"
                    onClick={() => setDeleteTarget(warehouse)}
                  />
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
                <Label className="text-sm font-medium">Name *</Label>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="Main Warehouse"
                />
              </div>
              <div className="space-y-2">
                <Label className="text-sm font-medium">Code</Label>
                <Input
                  value={formData.code}
                  onChange={(e) => setFormData({ ...formData, code: e.target.value })}
                  placeholder="WH-001"
                />
              </div>
            </div>
            
            <div className="space-y-2">
              <Label className="text-sm font-medium">Address</Label>
              <Input
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                placeholder="123 Storage Lane, City"
              />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label className="text-sm font-medium">Phone</Label>
                <Input
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  placeholder="+1234567890"
                />
              </div>
              <div className="space-y-2">
                <Label className="text-sm font-medium">Email</Label>
                <Input
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="warehouse@example.com"
                />
              </div>
            </div>
            
            <div className="space-y-2">
              <Label className="text-sm font-medium">Manager Name</Label>
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
                <Label className="text-sm font-medium">Active</Label>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  checked={formData.is_default}
                  onCheckedChange={(c: boolean) => setFormData({ ...formData, is_default: c })}
                />
                <Label className="text-sm font-medium">Default Warehouse</Label>
              </div>
            </div>
          </div>
          
          <DialogFooter>
            <HeaderActionButton variant="outline" onClick={() => setIsDialogOpen(false)}>
              Cancel
            </HeaderActionButton>
            <HeaderActionButton icon="solar:diskette-bold-duotone" loading={mutation.isPending} disabled={mutation.isPending} onClick={handleSubmit}>
              Save Warehouse
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={`Delete ${deleteTarget?.name || 'warehouse'}?`}
        description="Warehouses with stock or documents in use cannot be deleted."
        confirmLabel="Delete"
        icon="solar:trash-bin-trash-bold-duotone"
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          if (!deleteTarget) return
          deleteMutation.mutate(deleteTarget.id)
          setDeleteTarget(null)
        }}
      />
    </div>
  )
}
