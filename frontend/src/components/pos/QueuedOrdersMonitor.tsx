import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, formatDateTime, cn } from '@/lib/utils'
import api from '@/lib/api'

interface QueuedOperation {
  id: number
  terminal_id: number
  session_id?: number
  operation_type: 'create_order' | 'payment' | 'void' | 'refund'
  operation_data: any
  status: 'pending' | 'syncing' | 'synced' | 'failed'
  external_id?: string
  sync_attempts: number
  last_sync_attempt_at?: string
  synced_at?: string
  error_message?: string
  created_at: string
}

export function QueuedOrdersMonitor() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [selectedOperation, setSelectedOperation] = useState<QueuedOperation | null>(null)
  const [detailsDialogOpen, setDetailsDialogOpen] = useState(false)

  // Fetch pending operations
  const { data: operations = [], isLoading } = useQuery({
    queryKey: ['pos-queued-operations'],
    queryFn: async () => {
      const response = await api.get('/pos/sync/pending')
      return (response.data?.data || response.data || []) as QueuedOperation[]
    },
    refetchInterval: 5000, // Poll every 5 seconds
  })

  // Retry sync mutation
  const retrySyncMutation = useMutation({
    mutationFn: async (operationId: number) => {
      const response = await api.post(`/pos/sync/retry/${operationId}`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-queued-operations'] })
      toast({
        title: 'Sync Retry Initiated',
        description: 'The operation will be retried shortly.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Retry Failed',
        description: error.response?.data?.message || 'Failed to retry sync',
        variant: 'destructive',
      })
    },
  })

  const getOperationIcon = (type: string) => {
    switch (type) {
      case 'create_order':
        return 'solar:cart-plus-bold'
      case 'payment':
        return 'solar:wallet-money-bold'
      case 'void':
        return 'solar:danger-triangle-bold'
      case 'refund':
        return 'solar:cash-out-bold'
      default:
        return 'solar:document-bold'
    }
  }

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending':
        return 'bg-slate-100 text-slate-700 border-slate-200'
      case 'syncing':
        return 'bg-blue-100 text-blue-700 border-blue-200'
      case 'synced':
        return 'bg-green-100 text-green-700 border-green-200'
      case 'failed':
        return 'bg-red-100 text-red-700 border-red-200'
      default:
        return 'bg-slate-100 text-slate-700 border-slate-200'
    }
  }

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'pending':
        return 'solar:clock-circle-bold'
      case 'syncing':
        return 'solar:refresh-bold'
      case 'synced':
        return 'solar:check-circle-bold'
      case 'failed':
        return 'solar:close-circle-bold'
      default:
        return 'solar:info-circle-bold'
    }
  }

  const handleViewDetails = (operation: QueuedOperation) => {
    setSelectedOperation(operation)
    setDetailsDialogOpen(true)
  }

  const handleRetry = (operation: QueuedOperation) => {
    retrySyncMutation.mutate(operation.id)
  }

  const pendingCount = operations.filter((op) => op.status === 'pending').length
  const failedCount = operations.filter((op) => op.status === 'failed').length
  const syncedCount = operations.filter((op) => op.status === 'synced').length

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (operations.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-12">
          <Icon icon="solar:check-circle-bold" className="h-16 w-16 text-green-500 mb-4" />
          <h3 className="text-lg font-semibold">All Synced!</h3>
          <p className="text-sm text-muted-foreground mt-1">No queued operations</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Offline Queue</h2>
          <p className="text-sm text-muted-foreground">
            {operations.length} operation{operations.length !== 1 ? 's' : ''} in queue
          </p>
        </div>
        <div className="flex gap-2">
          {pendingCount > 0 && (
            <Badge variant="secondary" className="bg-slate-100">
              {pendingCount} Pending
            </Badge>
          )}
          {failedCount > 0 && (
            <Badge variant="destructive">{failedCount} Failed</Badge>
          )}
          {syncedCount > 0 && (
            <Badge className="bg-green-100 text-green-700 hover:bg-green-100">
              {syncedCount} Synced
            </Badge>
          )}
        </div>
      </div>

      <ScrollArea className="h-[600px]">
        <div className="space-y-3">
          {operations.map((operation) => (
            <Card
              key={operation.id}
              className={cn(
                'border-2',
                operation.status === 'failed' && 'border-red-200 bg-red-50/30',
                operation.status === 'synced' && 'border-green-200 bg-green-50/30',
                operation.status === 'syncing' && 'border-blue-200 bg-blue-50/30'
              )}
            >
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100">
                      <Icon icon={getOperationIcon(operation.operation_type)} className="h-5 w-5" />
                    </div>
                    <div>
                      <CardTitle className="text-base">
                        {operation.operation_type.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())}
                      </CardTitle>
                      <p className="text-xs text-muted-foreground">
                        {formatDateTime(operation.created_at)}
                      </p>
                    </div>
                  </div>
                  <Badge variant="outline" className={cn('text-xs', getStatusColor(operation.status))}>
                    <Icon icon={getStatusIcon(operation.status)} className="mr-1 h-3 w-3" />
                    {operation.status.charAt(0).toUpperCase() + operation.status.slice(1)}
                  </Badge>
                </div>
              </CardHeader>

              <CardContent className="space-y-3">
                {operation.external_id && (
                  <div className="text-xs">
                    <span className="text-muted-foreground">ID:</span>{' '}
                    <code className="rounded bg-slate-100 px-1 py-0.5 font-mono">
                      {operation.external_id}
                    </code>
                  </div>
                )}

                {operation.sync_attempts > 0 && (
                  <div className="text-xs text-muted-foreground">
                    Sync attempts: {operation.sync_attempts}
                  </div>
                )}

                {operation.error_message && (
                  <div className="rounded-lg border border-red-200 bg-red-50 p-2">
                    <p className="text-xs text-red-900">
                      <Icon icon="solar:danger-triangle-bold" className="mr-1 inline h-3 w-3" />
                      {operation.error_message}
                    </p>
                  </div>
                )}

                <div className="flex gap-2 pt-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1"
                    onClick={() => handleViewDetails(operation)}
                  >
                    <Icon icon="solar:eye-bold" className="mr-2 h-4 w-4" />
                    Details
                  </Button>
                  {operation.status === 'failed' && (
                    <Button
                      size="sm"
                      className="flex-1"
                      onClick={() => handleRetry(operation)}
                      disabled={retrySyncMutation.isPending}
                    >
                      <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
                      Retry
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </ScrollArea>

      {/* Details Dialog */}
      <Dialog open={detailsDialogOpen} onOpenChange={setDetailsDialogOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Operation Details</DialogTitle>
            <DialogDescription>
              {selectedOperation?.operation_type.replace(/_/g, ' ')}
            </DialogDescription>
          </DialogHeader>

          {selectedOperation && (
            <div className="space-y-4 py-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground">Status</p>
                  <Badge className={cn('mt-1', getStatusColor(selectedOperation.status))}>
                    {selectedOperation.status}
                  </Badge>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Created</p>
                  <p className="text-sm font-medium mt-1">
                    {formatDateTime(selectedOperation.created_at)}
                  </p>
                </div>
              </div>

              {selectedOperation.external_id && (
                <div>
                  <p className="text-xs text-muted-foreground">External ID</p>
                  <code className="mt-1 block rounded bg-slate-100 px-2 py-1 text-xs font-mono">
                    {selectedOperation.external_id}
                  </code>
                </div>
              )}

              <Separator />

              <div>
                <p className="text-xs text-muted-foreground mb-2">Operation Data</p>
                <ScrollArea className="h-64 rounded-lg border bg-slate-50 p-3">
                  <pre className="text-xs font-mono">
                    {JSON.stringify(selectedOperation.operation_data, null, 2)}
                  </pre>
                </ScrollArea>
              </div>

              {selectedOperation.error_message && (
                <>
                  <Separator />
                  <div>
                    <p className="text-xs text-muted-foreground mb-2">Error Message</p>
                    <div className="rounded-lg border border-red-200 bg-red-50 p-3">
                      <p className="text-sm text-red-900">{selectedOperation.error_message}</p>
                    </div>
                  </div>
                </>
              )}
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" onClick={() => setDetailsDialogOpen(false)}>
              Close
            </Button>
            {selectedOperation?.status === 'failed' && (
              <Button
                onClick={() => {
                  if (selectedOperation) {
                    handleRetry(selectedOperation)
                    setDetailsDialogOpen(false)
                  }
                }}
                disabled={retrySyncMutation.isPending}
              >
                <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4" />
                Retry Sync
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
