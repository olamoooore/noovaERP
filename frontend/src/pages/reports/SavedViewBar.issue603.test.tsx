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

const savedView = {
  id: 1,
  user_id: 7,
  name: 'My Monthly Sales',
  report_key: 'sales-analytics',
  tab: 'trends',
  filters: { start_date: '2026-09-01', end_date: '2026-09-30', interval: 'month' },
  grouping: null,
  visibility: 'personal',
  is_default: true,
  is_favourite: false,
  is_pinned: false,
  is_owner: true,
};

beforeEach(() => {
  get.mockImplementation((url: string) => {
    if (url === '/reports/saved-views') {
      return Promise.resolve({
        data: { success: true, data: { views: [savedView], default_view_id: 1 } },
      });
    }
    if (url === '/reports/saved-views/recipients') {
      return Promise.resolve({
        data: { success: true, data: [{ id: 7, name: 'Admin User', email: 'admin@example.test' }] },
      });
    }
    return Promise.resolve({ data: { success: true, data: [] } });
  });
  post.mockResolvedValue({ data: { success: true, data: { ...savedView, is_favourite: true } } });
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

describe('SavedViewBar favourites and dashboard pinning (#603)', () => {
  it('toggles the selected view as a favourite with one click', async () => {
    renderBar();
    await screen.findByText(/My Monthly Sales/);

    // The default view is applied on load, enabling the star for its owner.
    const star = await screen.findByRole('button', { name: /add to favourites/i });
    await waitFor(() => expect((star as HTMLButtonElement).disabled).toBe(false));
    fireEvent.click(star);

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith('/reports/saved-views/1/favourite');
    });
  });

  it('shows the star as active for a favourite view', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/reports/saved-views') {
        return Promise.resolve({
          data: {
            success: true,
            data: {
              views: [{ ...savedView, is_favourite: true }],
              default_view_id: 1,
            },
          },
        });
      }
      return Promise.resolve({ data: { success: true, data: [] } });
    });

    renderBar();
    await screen.findByText(/My Monthly Sales/);

    expect(await screen.findByRole('button', { name: /remove from favourites/i })).toBeTruthy();
  });

  it('adds a saved view to the dashboard from the manage dialog', async () => {
    renderBar();
    await screen.findByText(/My Monthly Sales/);

    fireEvent.click(screen.getByRole('button', { name: /manage views/i }));
    fireEvent.click(await screen.findByRole('button', { name: /add My Monthly Sales to dashboard/i }));

    await waitFor(() => {
      expect(post).toHaveBeenCalledWith('/reports/dashboard/widgets', { view_id: 1 });
    });
  });
});
