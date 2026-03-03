/**
 * Bidding Utility Functions
 * 
 * Consolidated utility functions for bidding-related operations
 * Requirements: 6.3 - Isolate bid flow processing (if present)
 */

import type { BidData, BidStatus, Event, AcquisitionMode } from './types'

// Bid status utilities
export const getBidStatusColor = (status: BidStatus): string => {
  const statusColors: Record<BidStatus, string> = {
    'pending': 'yellow',
    'accepted': 'green',
    'rejected': 'red',
  }
  return statusColors[status] || 'gray'
}

export const getBidStatusLabel = (status: BidStatus): string => {
  const statusLabels: Record<BidStatus, string> = {
    'pending': 'Pending Review',
    'accepted': 'Accepted',
    'rejected': 'Rejected',
  }
  return statusLabels[status] || status
}

export const isBidActive = (bid: BidData): boolean => {
  return bid.status === 'pending'
}

export const isBidProcessed = (bid: BidData): boolean => {
  return ['accepted', 'rejected'].includes(bid.status)
}

export const canProcessBid = (bid: BidData): boolean => {
  return bid.status === 'pending'
}

// Event bidding utilities
export const canEventAcceptBids = (event: Event): boolean => {
  return event.ticket_acquisition_mode === 'bid' && 
         event.status === 'published'
}

export const isEventBiddingOnly = (event: Event): boolean => {
  return event.ticket_acquisition_mode === 'bid'
}

export const isEventPurchaseOnly = (event: Event): boolean => {
  return event.ticket_acquisition_mode === 'buy'
}

export const getAcquisitionModeLabel = (mode: AcquisitionMode): string => {
  const modeLabels: Record<AcquisitionMode, string> = {
    'buy': 'Direct Purchase',
    'bid': 'Bidding',
  }
  return modeLabels[mode] || mode
}

export const getEventPriceDisplay = (event: Event): string => {
  if (event.ticket_acquisition_mode === 'buy' && event.price) {
    return `$${event.price.toFixed(2)}`
  } else if (event.ticket_acquisition_mode === 'bid' && event.starting_bid) {
    return `$${event.starting_bid.toFixed(2)}+`
  }
  return 'TBD'
}

export const getEventPriceLabel = (event: Event): string => {
  if (event.ticket_acquisition_mode === 'buy') {
    return 'Price'
  } else if (event.ticket_acquisition_mode === 'bid') {
    return 'Starting Bid'
  }
  return 'Price'
}

// Sale window utilities
export const isSaleWindowOpen = (event: Event): boolean => {
  const now = new Date()
  const startDate = event.sale_start_date ? new Date(event.sale_start_date) : null
  const endDate = event.sale_end_date ? new Date(event.sale_end_date) : null
  
  if (startDate && now < startDate) return false
  if (endDate && now > endDate) return false
  
  return true
}

export const getSaleWindowStatus = (event: Event): {
  status: 'not_started' | 'open' | 'closed'
  message: string
} => {
  const now = new Date()
  const startDate = event.sale_start_date ? new Date(event.sale_start_date) : null
  const endDate = event.sale_end_date ? new Date(event.sale_end_date) : null
  
  if (startDate && now < startDate) {
    return {
      status: 'not_started',
      message: `Bidding starts on ${formatDate(startDate)}`
    }
  }
  
  if (endDate && now > endDate) {
    return {
      status: 'closed',
      message: `Bidding ended on ${formatDate(endDate)}`
    }
  }
  
  return {
    status: 'open',
    message: 'Bidding is currently open'
  }
}

// Bid validation utilities
export const validateBidData = (data: any): { isValid: boolean; errors: string[] } => {
  const errors: string[] = []
  
  if (!data.event_id) {
    errors.push('Event ID is required')
  }
  
  if (!data.customer_id) {
    errors.push('Customer ID is required')
  }
  
  if (!data.bid_amount || typeof data.bid_amount !== 'number' || data.bid_amount <= 0) {
    errors.push('Valid bid amount is required')
  }
  
  if (!data.quantity || typeof data.quantity !== 'number' || data.quantity <= 0) {
    errors.push('Valid quantity is required')
  }
  
  return {
    isValid: errors.length === 0,
    errors
  }
}

export const validateBidAmount = (amount: number, startingBid?: number): { isValid: boolean; error?: string } => {
  if (amount <= 0) {
    return { isValid: false, error: 'Bid amount must be greater than zero' }
  }
  
  if (startingBid && amount < startingBid) {
    return { isValid: false, error: `Bid amount must be at least $${startingBid.toFixed(2)}` }
  }
  
  return { isValid: true }
}

// Formatting utilities
export const formatBidAmount = (amount: number): string => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount)
}

export const formatBidDate = (dateString: string): string => {
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

export const formatDate = (date: Date): string => {
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  }).format(date)
}

