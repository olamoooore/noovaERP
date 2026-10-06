// Issue #689 (INV-UX3): a batch- or serial-tracked product line on an invoice
// or delivery order cannot be saved without the lot or serial it issues.

export interface TrackedLineLike {
  line_type?: string | null
  product_id?: number | string | null
  tracking_method?: string | null
  product_batch_id?: number | null
  product_serial_id?: number | null
  lot_number?: string | null
  serial_number?: string | null
}

export function lineRequiresTracking(line: TrackedLineLike): boolean {
  if ((line.line_type ?? 'product') !== 'product' || !line.product_id) return false
  return line.tracking_method === 'batch' || line.tracking_method === 'serial'
}

export function lineHasTracking(line: TrackedLineLike): boolean {
  if (line.tracking_method === 'serial') {
    return line.product_serial_id != null || Boolean(line.serial_number)
  }
  return line.product_batch_id != null || Boolean(line.lot_number)
}

/** Index of the first tracked product line missing its lot/serial, or -1. */
export function missingTrackingLineIndex(lines: TrackedLineLike[]): number {
  return lines.findIndex((line) => lineRequiresTracking(line) && !lineHasTracking(line))
}
