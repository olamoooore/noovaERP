/**
 * Booking Constants
 * 
 * Consolidated constants for booking-related functionality
 * Requirements: 6.2 - Organize ticket booking functionality
 */

import type { BookingState, TicketStatus } from './types'

// Booking state constants
export const BOOKING_STATES: Record<string, BookingState> = {
  NEW: 'new',
  PENDING: 'pending',
  CONFIRMED: 'confirmed',
  BOOKED: 'booked',
  CANCELLED: 'cancelled',
} as const

export const BOOKING_STATE_TRANSITIONS: Record<BookingState, BookingState[]> = {
  'new': ['pending', 'cancelled'],
  'pending': ['confirmed', 'cancelled'],
  'confirmed': ['booked', 'cancelled'],
  'booked': ['cancelled'],
  'cancelled': [],
}

export const BOOKING_STATE_LABELS: Record<BookingState, string> = {
  'new': 'New',
  'pending': 'Payment Pending',
  'confirmed': 'Confirmed',
  'booked': 'Completed',
  'cancelled': 'Cancelled',
}

export const BOOKING_STATE_COLORS: Record<BookingState, string> = {
  'new': 'gray',
  'pending': 'yellow',
  'confirmed': 'blue',
  'booked': 'green',
  'cancelled': 'red',
}

// Ticket status constants
export const TICKET_STATUSES: Record<string, TicketStatus> = {
  VALID: 'valid',
  USED: 'used',
  EXPIRED: 'expired',
  CANCELLED: 'cancelled',
} as const

export const TICKET_STATUS_LABELS: Record<TicketStatus, string> = {
  'valid': 'Valid',
  'used': 'Used',
  'expired': 'Expired',
  'cancelled': 'Cancelled',
}

export const TICKET_STATUS_COLORS: Record<TicketStatus, string> = {
  'valid': 'green',
  'used': 'blue',
  'expired': 'orange',
  'cancelled': 'red',
}

// Payment-related constants
export const PAYMENT_METHODS = {
  CREDIT_CARD: 'credit_card',
  DEBIT_CARD: 'debit_card',
  PAYPAL: 'paypal',
  BANK_TRANSFER: 'bank_transfer',
  DIGITAL_WALLET: 'digital_wallet',
} as const

export const PAYMENT_STATUSES = {
  PENDING: 'pending',
  CONFIRMED: 'confirmed',
  SUCCESS: 'success',
  FAILED: 'failed',
  CANCELLED: 'cancelled',
  REFUNDED: 'refunded',
} as const

// Currency constants
export const SUPPORTED_CURRENCIES = {
  USD: 'USD',
  EUR: 'EUR',
  GBP: 'GBP',
  CAD: 'CAD',
  AUD: 'AUD',
} as const

export const CURRENCY_SYMBOLS: Record<string, string> = {
  USD: '$',
  EUR: '€',
  GBP: '£',
  CAD: 'C$',
  AUD: 'A$',
}

// Booking limits and constraints
export const BOOKING_LIMITS = {
  MAX_ATTENDEES_PER_BOOKING: 10,
  MAX_BOOKINGS_PER_CUSTOMER_PER_EVENT: 5,
  RESERVATION_TIMEOUT_MINUTES: 15,
  REFUND_CUTOFF_HOURS: 24,
  EARLY_ACCESS_DURATION_HOURS: 24,
} as const

// Fraud detection thresholds
export const FRAUD_THRESHOLDS = {
  LOW_RISK_SCORE: 30,
  MEDIUM_RISK_SCORE: 60,
  HIGH_RISK_SCORE: 80,
  BLOCK_THRESHOLD: 90,
  MANUAL_REVIEW_THRESHOLD: 70,
  IDENTITY_VERIFICATION_THRESHOLD: 80,
} as const

// Rate limiting constants
export const RATE_LIMITS = {
  BOOKING_ATTEMPTS_PER_HOUR: 10,
  FAILED_ATTEMPTS_THRESHOLD: 5,
  FAILURE_RATE_THRESHOLD: 70, // percentage
  TICKETS_PER_HOUR_LIMIT: 50,
} as const

