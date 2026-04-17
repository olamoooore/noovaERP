import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

// ─── Types ────────────────────────────────────────────────────────────────────

type DisplayState =
  | 'IDLE_SCREENSAVER'
  | 'ACTIVE_NO_CART'
  | 'MEMBER_NO_CART'
  | 'CART_ACTIVE'
  | 'PAYMENT_PENDING'
  | 'PAYMENT_APPROVED'
  | 'PAYMENT_FAILED'
  | 'COMPLETED'
  | 'LOCKED_REVIEW'

interface DisplayPayload {
  terminal: { code: string; name: string }
  session: { id: number; state: DisplayState; started_at: string | null; last_activity_at: string | null }
  store: { name: string; time: string; campaign_message: string | null }
  member: {
    identified: boolean
    display_name: string | null
    favorite_order: { label: string; items: string[] } | null
    recent_orders: { label: string; ordered_at: string }[]
  }
  cart_focus: {
    mode: string
    line_id: number | null
    product_id: number | null
    sku: string | null
    name: string | null
    variant_label: string | null
    qty: number
    unit_price: number
    line_total: number
    image_url: string | null
    status_label: string
    focus_reason: string
  } | null
  cart: {
    currency: string
    item_count: number
    items: {
      line_id: number
      product_id: number
      name: string
      variant_label: string | null
      sku: string | null
      qty: number
      unit_price: number
      line_total: number
      discount_amount: number
    }[]
    totals: { subtotal: number; discount: number; tax: number; grand_total: number }
  }
  payment: { status: string; method_label: string | null; status_label: string | null }
  ui_flags: {
    show_screensaver: boolean
    show_member_panel: boolean
    show_cart_focus_panel: boolean
    show_cart_panel: boolean
    show_payment_panel: boolean
    show_completed_panel: boolean
  }
}

interface ApiResponse {
  up_to_date?: boolean
  payload_version: number
  display_state: DisplayState
  occurred_at?: string
  payload?: DisplayPayload
}

// ─── Hook ─────────────────────────────────────────────────────────────────────

function useDisplayPayload(sessionId: number | null) {
  const [version, setVersion] = useState(0)
  const [cachedPayload, setCachedPayload] = useState<DisplayPayload | null>(null)
  const [displayState, setDisplayState] = useState<DisplayState>('IDLE_SCREENSAVER')

  const { data } = useQuery<ApiResponse>({
    queryKey: ['display-payload', sessionId, version],
    queryFn: async () => {
      const res = await api.get<ApiResponse>(
        `/pos/sessions/${sessionId}/display-payload?since_version=${version}`
      )
      return res.data
    },
    enabled: sessionId !== null,
    refetchInterval: 1000,
    retry: false,
    staleTime: 0,
  })

  useEffect(() => {
    if (!data) return
    if (data.up_to_date) return
    if (data.payload) {
      setCachedPayload(data.payload)
      setDisplayState(data.display_state)
      setVersion(data.payload_version)
    }
  }, [data])

  return { payload: cachedPayload, displayState }
}

// ─── Clock ────────────────────────────────────────────────────────────────────

function useClock() {
  const [time, setTime] = useState(new Date())
  useEffect(() => {
    const id = setInterval(() => setTime(new Date()), 1000)
    return () => clearInterval(id)
  }, [])
  return time
}

// ─── Display States ───────────────────────────────────────────────────────────

function ScreensaverState({ storeName }: { storeName: string }) {
  const time = useClock()
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-black text-white gap-8">
      <div className="flex flex-col items-center gap-2 opacity-90">
        <Icon icon="mdi:store" className="text-white/30" style={{ fontSize: 80 }} />
        <span className="text-3xl font-light tracking-widest uppercase text-white/40">
          {storeName}
        </span>
      </div>
      <div className="flex flex-col items-center">
        <span className="font-mono text-8xl font-thin tracking-tight">
          {time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })}
        </span>
        <span className="text-xl text-white/40 mt-2">
          {time.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
        </span>
      </div>
    </div>
  )
}

function ActiveNoCartState({ storeName, campaignMessage }: { storeName: string; campaignMessage: string | null }) {
  const time = useClock()
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-zinc-950 text-white gap-10">
      <div className="flex flex-col items-center gap-3">
        <Icon icon="mdi:store-outline" className="text-blue-400" style={{ fontSize: 64 }} />
        <span className="text-4xl font-semibold tracking-wide">{storeName}</span>
      </div>
      <div className="flex flex-col items-center gap-1 text-white/50">
        <span className="font-mono text-5xl font-light">
          {time.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true })}
        </span>
        <span className="text-sm">
          {time.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })}
        </span>
      </div>
      {campaignMessage && (
        <p className="text-base text-white/40 italic max-w-sm text-center">{campaignMessage}</p>
      )}
    </div>
  )
}

