/**
 * Bid Flow Manager
 * 
 * Consolidated manager for all bid flow operations on the frontend.
 * This class orchestrates bid-related operations and provides a clean interface
 * for components to interact with the bidding system.
 * 
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import { biddingAPI } from './api'
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

export interface BidFlowResult<T = any> {
  success: boolean
  data?: T
  error?: string
  errorCode?: string
}

export interface BidSubmissionFlow {
  eventId: number
  customerId: number
  bidAmount: number
  quantity: number
}

export interface BidProcessingFlow {
  bidId: number
  action: 'accept' | 'reject'
  adminNotes?: string
  reason?: string
}

export interface BidManagementContext {
  eventId: number
  adminId: number
  filters?: BidFilters
}

/**
 * Bid Flow Manager Class
 * 
 * Provides orchestrated bid flow operations with error handling,
 * validation, and consistent response formatting.
 */
class BidFlowManager {
  private static instance: BidFlowManager
  
  private constructor() {}
  
  public static getInstance(): BidFlowManager {
    if (!BidFlowManager.instance) {
      BidFlowManager.instance = new BidFlowManager()
    }
    return BidFlowManager.instance
  }

  /**
   * Complete bid submission flow with validation
   */
  async submitBidFlow(flow: BidSubmissionFlow): Promise<BidFlowResult<BidData>> {
    try {
      // Step 1: Check if bidding is allowed
      const availabilityCheck = await this.checkBidAvailability(flow.eventId)
      if (!availabilityCheck.success || !availabilityCheck.data?.can_bid) {
        return {
          success: false,
          error: availabilityCheck.data?.reason || 'Bidding not available',
          errorCode: 'BIDDING_NOT_ALLOWED'
        }
      }

      // Step 2: Validate bid data
      const validationResult = this.validateBidData(flow)
      if (!validationResult.success) {
        return validationResult
      }

      // Step 3: Submit the bid
      const submitRequest: SubmitBidRequest = {
        event_id: flow.eventId,
        customer_id: flow.customerId,
        bid_amount: flow.bidAmount,
        quantity: flow.quantity
      }

      const response = await biddingAPI.submitBid(submitRequest)
      
      return {
        success: true,
        data: response.data,
      }

    } catch (error: any) {
      return this.handleError(error, 'BID_SUBMISSION_FAILED')
    }
  }

  /**
   * Complete bid processing flow with access control
   */
  async processBidFlow(flow: BidProcessingFlow): Promise<BidFlowResult<BidData>> {
    try {
      // Validate action
      if (!['accept', 'reject'].includes(flow.action)) {
        return {
          success: false,
          error: 'Invalid action. Must be "accept" or "reject"',
          errorCode: 'INVALID_ACTION'
        }
      }

      // Process the bid
      const processRequest: ProcessBidRequest = {
        admin_notes: flow.adminNotes,
        reason: flow.reason
      }

      const response = await biddingAPI.processBidAction(flow.bidId, flow.action, processRequest)
      
      return {
        success: true,
        data: response.data,
      }

    } catch (error: any) {
      return this.handleError(error, 'BID_PROCESSING_FAILED')
    }
  }

  /**
   * Bulk bid processing flow
   */
  async bulkProcessBidsFlow(
    bids: Array<{ bidId: number; action: 'accept' | 'reject'; reason?: string }>,
    adminNotes?: string
  ): Promise<BidFlowResult<BulkProcessResponse>> {
    try {
      if (bids.length === 0) {
        return {
          success: false,
          error: 'No bids provided for processing',
          errorCode: 'NO_BIDS_PROVIDED'
        }
      }

      if (bids.length > 50) {
        return {
          success: false,
          error: 'Too many bids. Maximum 50 bids can be processed at once',
          errorCode: 'TOO_MANY_BIDS'
        }
      }

      const bulkRequest: BulkProcessBidsRequest = {
        bids: bids.map(bid => ({
          bid_id: bid.bidId,
          action: bid.action,
          reason: bid.reason
        })),
        admin_notes: adminNotes
      }

      const response = await biddingAPI.bulkProcessBids(bulkRequest)
      
      return {
        success: true,
        data: response.data,
      }

    } catch (error: any) {
      return this.handleError(error, 'BULK_PROCESSING_FAILED')
    }
  }

  /**
   * Get comprehensive bid management data
   */
  async getBidManagementData(context: BidManagementContext): Promise<BidFlowResult<{
    bids: BidData[]
    analytics: BidAnalytics
    eventSummary: EventBidSummary
  }>> {
    try {
      // Fetch bids for the event
      const bidsResponse = await biddingAPI.getBidsForEvent(context.eventId)
      
      // Fetch analytics
      const analyticsResponse = await biddingAPI.getBidAnalytics(context.eventId)
      
      // Fetch event summaries and find the relevant one
      const summariesResponse = await biddingAPI.getEventBidSummaries()
      const eventSummary = summariesResponse.data.find(summary => summary.event_id === context.eventId)

      return {
        success: true,
        data: {
          bids: bidsResponse.data,
          analytics: analyticsResponse.data,
          eventSummary: eventSummary || this.createEmptyEventSummary(context.eventId)
        }
      }

    } catch (error: any) {
      return this.handleError(error, 'MANAGEMENT_DATA_FAILED')
    }
  }

  /**
   * Check bid availability for an event
   */
  async checkBidAvailability(eventId: number): Promise<BidFlowResult<BidAvailabilityCheck>> {
    try {
      const response = await biddingAPI.canBid(eventId)
      return {
        success: true,
        data: response.data,
      }
    } catch (error: any) {
      return this.handleError(error, 'AVAILABILITY_CHECK_FAILED')
    }
  }

