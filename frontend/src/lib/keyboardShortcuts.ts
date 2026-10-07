// #508 (PY6): keyboard-first payment entry — shared helpers.

/**
 * True when a key press belongs to the field the user is typing in, so a
 * single-key page shortcut (P, ?) must NOT fire.
 */
export function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  const tag = target.tagName;
  if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
  if (tag === 'INPUT') {
    const type = (target as HTMLInputElement).type;
    return !['checkbox', 'radio', 'button', 'submit', 'reset'].includes(type);
  }
  return target.closest('[role="combobox"], [role="textbox"], [role="listbox"]') != null;
}

/** True for a bare key press (no Ctrl/⌘/Alt) — single-key shortcuts only. */
export function isPlainKey(event: { ctrlKey: boolean; metaKey: boolean; altKey: boolean }): boolean {
  return !event.ctrlKey && !event.metaKey && !event.altKey;
}

export interface ShortcutEntry {
  keys: string[];
  description: string;
}

/** True for Ctrl+K (⌘+K on Mac) — the #347 (D10) command bar shortcut. */
export function isCommandBarKey(event: { key: string; ctrlKey: boolean; metaKey: boolean; altKey: boolean }): boolean {
  return (event.ctrlKey || event.metaKey) && !event.altKey && event.key.toLowerCase() === 'k';
}

// #347: a page with its own, document-scoped command bar (the invoice form's
// `add 10 widget-a @90` / `terms 60` bar) claims Ctrl+K while it is mounted,
// so the global command bar stays closed there instead of both opening.
let commandBarClaims = 0;

/** Claim Ctrl+K for a page-level command bar; call the returned function to release it. */
export function claimCommandBar(): () => void {
  commandBarClaims += 1;
  let released = false;
  return () => {
    if (released) return;
    released = true;
    commandBarClaims -= 1;
  };
}

/** True while a page-level command bar owns Ctrl+K. */
export function isCommandBarClaimed(): boolean {
  return commandBarClaims > 0;
}

/** #347: the global command bar, listed first in the "?" overlay. */
export const COMMAND_BAR_SHORTCUTS: ShortcutEntry[] = [
  {
    keys: ['Ctrl', 'K'],
    description: 'Open the command bar: create a quotation, order, invoice or payment, open a document by number, go to a customer (⌘+K on Mac)',
  },
];

/** Shortcuts listed in the "?" overlay on the invoice list. */
export const PAYMENT_SHORTCUTS: ShortcutEntry[] = [
  { keys: ['P'], description: 'Register a payment for the focused invoice row' },
  { keys: ['Enter'], description: 'Payment dialog: save the payment as a draft' },
  { keys: ['Ctrl', 'Enter'], description: 'Payment dialog: save and post to the ledger (needs accounting.post; ⌘+Enter on Mac)' },
  { keys: ['Esc'], description: 'Close the dialog' },
  { keys: ['?'], description: 'Show this list of shortcuts' },
];
