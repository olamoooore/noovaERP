import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Regression test for the "site reloads forever" loop.
 *
 * Symptom: the app renders the authenticated shell while sending requests
 * WITHOUT an Authorization header, so every call 401s. The 401 handler
 * redirected to /login, but /login bounced straight back to /dashboard
 * because the zustand store (localStorage `auth-storage`) still held a token
 * -> mount dashboard -> 401 -> /login -> /dashboard -> ... about once per
 * second, re-firing /features, /notifications/unread-count and /dashboard.
 *
 * Two invariants keep that from happening:
 *   1. requests use the SAME token source the route guards use (the store,
 *      falling back to the legacy raw key), and
 *   2. a 401 clears BOTH homes (store + legacy keys) before redirecting.
 */

function makeStorage() {
  const map = new Map<string, string>()
  return {
    getItem: (k: string) => (map.has(k) ? (map.get(k) as string) : null),
    setItem: (k: string, v: string) => void map.set(k, String(v)),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => Array.from(map.keys())[i] ?? null,
    get length() {
      return map.size
    },
  }
}

const storage = makeStorage()
// Seed the persisted store BEFORE importing it, exactly like a restored session
// whose API token has since been invalidated.
storage.setItem(
  'auth-storage',
  JSON.stringify({
    state: { token: 'stale-store-token', user: { id: 17, name: 'Admin' } },
    version: 0,
  })
)

vi.stubGlobal('localStorage', storage)
vi.stubGlobal('window', { location: { pathname: '/dashboard', href: '' } })

const { useAuthStore } = await import('@/store/authStore')
const { api } = await import('@/lib/api')

function responseHandler() {
  const handlers = (api.interceptors.response as any).handlers as any[]
  return handlers.find((h) => typeof h?.rejected === 'function').rejected
}

function requestHandler() {
  const handlers = (api.interceptors.request as any).handlers as any[]
  return handlers.find((h) => typeof h?.fulfilled === 'function').fulfilled
}

async function trigger401(pathname = '/dashboard') {
  const loc = (window as any).location
  loc.pathname = pathname
  loc.href = ''
  const error = { config: { url: '/api/v1/dashboard' }, response: { status: 401 } }
  await expect(responseHandler()(error)).rejects.toBeTruthy()
}

beforeEach(() => {
  storage.clear()
  useAuthStore.setState({ token: 'stale-store-token', user: { id: 17, name: 'Admin' } as any })
})

describe('auth token has a single source of truth', () => {
  it('hydrates the store from auth-storage (the state the guards gate on)', () => {
    expect(useAuthStore.getState().token).toBeTypeOf('string')
  })

  it('sends the store token even when the legacy raw key is missing', () => {
    expect(storage.getItem('token')).toBeNull()

    const config = requestHandler()({ headers: {} as Record<string, string> })

    expect(config.headers.Authorization).toBe('Bearer stale-store-token')
  })
})

describe('401 handling', () => {
  it('clears the persisted store token so /login cannot bounce back', async () => {
    await trigger401()

    expect(useAuthStore.getState().token).toBeNull()
    const persisted = JSON.parse(storage.getItem('auth-storage') as string)
    expect(persisted.state.token).toBeNull()
    expect(storage.getItem('token')).toBeNull()
    expect(storage.getItem('user')).toBeNull()
  })

  it('redirects to /login when not already there', async () => {
    await trigger401('/dashboard')
    expect((window as any).location.href).toBe('/login')
  })

  it('does not re-navigate when already on /login (parallel 401s)', async () => {
    await trigger401('/login')
    expect((window as any).location.href).toBe('')
  })
})