// Validation constants
export const VALIDATION_RULES = {
  MIN_BUYER_NAME_LENGTH: 2,
  MAX_BUYER_NAME_LENGTH: 100,
  MIN_ATTENDEE_NAME_LENGTH: 2,
  MAX_ATTENDEE_NAME_LENGTH: 100,
  MAX_PHONE_LENGTH: 20,
  MIN_BOOKING_AMOUNT: 0.01,
  MAX_BOOKING_AMOUNT: 10000,
} as const

// Email validation regex
export const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

// Phone validation regex (basic international format)
export const PHONE_REGEX = /^[+]?[1-9][\d]{0,15}$/

// Booking reference format
export const BOOKING_REFERENCE_PREFIX = 'BK'
export const BOOKING_REFERENCE_LENGTH = 10

// Ticket reference format
export const TICKET_REFERENCE_PREFIX = 'TK'
export const TICKET_REFERENCE_LENGTH = 12

// API endpoints (relative to base URL)
export const BOOKING_ENDPOINTS = {
  // Core booking operations
  CREATE_BOOKING: '/bookings',
  GET_BOOKING: '/bookings/:id',
  GET_BOOKING_BY_REFERENCE: '/bookings/reference/:reference',
  GET_EVENT_BOOKINGS: '/bookings',
  
  // State management
  INITIATE_PAYMENT: '/bookings/:id/initiate-payment',
  PAYMENT_CALLBACK: '/bookings/payment-callback',
  CONFIRM_BOOKING: '/bookings/:id/confirm',
  COMPLETE_BOOKING: '/bookings/:id/complete',
  CANCEL_BOOKING: '/bookings/:id/cancel',
  
  // Validation and availability
  CHECK_AVAILABILITY: '/bookings/check-availability',
  STATE_TRANSITIONS: '/bookings/:id/state-transitions',
  
  // Customer operations
  CUSTOMER_DASHBOARD: '/customer/dashboard',
  UPCOMING_BOOKINGS: '/customer/bookings/upcoming',
  PAST_BOOKINGS: '/customer/bookings/past',
  DOWNLOAD_TICKET: '/customer/bookings/:id/ticket',
  
  // Ticket operations
  DOWNLOAD_TICKET_PDF: '/bookings/:id/tickets/download',
  VALIDATE_TICKET: '/tickets/validate',
  
  // Admin operations
  BOOKING_ANALYTICS: '/admin/bookings/analytics',
  CAPACITY_REPORT: '/admin/events/:id/capacity-report',
  FRAUD_ALERTS: '/admin/fraud/alerts',
  REVIEW_BOOKING: '/admin/bookings/:id/review',
  BATCH_CANCEL: '/admin/bookings/batch-cancel',
  BATCH_UPDATE_STATUS: '/admin/bookings/batch-update-status',
} as const

// Error codes
export const BOOKING_ERROR_CODES = {
  // General errors
  BOOKING_NOT_FOUND: 'BOOKING_NOT_FOUND',
  INVALID_ARGUMENT: 'INVALID_ARGUMENT',
  INTERNAL_SERVER_ERROR: 'INTERNAL_SERVER_ERROR',
  
  // Booking creation errors
  BOOKING_NOT_ALLOWED: 'BOOKING_NOT_ALLOWED',
  INSUFFICIENT_CAPACITY: 'INSUFFICIENT_CAPACITY',
  EVENT_SOLD_OUT: 'EVENT_SOLD_OUT',
  EVENT_NOT_AVAILABLE: 'EVENT_NOT_AVAILABLE',
  
  // State transition errors
  INVALID_STATE_TRANSITION: 'INVALID_STATE_TRANSITION',
  BOOKING_ALREADY_CANCELLED: 'BOOKING_ALREADY_CANCELLED',
  BOOKING_ALREADY_CONFIRMED: 'BOOKING_ALREADY_CONFIRMED',
  
  // Payment errors
  PAYMENT_FAILED: 'PAYMENT_FAILED',
  PAYMENT_TIMEOUT: 'PAYMENT_TIMEOUT',
  PAYMENT_ALREADY_PROCESSED: 'PAYMENT_ALREADY_PROCESSED',
  
  // Fraud and security errors
  FRAUD_DETECTED: 'FRAUD_DETECTED',
  RATE_LIMIT_EXCEEDED: 'RATE_LIMIT_EXCEEDED',
  IP_BLOCKED: 'IP_BLOCKED',
  IDENTITY_VERIFICATION_REQUIRED: 'IDENTITY_VERIFICATION_REQUIRED',
  
  // Validation errors
  VALIDATION_FAILED: 'VALIDATION_FAILED',
  INVALID_EMAIL: 'INVALID_EMAIL',
  INVALID_PHONE: 'INVALID_PHONE',
  CONSENT_REQUIRED: 'CONSENT_REQUIRED',
  
  // Authorization errors
  UNAUTHORIZED: 'UNAUTHORIZED',
  FORBIDDEN: 'FORBIDDEN',
  FEATURE_NOT_ENABLED: 'FEATURE_NOT_ENABLED',
} as const

