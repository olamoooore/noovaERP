/** Issue #376 (#152): badge colours for blanket order statuses (active|expired|cancelled). */
export const BLANKET_STATUS_CLASS: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  expired: 'bg-amber-100 text-amber-800',
  cancelled: 'bg-gray-100 text-gray-700',
}
