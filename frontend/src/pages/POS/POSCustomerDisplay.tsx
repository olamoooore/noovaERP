import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { formatCurrency, resolveImageUrl } from '@/lib/utils'
import { usePosSessionStore, CartItem, CustomerInfo } from '@/store/posSessionStore'
import { useSettingsStore } from '@/store/settingsStore'

type DisplayMode = 'active-no-cart' | 'member-no-cart' | 'cart-active'

interface CompanySettings {
  company_name?: string | null
  company_logo_url?: string | null
  address?: string | null
  phone?: string | null
  email?: string | null
  website?: string | null
}

interface TerminalSummary {
  id: string | number
  name: string
  code?: string | null
  warehouse?: {
    id: number
    name: string
  } | null
}

interface DisplayBrand {
  companyName: string
  companyLogoUrl: string
  outletName: string | null
  terminalName: string | null
  terminalCode: string | null
  supportLine: string | null
}

export function POSCustomerDisplay() {
  const cart = usePosSessionStore((state) => state.cart)
  const discount = usePosSessionStore((state) => state.discount)
  const customerId = usePosSessionStore((state) => state.customerId)
  const customerInfo = usePosSessionStore((state) => state.customerInfo)
  const activeTerminalId = usePosSessionStore((state) => state.activeTerminalId)
  const openedAt = usePosSessionStore((state) => state.openedAt)

  const currencyCode = useSettingsStore((state) => state.currencyCode)
  const taxConfig = useSettingsStore((state) => state.tax)
  const isSettingsLoaded = useSettingsStore((state) => state.isLoaded)
  const loadSettings = useSettingsStore((state) => state.loadSettings)

  const [currentTime, setCurrentTime] = useState(() => new Date())
  const [lastAddedItem, setLastAddedItem] = useState<number | null>(null)
  const [isFullscreen, setIsFullscreen] = useState(() => typeof document !== 'undefined' && !!document.fullscreenElement)

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

  useEffect(() => {
    const syncFromStorage = (value: string | null | undefined) => {
      if (!value) {
        return
      }

      try {
        const parsed = JSON.parse(value)
        if (parsed?.state) {
          usePosSessionStore.setState(parsed.state)
        }
      } catch {
        // Ignore malformed persistence snapshots.
      }
    }

    const channel = typeof window !== 'undefined' && 'BroadcastChannel' in window
      ? new BroadcastChannel('pos-cart-sync')
      : null

    const handleMessage = (event: MessageEvent) => {
      if (event.data?.type === 'CART_UPDATE') {
        syncFromStorage(localStorage.getItem('pos-session-storage-v2'))
      }
    }

    const handleStorageChange = (event: StorageEvent) => {
      if (event.key === 'pos-session-storage-v2') {
        syncFromStorage(event.newValue)
      }
    }

    channel?.addEventListener('message', handleMessage)
    window.addEventListener('storage', handleStorageChange)

    let lastSnapshot = localStorage.getItem('pos-session-storage-v2') ?? ''
    const pollInterval = window.setInterval(() => {
      const snapshot = localStorage.getItem('pos-session-storage-v2') ?? ''
      if (snapshot && snapshot !== lastSnapshot) {
        lastSnapshot = snapshot
        syncFromStorage(snapshot)
      }
    }, 500)

    return () => {
      channel?.removeEventListener('message', handleMessage)
      channel?.close()
      window.removeEventListener('storage', handleStorageChange)
      window.clearInterval(pollInterval)
    }
  }, [])

  useEffect(() => {
    if (cart.length === 0) {
      return
    }

    const latestItem = cart[cart.length - 1]
    setLastAddedItem(latestItem.id)

    const timeoutId = window.setTimeout(() => setLastAddedItem(null), 1200)
    return () => window.clearTimeout(timeoutId)
  }, [cart.length])

  const { data: companySettings } = useQuery<CompanySettings | null>({
    queryKey: ['customer-display-company-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings')
      return response.data?.data ?? null
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  })

  const { data: terminals = [] } = useQuery<TerminalSummary[]>({
    queryKey: ['customer-display-terminals'],
    queryFn: async () => {
      const response = await api.get('/pos/terminals')
      return response.data?.terminals ?? []
    },
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  })

  const activeTerminal = useMemo(
    () => terminals.find((terminal) => String(terminal.id) === String(activeTerminalId)) ?? null,
    [activeTerminalId, terminals]
  )

  const brand = useMemo<DisplayBrand>(() => {
    const companyName = companySettings?.company_name?.trim()
      || activeTerminal?.warehouse?.name
      || activeTerminal?.name
      || 'Point of Sale'

    const companyLogoUrl = companySettings?.company_logo_url
      ? resolveImageUrl(companySettings.company_logo_url)
      : ''

    const supportLine = companySettings?.website
      || companySettings?.phone
      || companySettings?.email
      || null

    return {
      companyName,
      companyLogoUrl,
      outletName: activeTerminal?.warehouse?.name ?? null,
      terminalName: activeTerminal?.name ?? null,
      terminalCode: activeTerminal?.code ?? null,
      supportLine,
    }
  }, [activeTerminal, companySettings])

  const displayMode: DisplayMode = cart.length > 0
    ? 'cart-active'
    : customerId !== 'walk-in' && customerInfo
      ? 'member-no-cart'
      : 'active-no-cart'

  const subtotal = cart.reduce((sum, item) => sum + item.line_subtotal, 0)
  const taxAmount = cart.reduce((sum, item) => sum + item.tax_amount, 0)

  let discountAmount = 0
  if (discount.type === 'fixed') {
    discountAmount = discount.value
  } else if (discount.type === 'percent') {
    discountAmount = (subtotal + taxAmount) * (discount.value / 100)
  } else if (discount.type === 'loyalty' && discount.loyaltyDiscountValue) {
    discountAmount = discount.loyaltyDiscountValue
  }

  const total = Math.max(0, subtotal + taxAmount - discountAmount)
  const focusItem = cart.length > 0 ? cart[cart.length - 1] : null

  const handleToggleFullscreen = async () => {
    if (typeof document === 'undefined') {
      return
    }

    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen()
        return
      }

      await document.documentElement.requestFullscreen()
    } catch {
      // Ignore fullscreen API failures and keep the display usable.
    }
  }

  return (
    <div className="relative h-full min-h-0 overflow-hidden bg-[#f3efe7] text-slate-950">
      <div className="pointer-events-none absolute inset-0 overflow-hidden">
        <div className="absolute -left-28 top-[-8rem] h-80 w-80 rounded-full bg-[#0f766e]/10 blur-3xl" />
        <div className="absolute right-[-6rem] top-1/3 h-96 w-96 rounded-full bg-[#f59e0b]/10 blur-3xl" />
        <div className="absolute bottom-[-8rem] left-1/3 h-80 w-80 rounded-full bg-[#1e293b]/8 blur-3xl" />
      </div>

      <div className="relative flex h-full min-h-0 flex-col px-4 py-4 lg:px-6 lg:py-6 xl:px-8 xl:py-7">
        <DisplayHeader
          brand={brand}
          currentTime={currentTime}
          displayMode={displayMode}
          isFullscreen={isFullscreen}
          openedAt={openedAt}
          onToggleFullscreen={handleToggleFullscreen}
        />

        <main className="mt-4 flex min-h-0 flex-1 flex-col gap-4 lg:mt-5 lg:grid lg:grid-cols-[1.4fr_0.9fr] lg:gap-5">
          {displayMode === 'cart-active' ? (
            <CartFocusStage
              brand={brand}
              focusItem={focusItem}
              currencyCode={currencyCode}
            />
          ) : displayMode === 'member-no-cart' ? (
            <MemberStage brand={brand} customerInfo={customerInfo} />
          ) : (
            <ActiveNoCartStage brand={brand} />
          )}

          <OrderSummaryPanel
            brand={brand}
            cart={cart}
            currencyCode={currencyCode}
            customerId={customerId}
            customerInfo={customerInfo}
            discount={discount}
            discountAmount={discountAmount}
            displayMode={displayMode}
            lastAddedItem={lastAddedItem}
            subtotal={subtotal}
            taxAmount={taxAmount}
            taxConfigLabel={taxConfig.default_tax_name ?? null}
            total={total}
          />
        </main>
      </div>
    </div>
  )
}

