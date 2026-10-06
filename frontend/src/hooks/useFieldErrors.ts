import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import {
  extractValidationErrors,
  focusFirstFieldError,
  remapLineErrors,
  type FieldErrors,
} from '@/lib/serverValidation'

export interface FieldErrorsState {
  errors: FieldErrors
  /** Replace the current errors; the first invalid field is scrolled to and focused. */
  show: (errors: FieldErrors) => void
  /** Drop one field's error (the user is fixing it). */
  clear: (key: string) => void
  /** Drop every `lines.*` error — row indexes are stale after a move/insert/delete. */
  clearLines: () => void
  clearAll: () => void
  /**
   * Show a Laravel 422's field errors inline (payload line indexes mapped to
   * grid rows via `rowIndexes`). Returns false when the error is not a 422
   * with field errors, so the caller keeps its own handling.
   */
  showServer: (error: unknown, rowIndexes?: number[]) => boolean
}

/**
 * #318: inline field errors for a document form. Pair with
 * `FieldErrorsProvider` + `FieldSlot` (components/ui/field-errors).
 */
export function useFieldErrors(): FieldErrorsState {
  const [errors, setErrors] = useState<FieldErrors>({})
  const focusPending = useRef(false)

  const show = useCallback((next: FieldErrors) => {
    focusPending.current = Object.keys(next).length > 0
    setErrors({ ...next })
  }, [])

  const clear = useCallback((key: string) => {
    setErrors((current) => {
      if (!(key in current)) return current
      const { [key]: _removed, ...rest } = current
      return rest
    })
  }, [])

  const clearLines = useCallback(() => {
    setErrors((current) => {
      const keys = Object.keys(current)
      if (!keys.some((key) => key.startsWith('lines.'))) return current
      return Object.fromEntries(Object.entries(current).filter(([key]) => !key.startsWith('lines.')))
    })
  }, [])

  const clearAll = useCallback(() => setErrors((current) => (Object.keys(current).length ? {} : current)), [])

  const showServer = useCallback((error: unknown, rowIndexes: number[] = []) => {
    const serverErrors = extractValidationErrors(error)
    if (!serverErrors) return false
    show(remapLineErrors(serverErrors, rowIndexes))
    return true
  }, [show])

  // Focus after the render that painted the messages.
  useEffect(() => {
    if (!focusPending.current) return
    focusPending.current = false
    focusFirstFieldError(errors)
  }, [errors])

  return useMemo(
    () => ({ errors, show, clear, clearLines, clearAll, showServer }),
    [errors, show, clear, clearLines, clearAll, showServer],
  )
}

/** Provided by `FieldErrorsProvider`; null outside a form that opted in. */
export const FieldErrorsContext = createContext<FieldErrorsState | null>(null)

export function useFieldErrorsContext(): FieldErrorsState | null {
  return useContext(FieldErrorsContext)
}
