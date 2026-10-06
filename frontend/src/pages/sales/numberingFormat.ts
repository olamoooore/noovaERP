// #548 (SET-UX4) / #542 (ST5): client-side mirror of the server's
// document-number format rules, for inline feedback in the numbering editor.
// The server (DocumentNumberingService) re-validates on preview and save.

export type ResetPeriod = 'never' | 'yearly' | 'monthly'

/**
 * Client-side mirror of the server's format rules (#542, ST5) for inline
 * feedback; the server re-validates on preview and save.
 */
export function validateNumberFormat(format: string): string[] {
  const errors: string[] = []
  let sequenceCount = 0

  for (const token of format.match(/\{[^}]*\}/g) ?? []) {
    const seq = token.match(/^\{SEQ:(\d+)\}$/)
    if (seq) {
      sequenceCount++
      const width = parseInt(seq[1], 10)
      if (width < 3 || width > 8) errors.push(`Sequence token ${token} must use between 3 and 8 digits.`)
      continue
    }
    if (!['{PREFIX}', '{YYYY}', '{YY}', '{MM}'].includes(token)) {
      errors.push(`Unsupported token ${token}. Supported tokens: {PREFIX}, {YYYY}, {YY}, {MM}, {SEQ:n}.`)
    }
  }

  if (sequenceCount !== 1) errors.push('The format must contain exactly one {SEQ:n} token.')

  const literal = format.replace(/\{(SEQ:\d+|SEQ|YYYY|YY|MM|PREFIX)\}/g, '')
  if (!/[A-Za-z]/.test(literal)) errors.push('The format must contain at least one letter (a document prefix).')

  return errors
}

/** Mirror of the server rule: a reset the format's date tokens cannot carry would repeat numbers. */
export function validateResetPeriod(format: string, period: ResetPeriod): string | null {
  const hasYear = format.includes('{YYYY}') || format.includes('{YY}')
  if (period === 'yearly' && !hasYear) {
    return 'A yearly reset needs a year token ({YYYY} or {YY}) in the format, or numbers would repeat.'
  }
  if (period === 'monthly' && (!hasYear || !format.includes('{MM}'))) {
    return 'A monthly reset needs both a year token ({YYYY} or {YY}) and {MM} in the format, or numbers would repeat.'
  }
  return null
}
