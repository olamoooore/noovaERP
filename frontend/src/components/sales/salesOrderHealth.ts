/**
 * Issue #412 (DX2): types and query key for the order health dry run served
 * by GET /sales/orders/{id}/health.
 */

export type HealthStatus = 'pass' | 'warn' | 'fail'

export interface SalesOrderHealthCheck {
  key: string
  label: string
  status: HealthStatus
  detail: string
}

export interface SalesOrderHealthLine {
  line_id: number
  product_id: number | null
  product_name?: string | null
  description?: string | null
  quantity: number
  open_quantity: number
  tracked: boolean
  available: number | null
  shortage: number
  incoming_quantity: number
  backorder_date: string | null
  promise_at_risk: boolean
  status: HealthStatus
  detail: string
  margin?: number
  margin_percent?: number | null
}

export interface SalesOrderHealth {
  ready: boolean
  passed: number
  total: number
  checks: SalesOrderHealthCheck[]
  lines: SalesOrderHealthLine[]
  can_see_cost: boolean
  margin: { total: { revenue: number; cost: number; margin: number; margin_percent: number | null } } | null
}

export const salesOrderHealthQueryKey = (id: string | number | undefined) => ['sales-order-health', String(id ?? '')]
