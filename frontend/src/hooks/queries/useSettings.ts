import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import api from '@/lib/api'

// Types
export type PricingMode = 'exclusive' | 'inclusive'

export interface SettingsTaxConfig {
  enabled: boolean
  pricing_mode: PricingMode
  default_tax_id: number | null
  default_tax_rate?: number | null
  default_tax_name?: string | null
}

export interface Settings {
  currencyCode: string
  tax: SettingsTaxConfig
}

interface SettingsResponse {
  currency?: {
    code: string
  }
  tax?: Partial<SettingsTaxConfig>
}

interface SettingsUpdatePayload {
  currency?: {
    code: string
  }
  tax?: Partial<SettingsTaxConfig>
}

// Query keys
export const settingsKeys = {
  all: ['settings'] as const,
  settings: () => [...settingsKeys.all, 'settings'] as const,
}

// API functions
const fetchSettings = async (): Promise<Settings> => {
  const response = await api.get('/settings')
  const data: SettingsResponse = response.data
  
  return {
    currencyCode: data.currency?.code || 'USD',
    tax: {
      enabled: data.tax?.enabled ?? true,
      pricing_mode: data.tax?.pricing_mode ?? 'exclusive',
      default_tax_id: data.tax?.default_tax_id ?? null,
      default_tax_rate: data.tax?.default_tax_rate ?? null,
      default_tax_name: data.tax?.default_tax_name ?? null,
    },
  }
}

const updateSettings = async (updates: Partial<Settings>): Promise<Settings> => {
  const payload: SettingsUpdatePayload = {}
  
  if (updates.currencyCode) {
    payload.currency = { code: updates.currencyCode }
  }
  
  if (updates.tax) {
    payload.tax = updates.tax
  }
  
  const response = await api.put('/settings', payload)
  const data: SettingsResponse = response.data
  
  return {
    currencyCode: data.currency?.code || updates.currencyCode || 'USD',
    tax: {
      enabled: data.tax?.enabled ?? updates.tax?.enabled ?? true,
      pricing_mode: data.tax?.pricing_mode ?? updates.tax?.pricing_mode ?? 'exclusive',
      default_tax_id: data.tax?.default_tax_id ?? updates.tax?.default_tax_id ?? null,
      default_tax_rate: data.tax?.default_tax_rate ?? updates.tax?.default_tax_rate ?? null,
      default_tax_name: data.tax?.default_tax_name ?? updates.tax?.default_tax_name ?? null,
    },
  }
}

// React Query hooks
export const useSettings = () => {
  return useQuery({
    queryKey: settingsKeys.settings(),
    queryFn: fetchSettings,
    staleTime: 10 * 60 * 1000, // 10 minutes
    gcTime: 30 * 60 * 1000, // 30 minutes (formerly cacheTime)
  })
}

export const useUpdateSettings = () => {
  const queryClient = useQueryClient()
  
  return useMutation({
    mutationFn: updateSettings,
    onSuccess: (data) => {
      // Update the settings cache with the new data
      queryClient.setQueryData(settingsKeys.settings(), data)
    },
    onError: (error) => {
      console.error('Failed to update settings:', error)
    },
  })
}

// Convenience hooks for specific settings
export const useCurrencyCode = () => {
  const { data: settings } = useSettings()
  return settings?.currencyCode || 'USD'
}

export const useTaxConfig = () => {
  const { data: settings } = useSettings()
  return settings?.tax || {
    enabled: true,
    pricing_mode: 'exclusive' as PricingMode,
    default_tax_id: null,
    default_tax_rate: null,
    default_tax_name: null,
  }
}