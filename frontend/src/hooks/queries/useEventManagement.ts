import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { 
  eventManagementAPI, 
  Event, 
  StaffAssignment, 
  PerformanceData, 
  DashboardData, 
  CustomerDashboard,
  FeedbackData,
  AssignmentData,
  BroadcastData,
  IncidentData
} from '@/lib/event-management-api'

// Query keys factory
export const eventManagementKeys = {
  all: ['eventManagement'] as const,
  
  // Events
  events: () => [...eventManagementKeys.all, 'events'] as const,
  event: (id: number) => [...eventManagementKeys.events(), id] as const,
  staffEvents: () => [...eventManagementKeys.events(), 'staff'] as const,
  
  // Staff
  staff: () => [...eventManagementKeys.all, 'staff'] as const,
  staffAssignments: (eventId: number) => [...eventManagementKeys.staff(), 'assignments', eventId] as const,
  performanceReport: () => [...eventManagementKeys.staff(), 'performance'] as const,
  
  // Operations
  operations: () => [...eventManagementKeys.all, 'operations'] as const,
  operationsDashboard: (eventId: number) => [...eventManagementKeys.operations(), 'dashboard', eventId] as const,
  
  // Customer
  customer: () => [...eventManagementKeys.all, 'customer'] as const,
  customerDashboard: () => [...eventManagementKeys.customer(), 'dashboard'] as const,
  
  // Feedback
  feedback: () => [...eventManagementKeys.all, 'feedback'] as const,
  eventFeedback: (eventId?: number) => eventId 
    ? [...eventManagementKeys.feedback(), 'event', eventId] as const
    : [...eventManagementKeys.feedback(), 'all'] as const,
  feedbackStats: (eventId: number) => [...eventManagementKeys.feedback(), 'stats', eventId] as const,
}

// Helper: Laravel API wraps responses as {data: [...], message: ''}.
// APIClient places the whole body at res.data, so the actual payload is at res.data.data
const unwrap = <T>(res: { data: any }): T => {
  const body = res.data
  // If the body itself is already an array, return as-is
  if (Array.isArray(body)) return body as T
  // Laravel standard wrapper: { data: payload, message: '' }
  if (body && 'data' in body) return body.data as T
  return body as T
}

// ============================================================================
// STAFF MANAGEMENT HOOKS
// ============================================================================

export const useStaffEvents = () => {
  return useQuery({
    queryKey: eventManagementKeys.staffEvents(),
    queryFn: () => eventManagementAPI.getStaffEvents().then(res => unwrap<Event[]>(res)),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useStaffAssignments = (eventId: number) => {
  return useQuery({
    queryKey: eventManagementKeys.staffAssignments(eventId),
    queryFn: () => eventManagementAPI.getStaffAssignments(eventId).then(res => unwrap<StaffAssignment[]>(res)),
    enabled: !!eventId,
    staleTime: 2 * 60 * 1000, // 2 minutes
  })
}

export const usePerformanceReport = () => {
  return useQuery({
    queryKey: eventManagementKeys.performanceReport(),
    queryFn: () => eventManagementAPI.getPerformanceReport().then(res => unwrap<PerformanceData[]>(res)),
    staleTime: 10 * 60 * 1000, // 10 minutes
  })
}

export const useAssignStaff = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ eventId, data }: { eventId: number; data: AssignmentData }) =>
      eventManagementAPI.assignStaff(eventId, data),
    onSuccess: (_, { eventId }) => {
      // Invalidate staff assignments for this event
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.staffAssignments(eventId) })
    },
  })
}

export const useUpdateStaffStatus = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ assignmentId, status }: { assignmentId: number; status: string }) =>
      eventManagementAPI.updateStaffStatus(assignmentId, status),
    onSuccess: () => {
      // Invalidate all staff assignments since we don't know which event this affects
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.staff() })
    },
  })
}

// ============================================================================
// LIVE OPERATIONS HOOKS
// ============================================================================

