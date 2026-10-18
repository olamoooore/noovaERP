// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { ActivitiesBadge } from './ActivitiesBadge'

const { apiGet } = vi.hoisted(() => ({ apiGet: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: vi.fn(async () => ({ data: {} })),
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockResolvedValue({
    data: { data: { overdue: [], today: [], upcoming: [], total: 3 } },
  })
})

afterEach(cleanup)

function renderBadge() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route path="/dashboard" element={<ActivitiesBadge />} />
          <Route path="/activities" element={<div>My Activities page</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('ActivitiesBadge (#637)', () => {
  it('shows the open-activity count and links to the My Activities page', async () => {
    renderBadge()

    await waitFor(() => expect(screen.getByText('3')).toBeTruthy())

    fireEvent.click(screen.getByRole('button', { name: /my activities/i }))

    await screen.findByText('My Activities page')
  })
})
