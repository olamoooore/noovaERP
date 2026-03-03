/**
 * Consolidated Booking Module for EventManagement
 * 
 * This module organizes all booking-related functionality into cohesive units,
 * ensuring isolation from other business logic while maintaining existing behavior.
 * 
 * Requirements: 6.2 - Organize ticket booking functionality
 */

// Re-export all booking-related functionality from organized modules
export * from './types'
export * from './api'
export * from './hooks'
export * from './utils'
export * from './constants'