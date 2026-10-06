import { useCallback, useRef } from 'react'

/**
 * Issue #320: "fill from the customer, but never over the user's own edits".
 *
 * Each field remembers the value the form last auto-filled into it. When a
 * (new) customer is picked, a field is refilled only if it is empty or still
 * holds that last auto-filled value — anything the user typed is left alone,
 * and nothing from the previous customer lingers in fields the user didn't
 * touch.
 */
export function useCustomerAutofill() {
  const lastFilledRef = useRef<Record<string, string>>({})

  const isUntouched = useCallback((key: string, current: string) => {
    const lastFilled = lastFilledRef.current[key]
    return current === '' || (lastFilled !== undefined && current === lastFilled)
  }, [])

  /**
   * Fill `key` with `next` when the user has not edited it. `serialize` turns
   * structured values (e.g. an address form) into a comparable string; an
   * all-empty value must serialize to ''.
   */
  const fill = useCallback(<T,>(
    key: string,
    current: T,
    next: T,
    setter: (value: T) => void,
    serialize: (value: T) => string = (value) => String(value ?? ''),
  ): boolean => {
    if (!isUntouched(key, serialize(current))) return false
    lastFilledRef.current[key] = serialize(next)
    setter(next)
    return true
  }, [isUntouched])

  /**
   * Record values loaded from a saved document as the auto-fill baseline, so a
   * later customer change can still replace them until the user edits them.
   */
  const setBaseline = useCallback((values: Record<string, string>) => {
    lastFilledRef.current = { ...values }
  }, [])

  return { fill, setBaseline }
}
