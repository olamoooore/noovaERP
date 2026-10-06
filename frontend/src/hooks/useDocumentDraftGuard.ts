import { useCallback, useEffect, useRef, useState } from 'react'
import { useHref, useNavigate } from 'react-router-dom'
import { useAuthStore } from '@/store/authStore'
import { useLeaveGuard } from '@/hooks/useLeaveGuard'

/**
 * Issue #317: unsaved-changes guard + local draft autosave for sales document
 * forms (quotation, sales order, invoice).
 *
 * - Dirty tracking: the form's serialisable `snapshot` is compared with a
 *   baseline that follows the form (async defaults, edit hydration) until the
 *   user first interacts with it (`trackProps` on the page wrapper).
 * - Leave guard: beforeunload while dirty, `guard()` for in-form actions
 *   (Back / Cancel), and in-app link clicks (sidebar, breadcrumbs) are held
 *   for confirmation. The app uses <BrowserRouter> (not a data router), so
 *   react-router's useBlocker is unavailable; the browser Back button is not
 *   intercepted.
 * - Draft: while dirty the snapshot is saved to localStorage, per tenant,
 *   user, document type and document id — only on this device, never to the
 *   server, and never posted. On return the user is offered to restore it;
 *   `clearDraft()` is called after a successful save.
 */

const STORAGE_PREFIX = 'noovaerp:document-draft:v1'
const AUTOSAVE_DELAY_MS = 300

interface StoredDraft {
  savedAt: string
  version: string | null
  data: string
}

export interface DocumentDraftOptions<T> {
  /** Document type, e.g. 'quotation', 'sales-order', 'invoice'. */
  kind: string
  /** Record id in edit mode; omitted for a new document. */
  documentId?: string | null
  /** Server lock version in edit mode — a draft from another version is dropped. */
  version?: string | number | null
  /** The form has its initial values (edit: hydrated from the server). */
  ready: boolean
  /** Save drafts locally (false = guard only). */
  persist?: boolean
  /** Serialisable form state. */
  snapshot: T
  /** Put a restored snapshot back into the form state. */
  apply: (snapshot: T) => void
}

const safeStorage = {
  get(key: string): string | null {
    try { return window.localStorage.getItem(key) } catch { return null }
  },
  set(key: string, value: string) {
    try { window.localStorage.setItem(key, value) } catch { /* storage full/blocked: the leave guard still protects the work */ }
  },
  remove(key: string) {
    try { window.localStorage.removeItem(key) } catch { /* see above */ }
  },
}

export function documentDraftKey(parts: { tenantId?: string | null; userId?: string | number | null; kind: string; documentId?: string | null }) {
  return [STORAGE_PREFIX, parts.tenantId ?? '-', parts.userId ?? '-', parts.kind, parts.documentId || 'new'].join(':')
}