function MemberNoCartState({ member, storeName }: {
  member: DisplayPayload['member']
  storeName: string
}) {
  return (
    <div className="flex h-full w-full bg-zinc-950 text-white">
      <div className="flex flex-col w-1/2 h-full p-10 gap-6 border-r border-white/10">
        <div className="flex items-center gap-3">
          <div className="w-12 h-12 rounded-full bg-blue-500/20 flex items-center justify-center">
            <Icon icon="mdi:account" className="text-blue-400" style={{ fontSize: 28 }} />
          </div>
          <div>
            <p className="text-xs text-white/40 uppercase tracking-widest">Welcome back</p>
            <p className="text-xl font-semibold">{member.display_name ?? 'Valued Customer'}</p>
          </div>
        </div>
        {member.favorite_order && (
          <div className="flex flex-col gap-2 bg-white/5 rounded-xl p-5">
            <p className="text-xs text-white/40 uppercase tracking-widest">Your favourite</p>
            <p className="text-sm font-medium text-blue-300">{member.favorite_order.label}</p>
            <div className="flex flex-col gap-1 mt-1">
              {member.favorite_order.items.map((item, i) => (
                <p key={i} className="text-xs text-white/60">• {item}</p>
              ))}
            </div>
          </div>
        )}
        {member.recent_orders.length > 0 && (
          <div className="flex flex-col gap-2">
            <p className="text-xs text-white/40 uppercase tracking-widest">Recent orders</p>
            {member.recent_orders.map((order, i) => (
              <div key={i} className="flex items-center justify-between py-2 border-b border-white/5">
                <p className="text-sm text-white/80">{order.label}</p>
                <p className="text-xs text-white/30">{order.ordered_at}</p>
              </div>
            ))}
          </div>
        )}
      </div>
      <div className="flex flex-col items-center justify-center w-1/2 h-full gap-4 text-white/30">
        <Icon icon="mdi:store" style={{ fontSize: 72 }} />
        <span className="text-2xl font-light tracking-widest">{storeName}</span>
      </div>
    </div>
  )
}