export const useOperationsDashboard = (eventId: number, options?: { refetchInterval?: number }) => {
  return useQuery({
    queryKey: eventManagementKeys.operationsDashboard(eventId),
    queryFn: () => eventManagementAPI.getOperationsDashboard(eventId).then(res => {
      const raw = unwrap<any>(res);
      // API returns a different shape than the DashboardData interface — normalize here
      // staff_status may be an empty array or an object grouped by role
      const staffByRole = raw?.staff_status ?? {};
      const activeStaff = Array.isArray(staffByRole)
        ? staffByRole.length
        : Object.values(staffByRole as Record<string, any[]>).reduce<number>(
            (acc, arr) => acc + (Array.isArray(arr) ? arr.length : 0), 0
          );
      return {
        event: {
          id: raw?.event_id,
          title: raw?.event_title,
          name: raw?.event_title,
          status: raw?.event_status ?? 'unknown',
          event_date: raw?.event_date,
          start_date: raw?.event_date,
        },
        active_staff: activeStaff,
        total_attendees: raw?.check_in_metrics?.total_tickets ?? 0,
        incidents: raw?.recent_incidents ?? [],
        metrics: {
          check_ins: raw?.check_in_metrics?.checked_in_tickets ?? 0,
          check_outs: 0,
          active_sessions: raw?.check_in_metrics?.pending_check_ins ?? 0,
        },
        // Extra fields passed through for zone/queue panels
        zone_status: raw?.zone_status ?? [],
        queue_status: raw?.queue_status ?? {},
        current_operational_status: raw?.current_operational_status ?? {},
        last_updated: raw?.last_updated,
      } as DashboardData;
    }),
    enabled: !!eventId,
    refetchInterval: options?.refetchInterval || 30000, // 30 seconds default
    staleTime: 30 * 1000, // 30 seconds
  })
}

export const useReportIncident = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: Omit<IncidentData, 'id' | 'reported_at'>) =>
      eventManagementAPI.reportIncident(data),
    onSuccess: (_, variables) => {
      // Invalidate operations dashboard for the event
      queryClient.invalidateQueries({ 
        queryKey: eventManagementKeys.operationsDashboard(variables.event_id) 
      })
    },
  })
}

export const useUpdateIncident = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ incidentId, action }: { incidentId: number; action: string }) =>
      eventManagementAPI.updateIncident(incidentId, action),
    onSuccess: () => {
      // Invalidate all operations dashboards since we don't know which event this affects
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.operations() })
    },
  })
}

export const useSendBroadcast = () => {
  return useMutation({
    mutationFn: ({ eventId, data }: { eventId: number; data: BroadcastData }) =>
      eventManagementAPI.sendBroadcast(eventId, data),
  })
}

// ============================================================================
// CUSTOMER DASHBOARD HOOKS
// ============================================================================

export const useCustomerDashboard = () => {
  return useQuery({
    queryKey: eventManagementKeys.customerDashboard(),
    queryFn: () => eventManagementAPI.getCustomerDashboard().then(res => {
      // API returns { success, customer_id, dashboard: { tickets, upcoming_events, stats } }
      const raw = res.data as any
      const inner = raw?.dashboard ?? raw ?? {}

      // Map booking objects → Event shape (for the Upcoming tab)
      // Use booking id (b.id) as the unique React key via _bookingId
      const toEvent = (b: any) => ({
        id:          b.id,          // booking id — unique per row, used as React key
        _eventId:    b.event_id,    // actual event id for navigation
        name:        b.event_title ?? b.event_name ?? 'Event',
        description: '',
        start_date:  b.event_date ?? b.event_start ?? b.start_date ?? b.created_at,
        end_date:    b.event_date ?? b.end_date ?? '',
        venue:       b.event_venue ?? b.venue,
        status:      b.event_status ?? 'published',
        capacity:    null,
        created_at:  b.created_at,
        updated_at:  b.created_at,
      })

      // Map booking objects → BookingData shape (for My Bookings tab)
      const toBooking = (b: any) => ({
        id:                b.id,
        event_id:          b.event_id,
        status:            b.state ?? b.status ?? 'confirmed',
        booking_date:      b.created_at ?? b.booking_date ?? '',
        total_amount:      b.amount ?? b.total_amount ?? 0,
        currency:          b.currency ?? 'SGD',
        booking_reference: b.booking_reference,
        event_title:       b.event_title,
        event_venue:       b.event_venue,
        event_date:        b.event_date ?? b.event_start,
        buyer_name:        b.buyer_name,
        buyer_email:       b.buyer_email,
        ticket_count:      b.ticket_count ?? (Array.isArray(b.tickets) ? b.tickets.length : 0),
        tickets:           Array.isArray(b.tickets) ? b.tickets : [],
      })

      const upcomingRaw: any[] = inner?.upcoming_events ?? []
      const ticketsRaw: any[]  = inner?.tickets ?? upcomingRaw

      return {
        upcoming_events: upcomingRaw.map(toEvent),
        bookings:        ticketsRaw.map(toBooking),
        tickets:         ticketsRaw.map(toBooking),
      } as CustomerDashboard
    }),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useDownloadTicket = () => {
  return useMutation({
    mutationFn: (bookingId: number) => eventManagementAPI.downloadTicket(bookingId),
    onSuccess: (response, bookingId) => {
      // Create download link
      const url = window.URL.createObjectURL(response.data as Blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `ticket-${bookingId}.pdf`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    },
  })
}

export const useCancelBooking = () => {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ bookingId, reason }: { bookingId: number; reason: string }) =>
      eventManagementAPI.cancelBooking(bookingId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.customerDashboard() })
    },
  })
}

