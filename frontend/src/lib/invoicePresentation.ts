/** Remove legacy internal references while preserving genuine invoice notes. */
export function getVisibleInvoiceNotes(notes: string | null | undefined): string {
  if (!notes) return ''

  return notes
    .split(/\r?\n/)
    .filter((line) => !/^\s*Sales Order\s*#\s*\S+\s*$/i.test(line))
    .join('\n')
    .trim()
}
