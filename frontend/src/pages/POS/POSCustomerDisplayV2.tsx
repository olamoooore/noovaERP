import { useEffect, useState } from 'react'
import { useQuery } from '@tantml:parameter>
import { useParams, useSearchParams } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { formatCurrency, resolveImageUrl } from '@/lib/utils'
import { useSettingsStore } from '@/store/settingsStore'
import { PaymentPendingStage } from '@/components/pos/display/PaymentPendingStage'
import { PaymentSuccessStage } from '@/components/pos/display/PaymentSuccessStage'
import { PaymentFailedStage } from '@/components/pos/display/PaymentFailedStage'
import { SessionEndedStage } from '@/components/pos/display/SessionEndedStage'

interface DisplayData {
  state: 'idle' | 'session-active' | 'cart-active' | 'member-identified' | 'payment-pending' | 'payment-success' | 'payment-failed'
  payment_state?: string | null
  items?: Array<{
    name: string
    sku: string
    quantity: number
    unit_price: number
    line_subtotal: number
    tax_amount: number
    line_total: number
    image_url?: string | null
  }>
  subtotal?: number
  tax_total?: number
  discount_amount?: number
  total?: number
  discount?: {
    type: string
    value: number
    amount: number
  } | null
  customer?: {
    first_name: string
    loyalty_tier?: string | null
    loyalty_points?: number
  } | null
  payment?: {
    state: string
    method?: string | null
    amount_tendered?: number | null
    change_due?: number | null
  } | null
  terminal_name?: string
  message?: string
  session_opened_at?: string
  last_updated_at?: string
}

