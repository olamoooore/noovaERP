import { useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Progress } from '@/components/ui/progress'
import { useLoyalty } from '../../hooks/useLoyalty'
import { useToast } from '@/hooks/use-toast'

export function LoyaltyPage() {
  const { balance, transactions, loading, fetchBalance, fetchHistory } = useLoyalty()
  const { toast } = useToast()

  useEffect(() => {
    fetchBalance()
    fetchHistory()
  }, [fetchBalance, fetchHistory])

  const getTypeIcon = (type: string) => {
    switch (type) {
      case 'earn':
        return 'solar:gift-bold-duotone'
      case 'redeem':
        return 'solar:tag-bold-duotone'
      case 'adjust':
        return 'solar:settings-bold-duotone'
      case 'expire':
        return 'solar:clock-circle-bold-duotone'
      default:
        return 'solar:star-bold-duotone'
    }
  }

  const getTypeColor = (type: string) => {
    switch (type) {
      case 'earn':
        return 'text-green-500'
      case 'redeem':
        return 'text-blue-500'
      case 'adjust':
        return 'text-yellow-500'
      case 'expire':
        return 'text-red-500'
      default:
        return 'text-gray-500'
    }
  }

  if (!balance) {
    return <div>Loading...</div>
  }

  if (!balance.enrolled) {
    return (
      <Card>
        <CardContent className="pt-6 text-center">
          <Icon icon="solar:gift-bold-duotone" className="h-16 w-16 mx-auto text-muted-foreground mb-4" />
          <h3 className="text-lg font-semibold mb-2">Not Enrolled Yet</h3>
          <p className="text-muted-foreground">
            Make your first purchase to automatically join our loyalty program!
          </p>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-6">
      {/* Points Balance Card */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex items-center justify-between mb-4">
            <div>
              <p className="text-sm text-muted-foreground">Current Balance</p>
              <p className="text-4xl font-bold">{balance.points.current.toLocaleString()}</p>
              <p className="text-sm text-muted-foreground">points</p>
            </div>
            {balance.tier && (
              <div className="text-right">
                <div
                  className="inline-flex items-center gap-2 px-4 py-2 rounded-full font-semibold"
                  style={{ backgroundColor: balance.tier.color + '20', color: balance.tier.color }}
                >
                  <Icon icon={balance.tier.icon || 'solar:crown-bold-duotone'} className="h-5 w-5" />
                  {balance.tier.name}
                </div>
                <p className="text-sm text-muted-foreground mt-1">
                  {balance.tier.multiplier}x points multiplier
                </p>
              </div>
            )}
          </div>

          {/* Tier Progress */}
          {balance.next_tier && balance.progress && (
            <div className="mt-6 pt-6 border-t">
              <div className="flex justify-between text-sm mb-2">
                <span>Progress to {balance.next_tier.name}</span>
                <span>{balance.progress.percent}%</span>
              </div>
              <Progress value={balance.progress.percent} className="h-2" />
              <p className="text-xs text-muted-foreground mt-2">
                {balance.next_tier.points_needed.toLocaleString()} more points needed
              </p>
            </div>
          )}

          {/* Stats Grid */}
          <div className="grid grid-cols-3 gap-4 mt-6 pt-6 border-t">
            <div className="text-center">
              <p className="text-2xl font-bold">{balance.points.lifetime.toLocaleString()}</p>
              <p className="text-xs text-muted-foreground">Lifetime Points</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold">{balance.points.earned.toLocaleString()}</p>
              <p className="text-xs text-muted-foreground">Total Earned</p>
            </div>
            <div className="text-center">
              <p className="text-2xl font-bold">{balance.points.redeemed.toLocaleString()}</p>
              <p className="text-xs text-muted-foreground">Total Redeemed</p>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Transactions History */}
      <Card>
        <CardHeader>
          <CardTitle>Points History</CardTitle>
        </CardHeader>
        <CardContent>
          {loading ? (
            <div>Loading history...</div>
          ) : transactions.length === 0 ? (
            <p className="text-muted-foreground text-center py-4">No transactions yet</p>
          ) : (
            <div className="space-y-4">
              {transactions.map((transaction) => (
                <div key={transaction.id} className="flex items-center justify-between p-3 rounded-lg bg-muted/50">
                  <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-full bg-background ${getTypeColor(transaction.type)}`}>
                      <Icon icon={getTypeIcon(transaction.type)} className="h-5 w-5" />
                    </div>
                    <div>
                      <p className="font-medium">{transaction.description}</p>
                      <p className="text-xs text-muted-foreground">
                        {new Date(transaction.created_at).toLocaleDateString()}
                        {transaction.expires_at && (
                          <span className="ml-2 text-yellow-600">
                            Expires: {new Date(transaction.expires_at).toLocaleDateString()}
                          </span>
                        )}
                      </p>
                    </div>
                  </div>
                  <div className="text-right">
                    <p className={`font-bold ${transaction.points > 0 ? 'text-green-600' : 'text-blue-600'}`}>
                      {transaction.points > 0 ? '+' : ''}{transaction.points}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      Balance: {transaction.balance_after}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* How It Works */}
      <Card>
        <CardHeader>
          <CardTitle>How It Works</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="text-center p-4">
              <div className="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <Icon icon="solar:cart-check-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <h4 className="font-semibold mb-1">Shop</h4>
              <p className="text-sm text-muted-foreground">Earn points on every purchase</p>
            </div>
            <div className="text-center p-4">
              <div className="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <Icon icon="solar:gift-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <h4 className="font-semibold mb-1">Earn</h4>
              <p className="text-sm text-muted-foreground">Points add up automatically</p>
            </div>
            <div className="text-center p-4">
              <div className="w-12 h-12 mx-auto mb-3 rounded-full bg-primary/10 flex items-center justify-center">
                <Icon icon="solar:tag-bold-duotone" className="h-6 w-6 text-primary" />
              </div>
              <h4 className="font-semibold mb-1">Redeem</h4>
              <p className="text-sm text-muted-foreground">Use points for discounts</p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
