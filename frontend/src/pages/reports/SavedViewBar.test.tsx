// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SavedViewBar } from './SavedViewBar';

const { get, post, put, del } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  del: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
  default: {
    get: (...args: unknown[]) => get(...args),
    post: (...args: unknown[]) => post(...args),
    put: (...args: unknown[]) => put(...args),
    delete: (...args: unknown[]) => del(...args),
  },
}));

// jsdom polyfills Radix Dialog needs at render time.
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
  })) as unknown as typeof window.matchMedia;
}
const testGlobals = globalThis as unknown as {
  ResizeObserver?: new () => { observe(): void; unobserve(): void; disconnect(): void };
};
if (!testGlobals.ResizeObserver) {
  testGlobals.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
}
if (!HTMLElement.prototype.scrollIntoView) {
  HTMLElement.prototype.scrollIntoView = () => {};
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false;
  Element.prototype.setPointerCapture = () => {};
  Element.prototype.releasePointerCapture = () => {};
}

const defaultView = {
  id: 1,
  user_id: 7,
  name: 'My Monthly Sales',
  report_key: 'sales-analytics',
  tab: 'trends',
  filters: { start_date: '2026-09-01', end_date: '2026-09-30', interval: 'month' },
  grouping: null,
  visibility: 'personal',
  is_default: true,
  is_owner: true,
};

beforeEach(() => {
  get.mockImplementation((url: string) => {
    if (url === '/reports/saved-views') {
      return Promise.resolve({ data: { success: true, data: { views: [defaultView], default_view_id: 1 } } });
    }
    if (url === '/reports/saved-views/recipients') {
      return Promise.resolve({
        data: { success: true, data: [{ id: 7, name: 'Admin User', email: 'admin@example.test' }] },
      });
    }
    return Promise.resolve({ data: { success: true, data: [] } });
  });
  post.mockResolvedValue({ data: { success: true, data: {} } });
  put.mockResolvedValue({ data: { success: true, data: {} } });
  del.mockResolvedValue({ data: { success: true } });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderBar(onApply = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <SavedViewBar
        tab="by-customer"
        filters={{ start_date: '2026-10-01', end_date: '2026-10-05' }}
        grouping={null}
        onApply={onApply}
      />
    </QueryClientProvider>,
  );
  return onApply;
}

describe('SavedViewBar (#598)', () => {
  it('lists saved views and applies the default view on load', async () => {
    const onApply = renderBar();

    expect(await screen.findByText(/My Monthly Sales/)).toBeTruthy();

    await waitFor(() =>
      expect(onApply).toHaveBeenCalledWith(
        expect.objectContaining({
          tab: 'trends',
          filters: expect.objectContaining({ start_date: '2026-09-01', end_date: '2026-09-30' }),
        }),
      ),
    );
  });

  it('saves the current tab, filters and grouping as a named view', async () => {
    renderBar();
    await screen.findByText(/My Monthly Sales/);

    fireEvent.click(screen.getByRole('button', { name: /save view/i }));

    fireEvent.change(await screen.findByLabelText('View name'), {
      target: { value: 'Weekly pivot' },
    });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith(
        '/reports/saved-views',
        expect.objectContaining({
          name: 'Weekly pivot',
          report_key: 'sales-analytics',
          tab: 'by-customer',
          filters: { start_date: '2026-10-01', end_date: '2026-10-05' },
        }),
      );
    });
  });

  it('creates a schedule with frequency, format and recipient user ids', async () => {
    renderBar();
    await screen.findByText(/My Monthly Sales/);

    await waitFor(() =>
      expect((screen.getByRole('button', { name: /^schedule$/i }) as HTMLButtonElement).disabled).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: /^schedule$/i }));

    await screen.findByLabelText('Frequency');
    fireEvent.change(screen.getByLabelText('Frequency'), { target: { value: 'monthly' } });
    fireEvent.change(screen.getByLabelText('Format'), { target: { value: 'pdf' } });
    fireEvent.click(await screen.findByLabelText('Admin User'));
    fireEvent.click(screen.getByRole('button', { name: /create schedule/i }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith(
        '/reports/saved-views/1/schedules',
        expect.objectContaining({
          frequency: 'monthly',
          format: 'pdf',
          recipients: [7],
        }),
      );
    });
  });
});
