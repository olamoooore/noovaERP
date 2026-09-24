import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Checkbox } from '@/components/ui/checkbox'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function LoyaltyRedemptionsPage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()

  const [statusFilter, setStatusFilter] = useState('all')
  const [typeFilter, setTypeFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [selectedRedemptions, setSelectedRedemptions] = useState<number[]>([])
  const [cancelDialogOpen, setCancelDialogOpen] = useState(false)
  const [redemptionToCancel, setRedemptionToCancel] = useState<any>(null)

  // Fetch redemptions
  const { data: redemptions, isLoading } = useQuery({
    queryKey: ['all-redemptions', statusFilter, typeFilter, search],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (statusFilter !== 'all') params.append('status', statusFilter)
      if (typeFilter !== 'all') params.append('type', typeFilter)
      if (search) params.append('search', search)

      const response = await api.get(`/loyalty/redemptions?${params.toString()}`)
      return response.data
    },
  })

  // Update redemption status mutation
  const updateStatusMutation = useMutation({
    mutationFn: async ({ id, status }: { id: number; status: string }) => {
      const response = await api.put(`/loyalty/redemptions/${id}/status`, { status })
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Redemption status updated successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['all-redemptions'] })
      setSelectedRedemptions([])
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update status',
        variant: 'destructive',
      })
    },
  })

  // Cancel redemption mutation
  const cancelMutation = useMutation({
    mutationFn: async (id: number) => {
      const response = await api.post(`/loyalty/redemptions/${id}/cancel`)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Redemption cancelled and points refunded',
      })
      queryClient.invalidateQueries({ queryKey: ['all-redemptions'] })
      setCancelDialogOpen(false)
      setRedemptionToCancel(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to cancel redemption',
        variant: 'destructive',
      })
    },
  })

  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      const pendingIds = redemptions?.data
        ?.filter((r: any) => r.status === 'pending')
        .map((r: any) => r.id) || []
      setSelectedRedemptions(pendingIds)
    } else {
      setSelectedRedemptions([])
    }
  }

  const handleSelectRedemption = (id: number, checked: boolean) => {
    if (checked) {
      setSelectedRedemptions([...selectedRedemptions, id])
    } else {
      setSelectedRedemptions(selectedRedemptions.filter((rid) => rid !== id))
    }
  }

  const handleBatchComplete = () => {
    selectedRedemptions.forEach((id) => {
      updateStatusMutation.mutate({ id, status: 'completed' })
    })
  }

  const handleCancelRedemption = (redemption: any) => {
    setRedemptionToCancel(redemption)
    setCancelDialogOpen(true)
  }

  const confirmCancel = () => {
    if (redemptionToCancel) {
      cancelMutation.mutate(redemptionToCancel.id)
    }
  }

  const getStatusBadge = (status: string) => {
    const variants: Record<string, 'default' | 'secondary' | 'destructive'> = {
      pending: 'secondary',
      completed: 'default',
      cancelled: 'destructive',
    }
    return variants[status] || 'secondary'
  }

  const getTypeBadge = (type: string) => {
    return type === 'reward' ? 'default' : 'secondary'
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Redemption Management</h1>
          <p className="text-muted-foreground">Manage and process customer redemption requests</p>
        </div>
        {selectedRedemptions.length > 0 && (
          <HeaderActionButton icon="solar:check-circle-bold-duotone" onClick={handleBatchComplete} disabled={updateStatusMutation.isPending}>
            Mark {selectedRedemptions.length} as Completed
          </HeaderActionButton>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardHeader>
          <CardTitle>Filters</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-3">
            <div className="space-y-2">
              <label className="text-sm font-medium">Status</label>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="completed">Completed</SelectItem>
                  <SelectItem value="cancelled">Cancelled</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Type</label>
              <Select value={typeFilter} onValueChange={setTypeFilter}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="reward">Reward</SelectItem>
                  <SelectItem value="discount">Discount</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium">Search</label>
              <Input
                placeholder="Search customer name..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Redemptions Table */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <CardTitle>Redemption Requests</CardTitle>
            <div className="text-sm text-muted-foreground">
              {redemptions?.data?.length || 0} redemptions
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">
                    <Checkbox
                      checked={
                        selectedRedemptions.length > 0 &&
                        selectedRedemptions.length ===
                          redemptions?.data?.filter((r: any) => r.status === 'pending').length
                      }
                      onCheckedChange={handleSelectAll}
                    />
                  </TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Customer</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Description</TableHead>
                  <TableHead className="text-right">Points Used</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-center">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {redemptions?.data && redemptions.data.length > 0 ? (
                  redemptions.data.map((redemption: any) => (
                    <TableRow key={redemption.id}>
                      <TableCell>
                        {redemption.status === 'pending' && (
                          <Checkbox
                            checked={selectedRedemptions.includes(redemption.id)}
                            onCheckedChange={(checked) =>
                              handleSelectRedemption(redemption.id, checked as boolean)
                            }
                          />
                        )}
                      </TableCell>
                      <TableCell>{new Date(redemption.redeemed_at).toLocaleDateString()}</TableCell>
                      <TableCell>
                        <div>
                          <p className="font-medium">{redemption.customer_loyalty?.customer?.name}</p>
                          <p className="text-sm text-muted-foreground">
                            {redemption.customer_loyalty?.customer?.email}
                          </p>
                        </div>
                      </TableCell>
                      <TableCell>
                        <Badge variant={getTypeBadge(redemption.type)}>{redemption.type}</Badge>
                      </TableCell>
                      <TableCell>
                        {redemption.type === 'reward'
                          ? redemption.reward?.name || 'Reward'
                          : `Discount: $${redemption.discount_amount}`}
                      </TableCell>
                      <TableCell className="text-right font-semibold">
                        {redemption.points_used.toLocaleString()}
                      </TableCell>
                      <TableCell>
                        <Badge variant={getStatusBadge(redemption.status)}>{redemption.status}</Badge>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-2">
                          {redemption.status === 'pending' && (
                            <>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                  updateStatusMutation.mutate({ id: redemption.id, status: 'completed' })
                                }
                                disabled={updateStatusMutation.isPending}
                              >
                                <Icon icon="solar:check-circle-bold" className="h-4 w-4 text-green-600" />
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleCancelRedemption(redemption)}
                                disabled={cancelMutation.isPending}
                              >
                                <Icon icon="solar:close-circle-bold" className="h-4 w-4 text-red-600" />
                              </Button>
                            </>
                          )}
                          {redemption.status !== 'pending' && (
                            <span className="text-sm text-muted-foreground">-</span>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                      No redemptions found
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Cancel Confirmation Dialog */}
      <Dialog open={cancelDialogOpen} onOpenChange={setCancelDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Cancel Redemption</DialogTitle>
            <DialogDescription>
              Are you sure you want to cancel this redemption? Points will be refunded to the customer.
            </DialogDescription>
          </DialogHeader>
          {redemptionToCancel && (
            <div className="space-y-4">
              <div className="rounded-lg border p-4">
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Customer:</span>
                    <span className="font-medium">
                      {redemptionToCancel.customer_loyalty?.customer?.name}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Type:</span>
                    <Badge variant={getTypeBadge(redemptionToCancel.type)}>
                      {redemptionToCancel.type}
                    </Badge>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Points to Refund:</span>
                    <span className="font-semibold">{redemptionToCancel.points_used.toLocaleString()}</span>
                  </div>
                </div>
              </div>
              <div className="rounded-lg bg-yellow-50 p-4 text-sm text-yellow-800">
                <Icon icon="solar:info-circle-bold" className="inline mr-2 h-4 w-4" />
                This action cannot be undone. The customer will receive their points back.
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setCancelDialogOpen(false)}>
              Keep Redemption
            </Button>
            <Button variant="destructive" onClick={confirmCancel} disabled={cancelMutation.isPending}>
              {cancelMutation.isPending ? 'Cancelling...' : 'Cancel Redemption'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
