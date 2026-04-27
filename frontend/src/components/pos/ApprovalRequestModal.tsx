import { useState, useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency } from '@/lib/utils'
import api from '@/lib/api'

interface ApprovalRequestModalProps {
  open: boolean
  onClose: () => void
  operation: 'void' | 'refund' | 'return'
  amount: number
  orderId: number
  orderNumber: string
  onApproved: (approvalRequestId: number) => void
  onRejected: (reason: string) => void
}

interface ApprovalRequest {
  id: number
  type: string
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
}

export function ApprovalRequestModal({
  open,
  onClose,
  operation,
  amount,
  orderId,
  orderNumber,
  onApproved,
  onRejected,
}: ApprovalRequestModalProps) {
  const { toast } = useToast()
  const [reason, setReason] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [approvalRequestId, setApprovalRequestId] = useState<number | null>(null)
  const [pollingEnabled, setPollingEnabled] = useState(false)

  // Reset state when dialog opens/closes
  useEffect(() => {
    if (open) {
      setReason('')
      setApprovalRequestId(null)
      setPollingEnabled(false)
    }
  }, [open])

  // Poll for approval status
  const { data: approvalStatus } = useQuery({
    queryKey: ['approval-status', approvalRequestId],
    queryFn: async () => {
      if (!approvalRequestId) return null
      const response = await api.get(`/approvals/${approvalRequestId}`)
      return response.data as ApprovalRequest
    },
    enabled: pollingEnabled && !!approvalRequestId,
    refetchInterval: 2000, // Poll every 2 seconds
  })

  // Handle approval status changes
  useEffect(() => {
    if (!approvalStatus) return

    if (approvalStatus.status === 'approved') {
      setPollingEnabled(false)
      toast({
        title: 'Approval Granted',
        description: 'Your request has been approved by a supervisor.',
      })
      onApproved(approvalStatus.id)
    } else if (approvalStatus.status === 'rejected') {
      setPollingEnabled(false)
      toast({
        title: 'Request Rejected',
        description: 'Your request was rejected by a supervisor.',
        variant: 'destructive',
      })
      onRejected('Request rejected by supervisor')
    }
  }, [approvalStatus, onApproved, onRejected, toast])

  const handleSubmit = async () => {
    if (!reason.trim()) {
      toast({
        title: 'Reason Required',
        description: 'Please provide a reason for this operation.',
        variant: 'destructive',
      })
      return
    }

    setIsSubmitting(true)

    try {
      const response = await api.post('/approvals', {
        request_type: operation,
        subject_type: 'PosOrder',
        subject_id: orderId,
        amount,
        reason: reason.trim(),
      })

      const approvalRequest = response.data as ApprovalRequest

      setApprovalRequestId(approvalRequest.id)
      setPollingEnabled(true)

      toast({
        title: 'Approval Requested',
        description: 'Waiting for supervisor approval...',
      })
    } catch (error: any) {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create approval request',
        variant: 'destructive',
      })
      setIsSubmitting(false)
    }
  }

  const handleCancel = () => {
    setPollingEnabled(false)
    setApprovalRequestId(null)
    onClose()
  }

  const getOperationLabel = () => {
    switch (operation) {
      case 'void':
        return 'Void Order'
      case 'refund':
        return 'Refund Order'
      case 'return':
        return 'Return Items'
      default:
        return 'Operation'
    }
  }

  const getOperationIcon = () => {
    switch (operation) {
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

  const isWaitingForApproval = pollingEnabled && approvalRequestId

  return (
    <Dialog open={open} onOpenChange={handleCancel}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-3">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-amber-100">
              <Icon icon={getOperationIcon()} className="h-6 w-6 text-amber-600" />
            </div>
            <div>
              <DialogTitle>{getOperationLabel()}</DialogTitle>
              <DialogDescription>Order #{orderNumber}</DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {isWaitingForApproval ? (
          <div className="space-y-4 py-6">
            <div className="flex flex-col items-center justify-center space-y-4">
              <div className="relative">
                <Icon
                  icon="solar:shield-check-bold"
                  className="h-16 w-16 text-amber-500 animate-pulse"
                />
              </div>
              <div className="text-center">
                <h3 className="text-lg font-semibold">Waiting for Approval</h3>
                <p className="text-sm text-muted-foreground mt-1">
                  A supervisor needs to approve this request
                </p>
              </div>
              <Badge variant="outline" className="text-xs">
                Request ID: {approvalRequestId}
              </Badge>
            </div>

            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
              <div className="flex items-start gap-3">
                <Icon icon="solar:info-circle-bold" className="h-5 w-5 text-amber-600 mt-0.5" />
                <div className="flex-1 text-sm text-amber-900">
                  <p className="font-medium">Approval Required</p>
                  <p className="mt-1 text-amber-700">
                    This operation requires supervisor approval. Please ask a supervisor to check
                    the approval queue.
                  </p>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="space-y-4 py-4">
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-slate-700">Amount</span>
                <span className="text-lg font-semibold text-slate-900">
                  {formatCurrency(amount)}
                </span>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="reason">Reason *</Label>
              <Textarea
                id="reason"
                placeholder="Enter reason for this operation..."
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={4}
                className="resize-none"
              />
              <p className="text-xs text-muted-foreground">
                Minimum 10 characters required for audit trail
              </p>
            </div>

            <div className="rounded-lg border border-blue-200 bg-blue-50 p-3">
              <div className="flex items-start gap-2">
                <Icon icon="solar:shield-check-bold" className="h-4 w-4 text-blue-600 mt-0.5" />
                <p className="text-xs text-blue-900">
                  No password required. This request will be sent to a supervisor for approval.
                </p>
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          {isWaitingForApproval ? (
            <Button variant="outline" onClick={handleCancel} className="w-full">
              Cancel Request
            </Button>
          ) : (
            <>
              <Button variant="outline" onClick={handleCancel}>
                Cancel
              </Button>
              <Button
                onClick={handleSubmit}
                disabled={isSubmitting || reason.trim().length < 10}
              >
                {isSubmitting && (
                  <Icon icon="solar:refresh-bold" className="mr-2 h-4 w-4 animate-spin" />
                )}
                Request Approval
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
