/**
 * #318 / #398: shared mapping of validation errors onto form fields.
 *
 * Field errors are a flat `{ key: message }` map whose keys follow Laravel's
 * validation keys: header fields use their payload name (`customer_id`) and
 * document lines use `lines.<row>.<field>`. Form controls are wrapped in an
 * element carrying `data-field-key` (see `FieldSlot`), which is how the first
 * invalid field is found, scrolled into view and focused.
 *
 * Server line indexes refer to the submitted payload, which skips blank grid
 * rows — `remapLineErrors` translates them back to on-screen grid rows.
 */

export type FieldErrors = Record<string, string>

const FOCUSABLE = [
  'input:not([type="hidden"]):not([disabled])',
  'textarea:not([disabled])',
  'select:not([disabled])',
  'button:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

const LINE_KEY = /^lines\.(\d+)(\..+)?$/

/** `lines.<row>.<field>` — the key a grid cell is registered under. */
export function lineFieldKey(row: number, field: string): string {
  return `lines.${row}.${field}`
}

/**
 * The first message per key from a Laravel 422 response, or null when the
 * error is not a validation failure with field errors.
 */
export function extractValidationErrors(error: unknown): FieldErrors | null {
  const response = (error as { response?: { status?: number; data?: any } } | null)?.response
  if (!response || response.status !== 422) return null
  const raw = response.data?.errors
  if (!raw || typeof raw !== 'object') return null

  const errors: FieldErrors = {}
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    const messages = Array.isArray(value) ? value : [value]
    const message = messages.find((item): item is string => typeof item === 'string' && item.trim().length > 0)
    if (message) errors[key] = message
  }
  return Object.keys(errors).length > 0 ? errors : null
}

/** Grid row index of each payload line, given the filter that built the payload. */
export function payloadRowIndexes<T>(rows: T[], isSent: (row: T) => boolean): number[] {
  return rows.flatMap((row, index) => (isSent(row) ? [index] : []))
}

/** Rewrite `lines.<payloadIndex>…` keys to `lines.<gridRow>…`. */
export function remapLineErrors(errors: FieldErrors, rowIndexes: number[]): FieldErrors {
  const mapped: FieldErrors = {}
  for (const [key, message] of Object.entries(errors)) {
    const match = LINE_KEY.exec(key)
    if (!match) {
      mapped[key] = message
      continue
    }
    const payloadIndex = Number(match[1])
    const row = rowIndexes[payloadIndex] ?? payloadIndex
    mapped[`lines.${row}${match[2] ?? ''}`] = message
  }
  return mapped
}

function slotFor(errorKey: string, slots: HTMLElement[]): HTMLElement | undefined {
  const exact = slots.find((slot) => slot.dataset.fieldKey === errorKey)
  if (exact) return exact
  // A line error on a field without its own cell (or on the line itself)
  // points at the first cell of that line.
  const match = LINE_KEY.exec(errorKey)
  if (!match) return undefined
  const prefix = `lines.${match[1]}.`
  return slots.find((slot) => slot.dataset.fieldKey?.startsWith(prefix))
}

/**
 * Scroll the first invalid field (in document order) into view and focus it.
 * Returns the focused element, or null when no field on screen matches.
 */
export function focusFirstFieldError(errors: FieldErrors, root: ParentNode = document): HTMLElement | null {
  const slots = Array.from(root.querySelectorAll<HTMLElement>('[data-field-key]'))
  const targets = new Set(
    Object.keys(errors)
      .map((key) => slotFor(key, slots))
      .filter((slot): slot is HTMLElement => Boolean(slot)),
  )
  const slot = slots.find((candidate) => targets.has(candidate))
  if (!slot) return null

  // `data-field-focus` marks the preferred control when a slot holds several.
  const focusable = slot.matches(FOCUSABLE)
    ? slot
    : slot.querySelector<HTMLElement>('[data-field-focus]') ?? slot.querySelector<HTMLElement>(FOCUSABLE)
  if (typeof slot.scrollIntoView === 'function') {
    slot.scrollIntoView({ block: 'center', behavior: 'smooth' })
  }
  const target = focusable ?? slot
  target.focus({ preventScroll: true })
  return target
}
