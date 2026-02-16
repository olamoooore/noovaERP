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

export interface SettingsMailConfig {
  driver: 'smtp' | 'log' | 'array' | 'sendmail' // usually 'smtp' by default
  host: string
  port: number
  encryption: 'tls' | 'ssl' | ''
  username: string
  password?: string
  from_address: string
  from_name: string
}

interface SettingsState {
  currencyCode: string
  tax: SettingsTaxConfig
  mail: SettingsMailConfig
  isLoaded: boolean

  loadSettings: () => Promise<void>
  updateSettings: (next: Partial<{ currencyCode: string; tax: Partial<SettingsTaxConfig>; mail: Partial<SettingsMailConfig> }>) => Promise<void>
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
      mail: {
        driver: 'smtp',
        host: 'smtp.gmail.com',
        port: 587,
        encryption: 'tls',
        username: '',
        password: '',
        from_address: '',
        from_name: 'NoovaERP',
      },
      isLoaded: false,

      loadSettings: async () => {
        try {
          const res = await api.get('/settings')
          const currencyCode = res.data?.currency?.code || 'USD'
          const tax = res.data?.tax || {}
          const mail = res.data?.mail || {}

          set({
            currencyCode,
            tax: {
              enabled: tax.enabled ?? true,
              pricing_mode: tax.pricing_mode ?? 'exclusive',
              default_tax_id: tax.default_tax_id ?? null,
              default_tax_rate: tax.default_tax_rate ?? null,
              default_tax_name: tax.default_tax_name ?? null,
            },
            mail: {
              driver: mail.driver ?? 'smtp',
              host: mail.host ?? 'smtp.gmail.com',
              port: Number(mail.port ?? 587),
              encryption: mail.encryption ?? 'tls',
              username: mail.username ?? '',
              password: mail.password ?? '', // likely masked
              from_address: mail.from_address ?? '',
              from_name: mail.from_name ?? 'NoovaERP',
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
        const mail = { ...current.mail, ...(next.mail || {}) }

        // If password is just masked/empty and user didn't change it, we might want to omit sending it?
        // But backend handles "empty string means keep old if old exists". So we send it as is.
        // If it's masked "********", backend also ignores it. So safe to send.

        const res = await api.put('/settings', {
          currency: { code: currencyCode },
          tax,
          mail,
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
          mail: {
            driver: res.data?.mail?.driver ?? mail.driver,
            host: res.data?.mail?.host ?? mail.host,
            port: Number(res.data?.mail?.port ?? mail.port),
            encryption: res.data?.mail?.encryption ?? mail.encryption,
            username: res.data?.mail?.username ?? mail.username,
            password: res.data?.mail?.password ?? mail.password,
            from_address: res.data?.mail?.from_address ?? mail.from_address,
            from_name: res.data?.mail?.from_name ?? mail.from_name,
          }
        })
      },
    }),
    {
      name: 'settings-storage',
    }
  )
)
