import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import api from '@/lib/api'

export type PricingMode = 'exclusive' | 'inclusive'

export interface SettingsTaxConfig {
  enabled: boolean
  pricing_mode: PricingMode
  default_tax_id: number | null
  default_tax_rate?: number | null
  default_tax_name?: string | null
}

interface SettingsState {
  currencyCode: string
  tax: SettingsTaxConfig
  isLoaded: boolean

  loadSettings: () => Promise<void>
  updateSettings: (next: Partial<{ currencyCode: string; tax: Partial<SettingsTaxConfig> }>) => Promise<void>
}

export const useSettingsStore = create<SettingsState>()(
  persist(
    (set, get) => ({
      currencyCode: 'USD',
      tax: {
        enabled: true,
        pricing_mode: 'exclusive',
        default_tax_id: null,
        default_tax_rate: null,
        default_tax_name: null,
      },
      isLoaded: false,

      loadSettings: async () => {
        try {
          const res = await api.get('/settings')
          const currencyCode = res.data?.currency?.code || 'USD'
          const tax = res.data?.tax || {}

          set({
            currencyCode,
            tax: {
              enabled: tax.enabled ?? true,
              pricing_mode: tax.pricing_mode ?? 'exclusive',
              default_tax_id: tax.default_tax_id ?? null,
              default_tax_rate: tax.default_tax_rate ?? null,
              default_tax_name: tax.default_tax_name ?? null,
            },
            isLoaded: true,
          })
        } catch {
          // Keep defaults; still mark loaded to avoid repeated calls
          set({ isLoaded: true })
        }
      },

      updateSettings: async (next) => {
        const current = get()
        const currencyCode = next.currencyCode ?? current.currencyCode
        const tax = { ...current.tax, ...(next.tax || {}) }

        const res = await api.put('/settings', {
          currency: { code: currencyCode },
          tax,
        })

        set({
          currencyCode: res.data?.currency?.code || currencyCode,
          tax: {
            enabled: res.data?.tax?.enabled ?? tax.enabled,
            pricing_mode: res.data?.tax?.pricing_mode ?? tax.pricing_mode,
            default_tax_id: res.data?.tax?.default_tax_id ?? tax.default_tax_id,
            default_tax_rate: res.data?.tax?.default_tax_rate ?? tax.default_tax_rate ?? null,
            default_tax_name: res.data?.tax?.default_tax_name ?? tax.default_tax_name ?? null,
          },
        })
      },
    }),
    {
      name: 'settings-storage',
    }
  )
)
