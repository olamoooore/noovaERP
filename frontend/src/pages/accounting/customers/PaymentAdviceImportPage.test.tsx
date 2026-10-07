// @vitest-environment jsdom
// #1001 (VC-009): upload a payment advice → review the matched lines →
// confirm posts the reviewed allocations and shows the draft payments.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { PaymentAdviceImportPage, type PaymentAdviceImport } from './PaymentAdviceImportPage'

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

const { apiGet, apiPost } = vi.hoisted(() => ({ apiGet: vi.fn(), apiPost: vi.fn() }))

vi.mock('@/lib/api', () => ({
  default: {
    get: apiGet,
    post: apiPost,
    put: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: {} })),
    delete: vi.fn(async () => ({ data: {} })),
  },
}))

const draftAdvice: PaymentAdviceImport = {
  id: 5,
  original_name: 'uob.jpg',
  bank_code: 'uob_giro',
  extractor: 'ocr',
  status: 'draft',
  customer_id: 9,
  customer: { id: 9, name: 'Ascensus Clinic' },
  payer_name: 'ASCENSUS WOMEN (TMC) PTE. LTD',
  value_date: '2026-09-25',
  currency_code: 'SGD',
  total_amount: '8938.00',
  extracted_total: '8900.00',
  variance: '38.00',
  bank_reference: 'GIRO-778',
  lines: [
    { invoice_number: '2026/08/223', amount: 7194, status: 'matched', invoice_id: 101, invoice_balance: 7194, note: null },
    { invoice_number: '2026/07/089', amount: 1706, status: 'matched', invoice_id: 102, invoice_balance: 2000, note: null },
    { invoice_number: '2026/06/001', amount: 0, status: 'unmatched', invoice_id: null, invoice_balance: null, note: 'No invoice with this number was found.' },
  ],
  suggestions: [],
  warnings: ['line_total_mismatch'],
}

const confirmedAdvice: PaymentAdviceImport = {
  ...draftAdvice,
  status: 'confirmed',
  payments: [
    { id: 71, invoice_id: 101, payment_number: 'PAY/2026/09/0071', amount: '7194.00', payment_status: 'recorded' },
    { id: 72, invoice_id: 102, payment_number: 'PAY/2026/09/0072', amount: '1500.00', payment_status: 'recorded' },
  ],
}

function renderPage(path = '/accounting/customers/payments/advices') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/accounting/customers/payments/advices" element={<PaymentAdviceImportPage />} />
          <Route path="/accounting/customers/payments/advices/:id" element={<PaymentAdviceImportPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation(async (url: string) => {
    if (url === '/payment-advices') return { data: { data: [], current_page: 1, last_page: 1, total: 0 } }
    if (url === '/payment-advices/5') return { data: { data: draftAdvice } }
    throw new Error(`unexpected GET ${url}`)
  })
  apiPost.mockImplementation(async (url: string) => {
    if (url === '/payment-advices') return { data: { data: draftAdvice } }
    if (url === '/payment-advices/5/confirm') return { data: { data: confirmedAdvice } }
    throw new Error(`unexpected POST ${url}`)
  })
})

afterEach(() => cleanup())

describe('PaymentAdviceImportPage (#1001)', () => {
  it('uploads an advice, reviews the matches and confirms the edited allocations', async () => {
    renderPage()

    expect(await screen.findByText('No payment advices imported yet.')).toBeTruthy()

    const file = new File(['%PDF-1.4'], 'uob.jpg', { type: 'image/jpeg' })
    fireEvent.change(screen.getByTestId('payment-advice-file'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: /Upload & parse/ }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/payment-advices', expect.any(FormData), expect.anything()))
    const sent = apiPost.mock.calls[0][1] as FormData
    expect((sent.get('file') as File).name).toBe('uob.jpg')

    // Review: header facts, variance warning badge, matched + unmatched lines.
    expect(await screen.findByText('ASCENSUS WOMEN (TMC) PTE. LTD')).toBeTruthy()
    expect(screen.getByText('GIRO-778')).toBeTruthy()
    expect(screen.getByText('Line total ≠ remittance')).toBeTruthy()
    expect(screen.getAllByText('Matched')).toHaveLength(2)
    expect(screen.getByText('Unmatched')).toBeTruthy()
    expect((screen.getByLabelText('Include line 3') as HTMLButtonElement).disabled).toBe(true)

    // Partial allocation on line 2.
    fireEvent.change(screen.getByLabelText('Allocation amount line 2'), { target: { value: '1500' } })
    fireEvent.click(screen.getByRole('button', { name: /Confirm allocations/ }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/payment-advices/5/confirm', {
      allocations: [
        { line_index: 0, invoice_id: 101, amount: 7194 },
        { line_index: 1, invoice_id: 102, amount: 1500 },
      ],
    }))

    // The created drafts are listed with a way to post them.
    expect(await screen.findByText('PAY/2026/09/0071')).toBeTruthy()
    expect(screen.getByText('PAY/2026/09/0072')).toBeTruthy()
    const link = screen.getByRole('link', { name: /Go to Payments to post them/ })
    expect(link.getAttribute('href')).toBe('/accounting/customers/payments?payment_status=recorded')
  })

  it('leaves out lines the operator unticks', async () => {
    renderPage('/accounting/customers/payments/advices/5')

    fireEvent.click(await screen.findByLabelText('Include line 1'))
    fireEvent.click(screen.getByRole('button', { name: /Confirm allocations/ }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/payment-advices/5/confirm', {
      allocations: [{ line_index: 1, invoice_id: 102, amount: 1706 }],
    }))
  })

  it('lets the operator pick a suggested invoice for a no-reference advice', async () => {
    apiGet.mockImplementation(async () => ({
      data: {
        data: {
          ...draftAdvice,
          lines: [],
          variance: null,
          extracted_total: '0.00',
          total_amount: '545.00',
          warnings: ['no_invoice_references'],
          suggestions: [{ invoice_id: 300, invoice_number: '2026/05/088', invoice_date: '2026-05-02', balance: 545, suggested_amount: 545 }],
        },
      },
    }))

    renderPage('/accounting/customers/payments/advices/5')

    expect(await screen.findByText('No invoice references')).toBeTruthy()
    fireEvent.click(screen.getByLabelText('Use invoice 2026/05/088'))
    fireEvent.click(screen.getByRole('button', { name: /Confirm allocations/ }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/payment-advices/5/confirm', {
      allocations: [{ line_index: 0, invoice_id: 300, amount: 545 }],
    }))
  })
})