// Success messages
export const BOOKING_SUCCESS_MESSAGES = {
  BOOKING_CREATED: 'Booking created successfully',
  BOOKING_CONFIRMED: 'Booking confirmed successfully',
  BOOKING_COMPLETED: 'Booking completed and tickets generated',
  BOOKING_CANCELLED: 'Booking cancelled successfully',
  PAYMENT_INITIATED: 'Payment process initiated',
  PAYMENT_CONFIRMED: 'Payment confirmed successfully',
  TICKET_DOWNLOADED: 'Ticket downloaded successfully',
  TICKET_VALIDATED: 'Ticket validation successful',
} as const

// Cache durations (in milliseconds)
export const CACHE_DURATIONS = {
  BOOKING_DETAIL: 30 * 1000, // 30 seconds
  BOOKING_LIST: 60 * 1000, // 1 minute
  AVAILABILITY_CHECK: 10 * 1000, // 10 seconds
  CUSTOMER_DASHBOARD: 5 * 60 * 1000, // 5 minutes
  UPCOMING_BOOKINGS: 2 * 60 * 1000, // 2 minutes
  PAST_BOOKINGS: 10 * 60 * 1000, // 10 minutes
  ANALYTICS: 5 * 60 * 1000, // 5 minutes
} as const

// Feature flags
export const BOOKING_FEATURES = {
  EARLY_ACCESS: 'early_access',
  TIER_BENEFITS: 'tier_benefits',
  FRAUD_DETECTION: 'fraud_detection',
  IDENTITY_VERIFICATION: 'identity_verification',
  LOYALTY_POINTS: 'loyalty_points',
  CAPACITY_RESERVATION: 'capacity_reservation',
  REAL_TIME_UPDATES: 'real_time_updates',
  BATCH_OPERATIONS: 'batch_operations',
} as const

// Export all constants as a single object for easier importing
export const BookingConstants = {
  BOOKING_STATES,
  BOOKING_STATE_TRANSITIONS,
  BOOKING_STATE_LABELS,
  BOOKING_STATE_COLORS,
  TICKET_STATUSES,
  TICKET_STATUS_LABELS,
  TICKET_STATUS_COLORS,
  PAYMENT_METHODS,
  PAYMENT_STATUSES,
  SUPPORTED_CURRENCIES,
  CURRENCY_SYMBOLS,
  BOOKING_LIMITS,
  FRAUD_THRESHOLDS,
  RATE_LIMITS,
  VALIDATION_RULES,
  EMAIL_REGEX,
  PHONE_REGEX,
  BOOKING_REFERENCE_PREFIX,
  BOOKING_REFERENCE_LENGTH,
  TICKET_REFERENCE_PREFIX,
  TICKET_REFERENCE_LENGTH,
  BOOKING_ENDPOINTS,
  BOOKING_ERROR_CODES,
  BOOKING_SUCCESS_MESSAGES,
  CACHE_DURATIONS,
  BOOKING_FEATURES,
}