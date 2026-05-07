import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useMemo, useState, useEffect, useCallback } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Separator } from '@/components/ui/separator'
import { usePosSessionStore } from '@/store/posSessionStore'
import { POSOrderDrawer } from '@/components/pos/POSOrderDrawer'
import { PWAInstallButton } from '@/components/pos/PWAInstallButton'
import { OfflineBanner } from '@/components/pos/OfflineBanner'
import { useToast } from '@/hooks/use-toast'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'
import api from '@/lib/api'
import { formatCurrency } from '@/lib/utils'

interface PaymentBreakdown {
  method: string
  methodLabel: string
  expectedAmount: number
  countedAmount: string
  icon: string
}

const METHOD_ICONS: Record<string, string> = {
  cash: 'solar:wallet-money-bold-duotone',
  card: 'solar:card-bold-duotone',
  bank_transfer: 'solar:bank-card-bold-duotone',
  mobile: 'solar:smartphone-bold-duotone',
  voucher: 'solar:ticket-bold-duotone',
  credit: 'solar:file-text-bold-duotone',
  other: 'solar:wallet-bold-duotone',
}

const METHOD_LABELS: Record<string, string> = {
  cash: 'Cash',
  card: 'Card',
  bank_transfer: 'Bank Transfer',
  mobile: 'Mobile Payment',
  voucher: 'Voucher',
  credit: 'Credit',
  other: 'Other',
}

