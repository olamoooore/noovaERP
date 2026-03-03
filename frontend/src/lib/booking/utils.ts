/**
 * Booking Utility Functions
 * 
 * Consolidated utility functions for booking-related operations
 * Requirements: 6.2 - Organize ticket booking functionality
 */

import type { BookingData, BookingState, TicketStatus, Event } from './types'

// Booking state utilities
export const getBookingStateColor = (state: BookingState): string => {
  const stateColors: Record<BookingState, string> = {
    'new': 'gray',
    'pending': 'yellow',
    'confirmed': 'blue',
    'booked': 'green',
    'cancelled': 'red',
  }
  return stateColors[state] || 'gray'
}

export const getBookingStateLabel = (state: BookingState): string => {
  const stateLabels: Record<BookingState, string> = {
    'new': 'New',
    'pending': 'Payment Pending',
    'confirmed': 'Confirmed',
    'booked': 'Completed',
    'cancelled': 'Cancelled',
  }
  return stateLabels[state] || state
}

export const isBookingActive = (booking: BookingData): boolean => {
  return ['pending', 'confirmed', 'booked'].includes(booking.state)
}

export const isBookingCancellable = (booking: BookingData): boolean => {
  return ['new', 'pending', 'confirmed'].includes(booking.state)
}

export const isBookingRefundable = (booking: BookingData): boolean => {
  if (!booking.event?.start_date) return false
  
  const eventDate = new Date(booking.event.start_date)
  const now = new Date()
  const hoursBeforeEvent = (eventDate.getTime() - now.getTime()) / (1000 * 60 * 60)
  
  // Refundable if cancelled more than 24 hours before event
  return hoursBeforeEvent > 24 && booking.state !== 'cancelled'
}

// Ticket utilities
export const getTicketStatusColor = (status: TicketStatus): string => {
  const statusColors: Record<TicketStatus, string> = {
    'valid': 'green',
    'used': 'blue',
    'expired': 'orange',
    'cancelled': 'red',
  }
  return statusColors[status] || 'gray'
}

export const getTicketStatusLabel = (status: TicketStatus): string => {
  const statusLabels: Record<TicketStatus, string> = {
    'valid': 'Valid',
    'used': 'Used',
    'expired': 'Expired',
    'cancelled': 'Cancelled',
  }
  return statusLabels[status] || status
}

// Event utilities
export const canBookEvent = (event: Event): boolean => {
  const now = new Date()
  const eventStart = new Date(event.start_date)
  
  return event.status === 'published' && 
         eventStart > now &&
         (event.available_capacity === undefined || event.available_capacity > 0)
}

export const isEventSoldOut = (event: Event): boolean => {
  return event.available_capacity !== undefined && event.available_capacity <= 0
}

export const getEventAvailabilityStatus = (event: Event): {
  status: 'available' | 'sold_out' | 'ended' | 'not_published'
  message: string
} => {
  const now = new Date()
  const eventStart = new Date(event.start_date)
  
  if (event.status !== 'published') {
    return { status: 'not_published', message: 'Event not available for booking' }
  }
  
  if (eventStart <= now) {
    return { status: 'ended', message: 'Event has already started' }
  }
  
  if (isEventSoldOut(event)) {
    return { status: 'sold_out', message: 'Event is sold out' }
  }
  
  return { status: 'available', message: 'Available for booking' }
}

// Booking validation utilities
export const validateBookingData = (data: any): { isValid: boolean; errors: string[] } => {
  const errors: string[] = []
  
  if (!data.event_id) {
    errors.push('Event ID is required')
  }
  
  if (!data.buyer_name?.trim()) {
    errors.push('Buyer name is required')
  }
  
  if (!data.buyer_email?.trim()) {
    errors.push('Buyer email is required')
  } else if (!isValidEmail(data.buyer_email)) {
    errors.push('Valid email address is required')
  }
  
  if (!data.buyer_phone?.trim()) {
    errors.push('Buyer phone is required')
  }
  
  if (!data.attendees || !Array.isArray(data.attendees) || data.attendees.length === 0) {
    errors.push('At least one attendee is required')
  } else {
    data.attendees.forEach((attendee: any, index: number) => {
      if (!attendee.attendee_name?.trim()) {
        errors.push(`Attendee ${index + 1} name is required`)
      }
      if (!attendee.ticket_type_id) {
        errors.push(`Attendee ${index + 1} ticket type is required`)
      }
    })
  }
  
  if (typeof data.total_amount !== 'number' || data.total_amount <= 0) {
    errors.push('Valid total amount is required')
  }
  
  if (!data.consent_pdpa) {
    errors.push('PDPA consent is required')
  }
  
  return {
    isValid: errors.length === 0,
    errors
  }
}

// Formatting utilities
export const formatBookingReference = (reference: string): string => {
  // Format booking reference for display (e.g., BK12345678 -> BK-1234-5678)
  if (reference.length >= 8) {
    return `${reference.slice(0, 2)}-${reference.slice(2, 6)}-${reference.slice(6)}`
  }
  return reference
}

