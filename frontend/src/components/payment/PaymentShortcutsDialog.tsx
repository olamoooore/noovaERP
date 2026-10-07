import React from 'react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PAYMENT_SHORTCUTS } from '@/lib/keyboardShortcuts';

/** #508 (PY6): the "?" overlay — keyboard shortcuts for payment entry. */
export const PaymentShortcutsDialog: React.FC<{
  open: boolean;
  onOpenChange: (open: boolean) => void;
}> = ({ open, onOpenChange }) => (
  <Dialog open={open} onOpenChange={onOpenChange}>
    <DialogContent className="max-w-md">
      <DialogHeader>
        <DialogTitle>Keyboard shortcuts</DialogTitle>
        <DialogDescription>Shortcuts do not fire while you are typing in a field.</DialogDescription>
      </DialogHeader>
      <ul className="space-y-2 text-sm">
        {PAYMENT_SHORTCUTS.map((shortcut) => (
          <li key={shortcut.keys.join('+')} className="flex items-start justify-between gap-4">
            <span className="flex shrink-0 gap-1">
              {shortcut.keys.map((key) => (
                <kbd key={key} className="rounded border bg-muted px-1.5 py-0.5 font-mono text-xs">{key}</kbd>
              ))}
            </span>
            <span className="text-right text-muted-foreground">{shortcut.description}</span>
          </li>
        ))}
      </ul>
    </DialogContent>
  </Dialog>
);
