import { type ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { FieldErrorsContext, useFieldErrorsContext, type FieldErrorsState } from '@/hooks/useFieldErrors'

/**
 * #318: inline field errors. A form provides its `useFieldErrors()` state;
 * each control is wrapped in a `FieldSlot` registered under its validation
 * key (`customer_id`, `lines.2.quantity`, ...). The slot highlights the
 * control, shows the message under it, and clears it when the control changes.
 * Without a provider a slot renders its children only.
 */

export function FieldErrorsProvider({ value, children }: { value: FieldErrorsState; children: ReactNode }) {
  return <FieldErrorsContext.Provider value={value}>{children}</FieldErrorsContext.Provider>
}

const INVALID_CONTROL =
  '[&_input]:!border-destructive [&_textarea]:!border-destructive [&_select]:!border-destructive [&_button]:!border-destructive'

export function FieldSlot({
  name,
  className,
  highlight = true,
  children,
}: {
  name: string
  className?: string
  /** Outline the wrapped controls in red while invalid (off for whole-section slots). */
  highlight?: boolean
  children: ReactNode
}) {
  const context = useFieldErrorsContext()
  const message = context?.errors[name]

  return (
    <div
      data-field-key={name}
      data-invalid={message ? 'true' : undefined}
      className={cn(className, message && highlight && INVALID_CONTROL)}
      onChangeCapture={message ? () => context?.clear(name) : undefined}
    >
      {children}
      {message && (
        <p role="alert" className="mt-1 text-xs font-medium text-destructive">
          {message}
        </p>
      )}
    </div>
  )
}
