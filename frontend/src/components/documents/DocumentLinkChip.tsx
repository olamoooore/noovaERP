import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

export type DocumentKind = 'invoice' | 'quotation' | 'delivery_order'

/**
 * One colour identity per document type, used by every related-document chip
 * in the app so operators can recognise a document at a glance:
 *   Invoice        → blue
 *   Quotation      → violet
 *   Delivery Order → amber
 */
export const DOCUMENT_KIND_META: Record<
  DocumentKind,
  { icon: string; chip: string; activeChip: string }
> = {
  invoice: {
    icon: 'solar:bill-list-bold-duotone',
    chip: 'border-blue-300/60 bg-blue-50 text-blue-700 hover:border-blue-400 hover:bg-blue-100 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60',
    activeChip: 'border-blue-500/60 bg-blue-100 text-blue-800 dark:border-blue-700 dark:bg-blue-950/70 dark:text-blue-200',
  },
  quotation: {
    icon: 'solar:document-text-bold-duotone',
    chip: 'border-violet-300/60 bg-violet-50 text-violet-700 hover:border-violet-400 hover:bg-violet-100 dark:border-violet-900/60 dark:bg-violet-950/40 dark:text-violet-300 dark:hover:bg-violet-950/60',
    activeChip: 'border-violet-500/60 bg-violet-100 text-violet-800 dark:border-violet-700 dark:bg-violet-950/70 dark:text-violet-200',
  },
  delivery_order: {
    icon: 'solar:delivery-bold-duotone',
    chip: 'border-amber-300/60 bg-amber-50 text-amber-700 hover:border-amber-400 hover:bg-amber-100 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60',
    activeChip: 'border-amber-500/60 bg-amber-100 text-amber-800 dark:border-amber-700 dark:bg-amber-950/70 dark:text-amber-200',
  },
}

interface DocumentLinkChipProps {
  kind: DocumentKind
  /** Chip text, e.g. the document number or "2 Invoices". */
  label: string
  onClick: () => void
  /** Toggled-on state (e.g. the linked-invoices panel is open). */
  active?: boolean
  className?: string
  title?: string
}

export function DocumentLinkChip({
  kind,
  label,
  onClick,
  active = false,
  className,
  title,
}: DocumentLinkChipProps) {
  const meta = DOCUMENT_KIND_META[kind]

  return (
    <button
      type="button"
      onClick={onClick}
      title={title}
      className={cn(
        'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors',
        active ? meta.activeChip : meta.chip,
        className,
      )}
    >
      <Icon icon={meta.icon} className="h-3.5 w-3.5" />
      {label}
    </button>
  )
}