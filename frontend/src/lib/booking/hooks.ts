/**
 * Booking React Hooks
 * 
 * Consolidated React Query hooks for all booking-related operations
 * Requirements: 6.2 - Organize ticket booking functionality
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from '@/hooks/use-toast'
import { bookingAPI } from './api'
import type {
  CreateBookingRequest,
  PaymentCallbackRequest,
  CancelBookingRequest
} from './types'

// Query keys for consistent cache management
export const bookingQueryKeys = {
  all: ['bookings'] as const,
  lists: () => [...bookingQueryKeys.all, 'list'] as const,
  list: (filters: Record<string, any>) => [...bookingQueryKeys.lists(), filters] as const,
  details: () => [...bookingQueryKeys.all, 'detail'] as const,
  detail: (id: number) => [...bookingQueryKeys.details(), id] as const,
  reference: (reference: string) => [...bookingQueryKeys.all, 'reference', reference] as const,
  availability: (eventId: number, tickets: number) => [...bookingQueryKeys.all, 'availability', eventId, tickets] as const,
  stateTransitions: (id: number) => [...bookingQueryKeys.detail(id), 'transitions'] as const,
  customerDashboard: () => ['customer', 'dashboard'] as const,
  upcomingBookings: () => ['customer', 'bookings', 'upcoming'] as const,
  pastBookings: () => ['customer', 'bookings', 'past'] as const,
  analytics: (eventId?: number) => ['admin', 'bookings', 'analytics', eventId] as const,
}

// Core booking queries
export const useBooking = (bookingId: number) => {
  return useQuery({
    queryKey: bookingQueryKeys.detail(bookingId),
    queryFn: () => bookingAPI.getBooking(bookingId),
    enabled: !!bookingId,
    staleTime: 30 * 1000, // 30 seconds
  })
}

export const useBookingByReference = (reference: string) => {
  return useQuery({
    queryKey: bookingQueryKeys.reference(reference),
    queryFn: () => bookingAPI.getBookingByReference(reference),
    enabled: !!reference,
    staleTime: 30 * 1000,
  })
}

export const useEventBookings = (eventId: number) => {
  return useQuery({
    queryKey: bookingQueryKeys.list({ event_id: eventId }),
    queryFn: () => bookingAPI.getEventBookings(eventId),
    enabled: !!eventId,
    staleTime: 60 * 1000, // 1 minute
  })
}

export const useAllBookings = (filters?: {
  state?: string
  from_date?: string
  to_date?: string
  buyer_email?: string
}) => {
  return useQuery({
    queryKey: bookingQueryKeys.list(filters || {}),
    queryFn: () => bookingAPI.getAllBookings(filters),
    staleTime: 60 * 1000,
  })
}

// Booking availability and validation
export const useBookingAvailability = (eventId: number, requestedTickets: number, customerId?: number) => {
  return useQuery({
    queryKey: bookingQueryKeys.availability(eventId, requestedTickets),
    queryFn: () => bookingAPI.checkAvailability(eventId, requestedTickets, customerId),
    enabled: !!eventId && requestedTickets > 0,
    staleTime: 10 * 1000, // 10 seconds - short cache for availability
  })
}

export const useStateTransitions = (bookingId: number) => {
  return useQuery({
    queryKey: bookingQueryKeys.stateTransitions(bookingId),
    queryFn: () => bookingAPI.getStateTransitions(bookingId),
    enabled: !!bookingId,
    staleTime: 60 * 1000,
  })
}

// Customer dashboard queries
export const useCustomerDashboard = () => {
  return useQuery({
    queryKey: bookingQueryKeys.customerDashboard(),
    queryFn: () => bookingAPI.getCustomerDashboard(),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useUpcomingBookings = () => {
  return useQuery({
    queryKey: bookingQueryKeys.upcomingBookings(),
    queryFn: () => bookingAPI.getUpcomingBookings(),
    staleTime: 2 * 60 * 1000, // 2 minutes
  })
}

export const usePastBookings = () => {
  return useQuery({
    queryKey: bookingQueryKeys.pastBookings(),
    queryFn: () => bookingAPI.getPastBookings(),
    staleTime: 10 * 60 * 1000, // 10 minutes
  })
}

// Booking mutations
export const useCreateBooking = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: CreateBookingRequest) => bookingAPI.createBooking(data),
    onSuccess: (response, variables) => {
      // Invalidate relevant queries
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.availability(variables.event_id, variables.attendees.length) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.customerDashboard() })
      
      toast({
        title: "Booking Created",
        description: `Booking ${response.data.booking_reference} created successfully`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Booking Failed",
        description: error.userMessage || "Failed to create booking",
        variant: "destructive",
      })
    }
  })
}

export const useInitiatePayment = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (bookingId: number) => bookingAPI.initiatePayment(bookingId),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(response.data.id) })
      
      toast({
        title: "Payment Initiated",
        description: "Payment process has been started",
      })
    },
    onError: (error: any) => {
      toast({
        title: "Payment Failed",
        description: error.userMessage || "Failed to initiate payment",
        variant: "destructive",
      })
    }
  })
}

export const useProcessPaymentCallback = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (data: PaymentCallbackRequest) => bookingAPI.processPaymentCallback(data),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.reference(response.data.booking_reference) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(response.data.id) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.customerDashboard() })
      
      if (response.data.state === 'confirmed') {
        toast({
          title: "Payment Confirmed",
          description: "Your booking has been confirmed",
        })
      }
    },
    onError: (error: any) => {
      toast({
        title: "Payment Processing Failed",
        description: error.userMessage || "Failed to process payment",
        variant: "destructive",
      })
    }
  })
}

export const useConfirmBooking = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (bookingId: number) => bookingAPI.confirmBooking(bookingId),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(response.data.id) })
      
      toast({
        title: "Booking Confirmed",
        description: `Booking ${response.data.booking_reference} confirmed`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Confirmation Failed",
        description: error.userMessage || "Failed to confirm booking",
        variant: "destructive",
      })
    }
  })
}

export const useCompleteBooking = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: (bookingId: number) => bookingAPI.completeBooking(bookingId),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(response.data.booking.id) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.customerDashboard() })
      
      toast({
        title: "Booking Completed",
        description: "Tickets have been generated successfully",
      })
    },
    onError: (error: any) => {
      toast({
        title: "Completion Failed",
        description: error.userMessage || "Failed to complete booking",
        variant: "destructive",
      })
    }
  })
}

export const useCancelBooking = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ bookingId, data }: { bookingId: number; data: CancelBookingRequest }) => 
      bookingAPI.cancelBooking(bookingId, data),
    onSuccess: (response) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(response.data.id) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.lists() })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.customerDashboard() })
      
      toast({
        title: "Booking Cancelled",
        description: `Booking ${response.data.booking_reference} has been cancelled`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Cancellation Failed",
        description: error.userMessage || "Failed to cancel booking",
        variant: "destructive",
      })
    }
  })
}

// Ticket operations
export const useDownloadTicket = () => {
  return useMutation({
    mutationFn: (bookingId: number) => bookingAPI.downloadTicket(bookingId),
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
      
      toast({
        title: "Ticket Downloaded",
        description: "Your ticket has been downloaded successfully",
      })
    },
    onError: (error: any) => {
      toast({
        title: "Download Failed",
        description: error.userMessage || "Failed to download ticket",
        variant: "destructive",
      })
    }
  })
}

export const useValidateTicket = () => {
  return useMutation({
    mutationFn: (ticketReference: string) => bookingAPI.validateTicket(ticketReference),
    onSuccess: (response) => {
      if (response.data.valid) {
        toast({
          title: "Ticket Valid",
          description: "Ticket validation successful",
        })
      } else {
        toast({
          title: "Invalid Ticket",
          description: response.data.message || "Ticket is not valid",
          variant: "destructive",
        })
      }
    },
    onError: (error: any) => {
      toast({
        title: "Validation Failed",
        description: error.userMessage || "Failed to validate ticket",
        variant: "destructive",
      })
    }
  })
}

// Admin/Staff operations
export const useBookingAnalytics = (eventId?: number, dateRange?: { from: string; to: string }) => {
  return useQuery({
    queryKey: bookingQueryKeys.analytics(eventId),
    queryFn: () => bookingAPI.getBookingAnalytics(eventId, dateRange),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}

export const useReviewBooking = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: ({ bookingId, action, reason }: { 
      bookingId: number
      action: 'approve' | 'reject'
      reason?: string 
    }) => bookingAPI.reviewBooking(bookingId, action, reason),
    onSuccess: (response, variables) => {
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.detail(variables.bookingId) })
      queryClient.invalidateQueries({ queryKey: bookingQueryKeys.lists() })
      
      toast({
        title: `Booking ${variables.action === 'approve' ? 'Approved' : 'Rejected'}`,
        description: `Booking ${response.data.booking_reference} has been ${variables.action}d`,
      })
    },
    onError: (error: any) => {
      toast({
        title: "Review Failed",
        description: error.userMessage || "Failed to review booking",
        variant: "destructive",
      })
    }
  })
}

// Convenience hooks for common patterns
export const useBookingWithTransitions = (bookingId: number) => {
  const bookingQuery = useBooking(bookingId)
  const transitionsQuery = useStateTransitions(bookingId)
  
  return {
    booking: bookingQuery.data?.data,
    transitions: transitionsQuery.data?.data,
    isLoading: bookingQuery.isLoading || transitionsQuery.isLoading,
    error: bookingQuery.error || transitionsQuery.error,
    refetch: () => {
      bookingQuery.refetch()
      transitionsQuery.refetch()
    }
  }
}

export const useBookingOperations = (bookingId: number) => {
  const initiatePayment = useInitiatePayment()
  const confirmBooking = useConfirmBooking()
  const completeBooking = useCompleteBooking()
  const cancelBooking = useCancelBooking()
  
  return {
    initiatePayment: () => initiatePayment.mutate(bookingId),
    confirmBooking: () => confirmBooking.mutate(bookingId),
    completeBooking: () => completeBooking.mutate(bookingId),
    cancelBooking: (reason: string) => cancelBooking.mutate({ bookingId, data: { reason } }),
    isLoading: initiatePayment.isPending || confirmBooking.isPending || 
               completeBooking.isPending || cancelBooking.isPending,
  }
}