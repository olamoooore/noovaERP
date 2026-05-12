import { Icon } from '@iconify/react'

type ManualCard = {
  title: string
  description: string
  href?: string
  icon: string
  accent: 'inventory' | 'pos' | 'neutral'
  status: 'live' | 'coming-soon'
  bullets: string[]
}

const liveManuals: ManualCard[] = [
  {
    title: 'Inventory Module',
    description: 'Stock management, stock operations, purchase orders, warehouse transfers, warehouses, suppliers, and inventory settings.',
    href: '/manuals/inventory-manual.html',
    icon: 'solar:box-bold-duotone',
    accent: 'inventory',
    status: 'live',
    bullets: [
      'Detailed workflow guide',
      'Based on the current inventory pages and backend rules',
    ],
  },
  {
    title: 'POS Module',
    description: 'POS landing flow, register opening, live terminal layout, payments, receipts, barcode labels, reports, offline queue, and settings.',
    href: '/manuals/pos-manual.html',
    icon: 'solar:shop-bold-duotone',
    accent: 'pos',
    status: 'live',
    bullets: [
      'Customer-friendly operating guide',
      'Aligned to the current cashier-facing screens',
    ],
  },
]

const upcomingManuals: ManualCard[] = [
  {
    title: 'Event Management',
    description: 'Manual placeholder for event setup, bookings, staffing, and customer-facing event flows.',
    icon: 'solar:ticket-bold-duotone',
    accent: 'neutral',
    status: 'coming-soon',
    bullets: ['Reserved for the next published manual'],
  },
  {
    title: 'IoT Hub',
    description: 'Manual placeholder for device discovery, agent linking, scanners, printers, terminals, kiosks, and biometric devices.',
    icon: 'solar:smart-speaker-bold-duotone',
    accent: 'neutral',
    status: 'coming-soon',
    bullets: ['Ready to publish when the IoT manual is prepared'],
  },
  {
    title: 'Superadmin',
    description: 'Manual placeholder for tenants, subscriptions, entitlements, plans, and central administration workflows.',
    icon: 'solar:shield-user-bold-duotone',
    accent: 'neutral',
    status: 'coming-soon',
    bullets: ['Reserved for landlord and tenant control-plane guidance'],
  },
  {
    title: 'Additional Modules',
    description: 'Add future manuals here without changing the customer link. Replace a placeholder with a live card when the guide is ready.',
    icon: 'solar:documents-bold-duotone',
    accent: 'neutral',
    status: 'coming-soon',
    bullets: ['Keeps one stable entry point for customers'],
  },
]

function cardAccent(accent: ManualCard['accent']) {
  if (accent === 'inventory') {
    return {
      iconWrap: 'border-blue-200/70 bg-blue-50 text-blue-700',
      button: 'bg-blue-700 text-white hover:bg-blue-800',
      secondary: 'bg-blue-50 text-blue-700',
      status: 'bg-blue-50 text-blue-700',
    }
  }

  if (accent === 'pos') {
    return {
      iconWrap: 'border-rose-200/70 bg-rose-50 text-rose-700',
      button: 'bg-rose-700 text-white hover:bg-rose-800',
      secondary: 'bg-rose-50 text-rose-700',
      status: 'bg-rose-50 text-rose-700',
    }
  }

  return {
    iconWrap: 'border-slate-200/80 bg-slate-50 text-slate-600',
    button: 'bg-slate-300 text-slate-600 cursor-not-allowed',
    secondary: 'bg-slate-100 text-slate-600',
    status: 'bg-slate-100 text-slate-600',
  }
}

