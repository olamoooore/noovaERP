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

// =============================================================================
// Phase 6A — Product Catalog
// =============================================================================

export interface CatalogItemType {
  id: number
  key: string
  name: string
  description: string | null
  sort_order: number
  created_at: string
  updated_at: string
}

export type CatalogItemSource = 'catalog' | 'legacy_module'

export interface CatalogDependency {
  id: number
  item_id: number
  depends_on_item_id: number
  type: 'required' | 'optional'
  created_at: string
  updated_at: string
  depends_on?: CatalogItem
  item?: CatalogItem
}

export interface CatalogIncompatibility {
  id: number
  item_id: number
  incompatible_item_id: number
  reason: string | null
  created_at: string
  updated_at: string
  incompatible_item?: CatalogItem
  item?: CatalogItem
}

export interface CatalogVisibilityRule {
  id: number
  item_id: number
  rule_type: string
  condition: Record<string, unknown>
  created_at: string
  updated_at: string
}

export interface CatalogItem {
  id: number
  key: string                          // immutable — module[.submodule[.feature]]
  name: string
  description: string | null
  type_id: number
  parent_id: number | null
  is_active: boolean
  sort_order: number
  metadata: Record<string, unknown> | null
  effective_from: string               // date string YYYY-MM-DD
  effective_to: string | null
  source: CatalogItemSource
  created_at: string
  updated_at: string
  // Relations (loaded on demand)
  type?: CatalogItemType
  parent?: CatalogItem
  children?: CatalogItem[]
  dependencies?: CatalogDependency[]
  incompatibilities?: CatalogIncompatibility[]
  visibility_rules?: CatalogVisibilityRule[]
}

export interface CatalogSyncResult {
  synced: number
  skipped: number
  keys: string[]
}

// =============================================================================
// Phase 6B — Tier Matrix (Packaging Layer)
// =============================================================================

export type ItemAvailability = 'included' | 'add_on' | 'hidden' | 'custom_only'

export interface PlanItemDefault {
  id: number
  plan_family_id: number
  catalog_item_id: number
  availability: ItemAvailability
  notes: string | null
  created_at: string
  updated_at: string
  // Relations
  plan_family?: PlanFamily
  catalog_item?: CatalogItem
}

/** A single matrix cell, keyed by "familyId:itemId" */
export interface MatrixCell {
  id: number
  availability: ItemAvailability
  notes: string | null
}

/** Full matrix response from GET /packaging/matrix */
export interface TierMatrix {
  tiers: PlanFamily[]
  items: CatalogItem[]
  matrix: Record<string, MatrixCell>   // key: "familyId:itemId"
}

export interface BulkSetResult {
  updated: number
}

// =============================================================================
// Phase 6C — Tenant Selection Model
// =============================================================================

export type SelectionType = 'add_on_purchased' | 'override_include' | 'override_exclude'

export type EntitlementSource =
  | 'tier_included'
  | 'add_on_purchased'
  | 'override_include'
  | 'override_exclude'

export interface TenantSelectedItem {
  id: number
  tenant_id: string
  catalog_item_id: number
  selection_type: SelectionType
  notes: string | null
  selected_by_admin_id: number | null
  created_at: string
  updated_at: string
  catalog_item?: CatalogItem
}

export interface TenantEntitlement {
  id: number
  tenant_id: string
  catalog_item_id: number
  is_entitled: boolean
  source: EntitlementSource
  resolved_at: string
  created_at: string
  updated_at: string
  catalog_item?: CatalogItem
}

export type RebuildJobStatus = 'pending' | 'running' | 'completed' | 'failed'

export interface EntitlementRebuildJob {
  id: number
  tenant_id: string | null
  status: RebuildJobStatus
  reason: string | null
  triggered_by_admin_id: number | null
  items_processed: number
  tenants_processed: number
  error_message: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
  updated_at: string
}

/** Full configuration view for one tenant */
export interface TenantConfigurationView {
  tenant_id: string
  active_tier: PlanFamily | null
  items: CatalogItem[]
  tier_defaults: Record<number, PlanItemDefault>    // keyed by catalog_item_id
  selections: Record<number, TenantSelectedItem>    // keyed by catalog_item_id
  entitlements: Record<number, TenantEntitlement>   // keyed by catalog_item_id
  recent_jobs: EntitlementRebuildJob[]
}

export interface EntitlementCheckResult {
  tenant_id: string
  key: string
  is_entitled: boolean
}

// =============================================================================
// Phase 6D — Pricing Engine
// =============================================================================

export type BillingInterval = 'monthly' | 'yearly'

