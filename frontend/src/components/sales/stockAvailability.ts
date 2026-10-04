import api from '@/lib/api'

/**
 * Issue #687 (UX1): shared available-to-promise data plumbing.
 *
 * The figures come from the shared #465 stock-check path
 * (`GET/POST /sales/delivery-orders/stock-check`) — on hand minus
 * reservations held by other documents, computed once in
 * InventoryService (#465/#686). Kept outside the chip component so the
 * component module only exports components (fast-refresh).
 */

export interface IncomingStockEntry {
  source_type: string
  source_id: number
  reference: string | null
  quantity: number
  expected_date: string | null
  status?: string | null
}

export interface StockAvailability {
  on_hand: number
  reserved: number
  available: number
  incoming?: IncomingStockEntry[]
  incoming_quantity?: number
}

export interface BulkStockLine {
  product_id: number
  requested: number
  on_hand: number
  reserved: number
  available: number
  incoming: IncomingStockEntry[]
  incoming_quantity: number
  shortage: number
  sufficient: boolean
}

export interface BulkStockAvailability {
  warehouse_id: number
  lines: BulkStockLine[]
  summary: { line_count: number; lines_short: number }
  incoming_note: string | null
}

/**
 * Issue #687: preflight a document's lines through the bulk stock check.
 * Callers surface failures themselves (advisory check, not a silent catch).
 */
export async function fetchBulkStockAvailability(
  warehouseId: number | string,
  lines: Array<{ product_id: number | string; quantity: number }>,
): Promise<BulkStockAvailability> {
  const response = await api.post('/sales/delivery-orders/stock-check', {
    warehouse_id: warehouseId,
    lines,
  })

  return response.data as BulkStockAvailability
}
