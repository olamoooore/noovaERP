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
      return 'en-AE'
    case 'SAR':
      return 'en-SA'
    case 'QAR':
      return 'en-QA'
    case 'KWD':
      return 'en-KW'
    case 'BHD':
      return 'en-BH'
    case 'OMR':
      return 'en-OM'
    default:
      return 'en-US'
  }
}

export function formatCurrency(amount: number, currencyCode?: string): string {
  const activeCurrencyCode =
    currencyCode ?? useSettingsStore.getState().currencyCode ?? 'USD'

  const currencyDisplay = activeCurrencyCode?.toUpperCase() === 'SGD' ? 'symbol' : 'narrowSymbol'

  return new Intl.NumberFormat(localeForCurrency(activeCurrencyCode), {
    style: 'currency',
    currency: activeCurrencyCode || 'USD',
    currencyDisplay,
  }).format(amount)
}

export function getCurrencySymbol(currencyCode?: string): string {
  const activeCurrencyCode =
    currencyCode ?? useSettingsStore.getState().currencyCode ?? 'USD'
  const currencyDisplay = activeCurrencyCode?.toUpperCase() === 'SGD' ? 'symbol' : 'narrowSymbol'

  try {
    const parts = new Intl.NumberFormat(localeForCurrency(activeCurrencyCode), {
      style: 'currency',
      currency: activeCurrencyCode || 'USD',
      currencyDisplay,
      maximumFractionDigits: 0,
    }).formatToParts(0)

    return parts.find((p) => p.type === 'currency')?.value ?? activeCurrencyCode
  } catch {
    return activeCurrencyCode
  }
}

export function formatDate(date: string | Date): string {
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