export const formatCurrency = (amount: number, currency: string = 'USD'): string => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
  }).format(amount)
}

export const formatBookingDate = (dateString: string): string => {
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

// Calculation utilities
export const calculateBookingTotal = (attendees: any[], ticketTypes: any[]): number => {
  return attendees.reduce((total, attendee) => {
    const ticketType = ticketTypes.find(tt => tt.id === attendee.ticket_type_id)
    return total + (ticketType?.price || 0)
  }, 0)
}

export const calculateRefundAmount = (booking: BookingData): number => {
  if (!isBookingRefundable(booking)) return 0
  
  // Simple refund calculation - could be enhanced with refund policies
  const eventDate = new Date(booking.event?.start_date || '')
  const now = new Date()
  const hoursBeforeEvent = (eventDate.getTime() - now.getTime()) / (1000 * 60 * 60)
  
  if (hoursBeforeEvent > 72) {
    return booking.total_amount // Full refund
  } else if (hoursBeforeEvent > 24) {
    return booking.total_amount * 0.8 // 80% refund
  } else {
    return 0 // No refund
  }
}

// Search and filter utilities
export const filterBookings = (bookings: BookingData[], filters: {
  state?: BookingState
  search?: string
  dateFrom?: string
  dateTo?: string
}): BookingData[] => {
  return bookings.filter(booking => {
    // State filter
    if (filters.state && booking.state !== filters.state) {
      return false
    }
    
    // Search filter (booking reference, buyer name, buyer email)
    if (filters.search) {
      const searchTerm = filters.search.toLowerCase()
      const searchableText = [
        booking.booking_reference,
        booking.buyer_name,
        booking.buyer_email,
        booking.event?.title || ''
      ].join(' ').toLowerCase()
      
      if (!searchableText.includes(searchTerm)) {
        return false
      }
    }
    
    // Date range filter
    if (filters.dateFrom) {
      const bookingDate = new Date(booking.created_at)
      const fromDate = new Date(filters.dateFrom)
      if (bookingDate < fromDate) {
        return false
      }
    }
    
    if (filters.dateTo) {
      const bookingDate = new Date(booking.created_at)
      const toDate = new Date(filters.dateTo)
      if (bookingDate > toDate) {
        return false
      }
    }
    
    return true
  })
}

export const sortBookings = (bookings: BookingData[], sortBy: 'date' | 'amount' | 'state' | 'reference', order: 'asc' | 'desc' = 'desc'): BookingData[] => {
  return [...bookings].sort((a, b) => {
    let comparison = 0
    
    switch (sortBy) {
      case 'date':
        comparison = new Date(a.created_at).getTime() - new Date(b.created_at).getTime()
        break
      case 'amount':
        comparison = a.total_amount - b.total_amount
        break
      case 'state':
        comparison = a.state.localeCompare(b.state)
        break
      case 'reference':
        comparison = a.booking_reference.localeCompare(b.booking_reference)
        break
    }
    
    return order === 'asc' ? comparison : -comparison
  })
}

// Validation helpers
export const isValidEmail = (email: string): boolean => {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(email)
}

export const isValidPhone = (phone: string): boolean => {
  // Basic phone validation - can be enhanced based on requirements
  const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/
  return phoneRegex.test(phone.replace(/[\s\-\(\)]/g, ''))
}

// Analytics utilities
export const calculateBookingMetrics = (bookings: BookingData[]) => {
  const totalBookings = bookings.length
  const totalRevenue = bookings
    .filter(b => b.state !== 'cancelled')
    .reduce((sum, b) => sum + b.total_amount, 0)
  
  const stateDistribution = bookings.reduce((acc, booking) => {
    acc[booking.state] = (acc[booking.state] || 0) + 1
    return acc
  }, {} as Record<BookingState, number>)
  
  const averageBookingValue = totalRevenue / Math.max(totalBookings, 1)
  const cancellationRate = (stateDistribution.cancelled || 0) / Math.max(totalBookings, 1)
  
  return {
    totalBookings,
    totalRevenue,
    averageBookingValue,
    cancellationRate,
    stateDistribution,
  }
}

// Export all utilities as a single object for easier importing
export const BookingUtils = {
  // State utilities
  getBookingStateColor,
  getBookingStateLabel,
  isBookingActive,
  isBookingCancellable,
  isBookingRefundable,
  
  // Ticket utilities
  getTicketStatusColor,
  getTicketStatusLabel,
  
  // Event utilities
  canBookEvent,
  isEventSoldOut,
  getEventAvailabilityStatus,
  
  // Validation utilities
  validateBookingData,
  isValidEmail,
  isValidPhone,
  
  // Formatting utilities
  formatBookingReference,
  formatCurrency,
  formatBookingDate,
  
  // Calculation utilities
  calculateBookingTotal,
  calculateRefundAmount,
  
  // Search and filter utilities
  filterBookings,
  sortBookings,
  
  // Analytics utilities
  calculateBookingMetrics,
}