import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { useMemo, useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
// import { Badge } from '@/components/ui/badge'
import { usePosSessionStore } from '@/store/posSessionStore'
import { POSOrderDrawer } from '@/components/pos/POSOrderDrawer'
import { PWAInstallButton } from '@/components/pos/PWAInstallButton'
import { OfflineBanner } from '@/components/pos/OfflineBanner'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'

export function POSLayout() {
  const [isFullscreen, setIsFullscreen] = useState(false)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { toast } = useToast()

  const activeTerminalId = usePosSessionStore((s) => s.activeTerminalId)
  const activeSessionId = usePosSessionStore((s) => s.activeSessionId)
  // const warehouseId = usePosSessionStore((s) => s.warehouseId) // Reserved for future use
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

  const isTerminalScreen = location.pathname === '/pos/terminal'

  // Close Session Mutation
  const closeSessionMutation = useMutation({
    mutationFn: async ({ sessionId, closingBalance }: { sessionId: number; closingBalance: number }) => {
      console.log('POSLayout: Closing session via API:', sessionId)
      const response = await api.post(`/pos/sessions/${sessionId}/close`, {
        closing_balance: closingBalance,
        notes: 'Closed from POS header',
      })
      return response.data
    },
    onSuccess: async () => {
      console.log('POSLayout: Session closed successfully')
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
    if (window.confirm('Are you sure you want to close this session?')) {
      console.log('POSLayout: User confirmed, closing session via API...')
      closeSessionMutation.mutate({
        sessionId: activeSessionId,
        closingBalance: 0, // Default closing balance from header
      })
    }
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

  const toggleFullscreen = async () => {
    if (!document.fullscreenElement) {
      await document.documentElement.requestFullscreen()
    } else {
      await document.exitFullscreen()
    }
  }

  const terminals = terminalsData || []
  
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
      <OfflineBanner />
      {/* Mint Top Bar */}
      <header className="flex h-14 items-center justify-between border-b bg-primary px-4 text-primary-foreground">
        <div className="flex min-w-0 items-center gap-4">
          <Link to="/" className="flex items-center gap-2">
            <Icon icon="solar:buildings-2-bold" className="h-7 w-7" />
            <span className="text-lg font-bold">NoovaERP</span>
          </Link>

          {isTerminalScreen ? (
            <div className="flex items-center gap-2 min-w-0">
              <span className="relative flex h-3 w-3">
                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                <span className="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
              </span>
              <span className="font-semibold text-lg truncate">
                {activeTerminal?.name || 'Terminal'}
              </span>
            </div>
          ) : (
            <span className="text-sm opacity-80">Point of Sale</span>
          )}
        </div>

        <div className="flex items-center gap-2">
          {isTerminalScreen && (
            <>
              <div className="mr-2 hidden text-right sm:block">
                <div className="text-sm font-medium leading-none">{now.toLocaleTimeString()}</div>
                <div className="text-xs opacity-80">Opened: {openedDurationLabel}</div>
              </div>
              <Button
                variant="secondary"
                size="sm"
                className="mr-2"
                onClick={() => {
                  const width = 1920
                  const height = 1080
                  const left = window.screen.width - width
                  const top = 0
                  window.open(
                    `${import.meta.env.BASE_URL}pos/customer-display?session=${activeSessionId}`,
                    'CustomerDisplay',
                    `width=${width},height=${height},left=${left},top=${top},menubar=no,toolbar=no,location=no,status=no`
                  )
                }}
              >
                <Icon icon="solar:monitor-bold-duotone" className="mr-2 h-4 w-4" />
                Customer Display
              </Button>
              <Button
                variant="destructive"
                size="sm"
                className="mr-2"
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
            className="text-primary-foreground hover:bg-primary-foreground/10"
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
            className="text-primary-foreground hover:bg-primary-foreground/10"
            onClick={() => setIsDrawerOpen(true)}
          >
            <Icon icon="solar:document-text-bold" className="h-5 w-5" />
            <span className="ml-2 hidden sm:inline">Orders</span>
          </Button>

          <Button
            variant="ghost"
            size="sm"
            className="text-primary-foreground hover:bg-primary-foreground/10"
            onClick={() => isTerminalScreen ? navigate('/pos') : navigate('/')}
          >
            <Icon icon="solar:arrow-left-linear" className="h-5 w-5" />
            <span className="ml-2 hidden sm:inline">Back</span>
          </Button>
        </div>
      </header>

      {/* Main Content - Full Width */}
      <main className="flex-1 overflow-hidden">
        <Outlet />
      </main>

      <POSOrderDrawer open={isDrawerOpen} onOpenChange={setIsDrawerOpen} />
    </div>
  )
}
