import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

export function LoyaltyDashboardPage() {
  const { data: stats, isLoading } = useQuery({
    queryKey: ['loyalty-dashboard'],
    queryFn: async () => {
      const response = await api.get('/loyalty/dashboard')
      return response.data
    },
  })

  if (isLoading) {
    return (
      <div className="flex h-[50vh] items-center justify-center">
        <Icon icon="solar:loading-bold-duotone" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Loyalty Program</h1>
          <p className="text-muted-foreground">Manage customer loyalty and rewards</p>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Members</CardTitle>
            <Icon icon="solar:users-group-rounded-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_members || 0}</div>
            <p className="text-xs text-muted-foreground">Active loyalty accounts</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Points Issued</CardTitle>
            <Icon icon="solar:star-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_points_issued?.toLocaleString() || 0}</div>
            <p className="text-xs text-muted-foreground">All time</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Points Redeemed</CardTitle>
            <Icon icon="solar:gift-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.total_points_redeemed?.toLocaleString() || 0}</div>
            <p className="text-xs text-muted-foreground">
              {formatCurrency(stats?.total_discount_given || 0)} in discounts
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Active Rewards</CardTitle>
            <Icon icon="solar:ticket-bold-duotone" className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats?.active_rewards || 0}</div>
            <p className="text-xs text-muted-foreground">Available for redemption</p>
          </CardContent>
        </Card>
      </div>

      {/* Tier Distribution */}
      <Card>
        <CardHeader>
          <CardTitle>Member Tier Distribution</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="space-y-4">
            {stats?.tier_distribution?.map((tier: any) => (
              <div key={tier.name} className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <div className="flex items-center gap-2">
                    <Icon
                      icon={
                        tier.name === 'Platinum'
                          ? 'solar:crown-bold'
                          : tier.name === 'Gold'
                          ? 'solar:medal-star-bold'
                          : tier.name === 'Silver'
                          ? 'solar:medal-ribbons-star-bold'
                          : 'solar:shield-user-bold'
                      }
                      className={`h-5 w-5 ${
                        tier.name === 'Platinum'
                          ? 'text-purple-500'
                          : tier.name === 'Gold'
                          ? 'text-yellow-500'
                          : tier.name === 'Silver'
                          ? 'text-gray-400'
                          : 'text-orange-500'
                      }`}
                    />
                    <span className="font-medium">{tier.name}</span>
                  </div>
                  <span className="text-muted-foreground">
                    {tier.count} members ({tier.percentage}%)
                  </span>
                </div>
                <Progress value={tier.percentage} className="h-2" />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Recent Activity */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Recent Points Earned</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {stats?.recent_earnings && stats.recent_earnings.length > 0 ? (
                stats.recent_earnings.map((transaction: any) => (
                <div key={transaction.id} className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                      <Icon icon="solar:add-circle-bold" className="h-5 w-5 text-green-600" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">{transaction.customer_name}</p>
                      <p className="text-xs text-muted-foreground">{transaction.description}</p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-bold text-green-600">+{transaction.points}</p>
                    <p className="text-xs text-muted-foreground">{transaction.date}</p>
                  </div>
                </div>
                ))
              ) : (
                <p className="text-center text-sm text-muted-foreground py-4">No recent activity</p>
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Recent Redemptions</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {stats?.recent_redemptions && stats.recent_redemptions.length > 0 ? (
                stats.recent_redemptions.map((redemption: any) => (
                <div key={redemption.id} className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100">
                      <Icon icon="solar:gift-bold" className="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">{redemption.customer_name}</p>
                      <p className="text-xs text-muted-foreground">{redemption.type}</p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className="text-sm font-bold text-blue-600">-{redemption.points}</p>
                    <p className="text-xs text-muted-foreground">{redemption.date}</p>
                  </div>
                </div>
                ))
              ) : (
                <p className="text-center text-sm text-muted-foreground py-4">No recent redemptions</p>
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
