import { cn } from '@/lib/utils'

/**
 * Issue #323 (§8.3): the compact design system shared by every sales
 * document form. One control height (36px), one text size (13px — the
 * densified `text-sm` scale) and exactly ONE corner radius (`rounded-lg`,
 * i.e. `var(--radius)` in tailwind.config.js).
 *
 * The same values are declared as `.sales-form` CSS variables in index.css
 * so the density rules and any non-React markup read the same tokens.
 */
export const SALES_FORM_CLASS = 'sales-form'
export const SALES_CONTROL_HEIGHT = 'h-9' // 36px
export const SALES_TEXT_SIZE = 'text-sm' // 13px after the densified scale
export const SALES_RADIUS = 'rounded-lg' // single sales-form radius

/** Standard 36px sales-form control (input/select). */
export const SALES_CONTROL = cn(
  'h-9 w-full min-w-0 border border-input bg-background px-2.5 text-sm text-foreground shadow-sm',
  'rounded-lg placeholder:text-muted-foreground',
  'focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
)

/** Standard sales-form textarea — same tokens, natural height. */
export const SALES_TEXTAREA = cn(
  'min-h-9 w-full resize-y border border-input bg-background px-2.5 py-1.5 text-sm text-foreground shadow-sm',
  'rounded-lg placeholder:text-muted-foreground',
  'focus:outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
)

/** Bordered shell for an inline label+control chip (currency, price list, …). */
export const SALES_FIELD_SHELL = cn(
  'inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-background px-3 text-sm',
)

/** Transparent control living inside a SALES_FIELD_SHELL. */
export const SALES_FIELD_CONTROL = 'bg-transparent text-sm font-medium focus:outline-none'
