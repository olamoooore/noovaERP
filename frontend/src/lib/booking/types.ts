/**
 * Booking-related TypeScript types and interfaces
 * 
 * Consolidated type definitions for all booking functionality
 * Requirements: 6.2 - Organize ticket booking functionality
 */

export interface BookingData {
  id: number
  booking_reference: string
  event_id: number
  customer_id?: number
  buyer_name: string
  buyer_email: string
  buyer_phone: string
  total_amount: number
  original_amount: number
  currency: string
  state: BookingState
  consent_pdpa: boolean
  consent_marketing: boolean
  tier_benefits_applied?: TierBenefit[]
  fraud_risk_score?: number
  requires_manual_review?: boolean
  capacity_reservation_token?: string
  capacity_reservation_expires_at?: string
  payment_reference?: string
  created_at: string
  updated_at: string
  
  // Relationships
  event?: Event
  attendees?: BookingAttendee[]
  tickets?: TicketData[]
}

export interface BookingAttendee {
  id: number
  booking_id: number
  ticket_type_id: number
  attendee_name: string
  attendee_email?: string
  attendee_phone?: string
  encrypted_id_info?: string
  created_at: string
  updated_at: string
  
  // Relationships
  ticket_type?: TicketType
  ticket?: TicketData
}

export interface TicketData {
  id: number
  booking_id: number
  attendee_id: number
  ticket_reference: string
  qr_code: string
  status: TicketStatus
  validated_at?: string
  validated_by?: number
  created_at: string
  updated_at: string
  
  // Relationships
  booking?: BookingData
  attendee?: BookingAttendee
}

export interface Event {
  id: number
  title: string
  description?: string
  start_date: string
  end_date: string
  status: EventStatus
  venue: Venue
  capacity?: number
  available_capacity?: number
  image_url?: string
  artists: Artist[]
  ticket_types: TicketType[]
  created_at: string
  updated_at: string
}

export interface Venue {
  id: number
  name: string
  address: string
  city: string
  state?: string
  country: string
  capacity?: number
}

export interface Artist {
  id: number
  name: string
  bio?: string
  image_url?: string
  genre?: string
}

export interface TicketType {
  id: number
  event_id: number
  name: string
  description?: string
  price: number
  currency: string
  max_quantity_per_booking?: number
  available_quantity?: number
  sale_start_date?: string
  sale_end_date?: string
  is_active: boolean
}

export interface TierBenefit {
  benefit_id: number
  benefit_name: string
  discount_percentage?: number
  discount_amount?: number
}

// Enums
export type BookingState = 
  | 'new'
  | 'pending'
  | 'confirmed'
  | 'booked'
  | 'cancelled'

export type TicketStatus = 
  | 'valid'
  | 'used'
  | 'expired'
  | 'cancelled'

export type EventStatus = 
  | 'draft'
  | 'published'
  | 'active'
  | 'completed'
  | 'cancelled'

// Request/Response types
export interface CreateBookingRequest {
  event_id: number
  customer_id?: number
  buyer_name: string
  buyer_email: string
  buyer_phone: string
  total_amount: number
  currency?: string
  consent_pdpa: boolean
  consent_marketing: boolean
  attendees: CreateAttendeeRequest[]
  session_id?: string
  ip_address?: string
  user_agent?: string
  metadata?: Record<string, any>
}

export interface CreateAttendeeRequest {
  ticket_type_id: number
  attendee_name: string
  attendee_email?: string
  attendee_phone?: string
  id_info?: string
}

export interface PaymentCallbackRequest {
  booking_reference: string
  payment_reference: string
  status: 'confirmed' | 'success' | 'failed' | 'cancelled'
  amount?: number
  currency?: string
  payment_method?: string
  timestamp?: string
  processing_time_ms?: number
}

export interface CancelBookingRequest {
  reason: string
}

export interface BookingAvailabilityCheck {
  can_book: boolean
  reason?: string
  available_capacity: number
  requested_tickets: number
  tier_info?: TierEligibilityInfo
  early_access_info?: EarlyAccessInfo
  fraud_check?: FraudCheckResult
  identity_verification_required: boolean
}

export interface TierEligibilityInfo {
  eligible: boolean
  reason?: string
  early_access_info?: EarlyAccessInfo
}

export interface EarlyAccessInfo {
  has_access: boolean
  reason?: string
  window?: EarlyAccessWindow
}

export interface EarlyAccessWindow {
  id: number
  event_id: number
  tier_id: number
  start_date: string
  end_date: string
  max_tickets_per_customer?: number
  eligible_ticket_types: number[]
}

export interface FraudCheckResult {
  blocked: boolean
  reason?: string
  risk_score: number
  risk_factors: string[]
  requires_manual_review: boolean
  requires_identity_verification: boolean
  triggered_rules: string[]
}

// State transition types
export interface StateTransitionInfo {
  current_state: BookingState
  valid_transitions: BookingState[]
  booking_id: number
}

// Dashboard types
export interface UpcomingEvent {
  booking_id: number
  booking_reference: string
  status: BookingState
  total_amount: number
  can_check_in: boolean
  event: Event
  tickets: TicketData[]
}

export interface PastEvent {
  booking_id: number
  booking_reference: string
  status: BookingState
  event: Event
  attended: boolean
}

export interface BookingStatistics {
  total_bookings: number
  total_spent: number
  events_attended: number
  favorite_artists: Array<{
    id: number
    name: string
    booking_count: number
  }>
  member_since: string
}

export interface CustomerDashboard {
  upcoming_events: UpcomingEvent[]
  past_events: PastEvent[]
  statistics: BookingStatistics
  recent_activity: any[]
}

// Error types
export interface BookingError {
  code: string
  message: string
  details?: Record<string, any>
  request_id?: string
}

// API Response wrapper
export interface BookingAPIResponse<T> {
  data: T
  message?: string
  error?: BookingError
}