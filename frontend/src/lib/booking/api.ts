/**
 * Booking API Service
 * 
 * Consolidated API interface for all booking-related operations
 * Requirements: 6.2 - Organize ticket booking functionality
 */

import { apiClient, APIResponse } from '../api-client'
import type {
  BookingData,
  CreateBookingRequest,
  PaymentCallbackRequest,
  CancelBookingRequest,
  BookingAvailabilityCheck,
  StateTransitionInfo,
  CustomerDashboard,
  UpcomingEvent,
  PastEvent
} from './types'

/**
 * Booking API Service Class
 * 
 * Provides a clean, organized interface for all booking-related API operations
 */
class BookingAPIService {
  // Core booking operations
  async createBooking(data: CreateBookingRequest): Promise<APIResponse<BookingData>> {
    return apiClient.post('/bookings', data)
  }

  async getBooking(bookingId: number): Promise<APIResponse<BookingData>> {
    return apiClient.get(`/bookings/${bookingId}`)
  }

  async getBookingByReference(reference: string): Promise<APIResponse<BookingData>> {
    return apiClient.get(`/bookings/reference/${reference}`)
  }

  async getEventBookings(eventId: number): Promise<APIResponse<BookingData[]>> {
    return apiClient.get(`/bookings?event_id=${eventId}`)
  }

  async getAllBookings(filters?: {
    state?: string
    from_date?: string
    to_date?: string
    buyer_email?: string
  }): Promise<APIResponse<BookingData[]>> {
    const params = new URLSearchParams()
    if (filters) {
      Object.entries(filters).forEach(([key, value]) => {
        if (value) params.append(key, value)
      })
    }
    const queryString = params.toString()
    return apiClient.get(`/bookings${queryString ? `?${queryString}` : ''}`)
  }

  // Booking state management
  async initiatePayment(bookingId: number): Promise<APIResponse<BookingData>> {
    return apiClient.post(`/bookings/${bookingId}/initiate-payment`)
  }

  async processPaymentCallback(data: PaymentCallbackRequest): Promise<APIResponse<BookingData>> {
    return apiClient.post('/bookings/payment-callback', data)
  }

  async confirmBooking(bookingId: number): Promise<APIResponse<BookingData>> {
    return apiClient.post(`/bookings/${bookingId}/confirm`)
  }

  async completeBooking(bookingId: number): Promise<APIResponse<{
    booking: BookingData
    tickets: any[]
  }>> {
    return apiClient.post(`/bookings/${bookingId}/complete`)
  }

  async cancelBooking(bookingId: number, data: CancelBookingRequest): Promise<APIResponse<BookingData>> {
    return apiClient.post(`/bookings/${bookingId}/cancel`, data)
  }

  // Booking validation and availability
  async checkAvailability(eventId: number, requestedTickets: number, customerId?: number): Promise<APIResponse<BookingAvailabilityCheck>> {
    return apiClient.post('/bookings/check-availability', {
      event_id: eventId,
      requested_tickets: requestedTickets,
      customer_id: customerId
    })
  }

  async getStateTransitions(bookingId: number): Promise<APIResponse<StateTransitionInfo>> {
    return apiClient.get(`/bookings/${bookingId}/state-transitions`)
  }

  // Customer-facing booking operations
  async getCustomerDashboard(): Promise<APIResponse<CustomerDashboard>> {
    return apiClient.get('/customer/dashboard')
  }

  async getUpcomingBookings(): Promise<APIResponse<UpcomingEvent[]>> {
    return apiClient.get('/customer/bookings/upcoming')
  }

  async getPastBookings(): Promise<APIResponse<PastEvent[]>> {
    return apiClient.get('/customer/bookings/past')
  }

  // Ticket operations
  async downloadTicket(bookingId: number): Promise<APIResponse<Blob>> {
    return apiClient.get(`/customer/bookings/${bookingId}/ticket`, {
      responseType: 'blob'
    })
  }

