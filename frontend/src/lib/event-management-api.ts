import { apiClient, APIResponse } from './api-client'

// EventManagement specific types
export interface Event {
  id: number
  name: string
  description?: string
  start_date: string
  end_date: string
  status: 'draft' | 'published' | 'active' | 'completed' | 'cancelled'
  venue?: string
  capacity?: number
  created_at: string
  updated_at: string
}

export interface StaffAssignment {
  id: number
  event_id: number
  user_id: number
  role: string
  status: 'assigned' | 'confirmed' | 'completed' | 'cancelled'
  assigned_at: string
  user?: {
    id: number
    name: string
    email: string
  }
}

export interface PerformanceData {
  user_id: number
  user_name: string
  events_completed: number
  average_rating: number
  total_hours: number
  on_time_percentage: number
}

export interface DashboardData {
  event: Event
  active_staff: number
  total_attendees: number
  incidents: IncidentData[]
  metrics: {
    check_ins: number
    check_outs: number
    active_sessions: number
  }
}

export interface IncidentData {
  id: number
  event_id: number
  title: string
  description: string
  severity: 'low' | 'medium' | 'high' | 'critical'
  status: 'open' | 'in_progress' | 'resolved' | 'closed'
  reported_by: string
  reported_at: string
  resolved_at?: string
}

export interface CustomerDashboard {
  upcoming_events: Event[]
  bookings: BookingData[]
  tickets: TicketData[]
}

export interface BookingData {
  id: number
  event_id: number
  status: 'pending' | 'confirmed' | 'cancelled'
  booking_date: string
  total_amount: number
}

export interface TicketData {
  id: number
  booking_id: number
  ticket_type: string
  qr_code: string
  status: 'valid' | 'used' | 'expired' | 'cancelled'
}

export interface FeedbackData {
  id?: number
  event_id: number
  rating: number
  comment: string
  category: string
  is_anonymous: boolean
  // Extended properties for comprehensive feedback system
  event?: {
    id: number
    title: string
    start_date: string
    venue: {
      name: string
    }
  }
  feedback_type?: string
  overall_rating?: number
  venue_rating?: number
  organization_rating?: number
  value_rating?: number
  title?: string
  media_attachments?: Array<{
    type: string
    original_name: string
    path: string
  }>
  status?: string
  created_at?: string
  helpful_votes?: number
  total_votes?: number
  is_featured?: boolean
}

export interface AssignmentData {
  user_id: number
  role: string
  notes?: string
}

export interface BroadcastData {
  message: string
  priority: 'low' | 'medium' | 'high'
  target_roles?: string[]
}

/**
 * EventManagement API Service
 * Provides a clean interface for all EventManagement-related API calls
 */
class EventManagementAPI {
  // Staff Management endpoints
  async getStaffEvents(): Promise<APIResponse<Event[]>> {
    return apiClient.get('/event-management/events')
  }

  async getStaffAssignments(eventId: number): Promise<APIResponse<StaffAssignment[]>> {
    return apiClient.get(`/event-management/staff/events/${eventId}/assignments`)
  }

  async getPerformanceReport(): Promise<APIResponse<PerformanceData[]>> {
    return apiClient.get('/event-management/staff/performance-report')
  }

  async assignStaff(eventId: number, data: AssignmentData): Promise<APIResponse<void>> {
    return apiClient.post(`/event-management/staff/events/${eventId}/assign`, data)
  }

  async updateStaffStatus(assignmentId: number, status: string): Promise<APIResponse<void>> {
    return apiClient.patch(`/event-management/staff/assignments/${assignmentId}/status`, { status })
  }

  // Live Operations endpoints
  async getOperationsDashboard(eventId: number): Promise<APIResponse<DashboardData>> {
    return apiClient.get(`/event-management/event-operations/dashboard/${eventId}`)
  }

  async reportIncident(data: Omit<IncidentData, 'id' | 'reported_at'>): Promise<APIResponse<IncidentData>> {
    return apiClient.post('/event-management/incidents', data)
  }

  async updateIncident(incidentId: number, action: string): Promise<APIResponse<void>> {
    return apiClient.patch(`/event-management/incidents/${incidentId}`, { action })
  }

  async sendBroadcast(eventId: number, data: BroadcastData): Promise<APIResponse<void>> {
    return apiClient.post(`/event-management/events/${eventId}/broadcast`, data)
  }

  // Customer Dashboard endpoints
  async getCustomerDashboard(): Promise<APIResponse<CustomerDashboard>> {
    return apiClient.get('/customer/dashboard')
  }

  // Admin booking actions
  async cancelBooking(bookingId: number, reason: string): Promise<APIResponse<any>> {
    return apiClient.post(`/event-management/bookings/${bookingId}/cancel`, { reason })
  }

  async refundBooking(bookingId: number, reason: string): Promise<APIResponse<any>> {
    // Cancelling triggers refund eligibility evaluation server-side
    return apiClient.post(`/event-management/bookings/${bookingId}/cancel`, {
      reason: `REFUND: ${reason}`,
    })
  }

