import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'

/**
 * FR-ARC-06 (P2 stop-serving): the legacy /store/* SPA is retired.
 *
 * The merchant's shop lives on their own website (WebsiteBuilder pages with
 * Storefront blocks) — there is no tenant-agnostic redirect target the
 * platform SPA could send an anonymous shopper to, so this route answers a
 * client-side 410 equivalent: an explicit "store retired" notice with no
 * shop UI, no data calls and no crawler content (see the
 * X-Robots-Tag/noindex on the platform host + robots.txt of the new store).
 * The legacy APIs these screens called answer a real HTTP 410.
 */
export function LegacyStoreRetiredPage() {
  return (
    <main className="flex min-h-[70vh] items-center justify-center px-6 py-16">
      <div className="w-full max-w-md rounded-xl border bg-card p-8 text-center shadow-sm">
        <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
          <Icon icon="solar:shop-bold-duotone" className="h-6 w-6 text-muted-foreground" />
        </span>
        <h1 className="text-xl font-semibold">This online store is no longer available</h1>
        <p className="mt-3 text-sm text-muted-foreground">
          The legacy shop has been retired. This merchant&rsquo;s store now runs on their own
          website — please use the shop link provided by the merchant, or an order-confirmation
          link from your email.
        </p>
        <div className="mt-6">
          <Link
            to="/"
            className="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm font-medium hover:bg-muted"
          >
            <Icon icon="solar:home-2-bold-duotone" className="h-4 w-4" />
            Go to the home page
          </Link>
        </div>
      </div>
    </main>
  )
}

export default LegacyStoreRetiredPage