function DisplayHeader({
  brand,
  currentTime,
  displayMode,
  isFullscreen,
  openedAt,
  onToggleFullscreen,
}: {
  brand: DisplayBrand
  currentTime: Date
  displayMode: DisplayMode
  isFullscreen: boolean
  openedAt: string | null
  onToggleFullscreen: () => void | Promise<void>
}) {
  const stateLabel = displayMode === 'cart-active'
    ? 'Live Order'
    : displayMode === 'member-no-cart'
      ? 'Member Identified'
      : 'Ready'

  const contextDetails = [
    isSameDisplayValue(brand.outletName, brand.companyName) ? null : brand.outletName,
    isSameDisplayValue(brand.terminalName, brand.outletName) || isSameDisplayValue(brand.terminalName, brand.companyName)
      ? null
      : brand.terminalName,
    brand.terminalCode,
    openedAt ? `Opened ${formatOpenedAt(openedAt)}` : null,
  ].filter((value, index, values): value is string => {
    if (!value) {
      return false
    }

    return values.findIndex((candidate) => isSameDisplayValue(candidate, value)) === index
  })

  return (
    <header className="grid gap-4 rounded-[2rem] border border-white/60 bg-white/75 p-5 shadow-[0_20px_70px_rgba(15,23,42,0.08)] backdrop-blur xl:grid-cols-[1fr_auto] xl:items-center">
      <div className="flex min-w-0 items-center gap-4">
        <BrandMark brand={brand} />
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="truncate text-2xl font-semibold tracking-[-0.02em] text-slate-950 lg:text-3xl">
              {brand.companyName}
            </h1>
            <span className="rounded-full bg-slate-950 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-white">
              {stateLabel}
            </span>
          </div>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
            {contextDetails.map((detail) => (
              <span key={detail}>{detail}</span>
            ))}
          </div>
        </div>
      </div>

      <div className="rounded-[1.5rem] bg-slate-950 px-5 py-4 text-right text-white shadow-[0_16px_48px_rgba(15,23,42,0.24)]">
        <div className="mb-3 flex justify-end">
          <button
            type="button"
            onClick={onToggleFullscreen}
            className="inline-flex items-center gap-2 rounded-full border border-white/12 bg-white/8 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.2em] text-white/80 transition hover:bg-white/14 hover:text-white"
          >
            <Icon
              icon={isFullscreen ? 'solar:quit-full-screen-bold' : 'solar:full-screen-bold'}
              className="h-4 w-4"
            />
            {isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
          </button>
        </div>
        <div className="text-xs font-medium uppercase tracking-[0.24em] text-white/55">Now</div>
        <div className="mt-1 font-mono text-3xl font-semibold tracking-[-0.04em] lg:text-4xl">
          {currentTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
        </div>
        <div className="mt-1 text-sm text-white/65">
          {currentTime.toLocaleDateString([], {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
          })}
        </div>
      </div>
    </header>
  )
}

function BrandMark({ brand }: { brand: DisplayBrand }) {
  if (brand.companyLogoUrl) {
    return (
      <div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-sm">
        <img src={brand.companyLogoUrl} alt={brand.companyName} className="h-full w-full object-contain p-2" />
      </div>
    )
  }

  return (
    <div className="flex h-16 w-16 items-center justify-center rounded-[1.5rem] bg-[#0f766e] text-xl font-semibold uppercase tracking-[0.2em] text-white shadow-[0_14px_30px_rgba(15,118,110,0.28)]">
      {getInitials(brand.companyName)}
    </div>
  )
}

function ActiveNoCartStage({ brand }: { brand: DisplayBrand }) {
  return (
    <section className="relative h-full min-h-0 overflow-hidden rounded-[2.25rem] border border-white/65 bg-[linear-gradient(135deg,#fffdf8_0%,#f4efe6_42%,#d9efe7_100%)] p-5 shadow-[0_30px_80px_rgba(15,23,42,0.08)] lg:p-7">
      <div className="absolute right-0 top-0 h-44 w-44 translate-x-12 -translate-y-12 rounded-full bg-[#0f766e]/10 blur-3xl" />
      <div className="relative flex h-full min-h-0 flex-col justify-between gap-8">
        <div className="max-w-2xl">
          <div className="inline-flex items-center gap-2 rounded-full border border-slate-300/80 bg-white/70 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-slate-600">
            <Icon icon="solar:shop-2-bold-duotone" className="h-4 w-4 text-[#0f766e]" />
            Customer Display
          </div>
          <h2 className="mt-6 text-4xl font-semibold tracking-[-0.04em] text-slate-950 lg:text-6xl">
            {brand.companyName}
          </h2>
          <p className="mt-4 max-w-xl text-lg leading-8 text-slate-600 lg:text-xl">
            {brand.outletName || brand.terminalName || 'Live customer-facing order screen'}
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <InfoTile label="Terminal" value={brand.terminalName || brand.terminalCode || 'Ready'} />
          <InfoTile label="Outlet" value={brand.outletName || brand.companyName} />
          <InfoTile label="Support" value={brand.supportLine || ' '} subtleEmpty />
        </div>
      </div>
    </section>
  )
}

function MemberStage({
  brand,
  customerInfo,
}: {
  brand: DisplayBrand
  customerInfo: CustomerInfo | null
}) {
  const customerName = customerInfo?.name || 'Member'
  const customerMeta = customerInfo?.email || customerInfo?.phone || brand.supportLine || ''

  return (
    <section className="grid h-full min-h-0 gap-5 rounded-[2.25rem] border border-white/65 bg-[linear-gradient(160deg,#fffdf8_0%,#f8f3ea_54%,#e7f4f0_100%)] p-5 shadow-[0_30px_80px_rgba(15,23,42,0.08)] lg:grid-cols-[1.12fr_0.88fr] lg:p-7">
      <div className="flex flex-col justify-between rounded-[1.9rem] bg-slate-950 p-6 text-white shadow-[0_24px_60px_rgba(15,23,42,0.2)] lg:p-8">
        <div>
          <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-white/65">
            <Icon icon="solar:user-id-bold-duotone" className="h-4 w-4 text-[#5eead4]" />
            Member Identified
          </div>
          <h2 className="mt-6 text-4xl font-semibold tracking-[-0.04em] lg:text-5xl">{customerName}</h2>
          {customerMeta && <p className="mt-3 text-base text-white/70 lg:text-lg">{customerMeta}</p>}
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-2">
          <InfoTileDark label="Loyalty Points" value={formatCount(customerInfo?.loyalty_points)} />
          <InfoTileDark label="Tier" value={customerInfo?.loyalty_tier_name || 'Member'} />
        </div>
      </div>

      <div className="flex flex-col justify-between rounded-[1.9rem] border border-slate-200/70 bg-white/70 p-6 shadow-sm lg:p-8">
        <div>
          <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Store Details</div>
          <h3 className="mt-3 text-3xl font-semibold tracking-[-0.03em] text-slate-950">
            {brand.outletName || brand.companyName}
          </h3>
          {!isSameDisplayValue(brand.outletName, brand.companyName) && brand.companyName && (
            <p className="mt-2 text-base text-slate-500">{brand.companyName}</p>
          )}
        </div>

        <div className="mt-8 grid gap-4 sm:grid-cols-2">
          <InfoTile label="Terminal" value={brand.terminalName || brand.terminalCode || 'Ready'} />
          <InfoTile label="Support" value={brand.supportLine || ' '} subtleEmpty />
        </div>
      </div>
    </section>
  )
}

function CartFocusStage({
  brand,
  focusItem,
  currencyCode,
}: {
  brand: DisplayBrand
  focusItem: CartItem | null
  currencyCode: string
}) {
  const focusImage = focusItem?.image ? resolveImageUrl(focusItem.image) : ''
  const focusTitle = focusItem?.name || 'Awaiting next item'
  const focusTitleClassName = getFocusTitleClassName(focusTitle)

  return (
    <section className="relative h-full min-h-0 overflow-hidden rounded-[2.25rem] border border-white/65 bg-[linear-gradient(160deg,#fffdf8_0%,#e8f4f0_58%,#dbece4_100%)] p-5 shadow-[0_30px_80px_rgba(15,23,42,0.08)] lg:p-7">
      <div className="absolute -right-20 top-[-4rem] h-60 w-60 rounded-full bg-[#0f766e]/12 blur-3xl" />
      <div className="relative flex h-full min-h-0 flex-col justify-between gap-6">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-full bg-slate-950 px-4 py-2 text-xs font-semibold uppercase tracking-[0.24em] text-white">
            Latest Line
          </span>
          {focusItem?.sku && (
            <span className="rounded-full border border-slate-300/80 bg-white/70 px-4 py-2 text-sm font-medium text-slate-700">
              {focusItem.sku}
            </span>
          )}
        </div>

        <div className="grid flex-1 gap-6 lg:grid-cols-[1fr_0.9fr] lg:items-center">
          <div className="rounded-[2rem] bg-white/72 p-5 shadow-[0_24px_60px_rgba(15,23,42,0.08)] lg:p-6">
            <div className="flex h-full min-h-[16rem] items-center justify-center rounded-[1.6rem] border border-slate-200/80 bg-[#f7f3ec] p-5 lg:min-h-[18rem]">
              {focusImage ? (
                <img src={focusImage} alt={focusItem?.name || brand.companyName} className="max-h-[20rem] w-full object-contain" />
              ) : (
                <div className="flex h-full min-h-[14rem] w-full flex-col items-center justify-center rounded-[1.4rem] border border-dashed border-slate-300 bg-white/80 text-center text-slate-500">
                  <Icon icon="solar:box-bold-duotone" className="h-20 w-20 text-slate-300" />
                  <p className="mt-4 text-sm uppercase tracking-[0.24em]">Product focus</p>
                </div>
              )}
            </div>
          </div>

          <div className="flex flex-col justify-between rounded-[2rem] bg-slate-950 p-6 text-white shadow-[0_24px_60px_rgba(15,23,42,0.2)] lg:p-8">
            <div>
              <div className="text-xs font-semibold uppercase tracking-[0.24em] text-white/45">Focus Item</div>
              <h2 className={`mt-4 break-words font-semibold tracking-[-0.04em] ${focusTitleClassName}`}>
                {focusTitle}
              </h2>
              {focusItem?.sku && (
                <p className="mt-4 break-all text-sm text-white/70 lg:text-base">SKU {focusItem.sku}</p>
              )}
            </div>

            <div className="mt-10 grid gap-4 sm:grid-cols-2">
              <InfoTileDark label="Quantity" value={formatCount(focusItem?.quantity)} />
              <InfoTileDark label="Line Total" value={formatCurrency(focusItem?.line_total ?? 0, currencyCode)} />
              <InfoTileDark label="Unit Price" value={formatCurrency(focusItem?.price ?? 0, currencyCode)} />
              <InfoTileDark label="Tax" value={formatCurrency(focusItem?.tax_amount ?? 0, currencyCode)} />
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

function OrderSummaryPanel({
  brand,
  cart,
  currencyCode,
  customerId,
  customerInfo,
  discount,
  discountAmount,
  displayMode,
  lastAddedItem,
  subtotal,
  taxAmount,
  taxConfigLabel,
  total,
}: {
  brand: DisplayBrand
  cart: CartItem[]
  currencyCode: string
  customerId: string
  customerInfo: CustomerInfo | null
  discount: { type: 'fixed' | 'percent' | 'loyalty'; value: number; loyaltyPointsUsed?: number; loyaltyDiscountValue?: number }
  discountAmount: number
  displayMode: DisplayMode
  lastAddedItem: number | null
  subtotal: number
  taxAmount: number
  taxConfigLabel: string | null
  total: number
}) {
  const lineCount = cart.length
  const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0)
  const showMemberSummary = displayMode === 'member-no-cart' && customerId !== 'walk-in' && !!customerInfo
  const showStateBadge = displayMode !== 'cart-active'

  return (
    <aside className="flex min-h-[22rem] flex-col overflow-hidden rounded-[2.25rem] bg-slate-950 text-white shadow-[0_30px_80px_rgba(15,23,42,0.18)]">
      <div className="border-b border-white/10 px-5 py-5 lg:px-7 lg:py-6">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="text-xs font-semibold uppercase tracking-[0.24em] text-white/45">Order Summary</div>
            <h2 className="mt-3 text-3xl font-semibold tracking-[-0.03em]">
              {lineCount > 0 ? `${itemCount} item${itemCount === 1 ? '' : 's'}` : brand.companyName}
            </h2>
          </div>
          {showStateBadge && (
            <div className="rounded-full border border-white/12 bg-white/5 px-3 py-2 text-xs font-semibold uppercase tracking-[0.22em] text-white/65">
              {displayMode === 'member-no-cart' ? 'Member View' : 'Awaiting Cart'}
            </div>
          )}
        </div>

        {showMemberSummary ? (
          <div className="mt-5 rounded-[1.5rem] border border-white/10 bg-white/5 p-4">
            <div className="space-y-1">
              <div className="text-xs uppercase tracking-[0.22em] text-white/45">Member</div>
              <div className="text-lg font-medium text-white">{customerInfo?.name}</div>
              {(customerInfo?.phone || customerInfo?.email) && (
                <div className="text-sm text-white/60">{customerInfo?.phone || customerInfo?.email}</div>
              )}
            </div>
          </div>
        ) : null}
      </div>

      <div className="flex min-h-0 flex-1 flex-col">
        <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 lg:px-6">
          {cart.length === 0 ? (
            <div className="flex h-full min-h-[16rem] flex-col items-center justify-center rounded-[1.8rem] border border-dashed border-white/12 bg-white/4 px-6 text-center text-white/55">
              <Icon icon="solar:shop-bold-duotone" className="h-16 w-16 text-white/18" />
              <p className="mt-4 text-sm font-medium uppercase tracking-[0.22em]">No active order</p>
            </div>
          ) : (
            <div className="space-y-3">
              {cart.map((item, index) => (
                <CartRow
                  key={`${item.id}-${index}`}
                  currencyCode={currencyCode}
                  index={index}
                  item={item}
                  lastAddedItem={lastAddedItem}
                />
              ))}
            </div>
          )}
        </div>

        <div className="border-t border-white/10 bg-white/[0.04] px-5 py-5 lg:px-7 lg:py-6">
          <div className="space-y-3 text-sm text-white/70">
            <SummaryRow label="Subtotal" value={formatCurrency(subtotal, currencyCode)} />
            {discountAmount > 0 && (
              <SummaryRow
                label={discount.type === 'loyalty' ? 'Loyalty Discount' : 'Discount'}
                value={`-${formatCurrency(discountAmount, currencyCode)}`}
                tone="positive"
              />
            )}
            {taxAmount > 0 && (
              <SummaryRow
                label={taxConfigLabel ? `Tax · ${taxConfigLabel}` : 'Tax'}
                value={formatCurrency(taxAmount, currencyCode)}
              />
            )}
          </div>

          <div className="mt-5 rounded-[1.75rem] bg-[#0f766e] px-5 py-5 shadow-[0_18px_40px_rgba(15,118,110,0.32)]">
            <div className="flex items-end justify-between gap-4">
              <div>
                <div className="text-xs font-semibold uppercase tracking-[0.24em] text-white/60">Grand Total</div>
                <div className="mt-2 text-4xl font-semibold tracking-[-0.04em] text-white lg:text-5xl">
                  {formatCurrency(total, currencyCode)}
                </div>
              </div>
              <Icon icon="solar:wallet-money-bold-duotone" className="h-12 w-12 text-white/55 lg:h-14 lg:w-14" />
            </div>
          </div>
        </div>
      </div>
    </aside>
  )
}

function CartRow({
  currencyCode,
  index,
  item,
  lastAddedItem,
}: {
  currencyCode: string
  index: number
  item: CartItem
  lastAddedItem: number | null
}) {
  const itemImage = item.image ? resolveImageUrl(item.image) : ''

  return (
    <div
      className={`rounded-[1.45rem] border p-4 transition-all duration-300 ${
        lastAddedItem === item.id
          ? 'border-[#5eead4]/70 bg-[#0f766e]/16 shadow-[0_0_0_1px_rgba(94,234,212,0.16)]'
          : 'border-white/8 bg-white/5'
      }`}
      style={{ animationDelay: `${index * 45}ms` }}
    >
      <div className="flex items-center gap-4">
        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-[1.2rem] border border-white/8 bg-white/8">
          {itemImage ? (
            <img src={itemImage} alt={item.name} className="h-full w-full object-cover" />
          ) : (
            <Icon icon="solar:box-bold-duotone" className="h-8 w-8 text-white/30" />
          )}
        </div>

        <div className="min-w-0 flex-1">
          <div className="truncate text-lg font-medium text-white">{item.name}</div>
          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-white/50">
            {item.sku && <span>SKU {item.sku}</span>}
            <span>Qty {item.quantity}</span>
            <span>{formatCurrency(item.price, currencyCode)} each</span>
          </div>
        </div>

        <div className="text-right">
          <div className="text-xl font-semibold tracking-[-0.02em] text-white">
            {formatCurrency(item.line_total, currencyCode)}
          </div>
          {item.tax_amount > 0 && <div className="mt-1 text-xs text-white/45">Tax {formatCurrency(item.tax_amount, currencyCode)}</div>}
        </div>
      </div>
    </div>
  )
}

function SummaryRow({
  label,
  value,
  tone = 'default',
}: {
  label: string
  value: string
  tone?: 'default' | 'positive'
}) {
  return (
    <div className={`flex items-center justify-between gap-4 ${tone === 'positive' ? 'text-[#86efac]' : ''}`}>
      <span>{label}</span>
      <span className="font-medium tracking-[-0.01em]">{value}</span>
    </div>
  )
}

function InfoTile({
  label,
  value,
  subtleEmpty = false,
}: {
  label: string
  value: string
  subtleEmpty?: boolean
}) {
  const isEmpty = subtleEmpty && value.trim().length === 0

  return (
    <div className="rounded-[1.5rem] border border-slate-200/80 bg-white/72 px-5 py-4 shadow-sm">
      <div className="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">{label}</div>
      <div className={`mt-2 text-lg font-medium tracking-[-0.02em] ${isEmpty ? 'text-slate-300' : 'text-slate-900'}`}>
        {isEmpty ? 'Unavailable' : value}
      </div>
    </div>
  )
}

function InfoTileDark({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-[1.4rem] border border-white/10 bg-white/6 px-4 py-4">
      <div className="text-xs font-semibold uppercase tracking-[0.22em] text-white/45">{label}</div>
      <div className="mt-2 text-2xl font-semibold tracking-[-0.03em] text-white">{value}</div>
    </div>
  )
}

function getInitials(name: string) {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((part) => part[0]).join('') || 'AC'
}

function formatOpenedAt(openedAt: string) {
  const parsedDate = new Date(openedAt)
  if (Number.isNaN(parsedDate.getTime())) {
    return 'recently'
  }

  return parsedDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatCount(value: number | null | undefined) {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return '0'
  }

  return new Intl.NumberFormat('en-US').format(value)
}

function isSameDisplayValue(left: string | null | undefined, right: string | null | undefined) {
  if (!left || !right) {
    return false
  }

  return left.trim().toLowerCase() === right.trim().toLowerCase()
}

function getFocusTitleClassName(value: string) {
  const length = value.trim().length

  if (length > 72) {
    return 'text-xl leading-[1.06] lg:text-[1.8rem]'
  }

  if (length > 56) {
    return 'text-xl leading-[1.04] lg:text-[2.05rem]'
  }

  if (length > 36) {
    return 'text-2xl leading-[1.02] lg:text-[2.35rem]'
  }

  if (length > 24) {
    return 'text-2xl leading-[0.98] lg:text-[2.75rem]'
  }

  return 'text-3xl leading-[0.94] lg:text-[4rem]'
}
