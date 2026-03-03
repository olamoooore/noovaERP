/**
 * Consolidated Bid Flow Components
 * 
 * Unified components for all bid flow operations using the BidFlowManager.
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import React, { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Loader2, CheckCircle, XCircle, AlertCircle, DollarSign } from 'lucide-react'
import { toast } from '@/hooks/use-toast'

// Mock types for bidding functionality
interface BidFlowResult<T = any> {
  success: boolean
  data?: T
  error?: string
}

interface BidData {
  id: number
  eventId: number
  customerId: number
  bidAmount: number
  quantity: number
  status: 'pending' | 'accepted' | 'rejected'
  customer?: { name: string }
  bid_amount: number
  processed_at?: string
  processedBy?: { name: string }
  admin_notes?: string
  rejection_reason?: string
}

interface BidAvailabilityCheck {
  can_bid: boolean
  reason?: string
}

interface BidSubmissionData {
  eventId: number
  customerId: number
  bidAmount: number
  quantity: number
}

// Mock bid flow manager
const bidFlowManager = {
  async checkBidAvailability(_eventId: number): Promise<BidFlowResult<BidAvailabilityCheck>> {
    return { success: true, data: { can_bid: true } }
  },
  async submitBidFlow(data: BidSubmissionData): Promise<BidFlowResult<BidData>> {
    return { 
      success: true, 
      data: { 
        id: 1, 
        eventId: data.eventId, 
        customerId: data.customerId, 
        bidAmount: data.bidAmount, 
        quantity: data.quantity, 
        status: 'pending' as const,
        bid_amount: data.bidAmount
      } 
    }
  },
  async quickAcceptBid(bidId: number, _notes?: string): Promise<BidFlowResult<BidData>> {
    return { 
      success: true, 
      data: { 
        id: bidId, 
        eventId: 1, 
        customerId: 1, 
        bidAmount: 100, 
        quantity: 1, 
        status: 'accepted' as const,
        bid_amount: 100,
        admin_notes: _notes
      } 
    }
  },
  async quickRejectBid(bidId: number, reason: string, _notes?: string): Promise<BidFlowResult<BidData>> {
    return { 
      success: true, 
      data: { 
        id: bidId, 
        eventId: 1, 
        customerId: 1, 
        bidAmount: 100, 
        quantity: 1, 
        status: 'rejected' as const,
        bid_amount: 100,
        rejection_reason: reason,
        admin_notes: _notes
      } 
    }
  },
  async acceptAllPendingBids(_eventId: number, _notes?: string): Promise<BidFlowResult<{ summary: { accepted: number; failed: number } }>> {
    return { 
      success: true, 
      data: { 
        summary: { accepted: 5, failed: 0 } 
      } 
    }
  },
  async getBidStatistics(_eventId?: number): Promise<BidFlowResult<any>> {
    return { 
      success: true, 
      data: { 
        totalBids: 10, 
        pendingBids: 3, 
        acceptedBids: 5, 
        rejectedBids: 2, 
        averageBidAmount: 150, 
        acceptanceRate: 70 
      } 
    }
  }
}

// Bid Submission Component
interface BidSubmissionProps {
  eventId: number
  customerId: number
  eventName: string
  onBidSubmitted?: (bid: BidData) => void
}

export function BidSubmissionForm({ eventId, customerId, eventName, onBidSubmitted }: BidSubmissionProps) {
  const [bidAmount, setBidAmount] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [availability, setAvailability] = useState<BidAvailabilityCheck | null>(null)
  const [isCheckingAvailability, setIsCheckingAvailability] = useState(true)

  useEffect(() => {
    checkBidAvailability()
  }, [eventId])

  const checkBidAvailability = async () => {
    setIsCheckingAvailability(true)
    const result = await bidFlowManager.checkBidAvailability(eventId)
    
    if (result.success && result.data) {
      setAvailability(result.data)
    } else {
      toast({
        title: "Error",
        description: "Failed to check bid availability",
        variant: "destructive",
      })
    }
    setIsCheckingAvailability(false)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    
    if (!availability?.can_bid) {
      toast({
        title: "Bidding Not Available",
        description: availability?.reason || "Bidding is not currently available",
        variant: "destructive",
      })
      return
    }

    setIsSubmitting(true)

    const result = await bidFlowManager.submitBidFlow({
      eventId,
      customerId,
      bidAmount: parseFloat(bidAmount),
      quantity: parseInt(quantity)
    })

    if (result.success && result.data) {
      toast({
        title: "Bid Submitted",
        description: `Your bid of $${bidAmount} has been submitted successfully`,
      })
      setBidAmount('')
      setQuantity('1')
      onBidSubmitted?.(result.data)
    } else {
      toast({
        title: "Bid Submission Failed",
        description: result.error || "Failed to submit bid",
        variant: "destructive",
      })
    }

    setIsSubmitting(false)
  }

  if (isCheckingAvailability) {
    return (
      <Card>
        <CardContent className="flex items-center justify-center p-6">
          <Loader2 className="h-6 w-6 animate-spin mr-2" />
          <span>Checking bid availability...</span>
        </CardContent>
      </Card>
    )
  }

  if (!availability?.can_bid) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center">
            <XCircle className="h-5 w-5 mr-2 text-red-500" />
            Bidding Not Available
          </CardTitle>
        </CardHeader>
        <CardContent>
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              {availability?.reason || "Bidding is not currently available for this event"}
            </AlertDescription>
          </Alert>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center">
          <DollarSign className="h-5 w-5 mr-2" />
          Submit Bid for {eventName}
        </CardTitle>
        <CardDescription>
          Enter your bid amount and quantity below
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="bidAmount">Bid Amount ($)</Label>
              <Input
                id="bidAmount"
                type="number"
                step="0.01"
                min="0.01"
                value={bidAmount}
                onChange={(e) => setBidAmount(e.target.value)}
                placeholder="Enter bid amount"
                required
                disabled={isSubmitting}
              />
            </div>
            <div>
              <Label htmlFor="quantity">Quantity</Label>
              <Input
                id="quantity"
                type="number"
                min="1"
                max="10"
                value={quantity}
                onChange={(e) => setQuantity(e.target.value)}
                required
                disabled={isSubmitting}
              />
            </div>
          </div>
          
          <Button type="submit" disabled={isSubmitting || !bidAmount} className="w-full">
            {isSubmitting ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin mr-2" />
                Submitting Bid...
              </>
            ) : (
              'Submit Bid'
            )}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

// Bid Management Component
interface BidManagementProps {
  bid: BidData
  onBidProcessed?: (bid: BidData) => void
}

export function BidManagementCard({ bid, onBidProcessed }: BidManagementProps) {
  const [isProcessing, setIsProcessing] = useState(false)
  const [showRejectForm, setShowRejectForm] = useState(false)
  const [rejectReason, setRejectReason] = useState('')
  const [adminNotes, setAdminNotes] = useState('')

  const handleAccept = async () => {
    setIsProcessing(true)
    
    const result = await bidFlowManager.quickAcceptBid(bid.id, adminNotes || undefined)
    
    if (result.success && result.data) {
      toast({
        title: "Bid Accepted",
        description: `Bid #${bid.id} has been accepted`,
      })
      onBidProcessed?.(result.data)
    } else {
      toast({
        title: "Accept Failed",
        description: result.error || "Failed to accept bid",
        variant: "destructive",
      })
    }
    
    setIsProcessing(false)
  }

  const handleReject = async () => {
    if (!rejectReason.trim()) {
      toast({
        title: "Reason Required",
        description: "Please provide a reason for rejecting this bid",
        variant: "destructive",
      })
      return
    }

    setIsProcessing(true)
    
    const result = await bidFlowManager.quickRejectBid(
      bid.id, 
      rejectReason, 
      adminNotes || undefined
    )
    
    if (result.success && result.data) {
      toast({
        title: "Bid Rejected",
        description: `Bid #${bid.id} has been rejected`,
      })
      onBidProcessed?.(result.data)
      setShowRejectForm(false)
      setRejectReason('')
      setAdminNotes('')
    } else {
      toast({
        title: "Reject Failed",
        description: result.error || "Failed to reject bid",
        variant: "destructive",
      })
    }
    
    setIsProcessing(false)
  }

  const getStatusBadge = (status: string) => {
    switch (status) {
      case 'pending':
        return <Badge variant="outline" className="text-yellow-600">Pending</Badge>
      case 'accepted':
        return <Badge variant="outline" className="text-green-600">Accepted</Badge>
      case 'rejected':
        return <Badge variant="outline" className="text-red-600">Rejected</Badge>
      default:
        return <Badge variant="outline">{status}</Badge>
    }
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex justify-between items-start">
          <div>
            <CardTitle className="text-lg">Bid #{bid.id}</CardTitle>
            <CardDescription>
              {bid.customer?.name} • ${bid.bid_amount} • Qty: {bid.quantity}
            </CardDescription>
          </div>
          {getStatusBadge(bid.status)}
        </div>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          {bid.status === 'pending' && (
            <>
              <div>
                <Label htmlFor="adminNotes">Admin Notes (Optional)</Label>
                <Textarea
                  id="adminNotes"
                  value={adminNotes}
                  onChange={(e) => setAdminNotes(e.target.value)}
                  placeholder="Add any notes about this bid..."
                  disabled={isProcessing}
                />
              </div>

              {!showRejectForm ? (
                <div className="flex gap-2">
                  <Button
                    onClick={handleAccept}
                    disabled={isProcessing}
                    className="bg-green-600 hover:bg-green-700"
                  >
                    {isProcessing ? (
                      <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    ) : (
                      <CheckCircle className="h-4 w-4 mr-2" />
                    )}
                    Accept
                  </Button>
                  <Button
                    onClick={() => setShowRejectForm(true)}
                    disabled={isProcessing}
                    variant="destructive"
                  >
                    <XCircle className="h-4 w-4 mr-2" />
                    Reject
                  </Button>
                </div>
              ) : (
                <div className="space-y-3">
                  <div>
                    <Label htmlFor="rejectReason">Rejection Reason *</Label>
                    <Textarea
                      id="rejectReason"
                      value={rejectReason}
                      onChange={(e) => setRejectReason(e.target.value)}
                      placeholder="Please provide a reason for rejecting this bid..."
                      required
                      disabled={isProcessing}
                    />
                  </div>
                  <div className="flex gap-2">
                    <Button
                      onClick={handleReject}
                      disabled={isProcessing || !rejectReason.trim()}
                      variant="destructive"
                    >
                      {isProcessing ? (
                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      ) : (
                        <XCircle className="h-4 w-4 mr-2" />
                      )}
                      Confirm Reject
                    </Button>
                    <Button
                      onClick={() => {
                        setShowRejectForm(false)
                        setRejectReason('')
                      }}
                      disabled={isProcessing}
                      variant="outline"
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}

          {bid.status !== 'pending' && (
            <div className="text-sm text-gray-600">
              {bid.processed_at && (
                <p>Processed: {new Date(bid.processed_at).toLocaleString()}</p>
              )}
              {bid.processedBy && (
                <p>By: {bid.processedBy.name}</p>
              )}
              {bid.admin_notes && (
                <p>Notes: {bid.admin_notes}</p>
              )}
              {bid.rejection_reason && (
                <p>Reason: {bid.rejection_reason}</p>
              )}
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  )
}

// Bulk Bid Processing Component
interface BulkBidProcessingProps {
  eventId: number
  onBulkProcessed?: () => void
}

export function BulkBidProcessing({ eventId, onBulkProcessed }: BulkBidProcessingProps) {
  const [isProcessing, setIsProcessing] = useState(false)
  const [adminNotes, setAdminNotes] = useState('')

  const handleAcceptAllPending = async () => {
    setIsProcessing(true)
    
    const result = await bidFlowManager.acceptAllPendingBids(eventId, adminNotes || undefined)
    
    if (result.success && result.data) {
      const { summary } = result.data
      toast({
        title: "Bulk Processing Complete",
        description: `Accepted ${summary.accepted} bids, ${summary.failed} failed`,
      })
      onBulkProcessed?.()
      setAdminNotes('')
    } else {
      toast({
        title: "Bulk Processing Failed",
        description: result.error || "Failed to process bids",
        variant: "destructive",
      })
    }
    
    setIsProcessing(false)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Bulk Bid Processing</CardTitle>
        <CardDescription>
          Process multiple bids at once for this event
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="space-y-4">
          <div>
            <Label htmlFor="bulkAdminNotes">Admin Notes (Optional)</Label>
            <Textarea
              id="bulkAdminNotes"
              value={adminNotes}
              onChange={(e) => setAdminNotes(e.target.value)}
              placeholder="Add notes for all processed bids..."
              disabled={isProcessing}
            />
          </div>
          
          <Button
            onClick={handleAcceptAllPending}
            disabled={isProcessing}
            className="w-full bg-green-600 hover:bg-green-700"
          >
            {isProcessing ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin mr-2" />
                Processing All Pending Bids...
              </>
            ) : (
              <>
                <CheckCircle className="h-4 w-4 mr-2" />
                Accept All Pending Bids
              </>
            )}
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}

// Bid Statistics Component
interface BidStatisticsProps {
  eventId?: number
}

export function BidStatistics({ eventId }: BidStatisticsProps) {
  const [statistics, setStatistics] = useState<any>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    loadStatistics()
  }, [eventId])

  const loadStatistics = async () => {
    setIsLoading(true)
    
    const result = await bidFlowManager.getBidStatistics(eventId)
    
    if (result.success && result.data) {
      setStatistics(result.data)
    } else {
      toast({
        title: "Error",
        description: "Failed to load bid statistics",
        variant: "destructive",
      })
    }
    
    setIsLoading(false)
  }

  if (isLoading) {
    return (
      <Card>
        <CardContent className="flex items-center justify-center p-6">
          <Loader2 className="h-6 w-6 animate-spin mr-2" />
          <span>Loading statistics...</span>
        </CardContent>
      </Card>
    )
  }

  if (!statistics) {
    return (
      <Card>
        <CardContent className="p-6">
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              Unable to load bid statistics
            </AlertDescription>
          </Alert>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Bid Statistics</CardTitle>
        <CardDescription>
          {eventId ? 'Event-specific' : 'Overall'} bidding statistics
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="text-center">
            <div className="text-2xl font-bold text-blue-600">{statistics.totalBids}</div>
            <div className="text-sm text-gray-600">Total Bids</div>
          </div>
          <div className="text-center">
            <div className="text-2xl font-bold text-yellow-600">{statistics.pendingBids}</div>
            <div className="text-sm text-gray-600">Pending</div>
          </div>
          <div className="text-center">
            <div className="text-2xl font-bold text-green-600">{statistics.acceptedBids}</div>
            <div className="text-sm text-gray-600">Accepted</div>
          </div>
          <div className="text-center">
            <div className="text-2xl font-bold text-red-600">{statistics.rejectedBids}</div>
            <div className="text-sm text-gray-600">Rejected</div>
          </div>
        </div>
        
        <div className="mt-4 pt-4 border-t">
          <div className="grid grid-cols-2 gap-4">
            <div className="text-center">
              <div className="text-lg font-semibold">${statistics.averageBidAmount.toFixed(2)}</div>
              <div className="text-sm text-gray-600">Average Bid</div>
            </div>
            <div className="text-center">
              <div className="text-lg font-semibold">{statistics.acceptanceRate}%</div>
              <div className="text-sm text-gray-600">Acceptance Rate</div>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}