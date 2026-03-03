/**
 * Consolidated Bidding Module for EventManagement
 * 
 * This module organizes all bidding-related functionality into cohesive units,
 * ensuring isolation from other business logic while maintaining existing behavior.
 * 
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

// Re-export all bidding-related functionality from organized modules
export * from './types'
export * from './api'
export * from './hooks'
export * from './utils'
export * from './constants'