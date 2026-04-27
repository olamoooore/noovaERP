import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Loader2, RefreshCw, AlertCircle, CheckCircle, Clock, XCircle } from 'lucide-react'
import { useToast } from '@/hooks/use-toast'
import { api } from '@/lib/api'

interface QueueItem {
  id: number
  operation: string
  payload: Record<string, any>
  status: 'pending' | 'syncing' | 'synced' | 'failed' | 'conflict'
  attempts: number
  error_message?: string
  created_at: string
  synced_at?: string
}

interface QueueStats {
  pending: number
  synced: number
  failed: number
  conflict: number
  total: number
}

export function POSOfflineQueuePage() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [autoRefresh, setAutoRefresh] = useState(true)

  // Fetch queue items
  const { data: queueItems = [], isLoading: isLoadingQueue } = useQuery<QueueItem[]>({
    queryKey: ['pos-offline-queue'],
    queryFn: async () => {
      const response = await api.get('/pos/sync/pending')
      return response.data
    },
    refetchInterval: autoRefresh ? 5000 : false,
  })

  // Fetch stats
  const { data: stats } = useQuery<QueueStats>({
    queryKey: ['pos-offline-stats'],
    queryFn: async () => {
      const response = await api.get('/pos/sync/stats')
      return response.data
    },
    refetchInterval: autoRefresh ? 5000 : false,
  })

  // Retry mutation
  const retryMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.post(`/pos/sync/${id}/retry`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-offline-queue'] })
      queryClient.invalidateQueries({ queryKey: ['pos-offline-stats'] })
      toast({
        title: 'Retry initiated',
        description: 'The operation will be retried shortly.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Retry failed',
        description: error.response?.data?.message || 'Failed to retry operation',
        variant: 'destructive',
      })
    },
  })

  // Sync all mutation
  const syncAllMutation = useMutation({
    mutationFn: async () => {
      await api.post('/pos/sync/all')
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-offline-queue'] })
      queryClient.invalidateQueries({ queryKey: ['pos-offline-stats'] })
      toast({
        title: 'Sync initiated',
        description: 'All pending operations will be synced.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Sync failed',
        description: error.response?.data?.message || 'Failed to sync operations',
        variant: 'destructive',
      })
    },
  })

  // Resolve conflict mutation
  const resolveConflictMutation = useMutation({
    mutationFn: async ({ id, resolution }: { id: number; resolution: 'skip' | 'retry' }) => {
      await api.post(`/pos/sync/${id}/resolve`, { resolution })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pos-offline-queue'] })
      queryClient.invalidateQueries({ queryKey: ['pos-offline-stats'] })
      toast({
        title: 'Conflict resolved',
        description: 'The conflict has been resolved.',
      })
    },
    onError: (error: any) => {
      toast({
        title: 'Resolution failed',
        description: error.response?.data?.message || 'Failed to resolve conflict',
        variant: 'destructive',
      })
    },
  })

  const getStatusBadge = (status: QueueItem['status']) => {
    switch (status) {
      case 'pending':
        return <Badge variant="secondary"><Clock className="w-3 h-3 mr-1" />Pending</Badge>
      case 'syncing':
        return <Badge variant="default"><Loader2 className="w-3 h-3 mr-1 animate-spin" />Syncing</Badge>
      case 'synced':
        return <Badge variant="default" className="bg-green-500"><CheckCircle className="w-3 h-3 mr-1" />Synced</Badge>
      case 'failed':
        return <Badge variant="destructive"><XCircle className="w-3 h-3 mr-1" />Failed</Badge>
      case 'conflict':
        return <Badge variant="destructive"><AlertCircle className="w-3 h-3 mr-1" />Conflict</Badge>
      default:
        return <Badge>{status}</Badge>
    }
  }

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleString()
  }

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Offline Queue</h1>
          <p className="text-muted-foreground">Manage offline operations and sync status</p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => setAutoRefresh(!autoRefresh)}
          >
            {autoRefresh ? 'Disable' : 'Enable'} Auto-refresh
          </Button>
          <Button
            onClick={() => {
              queryClient.invalidateQueries({ queryKey: ['pos-offline-queue'] })
              queryClient.invalidateQueries({ queryKey: ['pos-offline-stats'] })
            }}
            variant="outline"
          >
            <RefreshCw className="w-4 h-4 mr-2" />
            Refresh
          </Button>
          <Button
            onClick={() => syncAllMutation.mutate()}
            disabled={syncAllMutation.isPending || !stats?.pending}
          >
            {syncAllMutation.isPending && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Sync All
          </Button>
        </div>
      </div>

      {/* Stats Cards */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Total</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Pending</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats.pending}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Synced</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats.synced}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Failed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats.failed}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Conflicts</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-orange-600">{stats.conflict}</div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Queue Items */}
      <Card>
        <CardHeader>
          <CardTitle>Queue Items</CardTitle>
          <CardDescription>
            {autoRefresh && 'Auto-refreshing every 5 seconds'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoadingQueue ? (
            <div className="flex justify-center items-center py-8">
              <Loader2 className="w-8 h-8 animate-spin" />
            </div>
          ) : queueItems.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              No items in queue
            </div>
          ) : (
            <div className="space-y-4">
              {queueItems.map((item) => (
                <Card key={item.id}>
                  <CardContent className="pt-6">
                    <div className="flex justify-between items-start">
                      <div className="space-y-2 flex-1">
                        <div className="flex items-center gap-2">
                          <span className="font-semibold">{item.operation}</span>
                          {getStatusBadge(item.status)}
                        </div>
                        <div className="text-sm text-muted-foreground">
                          <div>Created: {formatDate(item.created_at)}</div>
                          {item.synced_at && <div>Synced: {formatDate(item.synced_at)}</div>}
                          <div>Attempts: {item.attempts}</div>
                        </div>
                        {item.error_message && (
                          <div className="text-sm text-red-600 bg-red-50 p-2 rounded">
                            {item.error_message}
                          </div>
                        )}
                        <details className="text-sm">
                          <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
                            View payload
                          </summary>
                          <pre className="mt-2 p-2 bg-muted rounded text-xs overflow-auto">
                            {JSON.stringify(item.payload, null, 2)}
                          </pre>
                        </details>
                      </div>
                      <div className="flex gap-2 ml-4">
                        {(item.status === 'failed' || item.status === 'pending') && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => retryMutation.mutate(item.id)}
                            disabled={retryMutation.isPending}
                          >
                            {retryMutation.isPending ? (
                              <Loader2 className="w-4 h-4 animate-spin" />
                            ) : (
                              <RefreshCw className="w-4 h-4" />
                            )}
                          </Button>
                        )}
                        {item.status === 'conflict' && (
                          <>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => resolveConflictMutation.mutate({ id: item.id, resolution: 'retry' })}
                              disabled={resolveConflictMutation.isPending}
                            >
                              Retry
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => resolveConflictMutation.mutate({ id: item.id, resolution: 'skip' })}
                              disabled={resolveConflictMutation.isPending}
                            >
                              Skip
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
