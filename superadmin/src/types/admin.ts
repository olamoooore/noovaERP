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

export type AuditSeverity = 'info' | 'warning' | 'critical'

export interface AuditDiffField {
  field: string
  before: unknown
  after: unknown
}

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
  severity: AuditSeverity
  human_description: string | null
  created_at: string
  admin_user?: { id: number; name: string; email: string } | null
  /** Only present in GET /audit-logs/{id} response */
  diff?: AuditDiffField[]
}

// ─── Tenant Timeline ──────────────────────────────────────────────────────────

export type TimelineEventType = 'audit' | 'operation' | 'health'

export interface TenantTimelineEvent {
  id: string
  type: TimelineEventType
  created_at: string
  severity: AuditSeverity
  action: string
  human_description: string
  status: 'success' | 'failure' | 'pending'
  admin_user?: { id: number; name: string; email: string } | null
  // health-specific
  db_connected?: boolean
  response_time_ms?: number | null
}

// ─── Observability Stats ─────────────────────────────────────────────────────

export interface ObservabilityStats {
  today_total: number
  today_failures: number
  today_critical: number
  today_warnings: number
  top_actions: Array<{ action: string; count: number }>
  hourly_volume: Array<{ hour: number; count: number }>
  recent_failures: AdminActionLog[]
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

export interface PaginatedResponse<T> {
  data: T[]
  meta: {
    total: number
    page: number
    per_page: number
    last_page: number
  }
}

export interface SyncResult {
  synced: number
  keys: string[]
}

// ─── Tenant 360 ───────────────────────────────────────────────────────────────

export type TenantStatus = 'active' | 'suspended' | 'trial'

export interface TenantDomain {
  id: number
  domain: string
}

export interface Tenant {
  id: string
  name: string | null
  email: string | null
  /** Display name for the current plan (from subscription if active, else legacy tenants.plan) */
  plan: string | null
  /** Plan family key for filtering (e.g. "starter", "professional") */
  plan_key?: string | null
  /** Plan version label (e.g. "v1") */
  plan_version?: string | null
  status: TenantStatus
  trial_ends_at: string | null
  subscription_ends_at: string | null
  created_at: string
  updated_at?: string | null
  domain?: string | null
  last_health_check?: string | null
  last_login_at?: string | null
}

export interface TenantDetail extends Tenant {
  modules_enabled: string[]
  recent_operations: TenantOperation[]
  latest_health: TenantHealthSnapshot | null
  notes: AdminNote[]
}

export interface TenantOperation {
  id: number
  tenant_id: string
  admin_user_id: number | null
  operation: string
  payload: Record<string, unknown> | null
  result: Record<string, unknown> | null
  status: 'pending' | 'success' | 'failure'
  created_at: string
  admin_user?: { id: number; name: string; email: string }
}

export interface TenantHealthSnapshot {
  id: number
  tenant_id: string
  db_connected: boolean
  storage_usage_mb: number | null
  last_login_at: string | null
  error_rate_percent: number | null
  response_time_ms: number | null
  metadata: Record<string, unknown> | null
  created_at: string
}

export interface AdminNote {
  id: number
  tenant_id: string
  admin_user_id: number | null
  body: string
  created_at: string
  updated_at: string
  admin_user?: { id: number; name: string; email: string }
}

// ─── Phase 3: Subscriptions & Billing ────────────────────────────────────────

export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled' | 'expired'
export type InvoiceStatus = 'draft' | 'open' | 'paid' | 'void' | 'uncollectible'

export interface Subscription {
  id: number
  tenant_id: string
  plan_version_id: number
  status: SubscriptionStatus
  trial_starts_at: string | null
  trial_ends_at: string | null
  current_period_start: string
  current_period_end: string
  canceled_at: string | null
  cancellation_reason: string | null
  created_at: string
  updated_at: string
  plan_version?: PlanVersion & { family?: PlanFamily }
  invoices?: SubscriptionInvoice[]
}

export interface SubscriptionInvoice {
  id: number
  subscription_id: number
  tenant_id: string
  invoice_number: string
  billing_interval: 'monthly' | 'yearly'
  currency: string
  subtotal: number   // cents
  tax: number        // cents
  total: number      // cents
  status: InvoiceStatus
  due_date: string | null
  paid_at: string | null
  period_start: string
  period_end: string
  notes: string | null
  created_at: string
  updated_at: string
  subscription?: Subscription
}
