import { describe, expect, it } from 'vitest'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

/**
 * Regression guard.
 *
 * HeaderActionButton is used as a Radix `asChild` trigger
 * (DropdownMenuTrigger for the Show/Hide Columns menu). Radix clones the child
 * and passes a ref to it; a plain function component silently drops that ref,
 * so the trigger never binds and clicking the button does nothing — which is
 * exactly how "Show/Hide Columns" stopped opening.
 *
 * React marks ref-accepting components structurally, so this asserts the
 * actual capability rather than any source text.
 */
describe('HeaderActionButton', () => {
  it('accepts a ref, as Radix asChild triggers require', () => {
    expect((HeaderActionButton as any).$$typeof).toBe(Symbol.for('react.forward_ref'))
  })
})
