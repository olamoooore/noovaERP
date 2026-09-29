import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

export interface TenantFeature {
  id?: number | string
  key: string
  name: string
  description?: string
  enabled: boolean
  is_core?: boolean
}

const normalizeFeatureKey = (key: string) => key.toLowerCase().replace(/[\s_]+/g, '-')

/** Feature keys whose spelling differs between the catalogue and nav/cockpit. */
const FEATURE_KEY_ALIASES: Record<string, string[]> = {
  hrm: ['human-resources', 'human_resources'],
  'field-service': ['field_service'],
  'event-management': ['event_management'],
  'financial-reporting': ['financial_reporting'],
  'master-contacts': ['contact', 'contacts'],
}

/**
 * Tenant-specific hard blocks for modules that must never appear even when the
 * platform catalogue reports them enabled.
 */
const TENANT_FORCE_DISABLED_FEATURES: Record<string, string[]> = {
  'c194ebd3-a0af-4aca-a9b5-1f3275318228': ['field-service'],
  '00000000-0000-0000-0000-000000000001': ['ecommerce', 'crm', 'hrm', 'pos', 'auditor-portal', 'myinvoice', 'storefront'],
  '00000000-0000-0000-0000-000000000002': ['ecommerce', 'crm', 'hrm', 'pos', 'auditor-portal', 'myinvoice', 'storefront'],
}

/**
 * Single source of truth for tenant module entitlement in the SPA.
 *
 * The sidebar, the dashboard module cockpit and the modules hub all gate from
 * this hook so a module the tenant does not own can never be surfaced — and
 * never diverges between surfaces. Server-side, `EnsureEntitlementAccess`
 * remains the real enforcement; this is the presentation contract.
 *
 * Failure semantics (kept from the sidebar fix):
 *   - first load, no data, no error  → fail CLOSED (avoid flashing modules)
 *   - fetch errored (429/network)    → fail OPEN (never blank the nav; the
 *     API still rejects unentitled calls, so this cannot grant access)
 */
export function useFeatureAccess() {
  const tenantId = localStorage.getItem('tenant_id') || 'default'

  const { data, isLoading, isError } = useQuery<TenantFeature[]>({
    queryKey: ['features', tenantId],
    queryFn: async () => {
      const response = await api.get('/features')
      const payload = response.data
      return Array.isArray(payload) ? payload : (Array.isArray(payload?.data) ? payload.data : [])
    },
    // 429s reset at a known time — retrying storm makes throttling worse.
    retry: (failureCount, error: any) => {
      const status = error?.response?.status
      if (status === 429) return false
      if (status >= 500 && status < 600) return failureCount < 3
      if (status >= 400 && status < 500) return false
      return failureCount < 3
    },
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 15000),
    staleTime: 1000 * 60 * 10,
    placeholderData: (prev: any) => prev,
  })

  const features = data ?? undefined

  const featureStatusByKey = new Map(
    (features || []).map((f) => [normalizeFeatureKey(String(f.key || '')), Boolean(f.enabled)]),
  )

  const isFeatureEnabled = (featureKey?: string): boolean => {
    if (!featureKey) return true

    if (!features) {
      return isError ? true : false
    }

    const normalizedKey = normalizeFeatureKey(featureKey)
    const forceDisabledForTenant = (TENANT_FORCE_DISABLED_FEATURES[tenantId] || []).map((key) =>
      normalizeFeatureKey(key),
    )

    if (forceDisabledForTenant.includes(normalizedKey)) {
      return false
    }

    const keysToCheck = [
      normalizedKey,
      ...(FEATURE_KEY_ALIASES[normalizedKey] || []).map((key) => normalizeFeatureKey(key)),
    ]
    const matchedKey = keysToCheck.find((key) => featureStatusByKey.has(key))

    // Unknown keys default to visible (core/nav-only capabilities).
    if (!matchedKey) {
      return true
    }

    return featureStatusByKey.get(matchedKey) === true
  }

  return {
    /** Undefined until the first fetch settles — callers use that to fail closed. */
    features,
    isLoading,
    isError,
    isFeatureEnabled,
  }
}