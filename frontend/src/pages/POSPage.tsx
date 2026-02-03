import { useMemo, useState, useEffect } from 'react'
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
      toast({
        title: 'Session opened',
        description: `Session ${session.session_number} started successfully.`,
      })
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
        terminalId: terminal.id,
        warehouseId: terminal.warehouse_id || null,
        staffId: String(terminal.current_session.user_id),
      })
      console.log('POSPage: Navigating to terminal...')
      navigate('/pos/terminal')
      return
    }

    // Otherwise open session dialog for new session
    setPendingTerminalId(terminal.id)
    setPendingWarehouseId(terminal.warehouse_id || (warehouses[0]?.id?.toString() ?? null))
    setSelectedStaffId('')
    setOpeningBalance('0')
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
    })
  }

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
        <DialogContent className="sm:max-w-[425px]">
          <DialogHeader>
            <DialogTitle>Open Session</DialogTitle>
            <DialogDescription>
              Select staff member and enter opening balance to start this terminal session.
            </DialogDescription>
          </DialogHeader>
          
          <div className="grid gap-6 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Staff Member *
              </label>
              <Select value={selectedStaffId} onValueChange={setSelectedStaffId}>
                <SelectTrigger>
                  <SelectValue placeholder="Select staff" />
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

            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Opening Balance
              </label>
              <Input
                type="number"
                min="0"
                step="0.01"
                value={openingBalance}
                onChange={(e) => setOpeningBalance(e.target.value)}
                placeholder="0.00"
              />
              <p className="text-xs text-muted-foreground">
                Cash amount in the drawer at session start
              </p>
            </div>

            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Warehouse
              </label>
              <Select 
                value={pendingWarehouseId || ''} 
                onValueChange={setPendingWarehouseId}
              >
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
            </div>
          </div>

          <DialogFooter>
            <Button 
              onClick={confirmOpenSession} 
              disabled={!selectedStaffId || openSessionMutation.isPending}
            >
              {openSessionMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Open Session
            </Button>
          </DialogFooter>
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