  async downloadTicketPDF(bookingId: number): Promise<APIResponse<Blob>> {
    return apiClient.get(`/bookings/${bookingId}/tickets/download`, {
      responseType: 'blob'
    })
  }

  async validateTicket(ticketReference: string): Promise<APIResponse<{
    valid: boolean
    ticket?: any
    message?: string
  }>> {
    return apiClient.post('/tickets/validate', {
      ticket_reference: ticketReference
    })
  }

  // Booking analytics and reporting (admin/staff)
  async getBookingAnalytics(eventId?: number, dateRange?: {
    from: string
    to: string
  }): Promise<APIResponse<any>> {
    const params = new URLSearchParams()
    if (eventId) params.append('event_id', eventId.toString())
    if (dateRange) {
      params.append('from_date', dateRange.from)
      params.append('to_date', dateRange.to)
    }
    const queryString = params.toString()
    return apiClient.get(`/admin/bookings/analytics${queryString ? `?${queryString}` : ''}`)
  }

  async getCapacityReport(eventId: number): Promise<APIResponse<any>> {
    return apiClient.get(`/admin/events/${eventId}/capacity-report`)
  }

  // Fraud and security operations (admin only)
  async getFraudAlerts(eventId?: number): Promise<APIResponse<any[]>> {
    const url = eventId ? `/admin/fraud/alerts?event_id=${eventId}` : '/admin/fraud/alerts'
    return apiClient.get(url)
  }

  async reviewBooking(bookingId: number, action: 'approve' | 'reject', reason?: string): Promise<APIResponse<BookingData>> {
    return apiClient.post(`/admin/bookings/${bookingId}/review`, {
      action,
      reason
    })
  }

  // Utility methods for common booking patterns
  async withBookingValidation<T>(
    operation: () => Promise<APIResponse<T>>,
    bookingId: number
  ): Promise<T | null> {
    try {
      // First check if booking exists and is in valid state
      await this.getBooking(bookingId)
      const response = await operation()
      return response.data
    } catch (error: any) {
      console.error(`Booking operation failed for booking ${bookingId}:`, error)
      return null
    }
  }

  async withCapacityCheck<T>(
    operation: () => Promise<APIResponse<T>>,
    eventId: number,
    requestedTickets: number
  ): Promise<T | null> {
    try {
      // Check availability first
      const availability = await this.checkAvailability(eventId, requestedTickets)
      if (!availability.data.can_book) {
        throw new Error(availability.data.reason || 'Booking not available')
      }
      
      const response = await operation()
      return response.data
    } catch (error: any) {
      console.error(`Capacity check failed for event ${eventId}:`, error)
      return null
    }
  }

  // Batch operations
  async batchCancelBookings(bookingIds: number[], reason: string): Promise<APIResponse<{
    successful: number[]
    failed: Array<{ id: number; error: string }>
  }>> {
    return apiClient.post('/admin/bookings/batch-cancel', {
      booking_ids: bookingIds,
      reason
    })
  }

  async batchUpdateBookingStatus(bookingIds: number[], status: string): Promise<APIResponse<{
    successful: number[]
    failed: Array<{ id: number; error: string }>
  }>> {
    return apiClient.post('/admin/bookings/batch-update-status', {
      booking_ids: bookingIds,
      status
    })
  }
}

// Create and export the booking API instance
export const bookingAPI = new BookingAPIService()

// Export the class for creating custom instances
export { BookingAPIService }

// Legacy compatibility - re-export booking-related methods from eventManagementAPI
export const legacyBookingAPI = {
  downloadTicket: (bookingId: number) => bookingAPI.downloadTicket(bookingId),
  downloadTicketPDF: (bookingId: number) => bookingAPI.downloadTicketPDF(bookingId),
  getCustomerDashboard: () => bookingAPI.getCustomerDashboard(),
}