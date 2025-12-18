import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
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
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { usePosSessionStore } from '@/store/posSessionStore'
import { cn } from '@/lib/utils'

interface Warehouse {
  id: number
  name: string
}

export function POSPage() {
  const { toast } = useToast()
  const navigate = useNavigate()

  const terminals = usePosSessionStore((s) => s.terminals)
  const activeTerminalId = usePosSessionStore((s) => s.activeTerminalId)
  const openedAt = usePosSessionStore((s) => s.openedAt)
  const addTerminal = usePosSessionStore((s) => s.addTerminal)
  const startSession = usePosSessionStore((s) => s.startSession)

  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [newTerminalName, setNewTerminalName] = useState('')
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<string>('')
  
  // Staff Selection State
  const [isStaffDialogOpen, setIsStaffDialogOpen] = useState(false)
  const [selectedStaffId, setSelectedStaffId] = useState<string>('')
  const [pendingTerminalId, setPendingTerminalId] = useState<string | null>(null)
  const [pendingWarehouseId, setPendingWarehouseId] = useState<string | null>(null)

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

  const handleCreateTerminal = () => {
    if (!newTerminalName.trim()) {
      toast({
        title: 'Name required',
        description: 'Please enter a terminal name.',
        variant: 'destructive',
      })
      return
    }

    const terminal = addTerminal(newTerminalName, selectedWarehouseId || undefined)
    setNewTerminalName('')
    setSelectedWarehouseId('')
    setIsDialogOpen(false)
    
    toast({
      title: 'Terminal created',
      description: `${terminal.name} is ready.`,
    })
  }

  const handleOpenTerminalClick = (terminalId: string, defaultWarehouseId?: string) => {
    // If already active, just go there
    if (activeTerminalId === terminalId) {
      navigate('/pos/terminal')
      return
    }

    // Otherwise open staff selection dialog
    setPendingTerminalId(terminalId)
    setPendingWarehouseId(defaultWarehouseId || (warehouses[0]?.id?.toString() ?? null))
    setSelectedStaffId('')
    setIsStaffDialogOpen(true)
  }

  const confirmOpenSession = () => {
    if (!pendingTerminalId || !selectedStaffId) return

    startSession({
      terminalId: pendingTerminalId,
      warehouseId: pendingWarehouseId,
      staffId: selectedStaffId,
    })

    setIsStaffDialogOpen(false)
    navigate('/pos/terminal')
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
          <Link to="/pos/orders">
            <Button variant="outline">
              <Icon icon="solar:document-text-bold" className="mr-2 h-4 w-4" />
              Orders
            </Button>
          </Link>
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
        {terminals.map((terminal) => {
          const isActive = activeTerminalId === terminal.id
          const warehouseName = warehouses.find(w => w.id.toString() === terminal.warehouseId)?.name || 'Default Warehouse'

          return (
            <Card 
              key={terminal.id} 
              className={cn(
                "cursor-pointer transition-all border-2",
                isActive 
                  ? "border-primary/50 bg-primary/5 shadow-sm" 
                  : "bg-card shadow-md hover:shadow-lg border-transparent hover:border-border"
              )}
              onClick={() => handleOpenTerminalClick(terminal.id, terminal.warehouseId)}
            >
              <CardHeader className="pb-2">
                <div className="flex justify-between items-start">
                  <div className="space-y-1">
                    <CardTitle className="text-xl">{terminal.name}</CardTitle>
                    <CardDescription>{warehouseName}</CardDescription>
                  </div>
                  <div className={cn(
                    "h-3 w-3 rounded-full ring-2 ring-offset-2",
                    isActive ? "bg-green-500 ring-green-200" : "bg-red-200 ring-transparent"
                  )} />
                </div>
              </CardHeader>
              <CardContent>
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Icon icon="solar:clock-circle-bold" className="w-4 h-4" />
                  {isActive ? (
                    <span className="text-green-600 font-medium">Active • {getActiveDuration()}</span>
                  ) : (
                    <span>Last seen: Recently</span>
                  )}
                </div>
              </CardContent>
              <CardFooter className="pt-0">
                <Button variant={isActive ? "default" : "secondary"} className="w-full">
                  {isActive ? 'Resume Session' : 'Open Terminal'}
                </Button>
              </CardFooter>
            </Card>
          )
        })}

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
                  Terminal
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
              <Button onClick={handleCreateTerminal}>
                Create new terminal
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
      
      <div className="text-center text-sm text-muted-foreground mt-8">
        {terminals.length} terminals configured
      </div>

      {/* Staff Selection Dialog */}
      <Dialog open={isStaffDialogOpen} onOpenChange={setIsStaffDialogOpen}>
        <DialogContent className="sm:max-w-[425px]">
          <DialogHeader>
            <DialogTitle>Open Session</DialogTitle>
            <DialogDescription>
              Select staff member to open this terminal session.
            </DialogDescription>
          </DialogHeader>
          
          <div className="grid gap-6 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium leading-none">
                Staff Member
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
            <Button onClick={confirmOpenSession} disabled={!selectedStaffId}>
              Open Session
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
