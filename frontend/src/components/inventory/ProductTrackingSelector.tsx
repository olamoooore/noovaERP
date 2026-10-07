import { useEffect, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'

export interface TrackingSelection {
  product_batch_id: number | null
  product_serial_id: number | null
  lot_number: string
  serial_number: string
  expiry_date: string
}

interface Props {
  productId: number | string | null
  warehouseId?: number | string | null
  trackingMethod?: string | null
  batchId?: number | null
  serialId?: number | null
  /** Lot / serial already on the line without an id (legacy or imported): never auto-replaced. */
  lotNumber?: string | null
  serialNumber?: string | null
  disabled?: boolean
  onChange: (selection: TrackingSelection) => void
}

const emptySelection: TrackingSelection = {
  product_batch_id: null,
  product_serial_id: null,
  lot_number: '',
  serial_number: '',
  expiry_date: '',
}

const toBatchSelection = (batch: any): TrackingSelection => ({
  product_batch_id: Number(batch.id),
  product_serial_id: null,
  lot_number: batch.batch_number || batch.lot_number,
  serial_number: '',
  expiry_date: batch.expiry_date || '',
})

const toSerialSelection = (serial: any): TrackingSelection => ({
  product_batch_id: serial.batch_id ? Number(serial.batch_id) : null,
  product_serial_id: Number(serial.id),
  lot_number: serial.batch_number || serial.lot_number || '',
  serial_number: serial.serial_number,
  expiry_date: serial.expiry_date || '',
})

const expiryHint = (item: any) =>
  item?.near_expiry && item.days_until_expiry != null ? ` · expires in ${item.days_until_expiry}d` : ''

export function ProductTrackingSelector({
  productId,
  warehouseId,
  trackingMethod,
  batchId,
  serialId,
  lotNumber,
  serialNumber,
  disabled,
  onChange,
}: Props) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['product-tracking-availability', productId, warehouseId],
    queryFn: async () => {
      const response = await api.get(`/products/${productId}/tracking-availability`, {
        params: warehouseId ? { warehouse_id: warehouseId } : undefined,
      })
      return response.data
    },
    enabled: Boolean(productId),
  })

  const method = data?.tracking_method || trackingMethod || 'none'
  const batches: any[] = data?.batches || []
  const serials: any[] = data?.serials || []

  // Issue #689: pre-select the FEFO suggestion (server-ordered, expired lots
  // excluded) once per product + warehouse, and only when the line has no lot
  // or serial yet — an existing or user-made choice is never overridden.
  const autoSelectedFor = useRef<string | null>(null)
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const hasSelection = Boolean(batchId || serialId || lotNumber || serialNumber)
  useEffect(() => {
    if (!data || disabled) return
    const key = `${productId}:${warehouseId ?? ''}`
    if (autoSelectedFor.current === key) return
    autoSelectedFor.current = key
    if (hasSelection) return
    if (method === 'batch' && data.suggested_batch_id != null) {
      const batch = (data.batches || []).find((item: any) => Number(item.id) === Number(data.suggested_batch_id))
      if (batch) onChangeRef.current(toBatchSelection(batch))
    } else if (method === 'serial' && data.suggested_serial_id != null) {
      const serial = (data.serials || []).find((item: any) => Number(item.id) === Number(data.suggested_serial_id))
      if (serial) onChangeRef.current(toSerialSelection(serial))
    }
  }, [data, disabled, productId, warehouseId, hasSelection, method])

  const baseClass = 'w-full rounded border bg-white px-2 py-1 text-sm dark:bg-gray-800'
  const normalBorder = 'border-gray-300 dark:border-gray-600'

  if (!productId || method === 'none' || method === 'quantity') return null
  if (isLoading) return <span className="text-xs text-muted-foreground">Loading tracking…</span>
  if (isError) return <span className="text-xs text-destructive">Unable to load available inventory</span>

  const renderHints = (selected: any, missing: boolean, label: string) => (
    <>
      {missing ? <div className="mt-1 text-xs text-destructive">{label} required</div> : null}
      {selected?.near_expiry && selected.days_until_expiry != null ? (
        <div className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-400">
          Near expiry: {selected.days_until_expiry} days left
        </div>
      ) : null}
    </>
  )

  if (method === 'batch') {
    const selected = batches.find((item) => Number(item.id) === Number(batchId))
    const missing = !batchId && !lotNumber
    const borderClass = missing ? 'border-destructive' : selected?.near_expiry ? 'border-amber-500' : normalBorder
    return (
      <div>
        <select
          className={`${baseClass} ${borderClass}`}
          value={batchId || ''}
          disabled={disabled}
          aria-invalid={missing || undefined}
          onChange={(event) => {
            const batch = batches.find((item) => String(item.id) === event.target.value)
            onChange(batch ? toBatchSelection(batch) : emptySelection)
          }}
        >
          <option value="">{batches.length ? 'Select available lot' : 'No available lots'}</option>
          {batches.map((batch) => (
            <option key={batch.id} value={batch.id}>
              {batch.batch_number || batch.lot_number} · {batch.available_quantity} available
              {batch.expiry_date ? ` · Exp ${batch.expiry_date}` : ''}
              {expiryHint(batch)}
              {Number(batch.id) === Number(data?.suggested_batch_id) ? ' · FEFO' : ''}
            </option>
          ))}
        </select>
        {renderHints(selected, missing, 'Lot')}
      </div>
    )
  }

  if (method === 'serial') {
    const selected = serials.find((item) => Number(item.id) === Number(serialId))
    const missing = !serialId && !serialNumber
    const borderClass = missing ? 'border-destructive' : selected?.near_expiry ? 'border-amber-500' : normalBorder
    return (
      <div>
        <select
          className={`${baseClass} ${borderClass}`}
          value={serialId || ''}
          disabled={disabled}
          aria-invalid={missing || undefined}
          onChange={(event) => {
            const serial = serials.find((item) => String(item.id) === event.target.value)
            onChange(serial ? toSerialSelection(serial) : emptySelection)
          }}
        >
          <option value="">{serials.length ? 'Select available serial' : 'No available serials'}</option>
          {serials.map((serial) => (
            <option key={serial.id} value={serial.id}>
              {serial.serial_number}
              {(serial.batch_number || serial.lot_number) ? ` · Lot ${serial.batch_number || serial.lot_number}` : ''}
              {serial.expiry_date ? ` · Exp ${serial.expiry_date}` : ''}
              {expiryHint(serial)}
              {Number(serial.id) === Number(data?.suggested_serial_id) ? ' · FEFO' : ''}
            </option>
          ))}
        </select>
        {renderHints(selected, missing, 'Serial')}
      </div>
    )
  }

  return null
}
