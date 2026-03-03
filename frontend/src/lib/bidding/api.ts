/**
 * Bidding API Service
 * 
 * Consolidated API interface for all bidding-related operations
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import { apiClient, APIResponse } from '../api-client'
import type {
  BidData,
  SubmitBidRequest,
  ProcessBidRequest,
  BulkProcessBidsRequest,
  BidAvailabilityCheck,
  BulkProcessResponse,
  BidAnalytics,
  EventBidSummary,
  BidFilters
} from './types'

/**
 * Bidding API Service Class
 * 
 * Provides a clean, organized interface for all bidding-related API operations
 */
class BiddingAPIService {
  // Core bidding operations
  async submitBid(data: SubmitBidRequest): Promise<APIResponse<BidData>> {
    return apiClient.post('/bidding/submit', data)
  }

  async canBid(eventId: number): Promise<APIResponse<BidAvailabilityCheck>> {
    return apiClient.get(`/events/${eventId}/can-bid`)
  }

  // Bid retrieval operations
  async getBidsForEvent(eventId: number): Promise<APIResponse<BidData[]>> {
    return apiClient.get(`/bidding/events/${eventId}`)
  }

  async getBidDetails(bidId: number): Promise<APIResponse<BidData>> {
    return apiClient.get(`/bidding/${bidId}`)
  }

  async getAllBids(filters?: BidFilters): Promise<APIResponse<BidData[]>> {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          params.append(key, value.toString())
        }
      })
    }
    const queryString = params.toString()
    return apiClient.get(`/admin/bids${queryString ? `?${queryString}` : ''}`)
  }

  async getPendingBids(): Promise<APIResponse<BidData[]>> {
    return apiClient.get('/admin/bids?status=pending')
  }

  // Bid management operations (admin/staff only)
  async acceptBid(bidId: number, data?: ProcessBidRequest): Promise<APIResponse<BidData>> {
    return apiClient.post(`/bidding/${bidId}/accept`, data || {})
  }

  async rejectBid(bidId: number, data?: ProcessBidRequest): Promise<APIResponse<BidData>> {
    return apiClient.post(`/bidding/${bidId}/reject`, data || {})
  }

  async processBidAction(
    bidId: number, 
    action: 'accept' | 'reject', 
    data?: ProcessBidRequest
  ): Promise<APIResponse<BidData>> {
    return apiClient.post(`/bidding/${bidId}/${action}`, data || {})
  }

  async bulkProcessBids(data: BulkProcessBidsRequest): Promise<APIResponse<BulkProcessResponse>> {
    return apiClient.post('/bidding/bulk-process', data)
  }

  // Analytics and reporting
  async getBidAnalytics(eventId?: number, dateRange?: {
    from: string
    to: string
  }): Promise<APIResponse<BidAnalytics>> {
    const params = new URLSearchParams()
    if (eventId) params.append('event_id', eventId.toString())
    if (dateRange) {
      params.append('from_date', dateRange.from)
      params.append('to_date', dateRange.to)
    }
    const queryString = params.toString()
    return apiClient.get(`/admin/bidding/analytics${queryString ? `?${queryString}` : ''}`)
  }

  async getEventBidSummaries(): Promise<APIResponse<EventBidSummary[]>> {
    return apiClient.get('/admin/bidding/event-summaries')
  }

  async getBidReport(eventId: number): Promise<APIResponse<any>> {
    return apiClient.get(`/admin/events/${eventId}/bid-report`)
  }

  // Customer-facing operations
  async getMyBids(customerId: number): Promise<APIResponse<BidData[]>> {
    return apiClient.get(`/customer/bids?customer_id=${customerId}`)
  }

  async getMyBidForEvent(customerId: number, eventId: number): Promise<APIResponse<BidData | null>> {
    return apiClient.get(`/customer/bids?customer_id=${customerId}&event_id=${eventId}`)
  }

  // Utility methods for common bidding patterns
  async withBidValidation<T>(
    operation: () => Promise<APIResponse<T>>,
    eventId: number
  ): Promise<T | null> {
    try {
      // First check if bidding is allowed
      const availability = await this.canBid(eventId)
      if (!availability.data.can_bid) {
        throw new Error(availability.data.reason || 'Bidding not available')
      }
      
      const response = await operation()
      return response.data
    } catch (error: any) {
      console.error(`Bidding operation failed for event ${eventId}:`, error)
      return null
    }
  }

  async withStaffPermission<T>(
    operation: () => Promise<APIResponse<T>>
  ): Promise<T | null> {
    try {
      const response = await operation()
      return response.data
    } catch (error: any) {
      if (error.status === 403) {
        console.error('Insufficient permissions for bid management operation')
      } else {
        console.error('Bid management operation failed:', error)
      }
      return null
    }
  }

  // Batch operations
  async batchAcceptBids(bidIds: number[], adminNotes?: string): Promise<APIResponse<BulkProcessResponse>> {
    const bids = bidIds.map(bid_id => ({ bid_id, action: 'accept' as const }))
    return this.bulkProcessBids({ bids, admin_notes: adminNotes })
  }

  async batchRejectBids(
    bidIds: number[], 
    reason?: string, 
    adminNotes?: string
  ): Promise<APIResponse<BulkProcessResponse>> {
    const bids = bidIds.map(bid_id => ({ bid_id, action: 'reject' as const, reason }))
    return this.bulkProcessBids({ bids, admin_notes: adminNotes })
  }

  // Real-time operations (if WebSocket support is available)
  async subscribeToBidUpdates(eventId: number, _callback: (bid: BidData) => void): Promise<void> {
    // This would implement WebSocket subscription for real-time bid updates
    // For now, it's a placeholder for future implementation
    console.log(`Subscribing to bid updates for event ${eventId}`)
  }

  async unsubscribeFromBidUpdates(eventId: number): Promise<void> {
    // This would implement WebSocket unsubscription
    console.log(`Unsubscribing from bid updates for event ${eventId}`)
  }
}

// Create and export the bidding API instance
export const biddingAPI = new BiddingAPIService()

// Export the class for creating custom instances
export { BiddingAPIService }

// Legacy compatibility - if needed for existing code
export const legacyBiddingAPI = {
  submitBid: (data: SubmitBidRequest) => biddingAPI.submitBid(data),
  getBidsForEvent: (eventId: number) => biddingAPI.getBidsForEvent(eventId),
  processBidAction: (bidId: number, action: 'accept' | 'reject', data?: ProcessBidRequest) => 
    biddingAPI.processBidAction(bidId, action, data),
}