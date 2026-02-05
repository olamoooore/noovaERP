import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'
import loyaltyApi from '@/lib/loyaltyApi'

interface Reward {
  id: number
  name: string
  description?: string
  points_cost: number
  points_required?: number
  stock_quantity?: number
  is_active: boolean
  value?: number
}

export function LoyaltyRewardsPage() {
  const [isCreateOpen, setIsCreateOpen] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [isDeleteOpen, setIsDeleteOpen] = useState(false)
  const [selectedReward, setSelectedReward] = useState<Reward | null>(null)
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const { data: rewards, isLoading } = useQuery({
    queryKey: ['loyalty-rewards'],
    queryFn: async () => {
      const response = await api.get('/loyalty/rewards')
      return response.data
    },
  })

  const createReward = useMutation({
    mutationFn: async (data: any) => {
      const response = await api.post('/loyalty/rewards', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['loyalty-rewards'] })
      setIsCreateOpen(false)
      toast({
        title: 'Success',
        description: 'Reward created successfully',
      })
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to create reward',
        variant: 'destructive',
      })
    },
  })

  const updateReward = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: any }) => {
      const response = await api.put(`/loyalty/rewards/${id}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['loyalty-rewards'] })
      setIsEditOpen(false)
      setSelectedReward(null)
      toast({
        title: 'Success',
        description: 'Reward updated successfully',
      })
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to update reward',
        variant: 'destructive',
      })
    },
  })

  const deleteReward = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/loyalty/rewards/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['loyalty-rewards'] })
      setIsDeleteOpen(false)
      setSelectedReward(null)
      toast({
        title: 'Success',
        description: 'Reward deleted successfully',
      })
    },
    onError: () => {
      toast({
        title: 'Error',
        description: 'Failed to delete reward',
        variant: 'destructive',
      })
    },
  })

  const handleCreateSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const formData = new FormData(e.currentTarget)
    createReward.mutate({
      loyalty_program_id: 1, // Default program ID
      name: formData.get('name'),
      description: formData.get('description'),
      points_cost: parseInt(formData.get('points_cost') as string),
      stock_quantity: parseInt(formData.get('stock_quantity') as string) || null,
      is_active: formData.get('is_active') === 'on',
    })
  }

  const handleEditSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!selectedReward) return
    
    const formData = new FormData(e.currentTarget)
    updateReward.mutate({
      id: selectedReward.id,
      data: {
        name: formData.get('name'),
        description: formData.get('description'),
        points_cost: parseInt(formData.get('points_cost') as string),
        stock_quantity: parseInt(formData.get('stock_quantity') as string) || null,
        is_active: formData.get('is_active') === 'on',
      },
    })
  }

  const handleDelete = () => {
    if (!selectedReward) return
    deleteReward.mutate(selectedReward.id)
  }

  const openEditDialog = (reward: Reward) => {
    setSelectedReward(reward)
    setIsEditOpen(true)
  }

  const openDeleteDialog = (reward: Reward) => {
    setSelectedReward(reward)
    setIsDeleteOpen(true)
  }

  const getPointsCost = (reward: Reward) => {
    return reward.points_cost || reward.points_required || 0
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Loyalty Rewards</h1>
          <p className="text-muted-foreground">Manage rewards catalog</p>
        </div>
        <Dialog open={isCreateOpen} onOpenChange={setIsCreateOpen}>
          <DialogTrigger asChild>
            <Button>
              <Icon icon="solar:add-circle-bold" className="mr-2 h-4 w-4" />
              Create Reward
            </Button>
          </DialogTrigger>
          <DialogContent>
            <form onSubmit={handleCreateSubmit}>
              <DialogHeader>
                <DialogTitle>Create New Reward</DialogTitle>
                <DialogDescription>Add a new reward to the loyalty catalog</DialogDescription>
              </DialogHeader>
              <div className="grid gap-4 py-4">
                <div className="grid gap-2">
                  <Label htmlFor="name">Reward Name</Label>
                  <Input id="name" name="name" placeholder="e.g., $10 Gift Card" required />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="description">Description</Label>
                  <Textarea
                    id="description"
                    name="description"
                    placeholder="Describe the reward..."
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="points_cost">Points Required</Label>
                  <Input
                    id="points_cost"
                    name="points_cost"
                    type="number"
                    placeholder="1000"
                    required
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="stock_quantity">Stock Quantity (optional)</Label>
                  <Input
                    id="stock_quantity"
                    name="stock_quantity"
                    type="number"
                    placeholder="Leave empty for unlimited"
                  />
                </div>
                <div className="flex items-center gap-2">
                  <Switch id="is_active" name="is_active" defaultChecked />
                  <Label htmlFor="is_active">Active</Label>
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setIsCreateOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={createReward.isPending}>
                  {createReward.isPending && (
                    <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Create Reward
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {rewards?.data?.length === 0 && (
            <div className="col-span-full text-center py-12 text-muted-foreground">
              <Icon icon="solar:gift-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
              <p>No rewards found</p>
              <p className="text-sm">Create your first reward to get started</p>
            </div>
          )}
          {rewards?.data?.map((reward: Reward) => (
            <Card key={reward.id}>
              <CardHeader>
                <div className="flex items-start justify-between">
                  <CardTitle className="text-lg">{reward.name}</CardTitle>
                  <Badge variant={reward.is_active ? 'default' : 'secondary'}>
                    {reward.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <p className="text-sm text-muted-foreground">{reward.description || 'No description'}</p>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Icon icon="solar:star-bold-duotone" className="h-5 w-5 text-yellow-500" />
                    <span className="font-semibold">
                      {getPointsCost(reward).toLocaleString()} points
                    </span>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    {reward.stock_quantity !== null && reward.stock_quantity !== undefined
                      ? `Stock: ${reward.stock_quantity}`
                      : 'Unlimited'
                    }
                  </div>
                </div>
                {reward.value && (
                  <div className="text-sm text-muted-foreground">
                    Value: {formatCurrency(reward.value)}
                  </div>
                )}
                <div className="flex gap-2">
                  <Button 
                    variant="outline" 
                    size="sm" 
                    className="flex-1"
                    onClick={() => openEditDialog(reward)}
                  >
                    <Icon icon="solar:pen-bold" className="mr-1 h-3 w-3" />
                    Edit
                  </Button>
                  <Button 
                    variant="outline" 
                    size="sm" 
                    className="flex-1 text-destructive hover:bg-destructive hover:text-white"
                    onClick={() => openDeleteDialog(reward)}
                  >
                    <Icon icon="solar:trash-bin-trash-bold" className="mr-1 h-3 w-3" />
                    Delete
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Edit Dialog */}
      <Dialog open={isEditOpen} onOpenChange={setIsEditOpen}>
        <DialogContent>
          {selectedReward && (
            <form onSubmit={handleEditSubmit}>
              <DialogHeader>
                <DialogTitle>Edit Reward</DialogTitle>
                <DialogDescription>Update reward details</DialogDescription>
              </DialogHeader>
              <div className="grid gap-4 py-4">
                <div className="grid gap-2">
                  <Label htmlFor="edit-name">Reward Name</Label>
                  <Input 
                    id="edit-name" 
                    name="name" 
                    defaultValue={selectedReward.name}
                    required 
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="edit-description">Description</Label>
                  <Textarea
                    id="edit-description"
                    name="description"
                    defaultValue={selectedReward.description || ''}
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="edit-points_cost">Points Required</Label>
                  <Input
                    id="edit-points_cost"
                    name="points_cost"
                    type="number"
                    defaultValue={getPointsCost(selectedReward)}
                    required
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="edit-stock_quantity">Stock Quantity (optional)</Label>
                  <Input
                    id="edit-stock_quantity"
                    name="stock_quantity"
                    type="number"
                    defaultValue={selectedReward.stock_quantity || ''}
                    placeholder="Leave empty for unlimited"
                  />
                </div>
                <div className="flex items-center gap-2">
                  <Switch 
                    id="edit-is_active" 
                    name="is_active" 
                    defaultChecked={selectedReward.is_active} 
                  />
                  <Label htmlFor="edit-is_active">Active</Label>
                </div>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setIsEditOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={updateReward.isPending}>
                  {updateReward.isPending && (
                    <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Save Changes
                </Button>
              </DialogFooter>
            </form>
          )}
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-destructive">
              <Icon icon="solar:danger-triangle-bold" className="h-5 w-5" />
              Delete Reward
            </DialogTitle>
            <DialogDescription>
              Are you sure you want to delete <strong>{selectedReward?.name}</strong>? 
              This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="mt-4">
            <Button type="button" variant="outline" onClick={() => setIsDeleteOpen(false)}>
              Cancel
            </Button>
            <Button 
              type="button" 
              variant="destructive"
              onClick={handleDelete}
              disabled={deleteReward.isPending}
            >
              {deleteReward.isPending && (
                <Icon icon="solar:loading-bold-duotone" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Delete Reward
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
