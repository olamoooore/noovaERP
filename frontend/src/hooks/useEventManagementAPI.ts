import { useState, useCallback } from 'react'
import { eventManagementAPI, Event, StaffAssignment, PerformanceData, DashboardData, IncidentData, CustomerDashboard, FeedbackData } from '@/lib/event-management-api'
import { CategorizedError } from '@/lib/api-client'

// Custom hook for EventManagement API operations
export function useEventManagementAPI() {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<CategorizedError | null>(null)

  const clearError = useCallback(() => {
    setError(null)
  }, [])

  // Staff Management hooks
  const useStaffEvents = () => {
    const [events, setEvents] = useState<Event[]>([])

    const fetchEvents = useCallback(async () => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getStaffEvents()
        setEvents(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [])

    return { events, fetchEvents, loading, error, clearError }
  }

  const useStaffAssignments = (eventId: number) => {
    const [assignments, setAssignments] = useState<StaffAssignment[]>([])

    const fetchAssignments = useCallback(async () => {
      if (!eventId) return
      
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getStaffAssignments(eventId)
        setAssignments(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId])

    const assignStaff = useCallback(async (userId: number, role: string, notes?: string) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.assignStaff(eventId, { user_id: userId, role, notes })
        await fetchAssignments() // Refresh assignments
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId, fetchAssignments])

    const updateStaffStatus = useCallback(async (assignmentId: number, status: string) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.updateStaffStatus(assignmentId, status)
        await fetchAssignments() // Refresh assignments
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchAssignments])

    return { 
      assignments, 
      fetchAssignments, 
      assignStaff, 
      updateStaffStatus, 
      loading, 
      error, 
      clearError 
    }
  }

  const usePerformanceReport = () => {
    const [performanceData, setPerformanceData] = useState<PerformanceData[]>([])

    const fetchPerformanceReport = useCallback(async () => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getPerformanceReport()
        setPerformanceData(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [])

    return { performanceData, fetchPerformanceReport, loading, error, clearError }
  }

  // Live Operations hooks
  const useOperationsDashboard = (eventId: number) => {
    const [dashboardData, setDashboardData] = useState<DashboardData | null>(null)

    const fetchDashboard = useCallback(async () => {
      if (!eventId) return
      
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getOperationsDashboard(eventId)
        setDashboardData(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId])

    const reportIncident = useCallback(async (incidentData: Omit<IncidentData, 'id' | 'reported_at'>) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.reportIncident(incidentData)
        await fetchDashboard() // Refresh dashboard
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchDashboard])

    const updateIncident = useCallback(async (incidentId: number, action: string) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.updateIncident(incidentId, action)
        await fetchDashboard() // Refresh dashboard
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchDashboard])

    const sendBroadcast = useCallback(async (message: string, priority: 'low' | 'medium' | 'high', targetRoles?: string[]) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.sendBroadcast(eventId, { message, priority, target_roles: targetRoles })
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId])

    return { 
      dashboardData, 
      fetchDashboard, 
      reportIncident, 
      updateIncident, 
      sendBroadcast, 
      loading, 
      error, 
      clearError 
    }
  }

  // Customer Dashboard hooks
  const useCustomerDashboard = () => {
    const [customerData, setCustomerData] = useState<CustomerDashboard | null>(null)

    const fetchCustomerDashboard = useCallback(async () => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getCustomerDashboard()
        setCustomerData(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [])

    const downloadTicket = useCallback(async (bookingId: number) => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.downloadTicket(bookingId)
        // Create download link
        const url = window.URL.createObjectURL(response.data as Blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `ticket-${bookingId}.pdf`
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [])

    return { 
      customerData, 
      fetchCustomerDashboard, 
      downloadTicket, 
      loading, 
      error, 
      clearError 
    }
  }

  // Feedback System hooks
  const useFeedbackSystem = (eventId?: number) => {
    const [feedback, setFeedback] = useState<FeedbackData[]>([])
    const [feedbackStats, setFeedbackStats] = useState<any>(null)

    const fetchFeedback = useCallback(async () => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getFeedback(eventId)
        setFeedback(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId])

    const submitFeedback = useCallback(async (feedbackData: Omit<FeedbackData, 'id'>) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.submitFeedback(feedbackData)
        await fetchFeedback() // Refresh feedback list
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchFeedback])

    const voteFeedback = useCallback(async (feedbackId: number, helpful: boolean) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.voteFeedback(feedbackId, helpful)
        await fetchFeedback() // Refresh feedback list
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchFeedback])

    const fetchFeedbackStats = useCallback(async () => {
      if (!eventId) return
      
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getFeedbackStats(eventId)
        setFeedbackStats(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [eventId])

    return { 
      feedback, 
      feedbackStats, 
      fetchFeedback, 
      submitFeedback, 
      voteFeedback, 
      fetchFeedbackStats, 
      loading, 
      error, 
      clearError 
    }
  }

  // Event Management hooks
  const useEvents = () => {
    const [events, setEvents] = useState<Event[]>([])

    const fetchEvents = useCallback(async () => {
      setLoading(true)
      setError(null)
      try {
        const response = await eventManagementAPI.getEvents()
        setEvents(response.data)
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [])

    const createEvent = useCallback(async (eventData: Omit<Event, 'id' | 'created_at' | 'updated_at'>) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.createEvent(eventData)
        await fetchEvents() // Refresh events list
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchEvents])

    const updateEvent = useCallback(async (eventId: number, eventData: Partial<Event>) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.updateEvent(eventId, eventData)
        await fetchEvents() // Refresh events list
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchEvents])

    const deleteEvent = useCallback(async (eventId: number) => {
      setLoading(true)
      setError(null)
      try {
        await eventManagementAPI.deleteEvent(eventId)
        await fetchEvents() // Refresh events list
      } catch (err: any) {
        setError(err)
      } finally {
        setLoading(false)
      }
    }, [fetchEvents])

    return { 
      events, 
      fetchEvents, 
      createEvent, 
      updateEvent, 
      deleteEvent, 
      loading, 
      error, 
      clearError 
    }
  }

  return {
    // Hooks
    useStaffEvents,
    useStaffAssignments,
    usePerformanceReport,
    useOperationsDashboard,
    useCustomerDashboard,
    useFeedbackSystem,
    useEvents,
    
    // Common state
    loading,
    error,
    clearError
  }
}

// Export individual hooks for convenience
export const useStaffEvents = () => useEventManagementAPI().useStaffEvents()
export const useStaffAssignments = (eventId: number) => useEventManagementAPI().useStaffAssignments(eventId)
export const usePerformanceReport = () => useEventManagementAPI().usePerformanceReport()
export const useOperationsDashboard = (eventId: number) => useEventManagementAPI().useOperationsDashboard(eventId)
export const useCustomerDashboard = () => useEventManagementAPI().useCustomerDashboard()
export const useFeedbackSystem = (eventId?: number) => useEventManagementAPI().useFeedbackSystem(eventId)
export const useEvents = () => useEventManagementAPI().useEvents()