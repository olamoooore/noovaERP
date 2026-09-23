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

export function ProductTrackingSelector({
  productId,
  warehouseId,
  trackingMethod,
  batchId,
  serialId,
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
  const selectClass = 'w-full rounded border border-gray-300 bg-white px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800'

  if (!productId || method === 'none' || method === 'quantity') return null
  if (isLoading) return <span className="text-xs text-muted-foreground">Loading tracking…</span>
  if (isError) return <span className="text-xs text-destructive">Unable to load available inventory</span>

  if (method === 'batch') {
    const batches = data?.batches || []
    return (
      <select
        className={selectClass}
        value={batchId || ''}
        disabled={disabled}
        onChange={(event) => {
          const batch = batches.find((item: any) => String(item.id) === event.target.value)
          onChange(batch ? {
            product_batch_id: Number(batch.id),
            product_serial_id: null,
            lot_number: batch.lot_number || batch.batch_number,
            serial_number: '',
            expiry_date: batch.expiry_date || '',
          } : emptySelection)
        }}
      >
        <option value="">{batches.length ? 'Select available lot' : 'No available lots'}</option>
        {batches.map((batch: any) => (
          <option key={batch.id} value={batch.id}>
            {batch.lot_number || batch.batch_number} · {batch.available_quantity} available{batch.expiry_date ? ` · Exp ${batch.expiry_date}` : ''}
          </option>
        ))}
      </select>
    )
  }

  if (method === 'serial') {
    const serials = data?.serials || []
    return (
      <select
        className={selectClass}
        value={serialId || ''}
        disabled={disabled}
        onChange={(event) => {
          const serial = serials.find((item: any) => String(item.id) === event.target.value)
          onChange(serial ? {
            product_batch_id: serial.batch_id ? Number(serial.batch_id) : null,
            product_serial_id: Number(serial.id),
            lot_number: serial.lot_number || '',
            serial_number: serial.serial_number,
            expiry_date: serial.expiry_date || '',
          } : emptySelection)
        }}
      >
        <option value="">{serials.length ? 'Select available serial' : 'No available serials'}</option>
        {serials.map((serial: any) => (
          <option key={serial.id} value={serial.id}>
            {serial.serial_number}{serial.lot_number ? ` · Lot ${serial.lot_number}` : ''}{serial.expiry_date ? ` · Exp ${serial.expiry_date}` : ''}
          </option>
        ))}
      </select>
    )
  }

  return null
}