function ManualGridCard({ manual }: { manual: ManualCard }) {
  const accent = cardAccent(manual.accent)

  return (
    <article className="flex min-h-[240px] flex-col gap-4 rounded-3xl border border-slate-200/80 bg-white p-6 shadow-sm transition-transform duration-200 hover:-translate-y-0.5 hover:shadow-lg">
      <div className="flex items-start justify-between gap-4">
        <div className={`flex h-14 w-14 items-center justify-center rounded-2xl border ${accent.iconWrap}`}>
          <Icon icon={manual.icon} className="h-7 w-7" />
        </div>
        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${accent.status}`}>
          {manual.status === 'live' ? 'Live' : 'Coming Soon'}
        </span>
      </div>

      <div className="space-y-2">
        <h2 className="text-xl font-semibold tracking-tight text-slate-900">{manual.title}</h2>
        <p className="text-sm leading-6 text-slate-600">{manual.description}</p>
      </div>

      <ul className="space-y-2 text-sm text-slate-600">
        {manual.bullets.map((bullet) => (
          <li key={bullet} className="flex items-center gap-2">
            <Icon icon="solar:check-circle-bold-duotone" className="h-4 w-4 text-slate-400" />
            <span>{bullet}</span>
          </li>
        ))}
      </ul>

      <div className="mt-auto pt-2">
        {manual.href ? (
          <a
            href={manual.href}
            className={`inline-flex min-h-11 items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition-colors ${accent.button}`}
          >
            <span>Open Manual</span>
            <Icon icon="solar:arrow-right-linear" className="h-4 w-4" />
          </a>
        ) : (
          <span className={`inline-flex min-h-11 items-center rounded-xl px-4 py-2 text-sm font-semibold ${accent.button}`}>
            Manual not published yet
          </span>
        )}
      </div>
    </article>
  )
}

export function ManualsLibraryPage() {
  return (
    <div className="min-h-screen bg-[radial-gradient(circle_at_top_left,rgba(29,78,216,0.10),transparent_26%),radial-gradient(circle_at_top_right,rgba(190,24,93,0.08),transparent_24%),linear-gradient(180deg,#f9fbfd_0%,#eff3f8_100%)] px-4 py-6 sm:px-6 lg:px-8">
      <div className="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <section className="overflow-hidden rounded-[32px] bg-[linear-gradient(135deg,#0f172a_0%,#1e3a8a_55%,#be123c_100%)] px-8 py-10 text-white shadow-[0_18px_45px_rgba(15,23,42,0.08)] sm:px-10">
          <span className="mb-3 inline-block text-xs font-semibold uppercase tracking-[0.18em] text-white/80">
            NoovaERP Documentation
          </span>
          <div className="mb-4 flex items-center gap-4">
            <div className="flex h-16 w-16 items-center justify-center rounded-3xl border border-white/15 bg-white/10">
              <Icon icon="solar:book-bold-duotone" className="h-8 w-8" />
            </div>
            <div>
              <h1 className="text-4xl font-bold tracking-[-0.05em] sm:text-5xl">Manuals Library</h1>
            </div>
          </div>
          <p className="max-w-4xl text-sm leading-7 text-white/85 sm:text-base">
            Use this as the single customer entry point for every NoovaERP manual. Share one link, publish live manuals from here, and keep future modules listed as coming soon until their documentation is ready.
          </p>
          <div className="mt-6 flex flex-wrap gap-3 text-sm text-white">
            <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-2">
              <Icon icon="solar:link-round-bold-duotone" className="h-4 w-4" />
              One customer link
            </span>
            <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-2">
              <Icon icon="solar:documents-bold-duotone" className="h-4 w-4" />
              Live and upcoming manuals
            </span>
            <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-2">
              <Icon icon="solar:widget-bold-duotone" className="h-4 w-4" />
              No login required
            </span>
          </div>
        </section>

        <section className="rounded-[28px] border border-slate-200/80 bg-white/90 p-7 shadow-[0_18px_45px_rgba(15,23,42,0.08)] backdrop-blur-sm">
          <div className="space-y-2">
            <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Available Manuals</h2>
            <p className="text-sm leading-6 text-slate-600">
              These are ready now and open directly from the same host under the <span className="font-semibold text-slate-800">/manuals</span> path.
            </p>
          </div>

          <div className="mt-6 grid gap-5 lg:grid-cols-2">
            {liveManuals.map((manual) => (
              <ManualGridCard key={manual.title} manual={manual} />
            ))}
          </div>
        </section>

        <section className="rounded-[28px] border border-slate-200/80 bg-white/90 p-7 shadow-[0_18px_45px_rgba(15,23,42,0.08)] backdrop-blur-sm">
          <div className="space-y-2">
            <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Coming Soon</h2>
            <p className="text-sm leading-6 text-slate-600">
              Keep future modules here so the library structure stays stable while more manuals are being prepared.
            </p>
          </div>

          <div className="mt-6 grid gap-5 lg:grid-cols-2">
            {upcomingManuals.map((manual) => (
              <ManualGridCard key={manual.title} manual={manual} />
            ))}
          </div>

          <p className="mt-6 text-sm text-slate-600">
            Recommended customer URL: <span className="font-semibold text-slate-900">/manuals</span>
          </p>
        </section>
      </div>
    </div>
  )
}