import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'
import { useSettingsStore } from '@/store/settingsStore'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

function localeForCurrency(currencyCode: string): string {
  switch ((currencyCode || '').toUpperCase()) {
    case 'USD':
      return 'en-US'
    case 'SGD':
      return 'en-SG'
    case 'AED':
      return 'ar-AE'
    case 'SAR':
      return 'ar-SA'
    case 'QAR':
      return 'ar-QA'
    case 'KWD':
      return 'ar-KW'
    case 'BHD':
      return 'ar-BH'
    case 'OMR':
      return 'ar-OM'
    default:
      return 'en-US'
  }
}

export function formatCurrency(amount: number, currencyCode?: string): string {
  const activeCurrencyCode =
    currencyCode ?? useSettingsStore.getState().currencyCode ?? 'USD'

  const normalizedCode = (activeCurrencyCode || 'USD').toUpperCase()
  const locale = localeForCurrency(normalizedCode)

  // Intl commonly renders SGD as "S$"; product requirement is "$S".
  if (normalizedCode === 'SGD') {
    const number = new Intl.NumberFormat(locale, {
      style: 'decimal',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(Math.abs(amount))

    const sign = amount < 0 ? '-' : ''
    return `${sign}$S${number}`
  }

  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: normalizedCode,
      currencyDisplay: 'symbol',
    }).format(amount)
  } catch {
    const fallbackNumber = new Intl.NumberFormat('en-US', {
      style: 'decimal',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(amount)
    return `${normalizedCode} ${fallbackNumber}`
  }
}

export function getCurrencySymbol(currencyCode?: string): string {
  const activeCurrencyCode =
    currencyCode ?? useSettingsStore.getState().currencyCode ?? 'USD'

  const normalizedCode = (activeCurrencyCode || 'USD').toUpperCase()

  if (normalizedCode === 'SGD') return '$S'

  try {
    const parts = new Intl.NumberFormat(localeForCurrency(normalizedCode), {
      style: 'currency',
      currency: normalizedCode,
      currencyDisplay: 'symbol',
      maximumFractionDigits: 0,
    }).formatToParts(0)

    return parts.find((p) => p.type === 'currency')?.value ?? normalizedCode
  } catch {
    return normalizedCode
  }
}

function normalizeDateValue(date?: string | Date | null): Date | null {
  if (!date) {
    return null
  }

  const parsedDate = date instanceof Date ? date : new Date(date)

  if (Number.isNaN(parsedDate.getTime())) {
    return null
  }

  return parsedDate
}

export function formatDate(date?: string | Date | null, fallback: string = '-'): string {
  const parsedDate = normalizeDateValue(date)

  if (!parsedDate) {
    return fallback
  }

  return parsedDate.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

export function formatDateTime(date?: string | Date | null, fallback: string = '-'): string {
  const parsedDate = normalizeDateValue(date)

  if (!parsedDate) {
    return fallback
  }

  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(parsedDate)
}

export function formatNumber(value: number, decimals: number = 0): string {
  return new Intl.NumberFormat('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value)
}

export function getApiErrorMessage(error: any, fallback: string = 'An error occurred'): string {
  const data = error?.response?.data

  if (data?.errors && typeof data.errors === 'object') {
    const messages = Object.values(data.errors)
      .flat()
      .filter((value): value is string => typeof value === 'string' && value.trim().length > 0)

    if (messages.length > 0) {
      return messages.join(', ')
    }
  }

  if (typeof data?.error === 'string' && data.error.trim().length > 0) {
    return data.error
  }

  if (typeof data?.message === 'string' && data.message.trim().length > 0) {
    return data.message
  }

  if (typeof data === 'string' && data.trim().length > 0) {
    return data
  }

  if (typeof error?.message === 'string' && error.message.trim().length > 0) {
    return error.message
  }

  return fallback
}

export function resolveImageUrl(path: string | null | undefined): string {
  if (!path) {
    return ''
  }

  if (
    path.startsWith('http://')
    || path.startsWith('https://')
    || path.startsWith('data:')
    || path.startsWith('blob:')
  ) {
    return path
  }

  if (path.startsWith('/storage/')) {
    return path
  }

  return `/storage/${path.replace(/^\//, '')}`
}
