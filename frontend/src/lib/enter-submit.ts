/**
 * Enter-to-submit for create/edit surfaces — one global handler.
 *
 * Pressing Enter in a single-line field should run the primary action
 * (Create / Save) on every create form and dialog:
 *
 *  - Inside a <form>: if the form (or a form-associated button) carries
 *    `data-enter-submit`, that button is clicked; otherwise the browser's
 *    native implicit submission handles it.
 *  - Inside a form-less dialog: the marked button wins, else the dialog's
 *    single visible primary button is clicked.
 *  - Form-less pages (e.g. Delivery Order create): the page's marked button
 *    is clicked when exactly one exists.
 *
 * Safety rules — Enter is never hijacked when it belongs to another widget:
 *  - textareas keep Enter for newlines (Ctrl/Cmd+Enter submits),
 *  - open comboboxes/menus/select popovers keep Enter for option selection,
 *  - checkboxes/radios/file inputs are ignored,
 *  - disabled buttons are respected,
 *  - already-handled events (defaultPrevented) are left alone,
 *  - anything inside `[data-enter-submit-ignore]` (line-item grids, note
 *    rows) is left to the editor.
 */
export function installEnterSubmit(): () => void {
  const handler = (event: KeyboardEvent) => {
    if (event.key !== 'Enter' || event.shiftKey || event.altKey) return
    if (event.defaultPrevented) return

    const target = event.target as HTMLElement | null
    if (!target) return

    const isTextarea = target instanceof HTMLTextAreaElement
    const isTextInput =
      (target instanceof HTMLInputElement &&
        !['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'range', 'color'].includes(target.type)) ||
      target instanceof HTMLSelectElement ||
      target.isContentEditable

    if (!isTextarea && !isTextInput) return
    if (isTextarea && !(event.metaKey || event.ctrlKey)) return

    // Enter belongs to an open dropdown/menu/combobox — let it select.
    if (target.closest('[cmdk-root], [role="listbox"], [role="menu"], [data-radix-popper-content-wrapper]')) {
      return
    }

    // Editing grids (line items, note lines) opt out: Enter is used while
    // typing rows, so it must never fire the document's primary action.
    // preventDefault is required — returning alone still lets the browser's
    // native implicit submission fire the form (regression: Enter in a note
    // line created the invoice mid-edit even with the opt-out marker).
    if (target.closest('[data-enter-submit-ignore]')) {
      event.preventDefault()
      return
    }

    const clickIfEnabled = (button: HTMLElement | null | undefined) => {
      if (!button) return false
      if (button instanceof HTMLButtonElement && (button.disabled || button.getAttribute('aria-disabled') === 'true')) {
        return false
      }
      event.preventDefault()
      button.click()
      return true
    }

    const findMarker = (root: ParentNode) =>
      root.querySelector<HTMLElement>('[data-enter-submit]:not([disabled]):not([aria-disabled="true"])')

    const form = target.closest('form')
    if (form) {
      // Marked submit button inside the form, or linked via the `form` attribute.
      if (clickIfEnabled(findMarker(form))) return
      if (form.id) {
        const associated = Array.from(
          document.querySelectorAll<HTMLElement>(`[data-enter-submit][form="${form.id}"]`),
        ).find((el) => !(el instanceof HTMLButtonElement) || (!el.disabled && el.getAttribute('aria-disabled') !== 'true'))
        if (clickIfEnabled(associated)) return
      }
      // No marker — native implicit submission takes over.
      return
    }

    const dialog = target.closest('[role="dialog"]')
    if (dialog) {
      if (clickIfEnabled(findMarker(dialog))) return

      const primaries = Array.from(dialog.querySelectorAll<HTMLButtonElement>('button')).filter(
        (button) =>
          !button.disabled &&
          button.offsetParent !== null &&
          button.classList.contains('bg-primary'),
      )
      if (primaries.length === 1) {
        clickIfEnabled(primaries[0])
      }
      return
    }

    // Form-less page: only an unambiguous top-level marker is clicked
    // (markers inside forms/dialogs are handled by their own scopes).
    const pageMarkers = Array.from(
      document.querySelectorAll<HTMLElement>('[data-enter-submit]:not([disabled]):not([aria-disabled="true"])'),
    ).filter((el) => !el.closest('form') && !el.closest('[role="dialog"]'))
    if (pageMarkers.length === 1) {
      clickIfEnabled(pageMarkers[0])
    }
  }

  document.addEventListener('keydown', handler)

  return () => document.removeEventListener('keydown', handler)
}