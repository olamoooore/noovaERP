import { useEffect, useState, type ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'

/**
 * Shared single-field prompt dialog — the styled replacement for
 * window.prompt (URL entry, reversal reasons, refund notes…).
 *
 * Render once per page/component and drive it with local state:
 *   const [prompt, setPrompt] = useState<null | { title: string; ... }>(null)
 */
export function PromptDialog({
  open,
  onOpenChange,
  title,
  description,
  label,
  placeholder,
  defaultValue = '',
  confirmLabel = 'Submit',
  cancelLabel = 'Cancel',
  icon = 'solar:pen-bold-duotone',
  tone = 'default',
  required = true,
  minLength,
  multiline = false,
  onConfirm,
  isPending = false,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: ReactNode
  label?: string
  placeholder?: string
  defaultValue?: string
  confirmLabel?: string
  cancelLabel?: string
  icon?: string
  tone?: 'default' | 'destructive' | 'warning'
  required?: boolean
  minLength?: number
  multiline?: boolean
  onConfirm: (value: string) => void
  isPending?: boolean
}) {
  const [value, setValue] = useState(defaultValue)

  // Reset the field each time the dialog opens (or the default changes)
  useEffect(() => {
    if (open) setValue(defaultValue)
  }, [open, defaultValue])

  const toneStyles = {
    default: { tile: 'bg-primary/10', icon: 'text-primary' },
    destructive: { tile: 'bg-destructive/10', icon: 'text-destructive' },
    warning: { tile: 'bg-amber-100 dark:bg-amber-950/60', icon: 'text-amber-600 dark:text-amber-400' },
  }[tone]

  const tooShort = required
    ? value.trim().length === 0 || (minLength ? value.trim().length < minLength : false)
    : false

  const submit = () => {
    if (tooShort) return
    onConfirm(value.trim())
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <div className="flex items-start gap-3">
            <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-full', toneStyles.tile)}>
              <Icon icon={icon} className={cn('h-5 w-5', toneStyles.icon)} />
            </div>
            <div className="space-y-1">
              <DialogTitle>{title}</DialogTitle>
              {description && <DialogDescription asChild>{description}</DialogDescription>}
            </div>
          </div>
        </DialogHeader>

        <div className="space-y-2 py-1">
          {label && <Label htmlFor="prompt-value">{label}</Label>}
          {multiline ? (
            <textarea
              id="prompt-value"
              autoFocus
              value={value}
              onChange={(e) => setValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit()
              }}
              placeholder={placeholder}
              className="flex min-h-[90px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
            />
          ) : (
            <Input
              id="prompt-value"
              autoFocus
              value={value}
              onChange={(e) => setValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') submit()
              }}
              placeholder={placeholder}
            />
          )}
          {minLength ? (
            <p className="text-xs text-muted-foreground">
              {value.trim().length}/{minLength} characters minimum
            </p>
          ) : null}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isPending}>
            {cancelLabel}
          </Button>
          <Button onClick={submit} disabled={isPending || tooShort}>
            {isPending && <span className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-current" />}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
