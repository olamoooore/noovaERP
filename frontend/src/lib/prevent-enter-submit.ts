import type { KeyboardEvent } from 'react'

/**
 * Stop Enter from implicitly submitting a long document form.
 *
 * Invoice / quotation / vendor-bill editors are filled over minutes; a stray
 * Enter in any single-line field (e.g. Invoice Number) used to trigger the
 * browser's native implicit form submission and create a half-finished
 * document. Enter stays available for multi-line fields (newlines) and for
 * widgets rendered in portals (comboboxes/menus), which are outside the form.
 *
 * Usage: <form onSubmit={handleSubmit} onKeyDown={preventImplicitSubmit}>
 */
export function preventImplicitSubmit(event: KeyboardEvent<HTMLFormElement>): void {
  if (event.key !== 'Enter') return

  const target = event.target as HTMLElement | null
  if (!target) return

  // Textareas keep Enter for newlines.
  if (target.tagName === 'TEXTAREA') return

  // Only plain single-line fields would trigger implicit submission.
  if (target.tagName !== 'INPUT' && target.tagName !== 'SELECT') return

  // Buttons, checkboxes, radios and other activatable controls keep their
  // native Enter behaviour.
  if (target instanceof HTMLInputElement) {
    const type = (target.type || 'text').toLowerCase()
    if (['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'range', 'color'].includes(type)) {
      return
    }
  }

  event.preventDefault()
}