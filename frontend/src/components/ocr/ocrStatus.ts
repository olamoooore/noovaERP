/**
 * Shared OCR document status styling so the list cards and the review page
 * render identical badges and icon tiles.
 */

export interface OcrStatusStyle {
  label: string;
  icon: string;
  badge: string;
  tile: string;
}

export const OCR_STATUS_STYLES: Record<string, OcrStatusStyle> = {
  PENDING: {
    label: 'Pending',
    icon: 'solar:clock-circle-bold',
    badge: 'border-slate-200 bg-slate-50 text-slate-700',
    tile: 'bg-slate-100 text-slate-600',
  },
  PROCESSING: {
    label: 'Processing',
    icon: 'solar:refresh-circle-bold',
    badge: 'border-blue-200 bg-blue-50 text-blue-700',
    tile: 'bg-blue-50 text-blue-600',
  },
  NEEDS_REVIEW: {
    label: 'Needs Review',
    icon: 'solar:eye-bold',
    badge: 'border-amber-200 bg-amber-50 text-amber-700',
    tile: 'bg-amber-50 text-amber-600',
  },
  POSTED: {
    label: 'Posted',
    icon: 'solar:check-circle-bold',
    badge: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    tile: 'bg-emerald-50 text-emerald-600',
  },
  EXTRACTION_FAILED: {
    label: 'Failed',
    icon: 'solar:close-circle-bold',
    badge: 'border-red-200 bg-red-50 text-red-700',
    tile: 'bg-red-50 text-red-600',
  },
}

export const getOcrStatusStyle = (status: string): OcrStatusStyle =>
  OCR_STATUS_STYLES[status] ?? OCR_STATUS_STYLES.PENDING
