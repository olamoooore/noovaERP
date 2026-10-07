// @vitest-environment jsdom
// #508 (PY6): keyboard-first payment entry in the shared payment dialog —
// Enter saves (draft), Ctrl/⌘+Enter saves AND posts, but only for users
// holding accounting.post (the same gate as POST /payments/{id}/post, #480);
// the payment method is remembered per user.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, waitFor, fireEvent, within } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useAuthStore } from '@/store/authStore'
import { RegisterPaymentDialog } from './RegisterPaymentDialog'
import { clearMethodMemory, readRememberedMethod, rememberMethod } from '@/lib/paymentDepositAccount'

if (!window.matchMedia) {
  window.matchMedia = ((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  })) as unknown as typeof window.matchMedia
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void }
}
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  }
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {}
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false
  Element.prototype.setPointerCapture = () => {}
  Element.prototype.releasePointerCapture = () => {}
}

const { apiGet, apiPost, apiPut } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn(), apiPut: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: apiPut, patch: vi.fn(), delete: vi.fn() },
}))

const setPermissions = (permissions: string[]) =>
  useAuthStore.setState({
    role: 'Accountant',
    user: { id: 2, name: 'Acct', email: 'a@b.c' },
    token: 't',
    permissions,
  })

beforeEach(() => {
  clearMethodMemory(2)
  setPermissions(['accounting.view', 'accounting.write', 'accounting.post'])
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/7') {
      return { data: { id: 7, invoice_number: 'INV-7', customer_id: 3, balance: 250, total: 250 } }
    }
    if (url === '/accounting/bank-accounts') {
      return { data: { data: [{ id: 5, account_name: 'Maybank Current', gl_account_code: '102000' }] } }
    }
    return { data: { data: [] } }
  })
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/payments') return { data: { id: 99, data: { id: 99 } } }
    return { data: { message: 'Payment posted' } }
  })
  apiPut.mockResolvedValue({ data: { data: {} } })
})

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

function renderDialog() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <RegisterPaymentDialog invoiceId={7} onOpenChange={() => {}} />
    </QueryClientProvider>,
  )
}

async function readyAmount() {
  const dialog = await screen.findByRole('dialog')
  const amount = within(dialog).getByLabelText(/^amount/i) as HTMLInputElement
  await waitFor(() => expect(amount.value).toBe('250'))
  // deposit account defaulted (so the payload is complete)
  await waitFor(() => expect(apiGet).toHaveBeenCalledWith('/accounting/bank-accounts'))
  return amount
}

const postCalls = () => apiPost.mock.calls.map((call) => call[0] as string)

describe('RegisterPaymentDialog keyboard entry (#508 PY6)', () => {
  it('Enter saves the payment as a draft and does not post it', async () => {
    renderDialog()
    const amount = await readyAmount()
    fireEvent.keyDown(amount, { key: 'Enter' })
    // jsdom has no implicit submission; per the HTML spec, Enter in a field
    // clicks the form's DEFAULT button — the first submit button in tree order.
    const form = amount.closest('form') as HTMLFormElement
    const defaultButton = form.querySelector('button[type="submit"]') as HTMLButtonElement
    expect(defaultButton.textContent).toMatch(/record payment/i)
    fireEvent.click(defaultButton)

    await waitFor(() => expect(postCalls()).toContain('/payments'))
    expect(postCalls()).not.toContain('/payments/99/post')
  })

  it('Ctrl+Enter saves and posts when the user has accounting.post', async () => {
    renderDialog()
    const amount = await readyAmount()
    fireEvent.keyDown(amount, { key: 'Enter', ctrlKey: true })

    await waitFor(() => expect(postCalls()).toEqual(['/payments', '/payments/99/post']))
  })

  it('⌘+Enter saves and posts on a Mac', async () => {
    renderDialog()
    const amount = await readyAmount()
    fireEvent.keyDown(amount, { key: 'Enter', metaKey: true })

    await waitFor(() => expect(postCalls()).toEqual(['/payments', '/payments/99/post']))
  })

  it('Ctrl+Enter never posts without accounting.post', async () => {
    setPermissions(['accounting.view', 'accounting.write'])
    renderDialog()
    const amount = await readyAmount()
    fireEvent.keyDown(amount, { key: 'Enter', ctrlKey: true })

    await new Promise((r) => setTimeout(r, 30))
    expect(postCalls()).not.toContain('/payments/99/post')
    expect(screen.queryByRole('button', { name: /save & post/i })).toBeNull()
  })

  it('offers a "Save & post" button to the same users the shortcut posts for', async () => {
    renderDialog()
    await readyAmount()
    expect(screen.getByRole('button', { name: /save & post/i })).toBeTruthy()
  })

  it('defaults the method to the one this user last used', async () => {
    rememberMethod(2, 'check')
    renderDialog()
    await readyAmount()
    const trigger = screen.getByLabelText(/payment method/i)
    expect(within(trigger).getByText('Check')).toBeTruthy()
  })

  it('remembers the method chosen when the payment is saved, per user', async () => {
    renderDialog()
    const amount = await readyAmount()
    const trigger = screen.getByLabelText(/payment method/i)
    fireEvent.keyDown(trigger, { key: 'Enter' })
    fireEvent.click(await screen.findByRole('option', { name: 'Cash' }))
    await waitFor(() => expect(within(trigger).getByText('Cash')).toBeTruthy())
    expect(readRememberedMethod(2)).toBeNull()

    fireEvent.submit(amount.closest('form') as HTMLFormElement)
    await waitFor(() => expect(postCalls()).toContain('/payments'))
    const body = apiPost.mock.calls.find((call) => call[0] === '/payments')?.[1] as Record<string, unknown>
    expect(body.method).toBe('cash')
    expect(readRememberedMethod(2)).toBe('cash')
    // another user's memory is separate
    expect(readRememberedMethod(3)).toBeNull()
  })
})