function CartActiveState({ cart, cartFocus }: {
  cart: DisplayPayload['cart']
  cartFocus: DisplayPayload['cart_focus']
}) {
  const currency = cart.currency
  return (
    <div className="flex h-full w-full bg-zinc-950 text-white">
      <div className="flex flex-col items-center justify-center w-[45%] h-full border-r border-white/10 p-8 gap-6">
        {cartFocus ? (
          <>
            {cartFocus.image_url ? (
              <img
                src={cartFocus.image_url}
                alt={cartFocus.name ?? ''}
                className="w-56 h-56 object-contain rounded-2xl bg-white/5"
              />
            ) : (
              <div className="w-56 h-56 rounded-2xl bg-white/5 flex items-center justify-center">
                <Icon icon="mdi:package-variant" className="text-white/20" style={{ fontSize: 80 }} />
              </div>
            )}
            <div className="flex flex-col items-center text-center gap-1 w-full">
              <p className="text-xs font-medium uppercase tracking-widest text-blue-400">
                {cartFocus.status_label}
              </p>
              <p className="text-xl font-semibold line-clamp-2">{cartFocus.name}</p>
              {cartFocus.variant_label && (
                <p className="text-sm text-white/40">{cartFocus.variant_label}</p>
              )}
              <div className="flex items-center justify-between w-full mt-3 px-4">
                <span className="text-sm text-white/50">Qty {cartFocus.qty}</span>
                <span className="text-2xl font-bold text-white">
                  {formatCurrency(cartFocus.line_total, currency)}
                </span>
              </div>
            </div>
          </>
        ) : (
          <div className="flex flex-col items-center gap-4 text-white/20">
            <Icon icon="mdi:cart-outline" style={{ fontSize: 72 }} />
          </div>
        )}
      </div>
      <div className="flex flex-col w-[55%] h-full">
        <div className="flex-1 overflow-y-auto p-6 space-y-1">
          {cart.items.length === 0 ? (
            <div className="flex items-center justify-center h-full text-white/20">
              <p className="text-sm">No items yet</p>
            </div>
          ) : (
            cart.items.map((item) => (
              <div key={item.line_id} className="flex items-center justify-between py-2 border-b border-white/5">
                <div className="flex-1 min-w-0 pr-4">
                  <p className="text-sm font-medium truncate">{item.name}</p>
                  {item.variant_label && (
                    <p className="text-xs text-white/40">{item.variant_label}</p>
                  )}
                </div>
                <div className="flex items-center gap-3 text-sm shrink-0">
                  <span className="text-white/40">×{item.qty}</span>
                  <span className="w-20 text-right tabular-nums font-medium">
                    {formatCurrency(item.line_total, currency)}
                  </span>
                </div>
              </div>
            ))
          )}
        </div>
        <div className="border-t border-white/10 p-6 space-y-2 bg-black/40">
          <div className="flex justify-between text-sm text-white/50">
            <span>Subtotal</span>
            <span className="tabular-nums">{formatCurrency(cart.totals.subtotal, currency)}</span>
          </div>
          {cart.totals.discount > 0 && (
            <div className="flex justify-between text-sm text-green-400">
              <span>Discount</span>
              <span className="tabular-nums">-{formatCurrency(cart.totals.discount, currency)}</span>
            </div>
          )}
          {cart.totals.tax > 0 && (
            <div className="flex justify-between text-sm text-white/50">
              <span>Tax</span>
              <span className="tabular-nums">{formatCurrency(cart.totals.tax, currency)}</span>
            </div>
          )}
          <div className="flex justify-between items-baseline pt-2 border-t border-white/10">
            <span className="text-base font-medium text-white/70">Total</span>
            <span className="text-4xl font-bold tabular-nums text-white">
              {formatCurrency(cart.totals.grand_total, currency)}
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}

function PaymentPendingState({ cart, payment }: {
  cart: DisplayPayload['cart']
  payment: DisplayPayload['payment']
}) {
  const currency = cart.currency
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-zinc-950 text-white gap-8">
      <div className="flex flex-col items-center gap-3">
        <div className="w-20 h-20 rounded-full bg-blue-500/20 flex items-center justify-center animate-pulse">
          <Icon icon="mdi:credit-card-outline" className="text-blue-400" style={{ fontSize: 40 }} />
        </div>
        <p className="text-xl font-medium text-white/70">{payment.status_label ?? 'Waiting for payment'}</p>
      </div>
      <div className="flex flex-col items-center">
        <p className="text-sm text-white/30 uppercase tracking-widest mb-1">Amount due</p>
        <p className="text-7xl font-bold tabular-nums">
          {formatCurrency(cart.totals.grand_total, currency)}
        </p>
      </div>
    </div>
  )
}

function PaymentApprovedState({ cart }: { cart: DisplayPayload['cart'] }) {
  const currency = cart.currency
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-green-950 text-white gap-8">
      <div className="w-24 h-24 rounded-full bg-green-500/20 flex items-center justify-center">
        <Icon icon="mdi:check-circle" className="text-green-400" style={{ fontSize: 56 }} />
      </div>
      <div className="flex flex-col items-center gap-2">
        <p className="text-3xl font-semibold text-green-300">Payment Approved</p>
        <p className="text-6xl font-bold tabular-nums mt-2">
          {formatCurrency(cart.totals.grand_total, currency)}
        </p>
      </div>
    </div>
  )
}

function PaymentFailedState({ payment }: { payment: DisplayPayload['payment'] }) {
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-red-950 text-white gap-8">
      <div className="w-24 h-24 rounded-full bg-red-500/20 flex items-center justify-center">
        <Icon icon="mdi:close-circle" className="text-red-400" style={{ fontSize: 56 }} />
      </div>
      <div className="flex flex-col items-center gap-2 text-center">
        <p className="text-3xl font-semibold text-red-300">Payment Failed</p>
        <p className="text-base text-white/60 max-w-sm">
          {payment.status_label ?? 'Payment could not be processed. Please try again.'}
        </p>
      </div>
    </div>
  )
}

function CompletedState({ cart, storeName }: { cart: DisplayPayload['cart']; storeName: string }) {
  const currency = cart.currency
  return (
    <div className="flex flex-col items-center justify-center h-full w-full bg-zinc-950 text-white gap-8">
      <div className="w-24 h-24 rounded-full bg-emerald-500/20 flex items-center justify-center">
        <Icon icon="mdi:check-all" className="text-emerald-400" style={{ fontSize: 52 }} />
      </div>
      <div className="flex flex-col items-center gap-2 text-center">
        <p className="text-4xl font-semibold text-emerald-300">Thank you!</p>
        <p className="text-5xl font-bold tabular-nums mt-2">
          {formatCurrency(cart.totals.grand_total, currency)}
        </p>
        <p className="text-sm text-white/30 mt-2">Visit us again at {storeName}</p>
      </div>
    </div>
  )
}

