/**
 * Bidding React Hooks
 * 
 * Consolidated React Query hooks for all bidding-related operations
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from '@/hooks/use-toast'
import { biddingAPI } from './api'
import type {
  SubmitBidRequest,
  ProcessBidRequest,
  BulkProcessBidsRequest,
  BidFilters
} from './types'

// Query keys for consistent cache management
export const biddingQueryKeys = {
  all: ['bidding'] as const,
  lists: () => [...biddingQueryKeys.all, 'list'] as const,
  list: (filters: Record<string, any>) => [...biddingQueryKeys.lists(), filters] as const,
  details: () => [...biddingQueryKeys.all, 'detail'] as const,
  detail: (id: number) => [...biddingQueryKeys.details(), id] as const,
  eventBids: (eventId: number) => [...biddingQueryKeys.all, 'event', eventId] as const,
  canBid: (eventId: number) => [...biddingQueryKeys.all, 'can-bid', eventId] as const,
  myBids: (customerId: number) => [...biddingQueryKeys.all, 'my-bids', customerId] as const,
  pendingBids: () => [...biddingQueryKeys.all, 'pending'] as const,
  analytics: (eventId?: number) => [...biddingQueryKeys.all, 'analytics', eventId] as const,
}

// Core bidding queries
export const useBid = (bidId: number) => {
  return useQuery({
    queryKey: biddingQueryKeys.detail(bidId),
    queryFn: () => biddingAPI.getBidDetails(bidId),
    enabled: !!bidId,
    staleTime: 30 * 1000, // 30 seconds
  })
}

export const useEventBids = (eventId: number) => {
  return useQuery({
    queryKey: biddingQueryKeys.eventBids(eventId),
    queryFn: () => biddingAPI.getBidsForEvent(eventId),
    enabled: !!eventId,
    staleTime: 15 * 1000, // 15 seconds - shorter for active bidding
  })
}

export const useAllBids = (filters?: BidFilters) => {
  return useQuery({
    queryKey: biddingQueryKeys.list(filters || {}),
    queryFn: () => biddingAPI.getAllBids(filters),
    staleTime: 30 * 1000,
  })
}

export const usePendingBids = () => {
  return useQuery({
    queryKey: biddingQueryKeys.pendingBids(),
    queryFn: () => biddingAPI.getPendingBids(),
    staleTime: 10 * 1000, // 10 seconds - frequent updates for pending bids
  })
}

// Bidding availability
export const useCanBid = (eventId: number) => {
  return useQuery({
    queryKey: biddingQueryKeys.canBid(eventId),
    queryFn: () => biddingAPI.canBid(eventId),
    enabled: !!eventId,
    staleTime: 30 * 1000,
  })
}

// Customer bidding queries
export const useMyBids = (customerId: number) => {
  return useQuery({
    queryKey: biddingQueryKeys.myBids(customerId),
    queryFn: () => biddingAPI.getMyBids(customerId),
    enabled: !!customerId,
    staleTime: 60 * 1000, // 1 minute
  })
}

export const useMyBidForEvent = (customerId: number, eventId: number) => {
  return useQuery({
    queryKey: [...biddingQueryKeys.myBids(customerId), 'event', eventId],
    queryFn: () => biddingAPI.getMyBidForEvent(customerId, eventId),
    enabled: !!customerId && !!eventId,
    staleTime: 30 * 1000,
  })
}

// Analytics queries
export const useBidAnalytics = (eventId?: number, dateRange?: { from: string; to: string }) => {
  return useQuery({
    queryKey: biddingQueryKeys.analytics(eventId),
    queryFn: () => biddingAPI.getBidAnalytics(eventId, dateRange),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useEventBidSummaries = () => {
  return useQuery({
    queryKey: [...biddingQueryKeys.all, 'event-summaries'],
    queryFn: () => biddingAPI.getEventBidSummaries(),
    staleTime: 2 * 60 * 1000, // 2 minutes
  })
}

// Bidding mutations
export const useSubmitBid = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: SubmitBidRequest) => biddingAPI.submitBid(data),
    onSuccess: (_response, variables) => {
      // Invalidate relevant queries
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.eventBids(variables.event_id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.myBids(variables.customer_id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.lists() })
      
      toast({
        title: "Bid Submitted",
        description: `Your bid of $${variables.bid_amount} has been submitted successfully`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Bid Submission Failed",
        description: error.userMessage || "Failed to submit bid",
        variant: "destructive",
      })
    }
  })
}

export const useAcceptBid = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ bidId, data }: { bidId: number; data?: ProcessBidRequest }) => 
      biddingAPI.acceptBid(bidId, data),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.detail(response.data.id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.eventBids(response.data.event_id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.pendingBids() })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.lists() })
      
      toast({
        title: "Bid Accepted",
        description: `Bid #${response.data.id} has been accepted`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Accept Failed",
        description: error.userMessage || "Failed to accept bid",
        variant: "destructive",
      })
    }
  })
}

export const useRejectBid = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ bidId, data }: { bidId: number; data?: ProcessBidRequest }) => 
      biddingAPI.rejectBid(bidId, data),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.detail(response.data.id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.eventBids(response.data.event_id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.pendingBids() })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.lists() })
      
      toast({
        title: "Bid Rejected",
        description: `Bid #${response.data.id} has been rejected`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Reject Failed",
        description: error.userMessage || "Failed to reject bid",
        variant: "destructive",
      })
    }
  })
}

export const useProcessBidAction = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ 
      bidId, 
      action, 
      data 
    }: { 
      bidId: number
      action: 'accept' | 'reject'
      data?: ProcessBidRequest 
    }) => biddingAPI.processBidAction(bidId, action, data),
    onSuccess: (response, variables) => {
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.detail(variables.bidId) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.eventBids(response.data.event_id) })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.pendingBids() })
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.lists() })
      
      const actionText = variables.action === 'accept' ? 'accepted' : 'rejected'
      toast({
        title: `Bid ${actionText.charAt(0).toUpperCase() + actionText.slice(1)}`,
        description: `Bid #${response.data.id} has been ${actionText}`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Action Failed",
        description: error.userMessage || "Failed to process bid action",
        variant: "destructive",
      })
    }
  })
}

export const useBulkProcessBids = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: BulkProcessBidsRequest) => biddingAPI.bulkProcessBids(data),
    onSuccess: (response) => {
      // Invalidate all bid-related queries since we processed multiple bids
      queryClient.invalidateQueries({ queryKey: biddingQueryKeys.all })
      
      const { summary } = response.data
      toast({
        title: "Bulk Processing Complete",
        description: `Processed ${summary.total} bids: ${summary.accepted} accepted, ${summary.rejected} rejected`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Bulk Processing Failed",
        description: error.userMessage || "Failed to process bids",
        variant: "destructive",
      })
    }
  })
}

// Convenience hooks for common patterns
export const useBidWithEvent = (bidId: number) => {
  const bidQuery = useBid(bidId)
  
  return {
    bid: bidQuery.data?.data,
    event: bidQuery.data?.data?.event,
    isLoading: bidQuery.isLoading,
    error: bidQuery.error,
    refetch: bidQuery.refetch
  }
}

export const useBidManagement = (bidId: number) => {
  const acceptBid = useAcceptBid()
  const rejectBid = useRejectBid()
  const processBidAction = useProcessBidAction()
  
  return {
    acceptBid: (data?: ProcessBidRequest) => acceptBid.mutate({ bidId, data }),
    rejectBid: (data?: ProcessBidRequest) => rejectBid.mutate({ bidId, data }),
    processBidAction: (action: 'accept' | 'reject', data?: ProcessBidRequest) => 
      processBidAction.mutate({ bidId, action, data }),
    isProcessing: acceptBid.isPending || rejectBid.isPending || processBidAction.isPending,
  }
}

export const useEventBidManagement = (eventId: number) => {
  const eventBidsQuery = useEventBids(eventId)
  const bulkProcessBids = useBulkProcessBids()
  
  const pendingBids = eventBidsQuery.data?.data?.filter(bid => bid.status === 'pending') || []
  const acceptedBids = eventBidsQuery.data?.data?.filter(bid => bid.status === 'accepted') || []
  const rejectedBids = eventBidsQuery.data?.data?.filter(bid => bid.status === 'rejected') || []
  
  return {
    allBids: eventBidsQuery.data?.data || [],
    pendingBids,
    acceptedBids,
    rejectedBids,
    isLoading: eventBidsQuery.isLoading,
    error: eventBidsQuery.error,
    refetch: eventBidsQuery.refetch,
    bulkAccept: (bidIds: number[], adminNotes?: string) => {
      const bids = bidIds.map(bid_id => ({ bid_id, action: 'accept' as const }))
      bulkProcessBids.mutate({ bids, admin_notes: adminNotes })
    },
    bulkReject: (bidIds: number[], reason?: string, adminNotes?: string) => {
      const bids = bidIds.map(bid_id => ({ bid_id, action: 'reject' as const, reason }))
      bulkProcessBids.mutate({ bids, admin_notes: adminNotes })
    },
    isBulkProcessing: bulkProcessBids.isPending,
  }
}

// Customer-specific hooks
export const useCustomerBidding = (customerId: number, eventId?: number) => {
  const myBidsQuery = useMyBids(customerId)
  const myBidForEventQuery = useMyBidForEvent(customerId, eventId || 0)
  const submitBid = useSubmitBid()
  const canBidQuery = useCanBid(eventId || 0)
  
  return {
    myBids: myBidsQuery.data?.data || [],
    myBidForEvent: eventId ? myBidForEventQuery.data?.data : null,
    canBid: eventId ? canBidQuery.data?.data : null,
    isLoading: myBidsQuery.isLoading || (eventId ? myBidForEventQuery.isLoading : false),
    submitBid: (bidData: Omit<SubmitBidRequest, 'customer_id'>) => 
      submitBid.mutate({ ...bidData, customer_id: customerId }),
    isSubmitting: submitBid.isPending,
    hasActiveBidForEvent: (checkEventId: number) => 
      myBidsQuery.data?.data?.some(bid => 
        bid.event_id === checkEventId && bid.status === 'pending'
      ) || false,
  }
}