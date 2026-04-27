import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Separator } from '@/components/ui/separator'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, cn } from '@/lib/utils'
import api from '@/lib/api'

interface ApprovalRequest {
  id: number
  type: 'void' | 'refund' | 'return'
  source_type: string
  source_id: number
  amount: number
  reason: string
  status: 'pending' | 'approved' | 'rejected'
  requested_by: number
  reviewed_by?: number
  requested_at: string
  reviewed_at?: string
  expires_at: string
  metadata?: {
    order_number?: string
    items?: any[]
  }
  requester?: {
    id: number
    name: string
  }
}

export function ApprovalQueue() {
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [selectedRequest, setSelectedRequest] = useState<ApprovalRequest | null>(null)
  const [rejectDialogOpen, setRejectDialogOpen] = useState(false)
  const [rejectReason, setRejectReason] = useState('')

  // Fetch pending approvals
  const { data: approvals = [], isLoading } = useQuery({
    queryKey: ['approval-requests'],
    queryFn: async () => {
      const response = await api.get('/approvals', {
        params: { status: 'pending' },
      })
      return (response.data?.data || response.data || []) as ApprovalRequest[]
    },
    refetchInterval: 3000, // Poll every 3 seconds
  })

  // Approve mutation
  const approveMutation = useMutation({
    mutationFn: async (requestId: number) => {
      const response = await api.post(`/approvals/${requestId}/approve`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['approval-requests'] })
      toast({
        title: 'Request Approved',
        description: 'The operation has been approved successfully.',
      })
      setSelectedRequest(null)
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to approve request',
        variant: 'destructive',
      })
    },
  })

  // Reject mutation
  const rejectMutation = useMutation({
    mutationFn: async ({ requestId, reason }: { requestId: number; reason: string }) => {
      const response = await api.post(`/approvals/${requestId}/reject`, { reason })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['approval-requests'] })
      toast({
        title: 'Request Rejected',
        description: 'The operation has been rejected.',
      })
      setSelectedRequest(null)
      setRejectDialogOpen(false)
      setRejectReason('')
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to reject request',
        variant: 'destructive',
      })
    },
  })

  const handleApprove = (request: ApprovalRequest) => {
    setSelectedRequest(request)
    approveMutation.mutate(request.id)
  }

  const handleReject = (request: ApprovalRequest) => {
    setSelectedRequest(request)
    setRejectDialogOpen(true)
  }

  const confirmReject = () => {
    if (!selectedRequest) return
    if (!rejectReason.trim()) {
      toast({
        title: 'Reason Required',
        description: 'Please provide a reason for rejection.',
        variant: 'destructive',
      })
      return
    }

    rejectMutation.mutate({
      requestId: selectedRequest.id,
      reason: rejectReason.trim(),
    })
  }

  const getOperationIcon = (type: string) => {
    switch (type) {
      case 'void':
        return 'solar:danger-triangle-bold'
      case 'refund':
        return 'solar:cash-out-bold'
      case 'return':
        return 'solar:undo-left-bold'
      default:
        return 'solar:info-circle-bold'
    }
  }

  const getOperationColor = (type: string) => {
    switch (type) {
      case 'void':
        return 'text-red-600 bg-red-100'
      case 'refund':
        return 'text-blue-600 bg-blue-100'
      case 'return':
        return 'text-amber-600 bg-amber-100'
      default:
        return 'text-slate-600 bg-slate-100'
    }
  }

  const getTimeAgo = (dateString: string) => {
    const date = new Date(dateString)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / 60000)

    if (diffMins < 1) return 'Just now'
    if (diffMins < 60) return `${diffMins}m ago`
    const diffHours = Math.floor(diffMins / 60)
    if (diffHours < 24) return `${diffHours}h ago`
    const diffDays = Math.floor(diffHours / 24)
    return `${diffDays}d ago`
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Icon icon="solar:refresh-bold" className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (approvals.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-12">
          <Icon icon="solar:check-circle-bold" className="h-16 w-16 text-green-500 mb-4" />
          <h3 className="text-lg font-semibold">All Clear!</h3>
          <p className="text-sm text-muted-foreground mt-1">No pending approval requests</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Approval Queue</h2>
          <p className="text-sm text-muted-foreground">
            {approvals.length} pending request{approvals.length !== 1 ? 's' : ''}
          </p>
        </div>
        <Badge variant="secondary" className="text-lg px-4 py-2">
          {approvals.length}
        </Badge>
      </div>

      <ScrollArea className="h-[600px]">
        <div className="space-y-3">
          {approvals.map((request) => (
            <Card key={request.id} className="border-2 border-amber-200 bg-amber-50/30">
              <CardHeader className="pb-3">
                <div className="flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    <div
                      className={cn(
                        'flex h-10 w-10 items-center justify-center rounded-full',
                        getOperationColor(request.type)
                      )}
                    >
                      <Icon icon={getOperationIcon(request.type)} className="h-5 w-5" />
                    </div>
                    <div>
                      <CardTitle className="text-base">
                        {request.type.charAt(0).toUpperCase() + request.type.slice(1)} Request
                      </CardTitle>
                      <p className="text-sm text-muted-foreground">
                        Order #{request.metadata?.order_number || request.source_id}
                      </p>
                    </div>
                  </div>
                  <Badge variant="outline" className="text-xs">
                    {getTimeAgo(request.requested_at)}
                  </Badge>
                </div>
              </CardHeader>

              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-xs text-muted-foreground">Amount</p>
                    <p className="text-lg font-semibold">{formatCurrency(request.amount)}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted-foreground">Requested By</p>
                    <p className="text-sm font-medium">
                      {request.requester?.name || `User #${request.requested_by}`}
                    </p>
                  </div>
                </div>

                <Separator />

                <div>
                  <p className="text-xs text-muted-foreground mb-1">Reason</p>
                  <p className="text-sm">{request.reason}</p>
                </div>

                {request.metadata?.items && request.metadata.items.length > 0 && (
                  <>
                    <Separator />
                    <div>
                      <p className="text-xs text-muted-foreground mb-2">Items</p>
                      <div className="space-y-1">
                        {request.metadata.items.map((item: any, idx: number) => (
                          <div key={idx} className="flex justify-between text-sm">
                            <span>
                              {item.quantity}x {item.name || 'Item'}
                            </span>
                            <span className="font-medium">
                              {formatCurrency(item.amount || 0)}
                            </span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </>
                )}

                <div className="flex gap-2 pt-2">
                  <Button
                    className="flex-1"
                    onClick={() => handleApprove(request)}
                    disabled={approveMutation.isPending || rejectMutation.isPending}
                  >
                    <Icon icon="solar:check-circle-bold" className="mr-2 h-4 w-4" />
                    Approve
                  </Button>
                  <Button
                    variant="outline"
                    className="flex-1"
                    onClick={() => handleReject(request)}
                    disabled={approveMutation.isPending || rejectMutation.isPending}
                  >
                    <Icon icon="solar:close-circle-bold" className="mr-2 h-4 w-4" />
                    Reject
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      </ScrollArea>

      {/* Reject Dialog */}
      <Dialog open={rejectDialogOpen} onOpenChange={setRejectDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Reject Request</DialogTitle>
            <DialogDescription>
              Please provide a reason for rejecting this request.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="reject-reason">Rejection Reason *</Label>
              <Textarea
                id="reject-reason"
                placeholder="Enter reason for rejection..."
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                rows={4}
                className="resize-none"
              />
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setRejectDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={confirmReject}
              disabled={rejectMutation.isPending || !rejectReason.trim()}
            >
              {rejectMutation.isPending && (
                <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
              )}
              Reject Request
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
