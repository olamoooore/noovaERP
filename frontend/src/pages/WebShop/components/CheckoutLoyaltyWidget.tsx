import { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useLoyalty } from '../hooks/useLoyalty'
import { useToast } from '@/hooks/use-toast'

interface CheckoutLoyaltyWidgetProps {
  orderAmount: number
  onRedeem: (points: number, discount: number) => void
  appliedPoints: number
  appliedDiscount: number
}

export function CheckoutLoyaltyWidget({
  orderAmount,
  onRedeem,
  appliedPoints,
  appliedDiscount,
}: CheckoutLoyaltyWidgetProps) {
  const { balance, fetchBalance, calculateDiscount } = useLoyalty()
  const { toast } = useToast()
  const [pointsInput, setPointsInput] = useState('')
  const [calculating, setCalculating] = useState(false)
  const [preview, setPreview] = useState<{ points: number; discount: number } | null>(null)

  useEffect(() => {
    fetchBalance()
  }, [fetchBalance])

  const handleCalculate = async () => {
    const points = parseInt(pointsInput)
    if (!points || points <= 0) {
      toast({
        variant: 'destructive',
        title: 'Invalid points',
        description: 'Please enter a valid number of points.',
      })
      return
    }

    setCalculating(true)
    const result = await calculateDiscount(points, orderAmount)
    setCalculating(false)

    if (result.can_redeem) {
      setPreview({
        points: result.points,
        discount: result.discount_amount,
      })
    } else {
      toast({
        variant: 'destructive',
        title: 'Cannot redeem',
        description: result.reason || 'Unable to redeem points.',
      })
    }
  }

  const handleApply = () => {
    if (preview) {
      onRedeem(preview.points, preview.discount)
      toast({
        title: 'Points applied!',
        description: `${preview.points} points redeemed for $${preview.discount.toFixed(2)} discount.`,
      })
    }
  }

  const handleRemove = () => {
    onRedeem(0, 0)
    setPreview(null)
    setPointsInput('')
  }

  if (!balance || !balance.enrolled) {
    return null
  }

  if (appliedPoints > 0) {
    return (
      <Card className="border-green-200 bg-green-50/50">
        <CardContent className="pt-6">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <Icon icon="solar:check-circle-bold-duotone" className="h-6 w-6 text-green-600" />
              <div>
                <p className="font-semibold text-green-900">Points Applied</p>
                <p className="text-sm text-green-700">
                  {appliedPoints} points = ${appliedDiscount.toFixed(2)} off
                </p>
              </div>
            </div>
            <Button variant="outline" size="sm" onClick={handleRemove}>
              Remove
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base flex items-center gap-2">
          <Icon icon="solar:gift-bold-duotone" className="h-5 w-5" />
          Loyalty Points
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-center justify-between">
          <span className="text-sm text-muted-foreground">Available Balance</span>
          <span className="font-semibold">{balance.points.current.toLocaleString()} points</span>
        </div>

        {balance.tier && (
          <div className="flex items-center gap-2 text-sm">
            <span
              className="px-2 py-1 rounded-full text-xs font-medium"
              style={{ backgroundColor: balance.tier.color + '20', color: balance.tier.color }}
            >
              {balance.tier.name}
            </span>
            <span className="text-muted-foreground">{balance.tier.multiplier}x multiplier</span>
          </div>
        )}

        <div className="flex gap-2">
          <Input
            type="number"
            placeholder="Points to redeem"
            value={pointsInput}
            onChange={(e) => setPointsInput(e.target.value)}
            min="1"
            max={balance.points.current}
          />
          <Button
            variant="outline"
            onClick={handleCalculate}
            disabled={calculating || !pointsInput}
          >
            {calculating ? '...' : 'Calculate'}
          </Button>
        </div>

        {preview && (
          <div className="p-3 bg-muted rounded-lg">
            <div className="flex justify-between items-center mb-2">
              <span className="text-sm">Discount Amount</span>
              <span className="font-bold text-lg">${preview.discount.toFixed(2)}</span>
            </div>
            <div className="flex justify-between items-center text-sm text-muted-foreground">
              <span>New Balance</span>
              <span>{(balance.points.current - preview.points).toLocaleString()} points</span>
            </div>
            <Button className="w-full mt-3" onClick={handleApply}>
              Apply Discount
            </Button>
          </div>
        )}

        <p className="text-xs text-muted-foreground">
          Points will be deducted immediately. Minimum redemption may apply.
        </p>
      </CardContent>
    </Card>
  )
}
