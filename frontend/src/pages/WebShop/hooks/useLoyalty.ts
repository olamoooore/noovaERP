import { useState, useEffect, useCallback } from 'react'
import api from '@/lib/api'

interface LoyaltyBalance {
  enrolled: boolean
  points: {
    current: number
    lifetime: number
    earned: number
    redeemed: number
  }
  tier: {
    id: number
    name: string
    color: string
    multiplier: number
    icon: string
  } | null
  next_tier: {
    name: string
    points_needed: number
  } | null
  progress: {
    current: number
    needed: number
    percent: number
  } | null
}

interface Transaction {
  id: number
  type: 'earn' | 'redeem' | 'adjust' | 'expire'
  points: number
  balance_after: number
  description: string
  reference_type: string
  reference_id: number | null
  created_at: string
  expires_at: string | null
}

export function useLoyalty() {
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null)
  const [transactions, setTransactions] = useState<Transaction[]>([])
  const [loading, setLoading] = useState(false)

  const fetchBalance = useCallback(async () => {
    try {
      const token = localStorage.getItem('web_shop_token')
      const response = await api.get('/web-shop/loyalty/balance', {
        headers: { Authorization: `Bearer ${token}` },
      })
      setBalance(response.data)
      return response.data
    } catch (error) {
      console.error('Failed to fetch loyalty balance:', error)
      return null
    }
  }, [])

  const fetchHistory = useCallback(async (page = 1) => {
    try {
      setLoading(true)
      const token = localStorage.getItem('web_shop_token')
      const response = await api.get('/web-shop/loyalty/history', {
        headers: { Authorization: `Bearer ${token}` },
        params: { page },
      })
      setTransactions(response.data.transactions)
      return response.data
    } catch (error) {
      console.error('Failed to fetch loyalty history:', error)
      return null
    } finally {
      setLoading(false)
    }
  }, [])

  const calculateDiscount = useCallback(async (points: number, orderAmount: number) => {
    try {
      const token = localStorage.getItem('web_shop_token')
      const response = await api.post(
        '/web-shop/loyalty/calculate',
        { points, order_amount: orderAmount },
        { headers: { Authorization: `Bearer ${token}` } }
      )
      return response.data
    } catch (error: any) {
      return {
        can_redeem: false,
        reason: error.response?.data?.reason || 'Calculation failed',
      }
    }
  }, [])

  return {
    balance,
    transactions,
    loading,
    fetchBalance,
    fetchHistory,
    calculateDiscount,
  }
}
