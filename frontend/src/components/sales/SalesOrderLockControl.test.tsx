// @vitest-environment jsdom
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup, fireEvent } from '@testing-library/react'
import { SalesOrderLockControl } from './SalesOrderLockControl'

afterEach(cleanup)

describe('SalesOrderLockControl (#407)', () => {
  it('shows the lock badge and an Unlock action for a locked order', () => {
    const onUnlock = vi.fn()

    render(
      <SalesOrderLockControl
        isLocked
        canWrite
        lockedAt="2026-10-05T10:00:00Z"
        onLock={vi.fn()}
        onUnlock={onUnlock}
      />,
    )

    expect(screen.getByTestId('sales-order-lock-badge').textContent).toContain('Locked')

    fireEvent.click(screen.getByRole('button', { name: /unlock/i }))
    expect(onUnlock).toHaveBeenCalledTimes(1)
  })

  it('shows a Lock action for an unlocked confirmed order', () => {
    const onLock = vi.fn()

    render(<SalesOrderLockControl isLocked={false} canWrite onLock={onLock} onUnlock={vi.fn()} />)

    expect(screen.queryByTestId('sales-order-lock-badge')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: /^lock$/i }))
    expect(onLock).toHaveBeenCalledTimes(1)
  })

  it('lets a read-only user see the locked state but never the action', () => {
    render(
      <SalesOrderLockControl isLocked canWrite={false} onLock={vi.fn()} onUnlock={vi.fn()} />,
    )

    expect(screen.getByTestId('sales-order-lock-badge').textContent).toContain('Locked')
    expect(screen.queryByRole('button', { name: /lock/i })).toBeNull()
  })

  it('renders nothing for a read-only user on an unlocked order', () => {
    const { container } = render(
      <SalesOrderLockControl isLocked={false} canWrite={false} onLock={vi.fn()} onUnlock={vi.fn()} />,
    )

    expect(container.querySelector('[data-testid="sales-order-lock-control"]')).toBeNull()
  })
})