export const useRefundBooking = () => {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ bookingId, reason }: { bookingId: number; reason: string }) =>
      eventManagementAPI.refundBooking(bookingId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.customerDashboard() })
    },
  })
}

// ============================================================================
// FEEDBACK SYSTEM HOOKS
// ============================================================================

export const useFeedback = (eventId?: number) => {
  return useQuery({
    queryKey: eventManagementKeys.eventFeedback(eventId),
    queryFn: () => eventManagementAPI.getFeedback(eventId).then(res => unwrap<FeedbackData[]>(res)),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useFeedbackStats = (eventId: number) => {
  return useQuery({
    queryKey: eventManagementKeys.feedbackStats(eventId),
    queryFn: () => eventManagementAPI.getFeedbackStats(eventId).then(res => unwrap<any>(res)),
    enabled: !!eventId,
    staleTime: 10 * 60 * 1000, // 10 minutes
  })
}

export const useSubmitFeedback = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: Omit<FeedbackData, 'id'>) =>
      eventManagementAPI.submitFeedback(data),
    onSuccess: (_, variables) => {
      // Invalidate feedback queries for the event
      queryClient.invalidateQueries({ 
        queryKey: eventManagementKeys.eventFeedback(variables.event_id) 
      })
      queryClient.invalidateQueries({ 
        queryKey: eventManagementKeys.feedbackStats(variables.event_id) 
      })
    },
  })
}

export const useVoteFeedback = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ feedbackId, helpful }: { feedbackId: number; helpful: boolean }) =>
      eventManagementAPI.voteFeedback(feedbackId, helpful),
    onSuccess: () => {
      // Invalidate all feedback queries since we don't know which event this affects
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.feedback() })
    },
  })
}

// ============================================================================
// EVENT MANAGEMENT HOOKS
// ============================================================================

export const useEvents = () => {
  return useQuery({
    queryKey: eventManagementKeys.events(),
    queryFn: () => eventManagementAPI.getEvents().then(res => unwrap<Event[]>(res)),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useEvent = (eventId: number) => {
  return useQuery({
    queryKey: eventManagementKeys.event(eventId),
    queryFn: () => eventManagementAPI.getEvent(eventId).then(res => unwrap<Event>(res)),
    enabled: !!eventId,
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useCreateEvent = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: Omit<Event, 'id' | 'created_at' | 'updated_at'>) =>
      eventManagementAPI.createEvent(data),
    onSuccess: () => {
      // Invalidate events list
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.events() })
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.staffEvents() })
    },
  })
}

export const useUpdateEvent = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ eventId, data }: { eventId: number; data: Partial<Event> }) =>
      eventManagementAPI.updateEvent(eventId, data),
    onSuccess: (_, { eventId }) => {
      // Invalidate specific event and events list
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.event(eventId) })
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.events() })
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.staffEvents() })
    },
  })
}

export const useDeleteEvent = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (eventId: number) => eventManagementAPI.deleteEvent(eventId),
    onSuccess: (_, eventId) => {
      // Remove specific event from cache and invalidate events list
      queryClient.removeQueries({ queryKey: eventManagementKeys.event(eventId) })
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.events() })
      queryClient.invalidateQueries({ queryKey: eventManagementKeys.staffEvents() })
    },
  })
}