function LockedReviewState({ cart }: { cart: DisplayPayload['cart'] }) {
  const currency = cart.currency
  return (
    <div className="flex h-full w-full bg-zinc-950 text-white">
      <div className="flex flex-col w-full h-full p-8 gap-4">
        <div className="flex items-center gap-2 text-white/40">
          <Icon icon="mdi:lock-outline" style={{ fontSize: 18 }} />
          <span className="text-sm uppercase tracking-widest">Order Summary</span>
        </div>
        <div className="flex-1 overflow-y-auto space-y-1">
          {cart.items.map((item) => (
            <div key={item.line_id} className="flex items-center justify-between py-2 border-b border-white/5">
              <div className="flex-1 min-w-0 pr-4">
                <p className="text-sm font-medium truncate">{item.name}</p>
                {item.variant_label && (
                  <p className="text-xs text-white/40">{item.variant_label}</p>
                )}
              </div>
              <div className="flex items-center gap-3 text-sm shrink-0">
                <span className="text-white/40">×{item.qty}</span>
                <span className="w-20 text-right tabular-nums font-medium">
                  {formatCurrency(item.line_total, currency)}
                </span>
              </div>
            </div>
          ))}
        </div>
        <div className="border-t border-white/10 pt-4 space-y-2">
          {cart.totals.discount > 0 && (
            <div className="flex justify-between text-sm text-green-400">
              <span>Discount</span>
              <span>-{formatCurrency(cart.totals.discount, currency)}</span>
            </div>
          )}
          <div className="flex justify-between items-baseline">
            <span className="text-base text-white/60">Total</span>
            <span className="text-4xl font-bold tabular-nums">
              {formatCurrency(cart.totals.grand_total, currency)}
            </span>
          </div>
        </div>
      </div>
    </div>
  )
}

// ─── Root ─────────────────────────────────────────────────────────────────────

export function POSCustomerDisplay() {
  const sessionId = (() => {
    const params = new URLSearchParams(window.location.search)
    const fromUrl = params.get('session')
    if (fromUrl) return parseInt(fromUrl, 10)
    const fromStorage = localStorage.getItem('pos_active_session_id')
    return fromStorage ? parseInt(fromStorage, 10) : null
  })()

  const { payload, displayState } = useDisplayPayload(sessionId)

  if (!sessionId || !payload) {
    return (
      <div className="flex flex-col items-center justify-center h-screen w-screen bg-black text-white gap-6">
        <Icon icon="mdi:television-play" className="text-white/20" style={{ fontSize: 80 }} />
        <p className="text-white/30 text-lg tracking-widest uppercase">Customer Display</p>
        <p className="text-white/20 text-sm">Waiting for POS session…</p>
      </div>
    )
  }

  const storeName = payload.store.name

  return (
    <div className="h-screen w-screen overflow-hidden bg-black">
      {displayState === 'IDLE_SCREENSAVER' && (
        <ScreensaverState storeName={storeName} />
      )}
      {displayState === 'ACTIVE_NO_CART' && (
        <ActiveNoCartState storeName={storeName} campaignMessage={payload.store.campaign_message} />
      )}
      {displayState === 'MEMBER_NO_CART' && (
        <MemberNoCartState member={payload.member} storeName={storeName} />
      )}
      {displayState === 'CART_ACTIVE' && (
        <CartActiveState cart={payload.cart} cartFocus={payload.cart_focus} />
      )}
      {displayState === 'PAYMENT_PENDING' && (
        <PaymentPendingState cart={payload.cart} payment={payload.payment} />
      )}
      {displayState === 'PAYMENT_APPROVED' && (
        <PaymentApprovedState cart={payload.cart} />
      )}
      {displayState === 'PAYMENT_FAILED' && (
        <PaymentFailedState payment={payload.payment} />
      )}
      {displayState === 'COMPLETED' && (
        <CompletedState cart={payload.cart} storeName={storeName} />
      )}
      {displayState === 'LOCKED_REVIEW' && (
        <LockedReviewState cart={payload.cart} />
      )}
    </div>
  )
}