export const formatDateTime = (date: Date): string => {
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

// Search and filter utilities
export const filterBids = (bids: BidData[], filters: {
  status?: BidStatus
  search?: string
  minAmount?: number
  maxAmount?: number
  dateFrom?: string
  dateTo?: string
}): BidData[] => {
  return bids.filter(bid => {
    // Status filter
    if (filters.status && bid.status !== filters.status) {
      return false
    }
    
    // Search filter (customer name, event name)
    if (filters.search) {
      const searchTerm = filters.search.toLowerCase()
      const searchableText = [
        bid.customer?.name || '',
        bid.customer?.email || '',
        bid.event?.name || '',
        bid.bid_amount.toString()
      ].join(' ').toLowerCase()
      
      if (!searchableText.includes(searchTerm)) {
        return false
      }
    }
    
    // Amount range filter
    if (filters.minAmount && bid.bid_amount < filters.minAmount) {
      return false
    }
    
    if (filters.maxAmount && bid.bid_amount > filters.maxAmount) {
      return false
    }
    
    // Date range filter
    if (filters.dateFrom) {
      const bidDate = new Date(bid.created_at)
      const fromDate = new Date(filters.dateFrom)
      if (bidDate < fromDate) {
        return false
      }
    }
    
    if (filters.dateTo) {
      const bidDate = new Date(bid.created_at)
      const toDate = new Date(filters.dateTo)
      if (bidDate > toDate) {
        return false
      }
    }
    
    return true
  })
}

export const sortBids = (
  bids: BidData[], 
  sortBy: 'created_at' | 'bid_amount' | 'status' | 'customer_name' | 'event_name', 
  order: 'asc' | 'desc' = 'desc'
): BidData[] => {
  return [...bids].sort((a, b) => {
    let comparison = 0
    
    switch (sortBy) {
      case 'created_at':
        comparison = new Date(a.created_at).getTime() - new Date(b.created_at).getTime()
        break
      case 'bid_amount':
        comparison = a.bid_amount - b.bid_amount
        break
      case 'status':
        comparison = a.status.localeCompare(b.status)
        break
      case 'customer_name':
        comparison = (a.customer?.name || '').localeCompare(b.customer?.name || '')
        break
      case 'event_name':
        comparison = (a.event?.name || '').localeCompare(b.event?.name || '')
        break
    }
    
    return order === 'asc' ? comparison : -comparison
  })
}

// Analytics utilities
export const calculateBidMetrics = (bids: BidData[]) => {
  const totalBids = bids.length
  const pendingBids = bids.filter(b => b.status === 'pending').length
  const acceptedBids = bids.filter(b => b.status === 'accepted').length
  const rejectedBids = bids.filter(b => b.status === 'rejected').length
  
  const totalBidAmount = bids.reduce((sum, b) => sum + b.bid_amount, 0)
  const averageBidAmount = totalBidAmount / Math.max(totalBids, 1)
  
  const acceptedBidAmount = bids
    .filter(b => b.status === 'accepted')
    .reduce((sum, b) => sum + b.bid_amount, 0)
  
  const acceptanceRate = acceptedBids / Math.max(totalBids, 1)
  
  const highestBid = Math.max(...bids.map(b => b.bid_amount), 0)
  const lowestBid = Math.min(...bids.map(b => b.bid_amount), 0)
  
  return {
    totalBids,
    pendingBids,
    acceptedBids,
    rejectedBids,
    totalBidAmount,
    averageBidAmount,
    acceptedBidAmount,
    acceptanceRate,
    highestBid,
    lowestBid,
  }
}

export const calculateEventBidSummary = (bids: BidData[], event: Event) => {
  const eventBids = bids.filter(b => b.event_id === event.id)
  const metrics = calculateBidMetrics(eventBids)
  
  return {
    event_id: event.id,
    event_name: event.name,
    acquisition_mode: event.ticket_acquisition_mode,
    starting_bid: event.starting_bid,
    ...metrics,
  }
}

// Bid comparison utilities
export const compareBids = (bidA: BidData, bidB: BidData): number => {
  // Primary sort: by bid amount (highest first)
  if (bidA.bid_amount !== bidB.bid_amount) {
    return bidB.bid_amount - bidA.bid_amount
  }
  
  // Secondary sort: by creation date (earliest first)
  return new Date(bidA.created_at).getTime() - new Date(bidB.created_at).getTime()
}

export const getTopBids = (bids: BidData[], limit: number = 10): BidData[] => {
  return [...bids]
    .sort(compareBids)
    .slice(0, limit)
}

export const getBidRank = (bid: BidData, allBids: BidData[]): number => {
  const eventBids = allBids.filter(b => b.event_id === bid.event_id)
  const sortedBids = eventBids.sort(compareBids)
  return sortedBids.findIndex(b => b.id === bid.id) + 1
}

// Export all utilities as a single object for easier importing
export const BiddingUtils = {
  // Status utilities
  getBidStatusColor,
  getBidStatusLabel,
  isBidActive,
  isBidProcessed,
  canProcessBid,
  
  // Event utilities
  canEventAcceptBids,
  isEventBiddingOnly,
  isEventPurchaseOnly,
  getAcquisitionModeLabel,
  getEventPriceDisplay,
  getEventPriceLabel,
  
  // Sale window utilities
  isSaleWindowOpen,
  getSaleWindowStatus,
  
  // Validation utilities
  validateBidData,
  validateBidAmount,
  
  // Formatting utilities
  formatBidAmount,
  formatBidDate,
  formatDate,
  formatDateTime,
  
  // Search and filter utilities
  filterBids,
  sortBids,
  
  // Analytics utilities
  calculateBidMetrics,
  calculateEventBidSummary,
  
  // Comparison utilities
  compareBids,
  getTopBids,
  getBidRank,
}