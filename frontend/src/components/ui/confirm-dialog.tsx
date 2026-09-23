import type { ReactNode } from 'react'
import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
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
 * Shared confirmation dialog — replaces window.confirm everywhere so
 * destructive actions get a consistent, styled prompt instead of a browser
 * alert. Render it once per page and drive it with local state.
 */
export function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  icon = 'solar:danger-triangle-bold-duotone',
  tone = 'destructive',
  onConfirm,
  onCancel,
  isPending = false,
  children,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description?: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  icon?: string
  tone?: 'destructive' | 'warning' | 'default'
  onConfirm: () => void
  /** Optional side-effect for the cancel button (e.g. proceed without saving). */
  onCancel?: () => void
  isPending?: boolean
  children?: ReactNode
}) {
  const toneStyles = {
    destructive: {
      tile: 'bg-destructive/10',
      icon: 'text-destructive',
      button: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
    },
    warning: {
      tile: 'bg-amber-100 dark:bg-amber-950/60',
      icon: 'text-amber-600 dark:text-amber-400',
      button: 'bg-amber-600 text-white hover:bg-amber-700',
    },
    default: {
      tile: 'bg-primary/10',
      icon: 'text-primary',
      button: '',
    },
  }[tone]

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

        {children}

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              onOpenChange(false)
              onCancel?.()
            }}
            disabled={isPending}
          >
            {cancelLabel}
          </Button>
          <Button className={toneStyles.button || undefined} onClick={onConfirm} disabled={isPending}>
            {isPending && (
              <span className="mr-2 h-4 w-4 animate-spin rounded-full border-b-2 border-current" />
            )}
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
