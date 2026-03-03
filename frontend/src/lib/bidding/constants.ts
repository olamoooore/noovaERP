/**
 * Bidding Constants
 * 
 * Consolidated constants for bidding-related functionality
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import type { BidStatus, AcquisitionMode } from './types'

// Bid status constants
export const BID_STATUSES: Record<string, BidStatus> = {
  PENDING: 'pending',
  ACCEPTED: 'accepted',
  REJECTED: 'rejected',
} as const

export const BID_STATUS_LABELS: Record<BidStatus, string> = {
  'pending': 'Pending Review',
  'accepted': 'Accepted',
  'rejected': 'Rejected',
}

export const BID_STATUS_COLORS: Record<BidStatus, string> = {
  'pending': 'yellow',
  'accepted': 'green',
  'rejected': 'red',
}

// Acquisition mode constants
export const ACQUISITION_MODES: Record<string, AcquisitionMode> = {
  BUY: 'buy',
  BID: 'bid',
} as const

export const ACQUISITION_MODE_LABELS: Record<AcquisitionMode, string> = {
  'buy': 'Direct Purchase',
  'bid': 'Bidding',
}

// Bidding limits and constraints
export const BIDDING_LIMITS = {
  MIN_BID_AMOUNT: 0.01,
  MAX_BID_AMOUNT: 100000,
  MIN_QUANTITY: 1,
  MAX_QUANTITY: 10,
  MAX_BIDS_PER_CUSTOMER_PER_EVENT: 1, // Only one active bid per customer per event
  BULK_PROCESS_LIMIT: 50, // Maximum bids to process in bulk
} as const

// Bidding validation rules
export const BIDDING_VALIDATION = {
  MIN_BID_INCREMENT: 1.00, // Minimum increment above starting bid
  BID_AMOUNT_PRECISION: 2, // Decimal places for bid amounts
  MAX_ADMIN_NOTES_LENGTH: 500,
  MAX_REJECTION_REASON_LENGTH: 255,
} as const

// Sale window constants
export const SALE_WINDOW_STATUS = {
  NOT_STARTED: 'not_started',
  OPEN: 'open',
  CLOSED: 'closed',
  SOLD_OUT: 'sold_out',
} as const

// API endpoints (relative to base URL)
export const BIDDING_ENDPOINTS = {
  // Core bidding operations
  SUBMIT_BID: '/bidding/submit',
  CAN_BID: '/events/:eventId/can-bid',
  
  // Bid retrieval
  EVENT_BIDS: '/bidding/events/:eventId',
  BID_DETAILS: '/bidding/:bidId',
  ALL_BIDS: '/admin/bids',
  PENDING_BIDS: '/admin/bids?status=pending',
  
  // Customer operations
  MY_BIDS: '/customer/bids',
  MY_BID_FOR_EVENT: '/customer/bids?customer_id=:customerId&event_id=:eventId',
  
  // Bid management (admin/staff)
  ACCEPT_BID: '/bidding/:bidId/accept',
  REJECT_BID: '/bidding/:bidId/reject',
  PROCESS_BID_ACTION: '/bidding/:bidId/:action',
  BULK_PROCESS: '/bidding/bulk-process',
  
  // Analytics and reporting
  BID_ANALYTICS: '/admin/bidding/analytics',
  EVENT_BID_SUMMARIES: '/admin/bidding/event-summaries',
  BID_REPORT: '/admin/events/:eventId/bid-report',
} as const

// Error codes
export const BIDDING_ERROR_CODES = {
  // General errors
  BID_NOT_FOUND: 'BID_NOT_FOUND',
  INVALID_ARGUMENT: 'INVALID_ARGUMENT',
  INTERNAL_SERVER_ERROR: 'INTERNAL_SERVER_ERROR',
  
  // Bidding errors
  INVALID_ACQUISITION_MODE: 'INVALID_ACQUISITION_MODE',
  BIDDING_NOT_ALLOWED: 'BIDDING_NOT_ALLOWED',
  PURCHASE_ONLY_EVENT: 'PURCHASE_ONLY_EVENT',
  
  // Sale window errors
  SALE_NOT_STARTED: 'SALE_NOT_STARTED',
  SALE_CLOSED: 'SALE_CLOSED',
  EVENT_SOLD_OUT: 'EVENT_SOLD_OUT',
  
  // Bid validation errors
  INVALID_BID_AMOUNT: 'INVALID_BID_AMOUNT',
  BID_TOO_LOW: 'BID_TOO_LOW',
  INVALID_QUANTITY: 'INVALID_QUANTITY',
  DUPLICATE_BID: 'DUPLICATE_BID',
  
  // Bid processing errors
  BID_ALREADY_PROCESSED: 'BID_ALREADY_PROCESSED',
  INVALID_BID_STATE: 'INVALID_BID_STATE',
  PROCESSING_FAILED: 'PROCESSING_FAILED',
  
  // Authorization errors
  UNAUTHORIZED: 'UNAUTHORIZED',
  FORBIDDEN: 'FORBIDDEN',
  INSUFFICIENT_PERMISSIONS: 'INSUFFICIENT_PERMISSIONS',
  
  // Validation errors
  VALIDATION_ERROR: 'VALIDATION_ERROR',
  MISSING_REQUIRED_FIELD: 'MISSING_REQUIRED_FIELD',
} as const

// Success messages
export const BIDDING_SUCCESS_MESSAGES = {
  BID_SUBMITTED: 'Bid submitted successfully',
  BID_ACCEPTED: 'Bid accepted successfully',
  BID_REJECTED: 'Bid rejected successfully',
  BULK_PROCESSING_COMPLETE: 'Bulk processing completed successfully',
  BID_UPDATED: 'Bid updated successfully',
} as const

// Cache durations (in milliseconds)
export const CACHE_DURATIONS = {
  BID_DETAIL: 30 * 1000, // 30 seconds
  EVENT_BIDS: 15 * 1000, // 15 seconds - shorter for active bidding
  PENDING_BIDS: 10 * 1000, // 10 seconds - frequent updates
  MY_BIDS: 60 * 1000, // 1 minute
  CAN_BID: 30 * 1000, // 30 seconds
  BID_ANALYTICS: 5 * 60 * 1000, // 5 minutes
  EVENT_SUMMARIES: 2 * 60 * 1000, // 2 minutes
} as const

// Sorting options
export const BID_SORT_OPTIONS = {
  CREATED_AT: 'created_at',
  BID_AMOUNT: 'bid_amount',
  STATUS: 'status',
  CUSTOMER_NAME: 'customer_name',
  EVENT_NAME: 'event_name',
} as const

export const SORT_DIRECTIONS = {
  ASC: 'asc',
  DESC: 'desc',
} as const

// Filter options
export const BID_FILTER_OPTIONS = {
  ALL_STATUSES: 'all',
  PENDING_ONLY: 'pending',
  ACCEPTED_ONLY: 'accepted',
  REJECTED_ONLY: 'rejected',
} as const

// Notification types for bidding events
export const BIDDING_NOTIFICATIONS = {
  BID_SUBMITTED: 'bid_submitted',
  BID_ACCEPTED: 'bid_accepted',
  BID_REJECTED: 'bid_rejected',
  BID_OUTBID: 'bid_outbid',
  BIDDING_STARTED: 'bidding_started',
  BIDDING_ENDING_SOON: 'bidding_ending_soon',
  BIDDING_ENDED: 'bidding_ended',
} as const

// Real-time update intervals
export const UPDATE_INTERVALS = {
  ACTIVE_BIDDING: 5 * 1000, // 5 seconds during active bidding
  PENDING_REVIEW: 30 * 1000, // 30 seconds for pending bids
  GENERAL: 60 * 1000, // 1 minute for general updates
} as const

// Bid processing actions
export const BID_ACTIONS = {
  ACCEPT: 'accept',
  REJECT: 'reject',
} as const

// Bulk processing limits
export const BULK_PROCESSING = {
  MIN_BATCH_SIZE: 1,
  MAX_BATCH_SIZE: 50,
  RECOMMENDED_BATCH_SIZE: 20,
} as const

// Feature flags for bidding functionality
export const BIDDING_FEATURES = {
  REAL_TIME_UPDATES: 'real_time_bidding_updates',
  BULK_PROCESSING: 'bulk_bid_processing',
  BID_ANALYTICS: 'bid_analytics',
  AUTOMATED_PROCESSING: 'automated_bid_processing',
  BID_NOTIFICATIONS: 'bid_notifications',
  BID_HISTORY: 'bid_history_tracking',
} as const

// Bidding UI constants
export const UI_CONSTANTS = {
  BIDS_PER_PAGE: 20,
  MAX_VISIBLE_BIDS: 100,
  REFRESH_BUTTON_COOLDOWN: 2000, // 2 seconds
  AUTO_REFRESH_INTERVAL: 30000, // 30 seconds
} as const

// Currency formatting
export const CURRENCY_CONFIG = {
  CURRENCY: 'USD',
  LOCALE: 'en-US',
  MINIMUM_FRACTION_DIGITS: 2,
  MAXIMUM_FRACTION_DIGITS: 2,
} as const

// Date formatting
export const DATE_FORMATS = {
  SHORT_DATE: 'MMM dd, yyyy',
  LONG_DATE: 'MMMM dd, yyyy',
  DATE_TIME: 'MMM dd, yyyy HH:mm',
  TIME_ONLY: 'HH:mm',
} as const

// Validation patterns
export const VALIDATION_PATTERNS = {
  BID_AMOUNT: /^\d+(\.\d{1,2})?$/, // Decimal with up to 2 decimal places
  QUANTITY: /^\d+$/, // Positive integers only
} as const

// Export all constants as a single object for easier importing
export const BiddingConstants = {
  BID_STATUSES,
  BID_STATUS_LABELS,
  BID_STATUS_COLORS,
  ACQUISITION_MODES,
  ACQUISITION_MODE_LABELS,
  BIDDING_LIMITS,
  BIDDING_VALIDATION,
  SALE_WINDOW_STATUS,
  BIDDING_ENDPOINTS,
  BIDDING_ERROR_CODES,
  BIDDING_SUCCESS_MESSAGES,
  CACHE_DURATIONS,
  BID_SORT_OPTIONS,
  SORT_DIRECTIONS,
  BID_FILTER_OPTIONS,
  BIDDING_NOTIFICATIONS,
  UPDATE_INTERVALS,
  BID_ACTIONS,
  BULK_PROCESSING,
  BIDDING_FEATURES,
  UI_CONSTANTS,
  CURRENCY_CONFIG,
  DATE_FORMATS,
  VALIDATION_PATTERNS,
}