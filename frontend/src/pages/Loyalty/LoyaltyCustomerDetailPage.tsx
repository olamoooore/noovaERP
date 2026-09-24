import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function LoyaltyCustomerDetailPage() {
  const { customerId } = useParams<{ customerId: string }>()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [redeemDialogOpen, setRedeemDialogOpen] = useState(false)
  const [selectedReward, setSelectedReward] = useState<any>(null)
  const [pointsToRedeem, setPointsToRedeem] = useState('')
  
  const [adjustDialogOpen, setAdjustDialogOpen] = useState(false)
  const [adjustPoints, setAdjustPoints] = useState('')
  const [adjustReason, setAdjustReason] = useState('')

  const { data: customer, isLoading } = useQuery({
    queryKey: ['loyalty-customer', customerId],
    queryFn: async () => {
      const response = await api.get(`/loyalty/customers/${customerId}`)
      return response.data
    },
  })

  const { data: dashboard } = useQuery({
    queryKey: ['loyalty-customer-dashboard', customerId],
    queryFn: async () => {
      const response = await api.get(`/loyalty/customers/${customerId}/dashboard`)
      return response.data
    },
  })

  const { data: transactions } = useQuery({
    queryKey: ['loyalty-customer-transactions', customerId],
    queryFn: async () => {
      const response = await api.get(`/loyalty/customers/${customerId}/transactions`)
      return response.data
    },
  })

  const { data: rewards } = useQuery({
    queryKey: ['loyalty-rewards-catalog', customerId],
    queryFn: async () => {
      const response = await api.get(`/loyalty/rewards/catalog/${customerId}`)
      return response.data
    },
  })

  const { data: redemptions } = useQuery({
    queryKey: ['loyalty-redemptions', customerId],
    queryFn: async () => {
      const response = await api.get(`/loyalty/redemptions/customers/${customerId}`)
      return response.data
    },
  })

  const redeemRewardMutation = useMutation({
    mutationFn: async (data: { reward_id: number }) => {
      const response = await api.post('/loyalty/redemptions/reward', {
        customer_id: customerId,
        ...data,
      })
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Reward redeemed successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer', customerId] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer-transactions', customerId] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-redemptions', customerId] })
      setRedeemDialogOpen(false)
      setSelectedReward(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to redeem reward',
        variant: 'destructive',
      })
    },
  })

  const redeemDiscountMutation = useMutation({
    mutationFn: async (data: { points: number; invoice_id?: number }) => {
      const response = await api.post('/loyalty/redemptions/discount', {
        customer_id: customerId,
        ...data,
      })
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Points redeemed for discount successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer', customerId] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer-transactions', customerId] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-redemptions', customerId] })
      setRedeemDialogOpen(false)
      setPointsToRedeem('')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to redeem points',
        variant: 'destructive',
      })
    },
  })

  const adjustPointsMutation = useMutation({
    mutationFn: async (data: { points: number; reason: string }) => {
      const response = await api.post(`/loyalty/customers/${customerId}/adjust`, data)
      return response.data
    },
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'Points adjusted successfully',
      })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer', customerId] })
      queryClient.invalidateQueries({ queryKey: ['loyalty-customer-transactions', customerId] })
      setAdjustDialogOpen(false)
      setAdjustPoints('')
      setAdjustReason('')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to adjust points',
        variant: 'destructive',
      })
    },
  })

  const handleRedeemReward = (reward: any) => {
    setSelectedReward(reward)
    setRedeemDialogOpen(true)
  }

  const confirmRedeemReward = () => {
    if (selectedReward) {
      redeemRewardMutation.mutate({ reward_id: selectedReward.id })
    }
  }

  const handleRedeemDiscount = () => {
    const points = parseInt(pointsToRedeem)
    if (points > 0) {
      redeemDiscountMutation.mutate({ points })
    }
  }

  const handleAdjustPoints = () => {
    const points = parseInt(adjustPoints)
    if (points !== 0 && adjustReason.trim()) {
      adjustPointsMutation.mutate({ points, reason: adjustReason })
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-[50vh] items-center justify-center">
        <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!customer?.enrolled) {
    return (
      <div className="flex h-[50vh] flex-col items-center justify-center gap-4">
        <Icon icon="solar:user-cross-bold-duotone" className="h-16 w-16 text-muted-foreground" />
        <div className="text-center">
          <h2 className="text-2xl font-bold">Customer Not Enrolled</h2>
          <p className="text-muted-foreground">This customer is not enrolled in the loyalty program</p>
        </div>
      </div>
    )
  }

  const loyalty = customer.customer_loyalty
  const nextTier = customer.next_tier

  const getTierBadge = (tierName: string) => {
    const colors = {
      Platinum: 'bg-purple-100 text-purple-800 border-purple-200',
      Gold: 'bg-yellow-100 text-yellow-800 border-yellow-200',
      Silver: 'bg-gray-100 text-gray-800 border-gray-200',
      Bronze: 'bg-orange-100 text-orange-800 border-orange-200',
    }
    return colors[tierName as keyof typeof colors] || colors.Bronze
  }

  const getTierIcon = (tierName: string) => {
    const icons = {
      Platinum: 'solar:crown-bold',
      Gold: 'solar:medal-star-bold',
      Silver: 'solar:medal-ribbons-star-bold',
      Bronze: 'solar:shield-user-bold',
    }
    return icons[tierName as keyof typeof icons] || icons.Bronze
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">{loyalty.customer?.name || 'Customer'}</h1>
          <p className="text-muted-foreground">{loyalty.customer?.email}</p>
        </div>
        <div className="flex items-center gap-3">
          <HeaderActionButton icon="solar:wallet-bold-duotone" variant="outline" onClick={() => setAdjustDialogOpen(true)}>
            Adjust Points
          </HeaderActionButton>
          <Badge variant="outline" className={getTierBadge(loyalty.tier?.name)}>
            <Icon icon={getTierIcon(loyalty.tier?.name)} className="mr-1 h-4 w-4" />
            {loyalty.tier?.name} Member
          </Badge>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Current Points</CardTitle>
            <Icon icon="solar:star-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{loyalty.current_points?.toLocaleString() || 0}</div>
            <p className="text-xs text-muted-foreground">Available to redeem</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Lifetime Points</CardTitle>
            <Icon icon="solar:chart-2-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{loyalty.lifetime_points?.toLocaleString() || 0}</div>
            <p className="text-xs text-muted-foreground">Total earned</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Points Redeemed</CardTitle>
            <Icon icon="solar:gift-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{loyalty.points_redeemed?.toLocaleString() || 0}</div>
            <p className="text-xs text-muted-foreground">All time</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Member Since</CardTitle>
            <Icon icon="solar:calendar-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {loyalty.joined_at ? new Date(loyalty.joined_at).toLocaleDateString() : 'N/A'}
            </div>
            <p className="text-xs text-muted-foreground">Join date</p>
          </CardContent>
        </Card>
      </div>

      {/* Tier Progress */}
      {nextTier && (
        <Card>
          <CardHeader>
            <CardTitle>Tier Progress</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center justify-between text-sm">
              <span className="font-medium">
                Progress to {nextTier.name}
              </span>
              <span className="text-muted-foreground">
                {nextTier.points_needed?.toLocaleString() || 0} points needed
              </span>
            </div>
            <Progress value={nextTier.progress_percentage || 0} className="h-2" />
            <p className="text-xs text-muted-foreground">
              {nextTier.progress_percentage || 0}% complete
            </p>
          </CardContent>
        </Card>
      )}

      {/* Tabs */}
      <Tabs defaultValue="transactions" className="space-y-4">
        <TabsList>
          <TabsTrigger value="transactions">Transaction History</TabsTrigger>
          <TabsTrigger value="redemptions">Redemptions</TabsTrigger>
          <TabsTrigger value="benefits">Tier Benefits</TabsTrigger>
        </TabsList>

        <TabsContent value="transactions" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Transaction History</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Points</TableHead>
                    <TableHead className="text-right">Balance</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {transactions?.data && transactions.data.length > 0 ? (
                    transactions.data.map((transaction: any) => (
                      <TableRow key={transaction.id}>
                        <TableCell>{new Date(transaction.created_at).toLocaleDateString()}</TableCell>
                        <TableCell>
                          <Badge variant={transaction.type === 'earn' ? 'default' : 'secondary'}>
                            {transaction.type}
                          </Badge>
                        </TableCell>
                        <TableCell>{transaction.description}</TableCell>
                        <TableCell className="text-right">
                          <span
                            className={
                              transaction.type === 'earn' ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'
                            }
                          >
                            {transaction.type === 'earn' ? '+' : '-'}
                            {Math.abs(transaction.points).toLocaleString()}
                          </span>
                        </TableCell>
                        <TableCell className="text-right">{transaction.balance_after?.toLocaleString()}</TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={5} className="text-center text-muted-foreground">
                        No transactions yet
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="redemptions" className="space-y-4">
          {/* Redeem for Discount */}
          <Card>
            <CardHeader>
              <CardTitle>Redeem Points for Discount</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-end gap-4">
                <div className="flex-1">
                  <Label htmlFor="points">Points to Redeem</Label>
                  <Input
                    id="points"
                    type="number"
                    placeholder="Enter points"
                    value={pointsToRedeem}
                    onChange={(e) => setPointsToRedeem(e.target.value)}
                    min="100"
                    max={loyalty?.current_points || 0}
                  />
                  <p className="text-xs text-muted-foreground mt-1">
                    Available: {loyalty?.current_points?.toLocaleString() || 0} points
                  </p>
                </div>
                <Button
                  onClick={handleRedeemDiscount}
                  disabled={!pointsToRedeem || parseInt(pointsToRedeem) < 100 || redeemDiscountMutation.isPending}
                >
                  {redeemDiscountMutation.isPending ? 'Redeeming...' : 'Redeem for Discount'}
                </Button>
              </div>
              <p className="text-sm text-muted-foreground">
                100 points = $1 discount. Minimum 100 points required.
              </p>
            </CardContent>
          </Card>

          {/* Available Rewards */}
          <Card>
            <CardHeader>
              <CardTitle>Available Rewards</CardTitle>
            </CardHeader>
            <CardContent>
              {rewards?.data && rewards.data.length > 0 ? (
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                  {rewards.data.map((reward: any) => (
                    <Card key={reward.id} className="overflow-hidden">
                      {reward.image_url && (
                        <div className="aspect-video bg-muted">
                          <img
                            src={reward.image_url}
                            alt={reward.name}
                            className="h-full w-full object-cover"
                          />
                        </div>
                      )}
                      <CardContent className="p-4 space-y-2">
                        <h3 className="font-semibold">{reward.name}</h3>
                        <p className="text-sm text-muted-foreground line-clamp-2">{reward.description}</p>
                        <div className="flex items-center justify-between pt-2">
                          <Badge variant="secondary">
                            <Icon icon="solar:star-bold" className="mr-1 h-3 w-3" />
                            {reward.points_cost} points
                          </Badge>
                          <Button
                            size="sm"
                            onClick={() => handleRedeemReward(reward)}
                            disabled={
                              reward.stock_quantity === 0 ||
                              (loyalty?.current_points || 0) < reward.points_cost
                            }
                          >
                            Redeem
                          </Button>
                        </div>
                        {reward.stock_quantity === 0 && (
                          <p className="text-xs text-red-500">Out of stock</p>
                        )}
                        {(loyalty?.current_points || 0) < reward.points_cost && (
                          <p className="text-xs text-orange-500">Insufficient points</p>
                        )}
                      </CardContent>
                    </Card>
                  ))}
                </div>
              ) : (
                <p className="text-center text-muted-foreground py-8">No rewards available</p>
              )}
            </CardContent>
          </Card>

          {/* Redemption History */}
          <Card>
            <CardHeader>
              <CardTitle>Redemption History</CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Date</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Points Used</TableHead>
                    <TableHead>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {redemptions?.data && redemptions.data.length > 0 ? (
                    redemptions.data.map((redemption: any) => (
                      <TableRow key={redemption.id}>
                        <TableCell>{new Date(redemption.created_at).toLocaleDateString()}</TableCell>
                        <TableCell>
                          <Badge variant={redemption.type === 'reward' ? 'default' : 'secondary'}>
                            {redemption.type}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {redemption.type === 'reward'
                            ? redemption.reward?.name || 'Reward'
                            : `Discount: $${redemption.discount_amount}`}
                        </TableCell>
                        <TableCell className="text-right">{redemption.points_used.toLocaleString()}</TableCell>
                        <TableCell>
                          <Badge
                            variant={
                              redemption.status === 'completed'
                                ? 'default'
                                : redemption.status === 'pending'
                                ? 'secondary'
                                : 'destructive'
                            }
                          >
                            {redemption.status}
                          </Badge>
                        </TableCell>
                      </TableRow>
                    ))
                  ) : (
                    <TableRow>
                      <TableCell colSpan={5} className="text-center text-muted-foreground">
                        No redemptions yet
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="benefits" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>{loyalty.tier?.name} Tier Benefits</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-4 md:grid-cols-2">
                <div className="flex items-start gap-3">
                  <Icon icon="solar:star-bold-duotone" className="h-5 w-5 text-yellow-500 mt-0.5" />
                  <div>
                    <p className="font-medium">Points Multiplier</p>
                    <p className="text-sm text-muted-foreground">
                      {loyalty.tier?.points_multiplier}x on all purchases
                    </p>
                  </div>
                </div>
                <div className="flex items-start gap-3">
                  <Icon icon="solar:sale-bold-duotone" className="h-5 w-5 text-green-500 mt-0.5" />
                  <div>
                    <p className="font-medium">Discount</p>
                    <p className="text-sm text-muted-foreground">
                      {loyalty.tier?.discount_percentage}% off on purchases
                    </p>
                  </div>
                </div>
              </div>
              {loyalty.tier?.benefits && (
                <div className="mt-4">
                  <p className="text-sm text-muted-foreground">{loyalty.tier.benefits}</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Redeem Reward Confirmation Dialog */}
      <Dialog open={redeemDialogOpen} onOpenChange={setRedeemDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Confirm Redemption</DialogTitle>
            <DialogDescription>
              Are you sure you want to redeem this reward for the customer?
            </DialogDescription>
          </DialogHeader>
          {selectedReward && (
            <div className="space-y-4">
              <div className="rounded-lg border p-4">
                <h3 className="font-semibold">{selectedReward.name}</h3>
                <p className="text-sm text-muted-foreground mt-1">{selectedReward.description}</p>
                <div className="flex items-center justify-between mt-3">
                  <span className="text-sm">Points Required:</span>
                  <Badge variant="secondary">
                    <Icon icon="solar:star-bold" className="mr-1 h-3 w-3" />
                    {selectedReward.points_cost} points
                  </Badge>
                </div>
              </div>
              <div className="rounded-lg bg-muted p-4">
                <div className="flex items-center justify-between text-sm">
                  <span>Current Points:</span>
                  <span className="font-semibold">{loyalty?.current_points?.toLocaleString()}</span>
                </div>
                <div className="flex items-center justify-between text-sm mt-2">
                  <span>After Redemption:</span>
                  <span className="font-semibold">
                    {((loyalty?.current_points || 0) - selectedReward.points_cost).toLocaleString()}
                  </span>
                </div>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setRedeemDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={confirmRedeemReward} disabled={redeemRewardMutation.isPending}>
              {redeemRewardMutation.isPending ? 'Redeeming...' : 'Confirm Redemption'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Adjust Points Dialog */}
      <Dialog open={adjustDialogOpen} onOpenChange={setAdjustDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Adjust Customer Points</DialogTitle>
            <DialogDescription>
              Manually add or deduct points for this customer. Use positive numbers to add, negative to deduct.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="rounded-lg bg-muted p-4">
              <div className="flex items-center justify-between text-sm">
                <span>Current Points:</span>
                <span className="font-semibold text-lg">{loyalty?.current_points?.toLocaleString()}</span>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="adjust-points">Points to Add/Deduct</Label>
              <Input
                id="adjust-points"
                type="number"
                placeholder="e.g., 100 or -50"
                value={adjustPoints}
                onChange={(e) => setAdjustPoints(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                Use positive numbers to add points, negative to deduct
              </p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="adjust-reason">Reason (Required)</Label>
              <Input
                id="adjust-reason"
                placeholder="e.g., Compensation for service issue"
                value={adjustReason}
                onChange={(e) => setAdjustReason(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                This will be recorded in the transaction history
              </p>
            </div>
            {adjustPoints && (
              <div className="rounded-lg border p-4">
                <div className="flex items-center justify-between text-sm">
                  <span>New Balance:</span>
                  <span className="font-semibold text-lg">
                    {((loyalty?.current_points || 0) + parseInt(adjustPoints || '0')).toLocaleString()}
                  </span>
                </div>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAdjustDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleAdjustPoints}
              disabled={!adjustPoints || !adjustReason.trim() || adjustPointsMutation.isPending}
            >
              {adjustPointsMutation.isPending ? 'Adjusting...' : 'Confirm Adjustment'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
