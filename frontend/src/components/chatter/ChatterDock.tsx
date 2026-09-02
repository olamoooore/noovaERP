import React, { useState, useEffect } from 'react'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { ChatterPanel } from './ChatterPanel'

interface Props {
  subjectType: string
  subjectId: number | string
  documentLabel?: string
  readOnly?: boolean
  /**
   * Where the chatter panel should dock.
   * - 'inline' (default): renders inside the page with a header tab;
   *   clicking opens a full-width inline panel below the header
   * - 'side': opens as a right-side drawer pinned to the viewport
   */
  position?: 'inline' | 'side'
  /** Default open state when position='inline' */
  defaultOpen?: boolean
  /** Optional controlled open state */
  open?: boolean
  /** Optional callback for open state changes (useful for syncing a button) */
  onOpenChange?: (open: boolean) => void
  className?: string
}

/**
 * Odoo-style inline chatter:
 * - a small header bar with the chatter icon + unread count + collapse arrow
 * - clicking expands a full-width panel directly under the page header
 * - 'side' variant docks to the right of the viewport
 *
 * Replaces the modal ChatterDialog so the user never loses context
 * of the underlying record.
 */
export function ChatterDock({
  subjectType,
  subjectId,
  documentLabel,
  readOnly,
  position = 'inline',
  defaultOpen = false,
  open: controlledOpen,
  onOpenChange,
  className,
}: Props) {
  const [internalOpen, setInternalOpen] = useState<boolean>(defaultOpen)
  const isControlled = controlledOpen !== undefined
  const open = isControlled ? controlledOpen : internalOpen
  const setOpen = (next: boolean | ((prev: boolean) => boolean)) => {
    const resolved = typeof next === 'function' ? next(open) : next
    if (!isControlled) setInternalOpen(resolved)
    onOpenChange?.(resolved)
  }

  // Close on Escape for the side drawer
  useEffect(() => {
    if (position !== 'side') return
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [position])

  if (position === 'side') {
    return (
      <>
        <Button
          variant="outline"
          size="sm"
          onClick={() => setOpen((v) => !v)}
          className={className}
          aria-pressed={open}
        >
          <Icon
            icon={open ? 'solar:chat-round-dots-bold' : 'solar:chat-round-dots-outline'}
            className="mr-2 h-4 w-4"
          />
          Chatter
          {documentLabel && (
            <span className="ml-2 text-muted-foreground text-xs font-normal">
              {documentLabel}
            </span>
          )}
          <Icon
            icon={open ? 'solar:alt-arrow-right-linear' : 'solar:alt-arrow-left-linear'}
            className="ml-2 h-4 w-4"
          />
        </Button>
        {open && (
          <div
            className={cn(
              'fixed inset-y-0 right-0 z-40 w-[420px] max-w-[90vw]',
              'border-l bg-background shadow-xl',
              'flex flex-col',
              className
            )}
          >
            <div className="flex items-center justify-between border-b px-4 py-3">
              <div className="flex items-center gap-2">
                <Icon icon="solar:chat-round-dots-bold" className="h-5 w-5 text-primary" />
                <div className="flex flex-col">
                  <span className="text-sm font-semibold">Chatter</span>
                  {documentLabel && (
                    <span className="text-xs text-muted-foreground">{documentLabel}</span>
                  )}
                </div>
              </div>
              <Button
                variant="ghost"
                size="icon"
                onClick={() => setOpen(false)}
                aria-label="Close chatter"
              >
                <Icon icon="solar:close-circle-bold" className="h-5 w-5" />
              </Button>
            </div>
            <div className="flex-1 overflow-hidden">
              <ChatterPanel
                subjectType={subjectType}
                subjectId={subjectId}
                documentLabel={documentLabel}
                readOnly={readOnly}
                className="h-full border-0 rounded-none"
              />
            </div>
          </div>
        )}
      </>
    )
  }

  // Inline (default): a collapsible panel directly under the page header
  return (
    <div className={cn('border rounded-lg bg-card overflow-hidden', className)}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'flex w-full items-center justify-between px-4 py-3',
          'hover:bg-muted/50 transition-colors text-left',
          open && 'border-b'
        )}
        aria-expanded={open}
      >
        <div className="flex items-center gap-2">
          <Icon
            icon="solar:chat-round-dots-bold-duotone"
            className="h-5 w-5 text-primary"
          />
          <span className="font-semibold">Chatter</span>
          {documentLabel && (
            <span className="text-sm text-muted-foreground font-normal">
              — {documentLabel}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <span>{open ? 'Hide' : 'Show'}</span>
          <Icon
            icon={open ? 'solar:alt-arrow-up-linear' : 'solar:alt-arrow-down-linear'}
            className="h-5 w-5"
          />
        </div>
      </button>
      {open && (
        <div className="h-[500px] max-h-[60vh]">
          <ChatterPanel
            subjectType={subjectType}
            subjectId={subjectId}
            documentLabel={documentLabel}
            readOnly={readOnly}
            className="h-full border-0 rounded-none"
          />
        </div>
      )}
    </div>
  )
}