  async resendTicketAdmin(ticketId: number): Promise<APIResponse<any>> {
    return apiClient.post(`/customer/tickets/${ticketId}/resend`, {
      delivery_method: 'email',
      reason: 'Admin resend',
    })
  }

  async downloadTicket(bookingId: number): Promise<APIResponse<Blob>> {
    const response = await apiClient.get(`/customer/bookings/${bookingId}/ticket`, {
      responseType: 'blob'
    })
    return response
  }

  async downloadTicketPDF(bookingId: number): Promise<APIResponse<Blob>> {
    return apiClient.get(`/bookings/${bookingId}/tickets/download`, {
      responseType: 'blob'
    })
  }

  async trackRecommendation(recommendationId: number, interactionType: string): Promise<APIResponse<void>> {
    return apiClient.post(`/customer/dashboard/recommendations/${recommendationId}/track`, {
      interaction_type: interactionType
    })
  }

  // Feedback System endpoints
  async submitFeedback(data: Omit<FeedbackData, 'id'>): Promise<APIResponse<FeedbackData>> {
    return apiClient.post('/customer/feedback', data)
  }

  async submitFeedbackWithFiles(formData: FormData): Promise<APIResponse<FeedbackData>> {
    return apiClient.post('/customer/feedback', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
  }

  async getFeedback(eventId?: number): Promise<APIResponse<FeedbackData[]>> {
    const url = eventId ? `/customer/feedback?event_id=${eventId}` : '/customer/feedback'
    return apiClient.get(url)
  }

  async voteFeedback(feedbackId: number, helpful: boolean): Promise<APIResponse<void>> {
    return apiClient.post(`/customer/feedback/${feedbackId}/vote`, { helpful })
  }

  async getFeedbackStats(eventId: number): Promise<APIResponse<any>> {
    return apiClient.get(`/customer/feedback/stats/${eventId}`)
  }

  // Additional feedback endpoints
  async getFeedbackTypes(): Promise<APIResponse<any>> {
    return apiClient.get('/customer/feedback/types')
  }

  async getRatingCategories(): Promise<APIResponse<any>> {
    return apiClient.get('/customer/feedback/rating-categories')
  }

  async getMyFeedback(): Promise<APIResponse<FeedbackData[]>> {
    return apiClient.get('/customer/feedback/my-feedback')
  }

  async getEventFeedback(eventId: number): Promise<APIResponse<FeedbackData[]>> {
    return apiClient.get(`/customer/feedback?event_id=${eventId}`)
  }

  async getEventFeedbackStats(eventId: number): Promise<APIResponse<any>> {
    return apiClient.get(`/customer/feedback/stats/${eventId}`)
  }

  // Event Management endpoints
  async getEvents(): Promise<APIResponse<Event[]>> {
    return apiClient.get('/event-management/events')
  }

  async getEvent(eventId: number): Promise<APIResponse<Event>> {
    return apiClient.get(`/event-management/events/${eventId}`)
  }

  async createEvent(data: Omit<Event, 'id' | 'created_at' | 'updated_at'>): Promise<APIResponse<Event>> {
    return apiClient.post('/event-management/events', data)
  }

  async updateEvent(eventId: number, data: Partial<Event>): Promise<APIResponse<Event>> {
    return apiClient.put(`/event-management/events/${eventId}`, data)
  }

  async deleteEvent(eventId: number): Promise<APIResponse<void>> {
    return apiClient.delete(`/event-management/events/${eventId}`)
  }

  // Staff scheduling and reporting endpoints
  async getSchedulingReport(eventId: number): Promise<APIResponse<any>> {
    return apiClient.get(`/event-management/staff/events/${eventId}/scheduling-report`)
  }

  // Emergency broadcast endpoint
  async sendEmergencyBroadcast(eventId: number, message: string): Promise<APIResponse<void>> {
    return apiClient.post(`/event-management/event-operations/emergency-broadcast/${eventId}`, { message })
  }

  // Utility methods for common patterns
  async withErrorHandling<T>(
    operation: () => Promise<APIResponse<T>>,
    customErrorMessage?: string
  ): Promise<T | null> {
    try {
      const response = await operation()
      return response.data
    } catch (error: any) {
      if (customErrorMessage) {
        console.error(customErrorMessage, error)
      }
      return null
    }
  }

  async withLoadingState<T>(
    operation: () => Promise<APIResponse<T>>,
    setLoading: (loading: boolean) => void
  ): Promise<T | null> {
    setLoading(true)
    try {
      const response = await operation()
      return response.data
    } catch (error) {
      return null
    } finally {
      setLoading(false)
    }
  }
}

// Create and export the EventManagement API instance
export const eventManagementAPI = new EventManagementAPI()

// Export the class for creating custom instances
export { EventManagementAPI }