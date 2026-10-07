import React from 'react'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { rememberedHintText, type RememberedDefaults } from '@/hooks/useRememberedCustomerDefaults'

/**
 * Issue #346 (D9): a small "remembered from last order" hint naming the
 * document the customer's remembered choices came from.
 */
export const RememberedDefaultsHint: React.FC<{ remembered: RememberedDefaults | null | undefined; className?: string }> = ({
  remembered,
  className,
}) => {
  const text = rememberedHintText(remembered)
  if (!text) return null

  return (
    <p
      className={cn('flex items-center gap-1.5 text-xs text-muted-foreground', className)}
      data-testid="remembered-defaults-hint"
      role="status"
    >
      <Icon icon="solar:history-linear" className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span>{text}</span>
    </p>
  )
}
