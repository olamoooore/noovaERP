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

/** Shortcuts listed in the "?" overlay on the invoice list. */
export const PAYMENT_SHORTCUTS: ShortcutEntry[] = [
  { keys: ['P'], description: 'Register a payment for the focused invoice row' },
  { keys: ['Enter'], description: 'Payment dialog: save the payment as a draft' },
  { keys: ['Ctrl', 'Enter'], description: 'Payment dialog: save and post to the ledger (needs accounting.post; ⌘+Enter on Mac)' },
  { keys: ['Esc'], description: 'Close the dialog' },
  { keys: ['?'], description: 'Show this list of shortcuts' },
];