  /**
   * Get customer's bidding history and status
   */
  async getCustomerBidContext(customerId: number, eventId?: number): Promise<BidFlowResult<{
    allBids: BidData[]
    eventBid?: BidData | null
    canBid?: BidAvailabilityCheck
  }>> {
    try {
      // Get all customer bids
      const allBidsResponse = await biddingAPI.getMyBids(customerId)
      
      let eventBid: BidData | null = null
      let canBid: BidAvailabilityCheck | undefined = undefined

      if (eventId) {
        // Get specific event bid
        const eventBidResponse = await biddingAPI.getMyBidForEvent(customerId, eventId)
        eventBid = eventBidResponse.data

        // Check if customer can bid on this event
        const canBidResponse = await biddingAPI.canBid(eventId)
        canBid = canBidResponse.data
      }

      return {
        success: true,
        data: {
          allBids: allBidsResponse.data,
          eventBid,
          canBid
        }
      }

    } catch (error: any) {
      return this.handleError(error, 'CUSTOMER_CONTEXT_FAILED')
    }
  }

  /**
   * Validate bid data before submission
   */
  private validateBidData(flow: BidSubmissionFlow): BidFlowResult {
    const errors: string[] = []

    if (!flow.eventId || flow.eventId <= 0) {
      errors.push('Valid event ID is required')
    }

    if (!flow.customerId || flow.customerId <= 0) {
      errors.push('Valid customer ID is required')
    }

    if (!flow.bidAmount || flow.bidAmount <= 0) {
      errors.push('Bid amount must be greater than zero')
    }

    if (!flow.quantity || flow.quantity <= 0) {
      errors.push('Quantity must be greater than zero')
    }

    if (flow.quantity > 10) {
      errors.push('Maximum quantity is 10 tickets per bid')
    }

    if (errors.length > 0) {
      return {
        success: false,
        error: errors.join(', '),
        errorCode: 'VALIDATION_ERROR'
      }
    }

    return { success: true }
  }

  /**
   * Handle API errors consistently
   */
  private handleError(error: any, defaultErrorCode: string): BidFlowResult {
    console.error('Bid flow error:', error)

    // Extract user-friendly error message
    let errorMessage = 'An unexpected error occurred'
    let errorCode = defaultErrorCode

    if (error.response?.data?.error) {
      errorMessage = error.response.data.error.message || errorMessage
      errorCode = error.response.data.error.code || errorCode
    } else if (error.message) {
      errorMessage = error.message
    }

    return {
      success: false,
      error: errorMessage,
      errorCode
    }
  }

  /**
   * Create empty event summary for missing data
   */
  private createEmptyEventSummary(eventId: number): EventBidSummary {
    return {
      event_id: eventId,
      event_name: 'Unknown Event',
      total_bids: 0,
      pending_bids: 0,
      highest_bid: 0,
      average_bid: 0,
      bid_range: {
        min: 0,
        max: 0
      }
    }
  }

  /**
   * Utility methods for common bid flow patterns
   */

  /**
   * Quick bid acceptance with validation
   */
  async quickAcceptBid(bidId: number, adminNotes?: string): Promise<BidFlowResult<BidData>> {
    return this.processBidFlow({
      bidId,
      action: 'accept',
      adminNotes
    })
  }

  /**
   * Quick bid rejection with reason
   */
  async quickRejectBid(bidId: number, reason: string, adminNotes?: string): Promise<BidFlowResult<BidData>> {
    return this.processBidFlow({
      bidId,
      action: 'reject',
      reason,
      adminNotes
    })
  }

  /**
   * Accept all pending bids for an event
   */
  async acceptAllPendingBids(eventId: number, adminNotes?: string): Promise<BidFlowResult<BulkProcessResponse>> {
    try {
      const bidsResponse = await biddingAPI.getBidsForEvent(eventId)
      const pendingBids = bidsResponse.data.filter(bid => bid.status === 'pending')

      if (pendingBids.length === 0) {
        return {
          success: false,
          error: 'No pending bids found for this event',
          errorCode: 'NO_PENDING_BIDS'
        }
      }

      const bidsToProcess = pendingBids.map(bid => ({
        bidId: bid.id,
        action: 'accept' as const
      }))

      return this.bulkProcessBidsFlow(bidsToProcess, adminNotes)

    } catch (error: any) {
      return this.handleError(error, 'ACCEPT_ALL_FAILED')
    }
  }

  /**
   * Get bid statistics for dashboard
   */
  async getBidStatistics(eventId?: number): Promise<BidFlowResult<{
    totalBids: number
    pendingBids: number
    acceptedBids: number
    rejectedBids: number
    averageBidAmount: number
    acceptanceRate: number
  }>> {
    try {
      const analyticsResponse = await biddingAPI.getBidAnalytics(eventId)
      const analytics = analyticsResponse.data

      const acceptanceRate = analytics.total_bids > 0 
        ? (analytics.accepted_bids / analytics.total_bids) * 100 
        : 0

      return {
        success: true,
        data: {
          totalBids: analytics.total_bids,
          pendingBids: analytics.pending_bids,
          acceptedBids: analytics.accepted_bids,
          rejectedBids: analytics.rejected_bids,
          averageBidAmount: analytics.average_bid_amount,
          acceptanceRate: Math.round(acceptanceRate * 100) / 100
        }
      }

    } catch (error: any) {
      return this.handleError(error, 'STATISTICS_FAILED')
    }
  }
}

// Export singleton instance
export const bidFlowManager = BidFlowManager.getInstance()

// Export class for testing or custom instances
export { BidFlowManager }