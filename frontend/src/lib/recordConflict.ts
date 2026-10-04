/**
 * Issue #906 (SALES-PLAT / UX2) — shared helpers for the "someone else is
 * editing" banner and the save-conflict dialog.
 *
 * The backend (#896 RecordVersionGuard) returns 409 `stale_record` with the
 * current document (`data`), the current version (`current_version`) and,
 * when the platform soft lock is active, `active_lock` (who saved last).
 * These helpers turn that response into something the Sales edit pages can
 * render without each page re-implementing the parsing.
 */

export interface ActiveEditor {
  user_id: number
  user_name?: string | null
  expires_at?: string | null
}

export interface SaveConflict {
  message: string
  currentVersion: number
  currentEtag?: string | null
  activeLock: ActiveEditor | null
  /** The server's current document ("theirs"). */
  theirs: Record<string, any> | null
  /** The payload this user tried to save ("mine"). */
  mine: Record<string, any>
}

const DEFAULT_MESSAGE =
  'This document was changed by another user since you loaded it.'

/** True when an axios error is the backend's optimistic-concurrency 409. */
export function isSaveConflict(error: any): boolean {
  const response = error?.response
  return (
    response?.status === 409
    && (response?.data?.code === 'stale_record' || response?.data?.conflict === true)
  )
}

/**
 * Build the dialog state from a failed save. Returns null for any error that
 * is not a version conflict so callers fall through to their normal toast.
 */
export function extractSaveConflict(
  error: any,
  mine: Record<string, any>,
): SaveConflict | null {
  if (!isSaveConflict(error)) return null
  const data = error.response?.data ?? {}

  return {
    message: typeof data.message === 'string' && data.message ? data.message : DEFAULT_MESSAGE,
    currentVersion: Number(data.current_version ?? 0),
    currentEtag: typeof data.current_etag === 'string' ? data.current_etag : null,
    activeLock: data.active_lock ?? null,
    theirs: data.data ?? null,
    mine,
  }
}

export interface ConflictFieldDiff {
  key: string
  label: string
  theirs: unknown
  mine: unknown
}

const FIELD_LABELS: Record<string, string> = {
  customer_id: 'Customer',
  customer_reference: 'Customer reference',
  internal_reference: 'Internal reference',
  invoice_number: 'Invoice number',
  quotation_number: 'Quotation number',
  invoice_date: 'Invoice date',
  quotation_date: 'Quotation date',
  due_date: 'Due date',
  valid_until: 'Valid until',
  commitment_date: 'Commitment date',
  shipping_policy: 'Shipping policy',
  discount_amount: 'Discount',
  discount_type: 'Discount type',
  discount_value: 'Discount value',
  payment_terms: 'Payment terms',
  notes: 'Notes',
  terms: 'Terms',
  lines: 'Line items',
}

export function conflictFieldLabel(key: string): string {
  return FIELD_LABELS[key]
    ?? key.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase())
}

function sameValue(a: unknown, b: unknown): boolean {
  if (a === b) return true
  try {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
  } catch {
    return false
  }
}

/**
 * The field-level differences the user must choose between: only fields the
 * attempted payload actually carries (so server-only columns like
 * `updated_at` never show up), excluding the concurrency token itself.
 */
export function diffConflictFields(
  theirs: Record<string, any> | null,
  mine: Record<string, any> | null,
): ConflictFieldDiff[] {
  if (!theirs || !mine) return []

  return Object.keys(mine)
    .filter((key) => key !== 'version')
    .filter((key) => !sameValue(theirs[key], mine[key]))
    .map((key) => ({
      key,
      label: conflictFieldLabel(key),
      theirs: theirs[key],
      mine: mine[key],
    }))
}

/** What "Copy my lines" puts on the clipboard: my edits, line items first. */
export function copyableConflictText(conflict: SaveConflict | null): string {
  if (!conflict) return ''
  const payload = conflict.mine?.lines != null
    ? { lines: conflict.mine.lines, header: { ...conflict.mine, lines: undefined } }
    : conflict.mine
  return JSON.stringify(payload, null, 2)
}

/** Clipboard write with a legacy fallback; returns whether the copy worked. */
export async function copyTextToClipboard(text: string): Promise<boolean> {
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // fall through to the legacy path below
  }

  try {
    const area = document.createElement('textarea')
    area.value = text
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    const copied = document.execCommand?.('copy') ?? false
    document.body.removeChild(area)
    return copied
  } catch {
    return false
  }
}
