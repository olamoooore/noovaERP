import { useMemo, useState, useEffect, useCallback } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import api from '@/lib/api'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription, CardFooter } from '@/components/ui/card'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  SlideOver,
  SlideOverTrigger,
  SlideOverContent,
} from '@/components/ui/slide-over'
import { POSOrdersPage } from './POS/POSOrdersPage'
import { useToast } from '@/hooks/use-toast'
import { usePosSessionStore, PosTerminal } from '@/store/posSessionStore'
import { cn } from '@/lib/utils'

const OPENING_DENOMINATIONS = [
  { label: 'S$100', value: 100, type: 'note' as const },
  { label: 'S$50', value: 50, type: 'note' as const },
  { label: 'S$10', value: 10, type: 'note' as const },
  { label: 'S$5', value: 5, type: 'note' as const },
  { label: 'S$2', value: 2, type: 'note' as const },
  { label: 'S$1', value: 1, type: 'coin' as const },
  { label: '50¢', value: 0.5, type: 'coin' as const },
  { label: '20¢', value: 0.2, type: 'coin' as const },
  { label: '10¢', value: 0.1, type: 'coin' as const },
  { label: '5¢', value: 0.05, type: 'coin' as const },
]

const formatSgMoney = (amount: number) => {
  return new Intl.NumberFormat('en-SG', {
    style: 'currency',
    currency: 'SGD',
    minimumFractionDigits: 2,
  }).format(amount)
}

interface Warehouse {
  id: number
  name: string
}

interface PosSessionResponse {
  id: number
  session_number: string
  terminal_id: number
  user_id: number
  status: 'open' | 'closed'
  opening_balance: number
  opened_at: string
  terminal?: PosTerminal
}

