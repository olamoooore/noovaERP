import { Icon } from '@iconify/react'

interface QuickOption {
  id: string
  icon: string
  label: string
  description: string
}

interface QuickOptionsProps {
  options: QuickOption[]
  onSelect: (optionId: string) => void
}

/**
 * Module ids -> themed duotone icons. Backend module icons are emoji
 * characters, which render inconsistently across platforms and clash with
 * the app's icon language — every option gets a proper icon here instead.
 */
const OPTION_ICONS: Record<string, string> = {
  orders: 'solar:delivery-bold-duotone',
  loyalty: 'solar:gift-bold-duotone',
  products: 'solar:bag-4-bold-duotone',
  inventory: 'solar:layers-minimalistic-bold-duotone',
  invoices: 'solar:document-text-bold-duotone',
  account: 'solar:user-circle-bold-duotone',
  accounting_insights: 'solar:chart-square-bold-duotone',
  analysis: 'solar:chart-square-bold-duotone',
  crm: 'solar:users-group-rounded-bold-duotone',
  hrm: 'solar:users-group-two-rounded-bold-duotone',
  sales: 'solar:cart-large-2-bold-duotone',
  purchases: 'solar:bag-check-bold-duotone',
  pos: 'solar:shop-bold-duotone',
  payments: 'solar:card-bold-duotone',
}

function iconFor(option: QuickOption): string {
  // Prefer a proper icon name if the backend ever sends one; ignore emoji.
  if (option.icon && option.icon.includes(':')) return option.icon
  return OPTION_ICONS[option.id] ?? 'solar:widget-5-bold-duotone'
}

export function QuickOptions({ options, onSelect }: QuickOptionsProps) {
  if (options.length === 0) return null

  return (
    <div className="my-3">
      <p className="mb-2 px-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
        Quick actions
      </p>
      {/* Compact two-column grid: the old full-width rows overflowed the
          chat panel once all module options were shown. */}
      <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
        {options.map((option) => (
          <button
            key={option.id}
            type="button"
            onClick={() => onSelect(option.id)}
            className="group flex w-full items-center gap-2 rounded-lg border bg-card px-2.5 py-1.5 text-left transition-colors hover:border-primary/40 hover:bg-accent"
          >
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
              <Icon icon={iconFor(option)} className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-xs font-medium text-foreground">{option.label}</span>
              {option.description ? (
                <span className="block truncate text-[11px] text-muted-foreground">{option.description}</span>
              ) : null}
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}
