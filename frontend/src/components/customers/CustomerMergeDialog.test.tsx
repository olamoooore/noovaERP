// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CustomerMergeDialog } from './CustomerMergeDialog'
import { CustomerDuplicateDialog } from './CustomerDuplicateDialog'
import { duplicatePayloadFromError } from '@/lib/customerDuplicate'

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: { get: apiGet, post: apiPost, put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const survivor = { id: 1, name: 'Acme', email: 'a@acme.test', phone: '111', website: null }
const merged = { id: 2, name: 'Acme', email: 'b@acme.test', phone: '222', website: null }

function withClient(node: React.ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(<QueryClientProvider client={client}>{node}</QueryClientProvider>)
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => ({ data: url.endsWith('/1') ? survivor : merged }))
  apiPost.mockResolvedValue({ data: { message: 'ok' } })
})

afterEach(() => cleanup())

describe('CustomerMergeDialog (#441)', () => {
  it('shows only differing fields side by side and posts the chosen field values', async () => {
    const onMerged = vi.fn()
    withClient(<CustomerMergeDialog open onOpenChange={() => {}} survivorId={1} mergedId={2} onMerged={onMerged} />)

    await screen.findByLabelText('Phone from merged')
    expect(screen.queryByLabelText('Name from merged')).toBeNull()
    expect(screen.getByText('111')).toBeTruthy()
    expect(screen.getByText('222')).toBeTruthy()

    fireEvent.click(screen.getByLabelText('Phone from merged'))
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Same company' } })
    fireEvent.click(screen.getByRole('button', { name: 'Merge' }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/customers/1/merge', {
      merged_customer_id: 2,
      reason: 'Same company',
      field_choices: { email: 'survivor', phone: 'merged' },
    })
    await waitFor(() => expect(onMerged).toHaveBeenCalledWith(1))
  })
})

describe('CustomerDuplicateDialog (#441)', () => {
  const payload = {
    message: 'A customer with the same legal identifier already exists.',
    tier: 'strong' as const,
    requires: 'duplicate_override' as const,
    matches: [{ customer_id: 7, customer_name: 'Existing Co', match_type: 'tax_number', tier: 'strong' as const }],
    merge_action: { action: 'merge_into_existing' as const, merged_customer_id: 3, requires_create_first: false },
  }

  it('reads the 409 duplicate body from an axios error', () => {
    expect(duplicatePayloadFromError({ response: { status: 409, data: payload } })).toEqual(payload)
    expect(duplicatePayloadFromError({ response: { status: 422, data: payload } })).toBeNull()
  })

  it('offers merge into existing for each match and save anyway with the override', () => {
    const onMergeInto = vi.fn()
    const onSaveAnyway = vi.fn()
    render(<CustomerDuplicateDialog payload={payload} onOpenChange={() => {}} onMergeInto={onMergeInto} onSaveAnyway={onSaveAnyway} />)

    expect(screen.getByText('Existing Co')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Merge into existing' }))
    expect(onMergeInto).toHaveBeenCalledWith(payload.matches[0])

    fireEvent.click(screen.getByRole('button', { name: 'Save anyway' }))
    expect(onSaveAnyway).toHaveBeenCalledWith({ allow_duplicate: true })
  })
})