export function useDocumentDraftGuard<T>(options: DocumentDraftOptions<T>) {
  const { kind, documentId, ready, persist = true, snapshot } = options
  const version = options.version == null ? null : String(options.version)
  const tenantId = useAuthStore((state) => state.tenantId)
  const userId = useAuthStore((state) => state.user?.id)
  const storageKey = documentDraftKey({ tenantId, userId, kind, documentId })
  const navigate = useNavigate()
  const basename = useHref('/').replace(/\/$/, '')

  const serialized = JSON.stringify(snapshot)
  const baselineRef = useRef<string | null>(null)
  const touchedRef = useRef(false)
  const clearedRef = useRef(false)
  const applyRef = useRef(options.apply)
  applyRef.current = options.apply

  const [pendingDraft, setPendingDraft] = useState<{ savedAt: string; data: T } | null>(null)
  const [draftChecked, setDraftChecked] = useState(false)

  // The baseline follows the form until the user's first interaction.
  useEffect(() => {
    if (ready && !touchedRef.current) baselineRef.current = serialized
  }, [ready, serialized])

  const dirty = ready
    && !clearedRef.current
    && touchedRef.current
    && baselineRef.current !== null
    && serialized !== baselineRef.current

  // On arrival: offer a saved draft for this document (once).
  useEffect(() => {
    if (!ready || draftChecked) return
    setDraftChecked(true)
    if (!persist) return
    const raw = safeStorage.get(storageKey)
    if (!raw) return
    try {
      const stored = JSON.parse(raw) as StoredDraft
      if (typeof stored?.data !== 'string' || stored.version !== version || stored.data === serialized) {
        // Saved against another version of the record (restoring it would
        // overwrite someone else's save) or nothing to restore.
        safeStorage.remove(storageKey)
        return
      }
      setPendingDraft({ savedAt: stored.savedAt, data: JSON.parse(stored.data) as T })
    } catch {
      safeStorage.remove(storageKey)
    }
  }, [ready, draftChecked, persist, storageKey, version, serialized])

  // Autosave while dirty. A draft waiting for restore/discard is never
  // overwritten behind the user's back.
  useEffect(() => {
    if (!persist || !dirty || pendingDraft || clearedRef.current) return
    const timer = window.setTimeout(() => {
      if (clearedRef.current) return
      const stored: StoredDraft = { savedAt: new Date().toISOString(), version, data: serialized }
      safeStorage.set(storageKey, JSON.stringify(stored))
    }, AUTOSAVE_DELAY_MS)
    return () => window.clearTimeout(timer)
  }, [persist, dirty, pendingDraft, serialized, storageKey, version])

  const markTouched = useCallback(() => {
    touchedRef.current = true
  }, [])

  const restoreDraft = useCallback(() => {
    if (!pendingDraft) return
    touchedRef.current = true
    applyRef.current(pendingDraft.data)
    setPendingDraft(null)
  }, [pendingDraft])

  const discardDraft = useCallback(() => {
    safeStorage.remove(storageKey)
    setPendingDraft(null)
  }, [storageKey])

  /** Call after a successful save: drops the draft and disarms the guard. */
  const clearDraft = useCallback(() => {
    clearedRef.current = true
    safeStorage.remove(storageKey)
  }, [storageKey])

  const leaveGuard = useLeaveGuard(dirty)
  const { guard } = leaveGuard

  // In-app links (sidebar, breadcrumbs, record links) are held for
  // confirmation while the document is dirty.
  useEffect(() => {
    if (!dirty) return
    const handleClick = (event: MouseEvent) => {
      if (event.defaultPrevented || event.button !== 0) return
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
      const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null
      if (!anchor || anchor.hasAttribute('download')) return
      if (anchor.target && anchor.target !== '_self') return
      const url = new URL(anchor.href, window.location.href)
      if (url.origin !== window.location.origin) return
      const current = window.location.pathname + window.location.search
      if (url.pathname + url.search === current) return
      event.preventDefault()
      event.stopPropagation()
      const path = basename && url.pathname.startsWith(basename) ? url.pathname.slice(basename.length) || '/' : url.pathname
      guard(() => navigate(path + url.search + url.hash))
    }
    document.addEventListener('click', handleClick, true)
    return () => document.removeEventListener('click', handleClick, true)
  }, [dirty, guard, navigate, basename])

  return {
    dirty,
    guard,
    clearDraft,
    /** Spread on the page wrapper so the first interaction freezes the baseline. */
    trackProps: {
      onPointerDownCapture: markTouched,
      onKeyDownCapture: markTouched,
      onChangeCapture: markTouched,
    },
    draft: {
      pending: pendingDraft !== null,
      savedAt: pendingDraft?.savedAt ?? null,
      restore: restoreDraft,
      discard: discardDraft,
    },
    leaveDialog: {
      open: leaveGuard.confirmOpen,
      onConfirm: leaveGuard.confirmDiscard,
      onKeepEditing: leaveGuard.keepEditing,
      persisted: persist,
    },
  }
}

export type DocumentDraftGuard = ReturnType<typeof useDocumentDraftGuard>
