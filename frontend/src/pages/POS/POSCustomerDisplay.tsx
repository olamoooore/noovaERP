import { useEffect, useState } from 'react'
import { usePosSessionStore, CartItem } from '@/store/posSessionStore'
import { useSettingsStore } from '@/store/settingsStore'
import { formatCurrency, getCurrencySymbol } from '@/lib/utils'
import { Icon } from '@iconify/react'

export function POSCustomerDisplay() {
  const cart = usePosSessionStore((s) => s.cart)
  const discount = usePosSessionStore((s) => s.discount)
  const customerId = usePosSessionStore((s) => s.customerId)
  const customerInfo = usePosSessionStore((s) => s.customerInfo)

  const taxConfig = useSettingsStore((s) => s.tax)

  const [currentTime, setCurrentTime] = useState(new Date())
  const [lastAddedItem, setLastAddedItem] = useState<number | null>(null)

  const companyName = 'NoovaERP'
  const companyLogo = null

  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000)
    return () => clearInterval(timer)
  }, [])

  useEffect(() => {
    const channel = new BroadcastChannel('pos-cart-sync')

    const handleMessage = (event: MessageEvent) => {
      if (event.data.type === 'CART_UPDATE') {
        const stored = localStorage.getItem('pos-session-storage-v2')
        if (stored) {
          const state = JSON.parse(stored)
          usePosSessionStore.setState(state.state)
        }
      }
    }

    channel.addEventListener('message', handleMessage)

    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === 'pos-session-storage-v2' && e.newValue) {
        const state = JSON.parse(e.newValue)
        usePosSessionStore.setState(state.state)
      }
    }

    window.addEventListener('storage', handleStorageChange)

    let lastCart = JSON.stringify(cart)
    const pollInterval = setInterval(() => {
      const stored = localStorage.getItem('pos-session-storage-v2')
      if (stored) {
        const state = JSON.parse(stored)
        const currentCart = JSON.stringify(state.state.cart)
        if (currentCart !== lastCart) {
          lastCart = currentCart
          usePosSessionStore.setState(state.state)
        }
      }
    }, 500)

    return () => {
      channel.removeEventListener('message', handleMessage)
      channel.close()
      window.removeEventListener('storage', handleStorageChange)
      clearInterval(pollInterval)
    }
  }, [])

  useEffect(() => {
    if (cart.length > 0) {
      const lastItem = cart[cart.length - 1]
      setLastAddedItem(lastItem.id)
      const timeout = setTimeout(() => setLastAddedItem(null), 1000)
      return () => clearTimeout(timeout)
    }
  }, [cart.length])

  const subtotal = cart.reduce((sum, item) => sum + item.line_subtotal, 0)
  const totalTax = cart.reduce((sum, item) => sum + item.tax_amount, 0)

  let discountAmount = 0
  if (discount.type === 'fixed') {
    discountAmount = discount.value
  } else if (discount.type === 'percent') {
    discountAmount = (subtotal * discount.value) / 100
  } else if (discount.type === 'loyalty' && discount.loyaltyDiscountValue) {
    discountAmount = discount.loyaltyDiscountValue
  }

  const total = subtotal + totalTax - discountAmount
  const currencySymbol = getCurrencySymbol()

  return (
    <div className="h-screen w-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 text-white overflow-hidden flex flex-col">
      <header className="bg-black/30 backdrop-blur-md border-b border-white/10 px-8 py-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            {companyLogo ? (
              <img src={companyLogo} alt={companyName} className="h-12 w-auto" />
            ) : (
              <div className="h-12 w-12 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                <Icon icon="solar:shop-bold" className="h-6 w-6" />
              </div>
            )}
            <div>
              <h1 className="text-2xl font-bold">{companyName || 'Point of Sale'}</h1>
              <p className="text-sm text-white/60">Customer Display</p>
            </div>
          </div>

          <div className="text-right">
            <div className="text-3xl font-bold font-mono">
              {currentTime.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
              })}
            </div>
            <div className="text-sm text-white/60">
              {currentTime.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}
            </div>
          </div>
        </div>
      </header>

      <div className="flex-1 flex overflow-hidden">
        <div className="flex-1 p-8 overflow-hidden flex flex-col">
          <div className="mb-6">
            <h2 className="text-2xl font-semibold mb-2">Your Items</h2>
            <div className="h-1 w-24 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full"></div>
          </div>

          {cart.length === 0 ? (
            <div className="flex-1 flex items-center justify-center">
              <div className="text-center">
                <div className="mb-6 relative">
                  <div className="absolute inset-0 bg-purple-500/20 blur-3xl rounded-full"></div>
                  <Icon
                    icon="solar:cart-large-2-bold-duotone"
                    className="h-32 w-32 text-purple-400 relative"
                  />
                </div>
                <h3 className="text-3xl font-bold mb-2">Welcome!</h3>
                <p className="text-xl text-white/60">Your items will appear here</p>
              </div>
            </div>
          ) : (
            <div className="flex-1 overflow-y-auto space-y-3 pr-4 custom-scrollbar">
              {cart.map((item, index) => (
                <CartRow
                  key={item.id}
                  currencySymbol={currencySymbol}
                  index={index}
                  item={item}
                  lastAddedItem={lastAddedItem}
                />
              ))}
            </div>
          )}
        </div>

        <div className="w-[480px] bg-black/40 backdrop-blur-xl border-l border-white/10 p-8 flex flex-col">
          <div className="mb-6">
            <h2 className="text-2xl font-semibold mb-2">Order Summary</h2>
            <div className="h-1 w-24 bg-gradient-to-r from-pink-500 to-purple-500 rounded-full"></div>
          </div>

          <div className="flex-1 flex flex-col justify-between">
            <div className="space-y-6">
              <div className="bg-white/5 rounded-xl p-4 border border-white/10">
                <div className="flex items-center justify-between">
                  <span className="text-white/80 flex items-center gap-2">
                    <Icon icon="solar:bag-4-bold-duotone" className="h-5 w-5" />
                    Total Items
                  </span>
                  <span className="text-xl font-semibold">
                    {cart.reduce((sum, item) => sum + item.quantity, 0)}
                  </span>
                </div>
              </div>

              <div className="flex items-center justify-between text-lg">
                <span className="text-white/80">Subtotal</span>
                <span className="font-semibold">{formatCurrency(subtotal, currencySymbol)}</span>
              </div>

              {totalTax > 0 && (
                <div className="flex items-center justify-between text-lg">
                  <span className="text-white/80 flex items-center gap-2">
                    Tax
                    {taxConfig?.default_tax_rate && (
                      <span className="text-sm text-white/50">({taxConfig.default_tax_rate}%)</span>
                    )}
                  </span>
                  <span className="font-semibold">{formatCurrency(totalTax, currencySymbol)}</span>
                </div>
              )}

              {discountAmount > 0 && (
                <div className="bg-green-500/20 border border-green-500/30 rounded-xl p-4 animate-fade-in">
                  <div className="flex items-center justify-between text-lg">
                    <span className="text-green-300 flex items-center gap-2">
                      <Icon icon="solar:tag-price-bold-duotone" className="h-5 w-5" />
                      Discount
                      {discount.type === 'percent' && (
                        <span className="text-sm">({discount.value}%)</span>
                      )}
                      {discount.type === 'loyalty' && discount.loyaltyPointsUsed && (
                        <span className="text-sm">({discount.loyaltyPointsUsed} pts)</span>
                      )}
                    </span>
                    <span className="font-semibold text-green-300">
                      -{formatCurrency(discountAmount, currencySymbol)}
                    </span>
                  </div>
                </div>
              )}

              {customerInfo && customerId !== 'walk-in' && (
                <div className="bg-purple-500/20 border border-purple-500/30 rounded-xl p-4">
                  <div className="flex items-start gap-3">
                    <Icon icon="solar:user-bold-duotone" className="h-6 w-6 text-purple-300 shrink-0 mt-0.5" />
                    <div className="flex-1 min-w-0">
                      <p className="text-purple-200 font-semibold text-base mb-1">{customerInfo.name}</p>
                      {customerInfo.phone && (
                        <p className="text-purple-300/80 text-sm mb-1">
                          <Icon icon="solar:phone-bold" className="h-3.5 w-3.5 inline mr-1" />
                          {customerInfo.phone}
                        </p>
                      )}
                      {customerInfo.email && (
                        <p className="text-purple-300/80 text-sm mb-1">
                          <Icon icon="solar:letter-bold" className="h-3.5 w-3.5 inline mr-1" />
                          {customerInfo.email}
                        </p>
                      )}
                      {customerInfo.loyalty_points !== undefined && customerInfo.loyalty_points > 0 && (
                        <div className="flex items-center gap-2 mt-2 pt-2 border-t border-purple-400/30">
                          <Icon icon="solar:star-bold" className="h-4 w-4 text-yellow-400" />
                          <span className="text-purple-200 text-sm font-medium">
                            {customerInfo.loyalty_points.toLocaleString()} Points
                          </span>
                          {customerInfo.loyalty_tier_name && (
                            <span className="text-xs bg-purple-400/30 text-purple-200 px-2 py-0.5 rounded-full">
                              {customerInfo.loyalty_tier_name}
                            </span>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </div>

            <div className="mt-8">
              <div className="bg-gradient-to-r from-purple-600 to-pink-600 rounded-2xl p-6 shadow-2xl shadow-purple-500/50">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-white/80 text-sm mb-1">Total Amount</p>
                    <p className="text-5xl font-bold font-mono">
                      {formatCurrency(total, currencySymbol)}
                    </p>
                  </div>
                  <Icon icon="solar:wallet-bold-duotone" className="h-16 w-16 text-white/30" />
                </div>
              </div>

              <div className="mt-6 text-center">
                <p className="text-white/60 text-lg">Thank you for shopping with us!</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <footer className="bg-black/30 backdrop-blur-md border-t border-white/10 px-8 py-4">
        <div className="flex items-center justify-between text-sm text-white/60">
          <div className="flex items-center gap-6">
            <span className="flex items-center gap-2">
              <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
              Secure Checkout
            </span>
            <span className="flex items-center gap-2">
              <Icon icon="solar:card-bold-duotone" className="h-4 w-4" />
              Multiple Payment Options
            </span>
          </div>
          <div>Powered by {companyName || 'NoovaERP'}</div>
        </div>
      </footer>

      <style>{`
        .custom-scrollbar::-webkit-scrollbar {
          width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
          background: rgba(255, 255, 255, 0.05);
          border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
          background: rgba(255, 255, 255, 0.2);
          border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
          background: rgba(255, 255, 255, 0.3);
        }

        @keyframes slideIn {
          from {
            opacity: 0;
            transform: translateX(-50px) scale(0.9);
          }
          to {
            opacity: 1;
            transform: translateX(0) scale(1);
          }
        }

        @keyframes fadeIn {
          from {
            opacity: 0;
            transform: translateY(-10px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }

        .animate-slide-in {
          animation: slideIn 0.3s ease-out forwards;
        }

        .animate-fade-in {
          animation: fadeIn 0.3s ease-out forwards;
        }
      `}</style>
    </div>
  )
}

function CartRow({
  currencySymbol,
  index,
  item,
  lastAddedItem,
}: {
  currencySymbol: string
  index: number
  item: CartItem
  lastAddedItem: number | null
}) {
  return (
    <div
      className={`bg-white/10 backdrop-blur-md rounded-2xl p-4 border transition-all animate-slide-in ${
        lastAddedItem === item.id
          ? 'border-purple-400 shadow-lg shadow-purple-500/50'
          : 'border-white/10'
      }`}
      style={{ animationDelay: `${index * 50}ms` }}
    >
      <div className="flex items-center gap-4">
        <div className="h-20 w-20 rounded-xl bg-white/5 flex items-center justify-center overflow-hidden shrink-0">
          {item.image ? (
            <img src={item.image} alt={item.name} className="h-full w-full object-cover" />
          ) : (
            <Icon icon="solar:box-bold-duotone" className="h-10 w-10 text-white/40" />
          )}
        </div>

        <div className="flex-1 min-w-0">
          <h4 className="text-lg font-semibold truncate">{item.name}</h4>
          <p className="text-sm text-white/60">SKU: {item.sku}</p>
          <div className="flex items-center gap-3 mt-1">
            <span className="text-white/80">
              {formatCurrency(item.price, currencySymbol)} × {item.quantity}
            </span>
          </div>
        </div>

        <div className="text-right">
          <div className="text-2xl font-bold">{formatCurrency(item.line_total, currencySymbol)}</div>
          {item.tax_amount > 0 && (
            <div className="text-xs text-white/60">
              Tax: {formatCurrency(item.tax_amount, currencySymbol)}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