export function POSPage() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const activeTerminalId = usePosSessionStore((s) => s.activeTerminalId)
  const activeSessionId = usePosSessionStore((s) => s.activeSessionId)
  const openedAt = usePosSessionStore((s) => s.openedAt)
  const startSession = usePosSessionStore((s) => s.startSession)
  const setActiveSession = usePosSessionStore((s) => s.setActiveSession)

  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [newTerminalName, setNewTerminalName] = useState('')
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<string>('')
  
  // Session Opening State
  const [isSessionDialogOpen, setIsSessionDialogOpen] = useState(false)
  const [selectedStaffId, setSelectedStaffId] = useState<string>('')
  const [openingBalance, setOpeningBalance] = useState<string>('0')
  const [openingNotes, setOpeningNotes] = useState<string>('')
  const [showOpeningCountPanel, setShowOpeningCountPanel] = useState(false)
  const [openingDenominationCounts, setOpeningDenominationCounts] = useState<Record<string, number>>({})
  const [pendingTerminalId, setPendingTerminalId] = useState<string | null>(null)
  const [pendingWarehouseId, setPendingWarehouseId] = useState<string | null>(null)

  // Edit Terminal State
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false)
  const [editingTerminal, setEditingTerminal] = useState<PosTerminal | null>(null)
  const [editTerminalName, setEditTerminalName] = useState('')
  const [editWarehouseId, setEditWarehouseId] = useState<string>('')

  // Fetch terminals from API
  const { data: terminalsData, isLoading: terminalsLoading } = useQuery({
    queryKey: ['pos-terminals'],
    queryFn: async () => {
      const response = await api.get('/pos/terminals')
      return response.data?.terminals || response.data || []
    },
    refetchOnMount: 'always',  // Always fetch fresh data when component mounts
    staleTime: 0,  // Consider data stale immediately
  })

  // Fetch current session from API
  const { data: currentSessionData, isLoading: sessionLoading } = useQuery({
    queryKey: ['pos-current-session'],
    queryFn: async () => {
      const response = await api.get('/pos/sessions/current')
      return response.data?.session || null
    },
    refetchOnMount: 'always',  // Always fetch fresh data when component mounts
    staleTime: 0,  // Consider data stale immediately
  })

  const terminals: PosTerminal[] = useMemo(() => {
    return Array.isArray(terminalsData) ? terminalsData : []
  }, [terminalsData])

  // Sync current session from API to store
  // If backend says no current session, clear local store session as well
  const clearSession = usePosSessionStore((s) => s.clearSession)
  
  useEffect(() => {
    if (currentSessionData) {
      setActiveSession(currentSessionData)
    } else if (currentSessionData === null && !sessionLoading) {
      // Backend returned null - no active session, clear local store
      clearSession()
    }
  }, [currentSessionData, sessionLoading, setActiveSession, clearSession])

  const { data: warehousesData } = useQuery({
    queryKey: ['pos-warehouses'],
    queryFn: async () => {
      const response = await api.get('/pos/warehouses')
      return response.data
    },
  })

  const { data: users = [] } = useQuery({
    queryKey: ['users'],
    queryFn: async () => {
      const res = await api.get('/users')
      return Array.isArray(res.data) ? res.data : (res.data?.data || [])
    },
  })

  const warehouses: Warehouse[] = useMemo(() => {
    const raw = warehousesData?.data || warehousesData || []
    return Array.isArray(raw) ? raw : []
  }, [warehousesData])

  // Create terminal mutation
  const createTerminalMutation = useMutation({
    mutationFn: async (data: { name: string; warehouse_id?: string }) => {
      const response = await api.post('/pos/terminals', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      toast({
        title: 'Terminal created',
        description: 'New terminal has been created successfully.',
      })
      setNewTerminalName('')
      setSelectedWarehouseId('')
      setIsDialogOpen(false)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create terminal',
        variant: 'destructive',
      })
    },
  })

  // Update terminal mutation
  const updateTerminalMutation = useMutation({
    mutationFn: async ({ id, data }: { id: string; data: { name: string; warehouse_id?: string } }) => {
      const response = await api.put(`/pos/terminals/${id}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      toast({
        title: 'Terminal updated',
        description: 'Terminal has been updated successfully.',
      })
      setIsEditDialogOpen(false)
      setEditingTerminal(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update terminal',
        variant: 'destructive',
      })
    },
  })

  const openEditTerminal = (terminal: PosTerminal) => {
    setEditingTerminal(terminal)
    setEditTerminalName(terminal.name)
    setEditWarehouseId(terminal.warehouse_id || '')
    setIsEditDialogOpen(true)
  }

  const handleUpdateTerminal = () => {
    if (!editingTerminal) return
    if (!editTerminalName.trim()) {
      toast({
        title: 'Name required',
        description: 'Please enter a terminal name.',
        variant: 'destructive',
      })
      return
    }

    updateTerminalMutation.mutate({
      id: editingTerminal.id,
      data: {
        name: editTerminalName.trim(),
        warehouse_id: editWarehouseId || undefined,
      },
    })
  }

  // Open session mutation
  const openSessionMutation = useMutation({
    mutationFn: async (data: { 
      terminal_id: string
      opening_balance: number
      notes?: string 
    }) => {
      const response = await api.post('/pos/sessions/open', data)
      return response.data
    },
    onSuccess: (data) => {
      const session: PosSessionResponse = data.session
      startSession({
        sessionId: session.id,
        terminalId: String(session.terminal_id),
        warehouseId: pendingWarehouseId,
        staffId: selectedStaffId,
      })
      queryClient.invalidateQueries({ queryKey: ['pos-current-session'] })
      queryClient.invalidateQueries({ queryKey: ['pos-terminals'] })
      setIsSessionDialogOpen(false)
      navigate('/pos/terminal')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.error || 'Failed to open session',
        variant: 'destructive',
      })
    },
  })

  const handleCreateTerminal = () => {
    if (!newTerminalName.trim()) {
      toast({
        title: 'Name required',
        description: 'Please enter a terminal name.',
        variant: 'destructive',
      })
      return
    }

    createTerminalMutation.mutate({
      name: newTerminalName.trim(),
      warehouse_id: selectedWarehouseId || undefined,
    })
  }

  const handleOpenTerminalClick = (terminal: PosTerminal) => {
    console.log('POSPage: Terminal clicked:', terminal.id, terminal.name)
    console.log('POSPage: has_open_session:', terminal.has_open_session)
    console.log('POSPage: current_session:', terminal.current_session)
    
    // If this terminal already has an open session, resume it directly
    if (terminal.has_open_session && terminal.current_session) {
      console.log('POSPage: Resuming existing session...')
      // Resume the existing session without showing dialog
      startSession({
        sessionId: terminal.current_session.id,
        terminalId: String(terminal.id),
        warehouseId: terminal.warehouse_id != null ? String(terminal.warehouse_id) : null,
        staffId: String(terminal.current_session.user_id),
      })
      console.log('POSPage: Navigating to terminal...')
      navigate('/pos/terminal')
      return
    }

    // Otherwise open session dialog for new session
    setPendingTerminalId(String(terminal.id))
    setPendingWarehouseId(
      terminal.warehouse_id != null
        ? String(terminal.warehouse_id)
        : (warehouses[0]?.id != null ? String(warehouses[0].id) : null)
    )
    setSelectedStaffId('')
    setOpeningBalance('0')
    setOpeningNotes('')
    setShowOpeningCountPanel(false)
    setOpeningDenominationCounts({})
    setIsSessionDialogOpen(true)
  }

  const confirmOpenSession = () => {
    if (!pendingTerminalId || !selectedStaffId) {
      toast({
        title: 'Required fields',
        description: 'Please select a staff member.',
        variant: 'destructive',
      })
      return
    }

    openSessionMutation.mutate({
      terminal_id: pendingTerminalId,
      opening_balance: parseFloat(openingBalance) || 0,
      notes: openingNotes || undefined,
    })
  }

  const notesTotal = useMemo(() => {
    return OPENING_DENOMINATIONS
      .filter((item) => item.type === 'note')
      .reduce((sum, item) => sum + (openingDenominationCounts[item.label] || 0) * item.value, 0)
  }, [openingDenominationCounts])

  const coinsTotal = useMemo(() => {
    return OPENING_DENOMINATIONS
      .filter((item) => item.type === 'coin')
      .reduce((sum, item) => sum + (openingDenominationCounts[item.label] || 0) * item.value, 0)
  }, [openingDenominationCounts])

  const openingPiecesTotal = useMemo(() => {
    return OPENING_DENOMINATIONS.reduce(
      (sum, item) => sum + (openingDenominationCounts[item.label] || 0),
      0
    )
  }, [openingDenominationCounts])

  const updateOpeningDenominationCount = useCallback((label: string, qty: number) => {
    const nextQty = Math.max(0, Math.floor(Number.isFinite(qty) ? qty : 0))
    setOpeningDenominationCounts((prev) => {
      const next = {
        ...prev,
        [label]: nextQty,
      }

      const total = OPENING_DENOMINATIONS.reduce(
        (sum, item) => sum + (next[item.label] || 0) * item.value,
        0
      )
      setOpeningBalance(total.toFixed(2))

      return next
    })
  }, [])

  const adjustOpeningDenominationCount = useCallback((label: string, delta: number) => {
    const currentQty = openingDenominationCounts[label] || 0
    updateOpeningDenominationCount(label, currentQty + delta)
  }, [openingDenominationCounts, updateOpeningDenominationCount])

  // Helper to calculate duration if active
  const getActiveDuration = () => {
    if (!openedAt) return ''
    const start = new Date(openedAt).getTime()
    const now = new Date().getTime()
    const diffMins = Math.floor((now - start) / 60000)
    if (diffMins < 60) return `${diffMins}m`
    const diffHours = Math.floor(diffMins / 60)
    return `${diffHours}h ${diffMins % 60}m`
  }

  return (
    <div className="container mx-auto py-8 max-w-5xl space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Point of Sale</h1>
          <p className="text-muted-foreground mt-1">Select a terminal to start selling.</p>
        </div>
        <div className="flex gap-2">
          <SlideOver>
            <SlideOverTrigger asChild>
              <Button variant="outline">
                <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
                Orders
              </Button>
            </SlideOverTrigger>

            <SlideOverContent>
              <POSOrdersPage />
            </SlideOverContent>
          </SlideOver>
          <Link to="/pos/barcodes">
            <Button variant="outline">
              <Icon icon="solar:qr-code-bold" className="mr-2 h-4 w-4" />
              Barcodes
            </Button>
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        {/* Existing Terminals */}
        {terminalsLoading ? (
          <div className="col-span-full flex items-center justify-center py-12">
            <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : (
          terminals.map((terminal) => {
            const isActive = activeTerminalId === terminal.id
            const warehouseName = terminal.warehouse?.name || terminal.warehouse_id 
              ? warehouses.find(w => w.id.toString() === terminal.warehouse_id)?.name 
              : 'Default Warehouse'

            return (
              <Card 
                key={terminal.id} 
                className={cn(
                  "cursor-pointer transition-all border-2",
                  isActive 
                    ? "border-primary/50 bg-primary/5 shadow-sm" 
                    : "bg-card shadow-md hover:shadow-lg border-transparent hover:border-border",
                  terminal.has_open_session && !isActive && "border-orange-200 bg-orange-50/30"
                )}
                onClick={() => handleOpenTerminalClick(terminal)}
              >
                <CardHeader className="pb-2">
                  <div className="flex justify-between items-start">
                    <div className="space-y-1">
                      <CardTitle className="text-xl">{terminal.name}</CardTitle>
                      <CardDescription>{warehouseName || 'Default Warehouse'}</CardDescription>
                    </div>
                    <div className={cn(
                      "h-3 w-3 rounded-full ring-2 ring-offset-2",
                      isActive ? "bg-green-500 ring-green-200" 
                        : terminal.has_open_session ? "bg-orange-400 ring-orange-200"
                        : "bg-red-200 ring-transparent"
                    )} />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Icon icon="solar:clock-circle-bold" className="w-4 h-4" />
                    {isActive ? (
                      <span className="text-green-600 font-medium">Active • {getActiveDuration()}</span>
                    ) : terminal.has_open_session ? (
                      <span className="text-orange-600 font-medium">Session in progress</span>
                    ) : (
                      <span>Click to open session</span>
                    )}
                  </div>
                </CardContent>
                <CardFooter className="pt-0 flex gap-2">
                  <Button 
                    variant={isActive ? "default" : terminal.has_open_session ? "outline" : "secondary"} 
                    className="flex-1"
                  >
                    {isActive ? 'Resume Session' 
                      : terminal.has_open_session ? 'Resume Session' 
                      : 'Open Session'}
                  </Button>
                  {!terminal.has_open_session && (
                    <Button
                      variant="outline"
                      size="icon"
                      onClick={(e) => {
                        e.stopPropagation()
                        openEditTerminal(terminal)
                      }}
                    >
                      <Icon icon="solar:pen-bold" className="h-4 w-4" />
                    </Button>
                  )}
                </CardFooter>
              </Card>
            )
          })
        )}

        {/* Add New Terminal Card */}
        <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
          <DialogTrigger asChild>
            <Card className="flex flex-col items-center justify-center min-h-[200px] border-2 border-dashed cursor-pointer hover:border-primary/50 hover:bg-accent/50 transition-colors group">
              <div className="h-12 w-12 rounded-full bg-secondary group-hover:bg-primary/10 flex items-center justify-center mb-4 transition-colors">
                <Icon icon="solar:add-circle-bold" className="w-6 h-6 text-muted-foreground group-hover:text-primary" />
              </div>
              <h3 className="font-semibold text-lg">Add New Terminal</h3>
              <p className="text-sm text-muted-foreground">Setup a new POS station</p>
            </Card>
          </DialogTrigger>
          <DialogContent className="sm:max-w-[425px]">
            <DialogHeader>
              <DialogTitle>POS Setup</DialogTitle>
              <DialogDescription>
                Configure your new Point of Sale terminal.
              </DialogDescription>
            </DialogHeader>
            
            <div className="grid gap-6 py-4">
              <div className="space-y-2">
                <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                  Terminal Name
                </label>
                <Input
                  id="name"
                  placeholder="e.g. Store 1 - Counter A"
                  value={newTerminalName}
                  onChange={(e) => setNewTerminalName(e.target.value)}
                />
                <p className="text-[0.8rem] text-muted-foreground">
                  This is your store/terminal for this POS session.
                </p>
              </div>

              <div className="space-y-2">
                <label className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                  Warehouse
                </label>
                <Select value={selectedWarehouseId} onValueChange={setSelectedWarehouseId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Select warehouse" />
                  </SelectTrigger>
                  <SelectContent>
                    {warehouses.map((w) => (
                      <SelectItem key={w.id} value={w.id.toString()}>
                        {w.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-[0.8rem] text-muted-foreground">
                  Used for stock context and filtering.
                </p>
              </div>
            </div>

            <DialogFooter>
              <Button 
                onClick={handleCreateTerminal}
                disabled={createTerminalMutation.isPending}
              >
                {createTerminalMutation.isPending && (
                  <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                )}
                Create Terminal
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
      
      <div className="text-center text-sm text-muted-foreground mt-8">
        {terminals.length} terminal{terminals.length !== 1 ? 's' : ''} configured
      </div>

      {/* Session Opening Dialog */}
      <Dialog open={isSessionDialogOpen} onOpenChange={setIsSessionDialogOpen}>
        <DialogContent className="w-[96vw] max-w-none sm:max-w-[820px] max-h-[90vh] overflow-hidden rounded-[1.5rem] border-0 p-0 shadow-[0_24px_64px_rgba(15,23,42,0.18)]">
          {/* Header */}
          <div className="relative bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-6 pt-6 pb-5 text-white">
            <div className="absolute right-4 top-4 h-24 w-24 rounded-full bg-[#1e40af]/10 blur-2xl" />
            <div className="relative flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[1rem] bg-emerald-500/15 ring-1 ring-emerald-400/20">
                <Icon icon="solar:wallet-money-bold-duotone" className="h-6 w-6 text-emerald-400" />
              </div>
              <DialogHeader className="space-y-1 p-0 text-left">
                <DialogTitle className="flex items-center gap-2 text-lg font-semibold tracking-[-0.02em]">
                  <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5 shrink-0 text-emerald-300" />
                  Open Register
                </DialogTitle>
                <DialogDescription className="text-sm leading-relaxed text-white/60">
                  Count the cash in the drawer and assign a staff member to start the session.
                </DialogDescription>
              </DialogHeader>
            </div>
          </div>

          {/* Body */}
          <div className="flex flex-col overflow-y-auto p-6" style={{ maxHeight: 'calc(90vh - 200px)' }}>
            {/* Terminal Info */}
            <div className="mb-5 grid grid-cols-2 gap-3 rounded-[1rem] border border-slate-200/80 bg-slate-50/80 p-3">
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Terminal</div>
                <div className="mt-1 text-sm font-semibold text-slate-900">
                  {terminals.find(t => String(t.id) === String(pendingTerminalId ?? ''))?.name || '—'}
                </div>
              </div>
              <div className="text-center">
                <div className="text-[11px] font-medium uppercase tracking-[0.1em] text-slate-400">Warehouse</div>
                <div className="mt-1 text-sm font-semibold text-slate-900">
                  {warehouses.find(w => String(w.id) === String(pendingWarehouseId ?? ''))?.name || '—'}
                </div>
              </div>
            </div>

            {/* Staff Assignment */}
            <div className="mb-5">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.1em] text-slate-500">Staff Assignment</h3>
              <Select value={selectedStaffId} onValueChange={setSelectedStaffId}>
                <SelectTrigger className="h-11 rounded-lg border-slate-200 bg-slate-50">
                  <SelectValue placeholder="Select staff member" />
                </SelectTrigger>
                <SelectContent>
                  {users.map((user: any) => (
                    <SelectItem key={user.id} value={user.id.toString()}>
                      {user.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Opening Cash Count */}
            <div className="mb-4">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-[0.1em] text-slate-500">Opening Cash Count</h3>
              <div className="rounded-[1rem] border border-slate-200/80 bg-white p-4">
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-2.5">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50">
                      <Icon icon="solar:wallet-money-bold-duotone" className="h-5 w-5 text-emerald-600" />
                    </div>
                    <div>
                      <div className="text-sm font-semibold text-slate-900">Cash in Drawer</div>
                      <div className="text-xs text-slate-400">Count the physical cash before opening</div>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <div className="relative w-32">
                      <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={openingBalance}
                        onChange={(e) => setOpeningBalance(e.target.value)}
                        className="h-11 rounded-lg border-slate-200 bg-slate-50 pl-7 text-right text-base font-semibold tabular-nums focus:bg-white"
                        placeholder="0.00"
                        autoFocus
                      />
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      className={cn(
                        'h-11 w-11 rounded-lg border-slate-200',
                        showOpeningCountPanel && 'border-primary bg-primary/5 text-primary'
                      )}
                      onClick={() => setShowOpeningCountPanel((prev) => !prev)}
                      title="Count notes and coins"
                    >
                      <Icon icon="solar:calculator-bold-duotone" className="h-5 w-5" />
                    </Button>
                  </div>
                </div>
              </div>
            </div>

            {showOpeningCountPanel && (
              <div className="mb-4 rounded-[1rem] border border-slate-200/80 bg-slate-50/70 p-4">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <div className="rounded-xl border border-slate-200 bg-white p-3">
                    <h4 className="mb-2 text-sm font-semibold text-slate-700">Notes Count</h4>
                    <div className="space-y-2">
                      {OPENING_DENOMINATIONS.filter((item) => item.type === 'note').map((item) => {
                        const qty = openingDenominationCounts[item.label] || 0
                        const rowTotal = qty * item.value
                        return (
                          <div key={item.label} className="grid grid-cols-[70px_1fr_90px] items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <span className="text-sm font-semibold text-slate-700">{item.label}</span>
                            <div className="grid grid-cols-[30px_1fr_30px] items-center gap-1">
                              <Button type="button" variant="outline" className="h-8 w-8 p-0" onClick={() => adjustOpeningDenominationCount(item.label, -1)}>
                                -
                              </Button>
                              <Input
                                type="number"
                                min="0"
                                step="1"
                                value={qty}
                                onChange={(e) => updateOpeningDenominationCount(item.label, Number(e.target.value || 0))}
                                className="h-8 px-1 text-center text-sm"
                              />
                              <Button type="button" variant="outline" className="h-8 w-8 p-0" onClick={() => adjustOpeningDenominationCount(item.label, 1)}>
                                +
                              </Button>
                            </div>
                            <span className="text-right text-xs font-semibold text-slate-600">{formatSgMoney(rowTotal)}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>

                  <div className="rounded-xl border border-slate-200 bg-white p-3">
                    <h4 className="mb-2 text-sm font-semibold text-slate-700">Coins Count</h4>
                    <div className="space-y-2">
                      {OPENING_DENOMINATIONS.filter((item) => item.type === 'coin').map((item) => {
                        const qty = openingDenominationCounts[item.label] || 0
                        const rowTotal = qty * item.value
                        return (
                          <div key={item.label} className="grid grid-cols-[70px_1fr_90px] items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2">
                            <span className="text-sm font-semibold text-slate-700">{item.label}</span>
                            <div className="grid grid-cols-[30px_1fr_30px] items-center gap-1">
                              <Button type="button" variant="outline" className="h-8 w-8 p-0" onClick={() => adjustOpeningDenominationCount(item.label, -1)}>
                                -
                              </Button>
                              <Input
                                type="number"
                                min="0"
                                step="1"
                                value={qty}
                                onChange={(e) => updateOpeningDenominationCount(item.label, Number(e.target.value || 0))}
                                className="h-8 px-1 text-center text-sm"
                              />
                              <Button type="button" variant="outline" className="h-8 w-8 p-0" onClick={() => adjustOpeningDenominationCount(item.label, 1)}>
                                +
                              </Button>
                            </div>
                            <span className="text-right text-xs font-semibold text-slate-600">{formatSgMoney(rowTotal)}</span>
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>

                <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-3">
                  <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <div className="text-xs text-slate-500">Notes Total</div>
                    <div className="text-base font-semibold text-slate-800">{formatSgMoney(notesTotal)}</div>
                  </div>
                  <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <div className="text-xs text-slate-500">Coins Total</div>
                    <div className="text-base font-semibold text-slate-800">{formatSgMoney(coinsTotal)}</div>
                  </div>
                  <div className="rounded-lg border border-slate-200 bg-white p-3">
                    <div className="text-xs text-slate-500">Total Pieces</div>
                    <div className="text-base font-semibold text-slate-800">{openingPiecesTotal}</div>
                  </div>
                </div>
              </div>
            )}

            {/* Notes */}
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-500">Notes (optional)</label>
              <Input
                value={openingNotes}
                onChange={(e) => setOpeningNotes(e.target.value)}
                placeholder="Any notes about the opening cash..."
                className="h-9 rounded-lg border-slate-200 bg-slate-50 text-sm"
              />
            </div>
          </div>

          {/* Footer */}
          <div className="border-t border-slate-200/80 bg-slate-50/50 px-6 py-4">
            <div className="flex gap-3">
              <Button
                variant="outline"
                onClick={() => setIsSessionDialogOpen(false)}
                disabled={openSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] border-slate-200 bg-white py-2.5 font-semibold text-slate-700 hover:bg-slate-50"
              >
                Cancel
              </Button>
              <Button
                onClick={confirmOpenSession}
                disabled={!selectedStaffId || openSessionMutation.isPending}
                className="flex-1 rounded-[0.85rem] bg-gradient-to-b from-emerald-600 to-emerald-700 py-2.5 font-semibold text-white shadow-[0_8px_20px_rgba(16,185,129,0.30)] hover:from-emerald-700 hover:to-emerald-800"
              >
                {openSessionMutation.isPending ? (
                  <span className="flex items-center justify-center gap-2">
                    <Icon icon="svg-spinners:ring-resize" className="h-4 w-4" />
                    Opening...
                  </span>
                ) : 'Open Register'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Edit Terminal Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="sm:max-w-[425px]">
          <DialogHeader>
            <DialogTitle>Edit Terminal</DialogTitle>
            <DialogDescription>
              Update terminal name and warehouse configuration.
            </DialogDescription>
          </DialogHeader>
          
          <div className="grid gap-6 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Terminal Name *
              </label>
              <Input
                value={editTerminalName}
                onChange={(e) => setEditTerminalName(e.target.value)}
                placeholder="e.g. Store 1 - Counter A"
              />
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Warehouse
              </label>
              <Select value={editWarehouseId} onValueChange={setEditWarehouseId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select warehouse" />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.map((w) => (
                    <SelectItem key={w.id} value={w.id.toString()}>
                      {w.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Used for stock context and filtering.
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={handleUpdateTerminal}
              disabled={updateTerminalMutation.isPending}
            >
              {updateTerminalMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Save Changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
