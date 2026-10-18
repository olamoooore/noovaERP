// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CustomerBankAccounts } from './CustomerBankAccounts'
import { useAuthStore } from '@/store/authStore'

const { apiGet, apiPost, apiPut, apiDelete } = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn(),
  apiDelete: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: apiPut, patch: vi.fn(), delete: apiDelete },
}))

const account = {
  id: 'b1',
  contact_id: 'c1',
  bank_name: 'Maybank',
  account_name: 'Acme Sdn Bhd',
  account_number: '5***********9',
  account_type: null,
  routing_number: null,
  swift_code: 'MBBEMYKL',
  iban: null,
  currency: 'MYR',
  branch_name: null,
  is_primary: true,
  is_active: true,
}

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <CustomerBankAccounts customerId={5} />
    </QueryClientProvider>,
  )
}

function setRole(role: string, permissions: string[]) {
  useAuthStore.setState({ role, permissions } as never)
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockResolvedValue({ data: { data: [account] } })
  apiPost.mockResolvedValue({ data: {} })
  apiDelete.mockResolvedValue({ data: {} })
})

afterEach(() => {
  cleanup()
})

describe('CustomerBankAccounts (#444)', () => {
  it('lists the masked account and hides edits without contacts.bank_accounts.edit', async () => {
    setRole('Sales', ['customers.view'])
    renderCard()

    expect(await screen.findByText('5***********9')).toBeTruthy()
    expect(screen.getByText('Maybank')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Add bank account' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull()
  })

  it('adds and removes a bank account with the edit permission', async () => {
    setRole('Accountant', ['customers.view', 'contacts.bank_accounts.edit'])
    renderCard()
    await screen.findByText('5***********9')

    fireEvent.click(screen.getByRole('button', { name: 'Add bank account' }))
    fireEvent.change(screen.getByLabelText('Bank name'), { target: { value: 'CIMB' } })
    fireEvent.change(screen.getByLabelText('Account number'), { target: { value: '7000111222' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save bank account' }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith(
        '/customers/5/bank-accounts',
        expect.objectContaining({ bank_name: 'CIMB', account_number: '7000111222', iban: null, is_primary: false }),
      ),
    )

    fireEvent.click(screen.getByRole('button', { name: 'Remove' }))
    await waitFor(() => expect(apiDelete).toHaveBeenCalledWith('/customers/5/bank-accounts/b1'))
  })
})
