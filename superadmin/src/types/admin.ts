// ─── Plan Catalog ────────────────────────────────────────────────────────────

export interface PlanPrice {
  id: number
  plan_version_id: number
  currency: string
  billing_interval: 'monthly' | 'yearly'
  unit_amount: number // in cents
}

export interface PlanModule {
  id: number
  plan_version_id: number
  module_catalog_id: number
  is_included: boolean
  module?: ModuleCatalogEntry
}

export interface PlanVersion {
  id: number
  plan_family_id: number
  version_label: string
  description: string | null
  is_active: boolean
  is_publicly_listed: boolean
  created_at: string
  updated_at: string
  prices?: PlanPrice[]
  plan_modules?: PlanModule[]
}

export interface PlanFamily {
  id: number
  key: string
  name: string
  description: string | null
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
  versions?: PlanVersion[]
  versions_count?: number
}

// ─── Module Catalog ───────────────────────────────────────────────────────────

export interface ModuleDependency {
  id: number
  module_catalog_id: number
  depends_on_module_catalog_id: number
  depends_on?: ModuleCatalogEntry
}

export interface ModuleCatalogEntry {
  id: number
  module_key: string
  name: string
  description: string | null
  type: 'core' | 'addon'
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
  plan_modules_count?: number
  dependencies?: ModuleDependency[]
}

// ─── Audit ────────────────────────────────────────────────────────────────────

export interface AdminActionLog {
  id: number
  admin_user_id: number | null
  action: string
  target_type: string | null
  target_id: string | null
  before_snapshot: Record<string, unknown> | null
  after_snapshot: Record<string, unknown> | null
  reason: string | null
  ip_address: string | null
  status: 'success' | 'failure'
  created_at: string
}

// ─── Dashboard ────────────────────────────────────────────────────────────────

export interface DashboardSummary {
  status: string
  version: string
  tenant_count: number
  plan_family_count: number
  module_count: number
  phase: string
  message: string
}

// ─── Shared API envelope ─────────────────────────────────────────────────────

export interface ApiResponse<T> {
  data: T
}

export interface ApiListResponse<T> {
  data: T[]
  meta?: {
    total: number
    page: number
    limit: number
  }
}

export interface SyncResult {
  synced: number
  keys: string[]
}