export function POSLayout() {
  const [isFullscreen, setIsFullscreen] = useState(false)
  const [isOnline, setIsOnline] = useState(() => navigator.onLine)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [showCloseSessionDialog, setShowCloseSessionDialog] = useState(false)
  const [drawerStatusFilter, setDrawerStatusFilter] = useState<'all' | 'draft' | 'paid'>('all')
  const [closingNotes, setClosingNotes] = useState('')
  const [paymentBreakdown, setPaymentBreakdown] = useState<PaymentBreakdown[]>([])
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const activeTerminalId = usePosSessionStore((s) => s.activeTerminalId)
  const activeSessionId = usePosSessionStore((s) => s.activeSessionId)
  const openedAt = usePosSessionStore((s) => s.openedAt)
  const clearSession = usePosSessionStore((s) => s.clearSession)
  const [now, setNow] = useState(() => new Date())

  // Fetch terminals from API
  const { data: terminalsData } = useQuery({
    queryKey: ['pos-terminals'],
    queryFn: async () => {
      const response = await api.get('/pos/terminals')
      return response.data?.terminals || response.data || []
    },
  })

  const { data: companySettings } = useQuery({
    queryKey: ['pos-layout-company-settings'],
    queryFn: async () => {
      const response = await api.get('/accounting/settings')
      return response.data?.data || null
    },
    staleTime: 60_000,
  })

  const isTerminalScreen = location.pathname === '/pos/terminal'
  const isCustomerDisplayScreen = location.pathname === '/pos/customer-display'

  useEffect(() => {
    const handleOnline = () => setIsOnline(true)
    const handleOffline = () => setIsOnline(false)

    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)

    return () => {
      window.removeEventListener('online', handleOnline)
      window.removeEventListener('offline', handleOffline)
    }
  }, [])

  // Fetch expected payment breakdown when close dialog opens
  useEffect(() => {
    if (!showCloseSessionDialog || !activeSessionId) return

    const fetchBreakdown = async () => {
      try {
        const response = await api.get(`/pos/sessions/${activeSessionId}/close-summary`)
        const breakdown = response.data?.expected_breakdown || []

        const items: PaymentBreakdown[] = breakdown.map((item: { method: string; expected_amount: number }) => ({
          method: item.method,
          methodLabel: METHOD_LABELS[item.method] || item.method,
          expectedAmount: item.expected_amount,
          countedAmount: item.expected_amount.toFixed(2),
          icon: METHOD_ICONS[item.method] || 'solar:wallet-bold-duotone',
        }))

        // Always include cash even if no transactions (for float counting)
        if (!items.some((i) => i.method === 'cash')) {
          items.unshift({
            method: 'cash',
            methodLabel: 'Cash',
            expectedAmount: 0,
            countedAmount: '0.00',
            icon: 'solar:wallet-money-bold-duotone',
          })
        }

        setPaymentBreakdown(items)
      } catch {
        // Fallback to basic cash only
        setPaymentBreakdown([
          {
            method: 'cash',
            methodLabel: 'Cash',
            expectedAmount: 0,
            countedAmount: '0.00',
            icon: 'solar:wallet-money-bold-duotone',
          },
        ])
      }
    }

    void fetchBreakdown()
    setClosingNotes('')
  }, [showCloseSessionDialog, activeSessionId])

  const updateCountedAmount = useCallback((method: string, value: string) => {
    setPaymentBreakdown((prev) =>
      prev.map((item) =>
        item.method === method ? { ...item, countedAmount: value } : item
      )
    )
  }, [])

  const totalExpected = useMemo(
    () => paymentBreakdown.reduce((sum, item) => sum + item.expectedAmount, 0),
    [paymentBreakdown]
  )

  const totalCounted = useMemo(
    () => paymentBreakdown.reduce((sum, item) => sum + (parseFloat(item.countedAmount) || 0), 0),
    [paymentBreakdown]
  )

  const totalDifference = totalCounted - totalExpected

  // Close Session Mutation
  const closeSessionMutation = useMutation({
    mutationFn: async () => {
      console.log('POSLayout: Closing session via API:', activeSessionId)
      const paymentCounts = paymentBreakdown.map((item) => ({
        method: item.method,
        method_name: item.methodLabel,
        counted_amount: parseFloat(item.countedAmount) || 0,
      }))

      const response = await api.post(`/pos/sessions/${activeSessionId}/close`, {
        payment_counts: paymentCounts,
        notes: closingNotes,
      })
      return response.data
    },
    onSuccess: async () => {
      console.log('POSLayout: Session closed successfully')
      setShowCloseSessionDialog(false)
      // Clear local session state
      clearSession()
      // Invalidate and refetch queries
      await queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      await queryClient.refetchQueries({ queryKey: ['pos-terminals'] })
      await queryClient.invalidateQueries({ queryKey: ['pos-current-session'] })
      navigate('/pos')
      toast({
        title: 'Session Closed',
        description: 'Your POS session has been closed successfully.',
      })
    },
    onError: (error: any) => {
      console.error('POSLayout: Failed to close session:', error)
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to close session',
        variant: 'destructive',
      })
    },
  })

  const handleCloseSession = () => {
    console.log('POSLayout: Close Session button clicked!')
    if (!activeSessionId) {
      toast({
        title: 'Error',
        description: 'No active session to close',
        variant: 'destructive',
      })
      return
    }
    setShowCloseSessionDialog(true)
  }

  const confirmCloseSession = () => {
    if (!activeSessionId) return
    console.log('POSLayout: User confirmed, closing session via API...')
    closeSessionMutation.mutate()
  }

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(id)
  }, [])

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement)
    }
    document.addEventListener('fullscreenchange', handleFullscreenChange)
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange)
  }, [])

  useEffect(() => {
    const handleOpenOrdersDrawer = (event: Event) => {
      const detail = (event as CustomEvent<{ status?: 'all' | 'draft' | 'paid' }>).detail
      setDrawerStatusFilter(detail?.status ?? 'all')
      setIsDrawerOpen(true)
    }

    window.addEventListener('pos:open-orders-drawer', handleOpenOrdersDrawer as EventListener)
    return () => window.removeEventListener('pos:open-orders-drawer', handleOpenOrdersDrawer as EventListener)
  }, [])

  const toggleFullscreen = async () => {
    if (!document.fullscreenElement) {
      await document.documentElement.requestFullscreen()
    } else {
      await document.exitFullscreen()
    }
  }

  const terminals = terminalsData || []
  const tenantCompanyName = companySettings?.company_name?.trim() || 'Point of Sale'
  
  const activeTerminal = useMemo(() => {
    if (!activeTerminalId) return null
    return terminals.find((t: any) => String(t.id) === String(activeTerminalId)) ?? null
  }, [terminals, activeTerminalId])

  const openedDurationLabel = useMemo(() => {
    if (!openedAt) return '—'
    const started = new Date(openedAt).getTime()
    const diff = Math.max(0, now.getTime() - started)
    const totalSeconds = Math.floor(diff / 1000)
    const hours = Math.floor(totalSeconds / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60

    const pad2 = (n: number) => n.toString().padStart(2, '0')
    return `${pad2(hours)}:${pad2(minutes)}:${pad2(seconds)}`
  }, [openedAt, now])

  return (
    <div className="flex h-screen flex-col overflow-hidden bg-background">
      {!isCustomerDisplayScreen && <OfflineBanner isOnline={isOnline} />}
      {!isCustomerDisplayScreen && (
        <header className="flex h-16 items-center justify-between border-b border-black/5 bg-[linear-gradient(90deg,hsl(var(--primary))_0%,hsl(223_58%_34%)_100%)] px-4 text-primary-foreground shadow-[0_10px_30px_rgba(15,23,42,0.10)]">
          <div className="flex min-w-0 items-center gap-4">
            <Link to="/" className="flex items-center">
              <img
                src={NOOVAERP_LOGO_URL}
                alt="NoovaERP"
                className="h-8 w-auto shrink-0 object-contain brightness-0 invert"
              />
            </Link>

            {isTerminalScreen && (
              <div
                className="flex h-9 w-9 items-center justify-center rounded-full border border-white/15 bg-white/10 shadow-inner"
                title={isOnline ? 'Online' : 'Offline'}
                aria-label={isOnline ? 'Online' : 'Offline'}
              >
                <div className="relative flex h-5 w-5 items-center justify-center">
                  {isOnline && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-2.5 w-2.5">
                      <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-80"></span>
                      <span className="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                    </span>
                  )}
                  {!isOnline && <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-rose-400"></span>}
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.9"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className={`h-4.5 w-4.5 ${isOnline ? 'text-white' : 'text-rose-200'}`}
                    aria-hidden="true"
                  >
                    <path d="M12 18h.01"></path>
                    <path d="M4.93 10.93a10 10 0 0 1 14.14 0"></path>
                    <path d="M8.46 14.46a5 5 0 0 1 7.07 0"></path>
                    <path d="M2.1 8.1a14 14 0 0 1 19.8 0"></path>
                  </svg>
                </div>
                <span className="sr-only">{isOnline ? 'Online' : 'Offline'}</span>
              </div>
            )}
          </div>

          <div className="flex items-center gap-2">
            {isTerminalScreen && (
              <>
                <div className="mr-2 hidden rounded-2xl bg-white/12 px-3 py-2 text-right shadow-inner sm:block">
                  <div className="text-sm font-medium leading-none">{now.toLocaleTimeString()}</div>
                  <div className="text-xs opacity-80">Opened: {openedDurationLabel}</div>
                </div>
                <Button
                  variant="secondary"
                  size="sm"
                  className="mr-2 rounded-xl border-0 bg-white/95 px-4 text-slate-800 shadow-sm hover:bg-white"
                  onClick={async () => {
                    try {
                      // Open customer display (uses BroadcastChannel + localStorage sync)
                      const width = 1920
                      const height = 1080
                      const left = window.screen.width - width
                      const top = 0
                      window.open(
                        `${import.meta.env.BASE_URL}pos/customer-display`,
                        'CustomerDisplay',
                        `width=${width},height=${height},left=${left},top=${top},menubar=no,toolbar=no,location=no,status=no`
                      )
                    } catch (error) {
                      console.error('Failed to open customer display:', error)
                      toast({
                        title: 'Error',
                        description: 'Failed to open customer display',
                        variant: 'destructive',
                      })
                    }
                  }}
                >
                  <Icon icon="solar:monitor-bold-duotone" className="mr-2 h-4 w-4" />
                  Customer Display
                </Button>
                <Button
                  variant="destructive"
                  size="sm"
                  className="mr-2 rounded-xl bg-[#ef4444] px-4 text-white shadow-sm hover:bg-[#dc2626]"
                  onClick={handleCloseSession}
                >
                  <Icon icon="solar:logout-2-bold" className="mr-2 h-4 w-4" />
                  Close Session
                </Button>
              </>
            )}

            <PWAInstallButton />

            <Button
              variant="ghost"
              size="sm"
              className="rounded-xl text-primary-foreground hover:bg-primary-foreground/12"
              onClick={toggleFullscreen}
            >
              <Icon 
                icon={isFullscreen ? "solar:quit-full-screen-bold" : "solar:full-screen-bold"} 
                className="h-5 w-5" 
              />
              <span className="ml-2 hidden sm:inline">
                {isFullscreen ? 'Exit Fullscreen' : 'Fullscreen'}
              </span>
            </Button>

            <Button
              variant="ghost"
              size="sm"
              className="rounded-xl text-primary-foreground hover:bg-primary-foreground/12"
              onClick={() => {
                setDrawerStatusFilter('all')
                setIsDrawerOpen(true)
              }}
            >
              <Icon icon="solar:document-text-bold" className="h-5 w-5" />
              <span className="ml-2 hidden sm:inline">Orders</span>
            </Button>

            <Button
              variant="ghost"
              size="sm"
              className="rounded-xl text-primary-foreground hover:bg-primary-foreground/12"
              onClick={() => isTerminalScreen ? navigate('/pos') : navigate('/')}
            >
              <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
              <span className="ml-2 hidden sm:inline">Back</span>
            </Button>
          </div>
        </header>
      )}

      {/* Main Content - Full Width */}
      <main className="flex-1 overflow-hidden">
        <Outlet />
      </main>

      <POSOrderDrawer
        open={isDrawerOpen}
        onOpenChange={setIsDrawerOpen}
        initialStatusFilter={drawerStatusFilter}
      />

<Dialog open={showCloseSessionDialog} onOpenChange={setShowCloseSessionDialog}>
        <DialogContent className="max-w-lg max-h-[90vh] overflow-hidden rounded-[1.5rem] border-0 p-0 shadow-[0_24px_64px_rgba(15,23,42,0.18)]">
          {/* Header */}
          <div className="relative bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-6 pt-6 pb-5 text-white">
            <div className="absolute right-4 top-4 h-24 w-24 rounded-full bg-[#1e40af]/10 blur-2xl" />
            <div className="relative flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[1rem] bg-rose-500/15 ring-1 ring-rose-400/20">
                <Icon icon="solar:logout-2-bold-duotone" className="h-6 w-6 text-rose-400" />
              </div>
              <DialogHeader className="space-y-1 p-0 text-left">
                <DialogTitle className="text-lg font-semibold tracking-[-0.02em]">Close Register</DialogTitle>
                <DialogDescription className="text-sm leading-relaxed text-white/60">
                  Count cash and verify all payment methods before closing.
                </DialogDescription>
              </DialogHeader>
            </div>
          </div>

          {/* Body */}
          <div className="flex flex-col overflow-y-auto p-6" style={{ maxHeight: 'calc(90vh - 200px)' }}>
            {/* Session Info */}
            <div className="mb-5 grid grid-cols-3 gap-3 rounded-[1rem] border border-slate-200/80 bg-slate-50/80 p-3">
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Terminal</div>
                <div className="mt-1 text-sm font-semibold text-slate-900">{activeTerminal?.name || '—'}</div>
              </div>
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Runtime</div>
                <div className="mt-1 font-mono text-sm font-semibold tabular-nums text-slate-900">{openedDurationLabel}</div>
              </div>
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Status</div>
                <div className={`mt-1 inline-flex items-center gap-1.5 text-sm font-semibold ${isOnline ? 'text-emerald-600' : 'text-rose-600'}`}>
                  <span className={`h-1.5 w-1.5 rounded-full ${isOnline ? 'bg-emerald-500' : 'bg-rose-500'}`} />
                  {isOnline ? 'Online' : 'Offline'}
                </div>
              </div>
            </div>

            {/* Payment Method Counting */}
            <div className="mb-4">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.1em] text-slate-500">Payment Reconciliation</h3>
              <div className="space-y-2">
                {paymentBreakdown.map((item) => {
                  const counted = parseFloat(item.countedAmount) || 0
                  const diff = counted - item.expectedAmount
                  const diffTone = diff > 0.005 ? 'text-emerald-600' : diff < -0.005 ? 'text-rose-600' : 'text-slate-400'
                  const diffLabel = diff > 0.005 ? 'Over' : diff < -0.005 ? 'Short' : 'Exact'

                  return (
                    <div key={item.method} className="rounded-[0.85rem] border border-slate-200/80 bg-white p-3">
                      <div className="flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100">
                            <Icon icon={item.icon} className="h-4 w-4 text-slate-600" />
                          </div>
                          <div>
                            <div className="text-sm font-semibold text-slate-900">{item.methodLabel}</div>
                            <div className="text-xs text-slate-400">Expected: {formatCurrency(item.expectedAmount)}</div>
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={item.countedAmount}
                            onChange={(e) => updateCountedAmount(item.method, e.target.value)}
                            className="h-9 w-28 rounded-lg border-slate-200 bg-slate-50 text-right text-sm font-semibold tabular-nums focus:bg-white"
                            placeholder="0.00"
                          />
                        </div>
                      </div>
                      {item.expectedAmount > 0 && (
                        <div className={`mt-2 flex items-center justify-end gap-1 text-xs font-medium ${diffTone}`}>
                          <span>{diffLabel}</span>
                          <span>{diff > 0 ? '+' : ''}{formatCurrency(diff)}</span>
                        </div>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>

            {/* Totals Summary */}
            <div className="mb-4 rounded-[1rem] border border-slate-200/80 bg-slate-50/80 p-4">
              <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-slate-500">Total Expected</span>
                  <span className="font-semibold tabular-nums text-slate-900">{formatCurrency(totalExpected)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Total Counted</span>
                  <span className="font-semibold tabular-nums text-slate-900">{formatCurrency(totalCounted)}</span>
                </div>
                <Separator />
                <div className="flex justify-between">
                  <span className="font-semibold text-slate-700">Difference</span>
                  <span className={`font-bold tabular-nums ${totalDifference > 0.005 ? 'text-emerald-600' : totalDifference < -0.005 ? 'text-rose-600' : 'text-slate-500'}`}>
                    {totalDifference > 0.005 ? '+' : ''}{formatCurrency(totalDifference)}
                  </span>
                </div>
              </div>
            </div>

            {/* Notes */}
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">Closing Notes (optional)</label>
              <Input
                value={closingNotes}
                onChange={(e) => setClosingNotes(e.target.value)}
                placeholder="Any discrepancies or notes..."
                className="h-9 rounded-lg border-slate-200 bg-slate-50 text-sm"
              />
            </div>
          </div>

          {/* Footer */}
          <div className="border-t border-slate-200/80 bg-slate-50/50 px-6 py-4">
            <div className="flex gap-3">
              <Button
                variant="outline"
                onClick={() => setShowCloseSessionDialog(false)}
                disabled={closeSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] border-slate-200 bg-white py-2.5 font-semibold text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </Button>
              <Button
                onClick={confirmCloseSession}
                disabled={closeSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] bg-gradient-to-b from-rose-600 to-rose-700 py-2.5 font-semibold text-white shadow-[0_8px_20px_rgba(225,29,72,0.30)] hover:from-rose-700 hover:to-rose-800"
              >
                {closeSessionMutation.isPending ? (
                  <span className="flex items-center justify-center gap-2">
                    <Icon icon="svg-spinners:ring-resize" className="h-4 w-4" />
                    Closing...
                  </span>
                ) : 'Close Register'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