export function POSCustomerDisplayV2() {
  const { terminalId } = useParams<{ terminalId: string }>()
  const [searchParams] = useSearchParams()
  const displayToken = searchParams.get('token')

  const currencyCode = useSettingsStore((state) => state.currencyCode)
  const isSettingsLoaded = useSettingsStore((state) => state.isLoaded)
  const loadSettings = useSettingsStore((state) => state.loadSettings)

  const [currentTime, setCurrentTime] = useState(() => new Date())
  const [isFullscreen, setIsFullscreen] = useState(() => typeof document !== 'undefined' && !!document.fullscreenElement)
  const [lastAddedItemIndex, setLastAddedItemIndex] = useState<number | null>(null)

  useEffect(() => {
    if (!isSettingsLoaded) {
      void loadSettings()
    }
  }, [isSettingsLoaded, loadSettings])

  useEffect(() => {
    const timerId = window.setInterval(() => setCurrentTime(new Date()), 1000)
    return () => window.clearInterval(timerId)
  }, [])

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement)
    }

    document.addEventListener('fullscreenchange', handleFullscreenChange)
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange)
  }, [])

  // Poll backend for display data
  const { data: displayData, isError, error } = useQuery<DisplayData>({
    queryKey: ['customer-display', terminalId],
    queryFn: async () => {
      const headers: Record<string, string> = {}
      if (displayToken) {
        headers['Authorization'] = `Bearer ${displayToken}`
      }
      
      const response = await api.get(`/pos/display/${terminalId}`, { headers })
      return response.data
    },
    refetchInterval: (data) => {
      // Poll every 1 second during active cart or payment
      if (data?.state === 'cart-active' || data?.state === 'payment-pending') {
        return 1000
      }
      // Poll every 3 seconds during idle
      return 3000
    },
    retry: 3,
    enabled: !!terminalId,
  })

  // Track last added item for highlight animation
  useEffect(() => {
    if (!displayData?.items || displayData.items.length === 0) {
      setLastAddedItemIndex(null)
      return
    }

    const latestIndex = displayData.items.length - 1
    setLastAddedItemIndex(latestIndex)

    const timeoutId = window.setTimeout(() => setLastAddedItemIndex(null), 1200)
    return () => window.clearTimeout(timeoutId)
  }, [displayData?.items?.length])

  const handleToggleFullscreen = () => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen().catch((err) => {
        console.error('Failed to enter fullscreen:', err)
      })
    } else {
      document.exitFullscreen().catch((err) => {
        console.error('Failed to exit fullscreen:', err)
      })
    }
  }

  // Error state
  if (isError) {
    const errorMessage = error instanceof Error ? error.message : 'Failed to connect to display'
    return (
      <div className="flex h-screen flex-col items-center justify-center bg-gradient-to-br from-red-50 to-rose-100 p-8">
        <Icon icon="mdi:alert-circle" className="h-32 w-32 text-red-600" />
        <h2 className="mt-6 text-4xl font-bold text-red-900">Display Error</h2>
        <p className="mt-2 text-xl text-red-700">{errorMessage}</p>
        <p className="mt-4 text-lg text-gray-600">Please contact support</p>
      </div>
    )
  }

  // Loading state
  if (!displayData) {
    return (
      <div className="flex h-screen items-center justify-center bg-gradient-to-br from-gray-50 to-slate-100">
        <Icon icon="svg-spinners:ring-resize" className="h-24 w-24 text-gray-400" />
      </div>
    )
  }

  // Payment states
  if (displayData.state === 'payment-pending') {
    return <PaymentPendingStage />
  }

  if (displayData.state === 'payment-success') {
    return (
      <PaymentSuccessStage
        total={displayData.total || 0}
        changeDue={displayData.payment?.change_due || 0}
        currencyCode={currencyCode}
      />
    )
  }

  if (displayData.state === 'payment-failed') {
    return <PaymentFailedStage />
  }

  // Idle or session-active state
  if (displayData.state === 'idle' || displayData.state === 'session-active') {
    return (
      <SessionEndedStage message={displayData.message || 'Welcome!'} />
    )
  }

  // Cart active or member identified state
  const items = displayData.items || []
  const subtotal = displayData.subtotal || 0
  const taxAmount = displayData.tax_total || 0
  const discountAmount = displayData.discount_amount || 0
  const total = displayData.total || 0

  return (
    <div className="relative flex h-screen flex-col overflow-hidden bg-gradient-to-br from-slate-50 via-blue-50 to-slate-100">
      {/* Header */}
      <header className="flex items-center justify-between border-b border-gray-200 bg-white/90 px-6 py-4 shadow-sm backdrop-blur-sm">
        <div className="flex items-center space-x-4">
          <Icon icon="mdi:cart" className="h-8 w-8 text-blue-600" />
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              {displayData.terminal_name || 'Customer Display'}
            </h1>
            <p className="text-sm text-gray-500">
              {currentTime.toLocaleTimeString()}
            </p>
          </div>
        </div>

        <button
          onClick={handleToggleFullscreen}
          className="rounded-lg p-2 hover:bg-gray-100"
          title={isFullscreen ? 'Exit Fullscreen' : 'Enter Fullscreen'}
        >
          <Icon
            icon={isFullscreen ? 'mdi:fullscreen-exit' : 'mdi:fullscreen'}
            className="h-6 w-6 text-gray-600"
          />
        </button>
      </header>

      {/* Main Content */}
      <div className="flex flex-1 overflow-hidden">
        {/* Cart Items */}
        <div className="flex-1 overflow-y-auto p-6">
          <div className="space-y-4">
            {items.map((item, index) => (
              <div
                key={index}
                className={`flex items-center space-x-4 rounded-lg bg-white p-4 shadow-sm transition-all ${
                  index === lastAddedItemIndex
                    ? 'ring-2 ring-blue-500 ring-offset-2'
                    : ''
                }`}
              >
                {item.image_url && (
                  <img
                    src={resolveImageUrl(item.image_url)}
                    alt={item.name}
                    className="h-16 w-16 rounded-lg object-cover"
                  />
                )}
                <div className="flex-1">
                  <h3 className="text-lg font-semibold text-gray-900">{item.name}</h3>
                  <p className="text-sm text-gray-500">SKU: {item.sku}</p>
                </div>
                <div className="text-right">
                  <p className="text-sm text-gray-600">Qty: {item.quantity}</p>
                  <p className="text-lg font-bold text-gray-900">
                    {formatCurrency(item.line_total, currencyCode)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* Order Summary */}
        <div className="w-96 border-l border-gray-200 bg-white p-6">
          <h2 className="mb-6 text-2xl font-bold text-gray-900">Order Summary</h2>

          {displayData.customer && (
            <div className="mb-6 rounded-lg bg-blue-50 p-4">
              <p className="text-sm font-medium text-blue-900">Member</p>
              <p className="text-lg font-bold text-blue-700">
                {displayData.customer.first_name}
              </p>
              {displayData.customer.loyalty_tier && (
                <p className="text-sm text-blue-600">
                  {displayData.customer.loyalty_tier} • {displayData.customer.loyalty_points} pts
                </p>
              )}
            </div>
          )}

          <div className="space-y-3">
            <div className="flex justify-between text-lg">
              <span className="text-gray-600">Subtotal</span>
              <span className="font-semibold text-gray-900">
                {formatCurrency(subtotal, currencyCode)}
              </span>
            </div>

            {discountAmount > 0 && (
              <div className="flex justify-between text-lg text-green-600">
                <span>Discount</span>
                <span className="font-semibold">
                  -{formatCurrency(discountAmount, currencyCode)}
                </span>
              </div>
            )}

            <div className="flex justify-between text-lg">
              <span className="text-gray-600">Tax</span>
              <span className="font-semibold text-gray-900">
                {formatCurrency(taxAmount, currencyCode)}
              </span>
            </div>

            <div className="border-t border-gray-200 pt-3">
              <div className="flex justify-between text-3xl font-bold">
                <span className="text-gray-900">Total</span>
                <span className="text-blue-600">
                  {formatCurrency(total, currencyCode)}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