export type PriceRuleType =
  | 'percentage_discount'
  | 'fixed_discount'
  | 'yearly_discount'
  | 'volume_tier'

export interface CatalogPrice {
  id: number
  catalog_item_id: number
  billing_interval: BillingInterval
  currency: string
  unit_amount: number            // cents
  created_at: string
  updated_at: string
  catalog_item?: CatalogItem
}

export interface CatalogPriceRule {
  id: number
  catalog_item_id: number | null  // null = global
  name: string
  rule_type: PriceRuleType
  condition: Record<string, unknown>
  modifier_value: string          // decimal as string from API
  priority: number
  is_exclusive: boolean
  is_active: boolean
  effective_from: string          // date
  effective_to: string | null
  created_at: string
  updated_at: string
  catalog_item?: CatalogItem
}

export interface QuoteLineItem {
  catalog_item_id: number
  item_key: string
  item_name: string
  source: EntitlementSource
  unit_amount: number            // cents
  quantity: number
  discount_cents: number
  line_total_cents: number
  rules_applied: string[]
}

// =============================================================================
// Phase 6E — Plan Change Engine
// =============================================================================

export type PlanChangeStatus = 'pending' | 'applied' | 'cancelled'
export type PlanChangeInvoiceType = 'credit' | 'charge'

export interface TenantPlanChange {
  id: number
  tenant_id: string
  from_family_id: number | null
  to_family_id: number
  effective_date: string          // YYYY-MM-DD
  status: PlanChangeStatus
  proration_credit_cents: number
  proration_charge_cents: number
  reason: string | null
  applied_at: string | null
  applied_by_admin_id: number | null
  created_by_admin_id: number | null
  created_at: string
  updated_at: string
  // Relations
  from_family?: PlanFamily | null
  to_family?: PlanFamily
  applied_by_admin?: { id: number; name: string; email: string } | null
  created_by_admin?: { id: number; name: string; email: string } | null
  change_invoices?: TenantPlanChangeInvoice[]
}

export interface TenantPlanChangeInvoice {
  id: number
  plan_change_id: number
  invoice_id: number
  type: PlanChangeInvoiceType
  amount_cents: number
  created_at: string
  updated_at: string
  invoice?: SubscriptionInvoice
}

export interface ProrationPreview {
  from_family: PlanFamily | null
  to_family: PlanFamily
  credit_cents: number
  charge_cents: number
  net_cents: number
  effective_date: string
  current_period_end: string | null
}

// =============================================================================
// Phase 6F — Catalog Analytics
// =============================================================================

export interface CatalogAnalyticsOverview {
  total_active_items: number
  total_prices: number
  total_active_rules: number
  tenants_with_entitlements: number
  total_quote_snapshots: number
  total_discount_cents: number
  total_revenue_cents: number
  pending_plan_changes: number
  plan_changes_this_month: number
  total_selections: number
  add_on_selections: number
}

export interface EntitlementHeatmapRow {
  item_id: number
  item_key: string
  item_name: string
  entitled_count: number
  blocked_count: number
  entitlement_pct: number
  tier_included_count: number
  add_on_count: number
  override_include_count: number
  override_exclude_count: number
}

export interface AddonAdoptionRow {
  item_id: number
  item_key: string
  item_name: string
  adoption_count: number
  pct_of_max: number
}

export interface RevenueByItemRow {
  item_id: number
  item_key: string
  item_name: string
  total_revenue_cents: number
  total_discount_cents: number
  appearance_count: number
  pct_of_max: number
}

export interface PlanDistributionRow {
  family_id: number
  family_key: string
  family_name: string
  tenant_count: number
  trialing_count: number
  active_count: number
  pct: number
}

export interface PlanChangeRecentRow {
  id: number
  tenant_id: string
  status: string
  effective_date: string
  proration_credit_cents: number
  proration_charge_cents: number
  created_at: string
  from_family_name: string | null
  to_family_name: string
}

export interface PlanChangeActivity {
  by_status: { pending: number; applied: number; cancelled: number }
  this_month: number
  last_month: number
  recent_changes: PlanChangeRecentRow[]
}

export interface PricingRuleImpactRow {
  rule_name: string
  application_count: number
  total_discount_cents: number
  pct_of_max: number
}

export interface TenantQuoteSnapshot {
  id: number
  tenant_id: string
  plan_family_id: number | null
  billing_interval: BillingInterval
  currency: string
  subtotal_cents: number
  discount_cents: number
  total_cents: number
  line_items: QuoteLineItem[]
  generated_by_admin_id: number | null
  generated_at: string
  created_at: string
  updated_at: string
  plan_family?: PlanFamily
  generated_by_admin?: { id: number; name: string; email: string } | null
}
