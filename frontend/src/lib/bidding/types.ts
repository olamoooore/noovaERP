/**
 * Bidding-related TypeScript types and interfaces
 * 
 * Consolidated type definitions for all bidding functionality
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

export interface BidData {
  id: number
  event_id: number
  customer_id: number
  bid_amount: number
  quantity: number
  status: BidStatus
  processed_by?: number
  processed_at?: string
  admin_notes?: string
  rejection_reason?: string
  created_at: string
  updated_at: string
  
  // Relationships
  event?: Event
  customer?: Customer
  processedBy?: User
}

export interface Customer {
  id: number
  name: string
  email: string
  phone?: string
  created_at: string
  updated_at: string
}

export interface User {
  id: number
  name: string
  email: string
  role?: string
}

export interface Event {
  id: number
  name: string
  description?: string
  type: EventType
  ticket_acquisition_mode: AcquisitionMode
  venue: string
  event_date: string
  event_time?: string
  capacity: number
  price?: number
  starting_bid?: number
  sale_start_date?: string
  sale_end_date?: string
  status: EventStatus
  tickets_sold: number
  banner_image?: string
  thumbnail_image?: string
  created_at: string
  updated_at: string
}

// Enums
export type BidStatus = 
  | 'pending'
  | 'accepted'
  | 'rejected'

export type EventType = 
  | 'concert'
  | 'meet-the-artist'
  | 'conference'
  | 'workshop'

export type AcquisitionMode = 
  | 'buy'
  | 'bid'

export type EventStatus = 
  | 'draft'
  | 'published'
  | 'active'
  | 'completed'
  | 'cancelled'

// Request/Response types
export interface SubmitBidRequest {
  event_id: number
  customer_id: number
  bid_amount: number
  quantity: number
}

export interface ProcessBidRequest {
  admin_notes?: string
  reason?: string
}

export interface BulkProcessBidsRequest {
  bids: Array<{
    bid_id: number
    action: 'accept' | 'reject'
    reason?: string
  }>
  admin_notes?: string
}

export interface BidAvailabilityCheck {
  can_bid: boolean
  reason?: string
}

// Response types
export interface BidResponse {
  data: BidData
  message?: string
}

export interface BidsListResponse {
  data: BidData[]
  message?: string
}

export interface BulkProcessResponse {
  processed: Array<{
    bid_id: number
    action: string
    status: string
    new_status: BidStatus
  }>
  failed: Array<{
    bid_id: number
    action: string
    error: string
  }>
  summary: {
    total: number
    accepted: number
    rejected: number
    failed: number
  }
}

// Dashboard and analytics types
export interface BidAnalytics {
  total_bids: number
  pending_bids: number
  accepted_bids: number
  rejected_bids: number
  average_bid_amount: number
  highest_bid_amount: number
  bid_acceptance_rate: number
}

export interface EventBidSummary {
  event_id: number
  event_name: string
  total_bids: number
  pending_bids: number
  highest_bid: number
  average_bid: number
  bid_range: {
    min: number
    max: number
  }
}

// Error types
export interface BiddingError {
  code: string
  message: string
  details?: Record<string, any>
  request_id?: string
}

// Sale window types
export interface SaleWindow {
  is_open: boolean
  status: 'not_started' | 'open' | 'closed' | 'sold_out'
  start_date?: string
  end_date?: string
  message?: string
}

// Validation types
export interface BidValidation {
  is_valid: boolean
  errors: string[]
  warnings?: string[]
}

// Filter and sorting types
export interface BidFilters {
  status?: BidStatus
  event_id?: number
  customer_id?: number
  min_amount?: number
  max_amount?: number
  date_from?: string
  date_to?: string
}

export interface BidSortOptions {
  field: 'created_at' | 'bid_amount' | 'status' | 'event_name' | 'customer_name'
  direction: 'asc' | 'desc'
}

// Admin management types
export interface BidManagementStats {
  pending_review: number
  processed_today: number
  average_processing_time: number
  acceptance_rate: number
}

export interface BidProcessingHistory {
  bid_id: number
  action: string
  processed_by: string
  processed_at: string
  admin_notes?: string
  reason?: string
}

// API Response wrapper
export interface BiddingAPIResponse<T> {
  data: T
  message?: string
  error?: BiddingError
}