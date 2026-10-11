import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'

/* ------------------------------------------------------------------ */
/* Reveal-on-scroll helper                                             */
/* ------------------------------------------------------------------ */

function Reveal({
  children,
  delay = 0,
  className = '',
}: {
  children: React.ReactNode
  delay?: number
  className?: string
}) {
  const ref = useRef<HTMLDivElement>(null)
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const node = ref.current
    if (!node) return
    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setVisible(true)
          observer.disconnect()
        }
      },
      { threshold: 0.12 }
    )
    observer.observe(node)
    return () => observer.disconnect()
  }, [])

  return (
    <div
      ref={ref}
      className={className}
      style={{
        opacity: visible ? 1 : 0,
        transform: visible ? 'translateY(0)' : 'translateY(28px)',
        transition: `opacity 0.8s cubic-bezier(0.16,1,0.3,1) ${delay}ms, transform 0.8s cubic-bezier(0.16,1,0.3,1) ${delay}ms`,
      }}
    >
      {children}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

const NAV_LINKS = [
  { label: 'Platform', href: '#platform' },
  { label: 'Modules', href: '#modules' },
  { label: 'Security', href: '#security' },
  { label: 'Why NoovaERP', href: '#why' },
]

const HERO_STATS = [
  { value: '14+', label: 'Integrated modules' },
  { value: '99.9%', label: 'Uptime architecture' },
  { value: '1', label: 'Source of truth' },
  { value: '14 days', label: 'Free trial' },
]

const MODULES = [
  {
    icon: 'solar:wallet-money-bold-duotone',
    name: 'Accounting & Finance',
    desc: 'Double-entry ledger, AR/AP, bank reconciliation, multi-currency, tax engines and a guided financial close.',
    accent: 'text-emerald-400',
    glow: 'from-emerald-500/20',
  },
  {
    icon: 'solar:box-bold-duotone',
    name: 'Inventory & Warehousing',
    desc: 'Real-time stock, multi-warehouse operations, purchase orders, transfers and movement-level traceability.',
    accent: 'text-emerald-400',
    glow: 'from-emerald-500/20',
  },
  {
    icon: 'solar:shop-bold-duotone',
    name: 'Retail & POS',
    desc: 'Offline-first point of sale with terminals, customer displays, barcode workflows and approval queues.',
    accent: 'text-emerald-400',
    glow: 'from-emerald-500/20',
  },
  {
    icon: 'solar:users-group-two-rounded-bold-duotone',
    name: 'HR Management',
    desc: 'People records, attendance, payroll-ready data and role-based access for every team member.',
    accent: 'text-rose-400',
    glow: 'from-rose-500/20',
  },
  {
    icon: 'solar:cart-large-2-bold-duotone',
    name: 'E-commerce & Storefronts',
    desc: 'Launch branded online stores and customer portals connected directly to your catalog and stock.',
    accent: 'text-amber-400',
    glow: 'from-amber-500/20',
  },
  {
    icon: 'solar:document-text-bold-duotone',
    name: 'OCR & Documents',
    desc: 'Capture invoices and receipts with AI-assisted extraction and review queues that feed your ledger.',
    accent: 'text-teal-400',
    glow: 'from-teal-500/20',
  },
  {
    icon: 'solar:sledgehammer-bold-duotone',
    name: 'Field Service',
    desc: 'Work orders, dispatching and on-site execution tied back to inventory, billing and customers.',
    accent: 'text-orange-400',
    glow: 'from-orange-500/20',
  },
  {
    icon: 'solar:cpu-bolt-bold-duotone',
    name: 'IoT & Devices',
    desc: 'Connect devices and sensors into your operations hub with live telemetry and device management.',
    accent: 'text-teal-400',
    glow: 'from-teal-500/20',
  },
  {
    icon: 'solar:chart-2-bold-duotone',
    name: 'Analytics & Reporting',
    desc: 'Executive dashboards, financial statements, sales analytics and inventory intelligence out of the box.',
    accent: 'text-teal-400',
    glow: 'from-teal-500/20',
  },
]

const SECURITY_POINTS = [
  {
    icon: 'solar:server-square-cloud-bold-duotone',
    title: 'Tenant isolation by design',
    desc: 'Every company runs in its own isolated tenant with dedicated schema separation - your data never mixes with anyone else\'s.',
  },
  {
    icon: 'solar:shield-keyhole-bold-duotone',
    title: 'Role-based access control',
    desc: 'Granular permissions across every module. Owners, accountants, cashiers and field techs each see exactly what they should.',
  },
  {
    icon: 'solar:lock-password-bold-duotone',
    title: 'Encrypted end to end',
    desc: 'TLS in transit, hardened auth with token-based sessions, and audit trails on sensitive financial activity.',
  },
  {
    icon: 'solar:clipboard-check-bold-duotone',
    title: 'Audit-ready by default',
    desc: 'Lock dates, immutable journals, approval queues and full activity logs keep you ready for any review.',
  },
]

const WHY_POINTS = [
  {
    icon: 'solar:layers-minimalistic-bold-duotone',
    title: 'One platform, not ten subscriptions',
    desc: 'Replace your patchwork of accounting tools, POS systems, inventory spreadsheets and HR apps with a single governed workspace.',
  },
  {
    icon: 'solar:bolt-circle-bold-duotone',
    title: 'Live in minutes, not months',
    desc: 'Sign up, name your workspace and start operating. Guided onboarding gets your chart of accounts and catalog ready fast.',
  },
  {
    icon: 'solar:magic-stick-3-bold-duotone',
    title: 'AI where it actually helps',
    desc: 'Document OCR, close-cycle findings, forecasting and an assistant that understands your business data.',
  },
  {
    icon: 'solar:graph-up-bold-duotone',
    title: 'Built to scale with you',
    desc: 'From a single shop to multi-entity, multi-warehouse, multi-currency operations - without migrating platforms.',
  },
]

const FOOTER_COLS = [
  {
    title: 'Platform',
    links: ['Accounting', 'Inventory', 'Point of Sale', 'HR Management', 'E-commerce', 'Field Service'],
  },
  {
    title: 'Company',
    links: ['About', 'Security', 'Contact', 'Careers'],
  },
  {
    title: 'Resources',
    links: ['Documentation', 'Status', 'Support', 'Changelog'],
  },
]

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

export function LandingPage() {
  const [scrolled, setScrolled] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 12)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  return (
    <div className="min-h-screen scroll-smooth bg-[#04120d] font-sans text-slate-100 antialiased [text-rendering:optimizeLegibility]">
      <style>{`
        @keyframes landing-float {
          0%, 100% { transform: translateY(0px); }
          50% { transform: translateY(-12px); }
        }
        @keyframes landing-pulse-glow {
          0%, 100% { opacity: 0.55; }
          50% { opacity: 1; }
        }
        @keyframes landing-marquee {
          from { transform: translateX(0); }
          to { transform: translateX(-50%); }
        }
        @keyframes landing-grid-pan {
          from { background-position: 0 0; }
          to { background-position: 0 -44px; }
        }
        .landing-marquee-track { animation: landing-marquee 36s linear infinite; }
        .landing-marquee:hover .landing-marquee-track { animation-play-state: paused; }
      `}</style>

      {/* ============================ NAV ============================ */}
      <header
        className={`fixed inset-x-0 top-0 z-50 transition-all duration-300 ${
          scrolled
            ? 'border-b border-white/[0.06] bg-[#04120d]/85 backdrop-blur-xl'
            : 'border-b border-transparent bg-transparent'
        }`}
      >
        <nav className="mx-auto flex h-[68px] w-full max-w-7xl items-center justify-between px-5 sm:px-8">
          <a href="#top" className="flex items-center">
            <span className="flex h-9 items-center rounded-lg bg-white px-2 shadow-[0_0_24px_rgba(82,179,155,0.30)]">
              <img src={NOOVAERP_LOGO_URL} alt="NoovaERP" className="h-6 w-auto object-contain" />
            </span>
          </a>

          <div className="hidden items-center gap-1 md:flex">
            {NAV_LINKS.map((link) => (
              <a
                key={link.href}
                href={link.href}
                className="rounded-full px-4 py-2 text-[13.5px] font-medium text-slate-300 transition hover:bg-white/[0.06] hover:text-white"
              >
                {link.label}
              </a>
            ))}
          </div>

          <div className="hidden items-center gap-2.5 md:flex">
            <Link
              to="/login"
              className="rounded-full px-4 py-2 text-[13.5px] font-medium text-slate-200 transition hover:bg-white/[0.06] hover:text-white"
            >
              Log in
            </Link>
            <Link
              to="/signup"
              className="group inline-flex items-center gap-1.5 rounded-full bg-white py-2 pl-5 pr-4 text-[13.5px] font-semibold text-slate-950 shadow-[0_0_28px_rgba(255,255,255,0.18)] transition hover:bg-slate-200"
            >
              Get started
              <Icon
                icon="solar:arrow-right-linear"
                className="h-4 w-4 transition-transform group-hover:translate-x-0.5"
              />
            </Link>
          </div>

          <button
            type="button"
            onClick={() => setMobileOpen((v) => !v)}
            className="flex h-10 w-10 items-center justify-center rounded-lg text-slate-200 transition hover:bg-white/[0.06] md:hidden"
            aria-label="Toggle menu"
          >
            <Icon icon={mobileOpen ? 'solar:close-circle-linear' : 'solar:hamburger-menu-linear'} className="h-6 w-6" />
          </button>
        </nav>

        {mobileOpen && (
          <div className="border-t border-white/[0.06] bg-[#04120d]/95 px-5 py-4 backdrop-blur-xl md:hidden">
            <div className="flex flex-col gap-1">
              {NAV_LINKS.map((link) => (
                <a
                  key={link.href}
                  href={link.href}
                  onClick={() => setMobileOpen(false)}
                  className="rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 transition hover:bg-white/[0.06] hover:text-white"
                >
                  {link.label}
                </a>
              ))}
              <div className="mt-3 flex gap-3 border-t border-white/[0.06] pt-4">
                <Link
                  to="/login"
                  className="flex-1 rounded-lg border border-white/15 px-4 py-2.5 text-center text-sm font-medium text-white transition hover:bg-white/[0.06]"
                >
                  Log in
                </Link>
                <Link
                  to="/signup"
                  className="flex-1 rounded-lg bg-white px-4 py-2.5 text-center text-sm font-semibold text-slate-950 transition hover:bg-slate-200"
                >
                  Get started
                </Link>
              </div>
            </div>
          </div>
        )}
      </header>

      {/* ============================ HERO ============================ */}
      <section id="top" className="relative overflow-hidden pb-10 pt-[150px] sm:pt-[170px]">
        {/* background */}
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            backgroundImage:
              'radial-gradient(1200px 600px at 70% -20%, rgba(82,179,155,0.18), transparent 60%),' +
              'radial-gradient(900px 500px at 10% 10%, rgba(61,163,130,0.16), transparent 60%),' +
              'radial-gradient(800px 500px at 50% 110%, rgba(16,185,129,0.10), transparent 60%)',
          }}
        />
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.05]"
          style={{
            backgroundImage:
              'linear-gradient(rgba(255,255,255,0.7) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.7) 1px, transparent 1px)',
            backgroundSize: '44px 44px',
            maskImage: 'radial-gradient(ellipse 90% 70% at 50% 30%, black 40%, transparent 85%)',
            WebkitMaskImage: 'radial-gradient(ellipse 90% 70% at 50% 30%, black 40%, transparent 85%)',
            animation: 'landing-grid-pan 6s linear infinite',
          }}
        />

        <div className="relative mx-auto w-full max-w-7xl px-5 sm:px-8">
          <div className="mx-auto max-w-3xl text-center">
            <Reveal>
              <div className="mb-7 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.05] px-4 py-1.5 text-[12px] font-medium tracking-wide text-slate-300 backdrop-blur">
                <span
                  className="h-1.5 w-1.5 rounded-full bg-emerald-400"
                  style={{ animation: 'landing-pulse-glow 2.4s ease-in-out infinite' }}
                />
                Multi-tenant ERP for modern operations
                <span className="hidden text-slate-500 sm:inline">|</span>
                <span className="hidden text-slate-400 sm:inline">14-day free trial</span>
              </div>
            </Reveal>

            <Reveal delay={80}>
              <h1 className="text-balance text-[44px] font-semibold leading-[1.04] tracking-tight text-white sm:text-[64px]">
                Run your entire business
                <span className="block bg-gradient-to-r from-emerald-300 via-teal-300 to-emerald-300 bg-clip-text text-transparent">
                  on one platform.
                </span>
              </h1>
            </Reveal>

            <Reveal delay={160}>
              <p className="mx-auto mt-6 max-w-2xl text-pretty text-[16px] leading-7 text-slate-400 sm:text-[17.5px] sm:leading-8">
                NoovaERP unifies accounting, inventory, retail POS, HR, e-commerce and field
                service into a single governed workspace - so every number, order and
                decision lives in one source of truth.
              </p>
            </Reveal>

            <Reveal delay={240}>
              <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <Link
                  to="/signup"
                  className="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-full bg-white px-7 text-[15px] font-semibold text-slate-950 shadow-[0_0_40px_rgba(82,179,155,0.35)] transition hover:bg-slate-200 sm:w-auto"
                >
                  Start free trial
                  <Icon
                    icon="solar:arrow-right-linear"
                    className="h-[18px] w-[18px] transition-transform group-hover:translate-x-1"
                  />
                </Link>
                <Link
                  to="/login"
                  className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-full border border-white/15 bg-white/[0.04] px-7 text-[15px] font-medium text-white backdrop-blur transition hover:border-white/30 hover:bg-white/[0.08] sm:w-auto"
                >
                  <Icon icon="solar:login-3-bold-duotone" className="h-[18px] w-[18px] text-emerald-300" />
                  Sign in to workspace
                </Link>
              </div>
              <p className="mt-4 text-[12.5px] text-slate-500">
                No credit card required. Full access for 14 days.
              </p>
            </Reveal>
          </div>

          {/* -------- Product preview mockup -------- */}
          <Reveal delay={320} className="relative mx-auto mt-16 max-w-5xl sm:mt-20">
            <div
              className="pointer-events-none absolute -inset-x-8 -top-10 h-40 rounded-full bg-emerald-500/20 blur-[90px]"
              style={{ animation: 'landing-pulse-glow 5s ease-in-out infinite' }}
            />
            <div className="relative rounded-2xl border border-white/10 bg-gradient-to-b from-white/[0.07] to-white/[0.02] p-2 shadow-[0_40px_120px_-20px_rgba(2,16,11,0.9)] backdrop-blur">
              <div className="overflow-hidden rounded-xl border border-white/[0.06] bg-[#081c16]">
                {/* window chrome */}
                <div className="flex items-center justify-between border-b border-white/[0.06] bg-white/[0.02] px-4 py-2.5">
                  <div className="flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-full bg-rose-400/70" />
                    <span className="h-2.5 w-2.5 rounded-full bg-amber-400/70" />
                    <span className="h-2.5 w-2.5 rounded-full bg-emerald-400/70" />
                  </div>
                  <div className="flex items-center gap-2 rounded-md border border-white/[0.06] bg-white/[0.03] px-3 py-1 text-[10.5px] text-slate-500">
                    <Icon icon="solar:lock-keyhole-minimalistic-bold" className="h-3 w-3 text-emerald-400/80" />
                    app.noovaerp.cloud/dashboard
                  </div>
                  <div className="w-12" />
                </div>

                {/* app body */}
                <div className="grid grid-cols-[180px_1fr] max-sm:grid-cols-1">
                  {/* sidebar */}
                  <div className="hidden border-r border-white/[0.06] p-3 sm:block">
                    <div className="mb-3 flex items-center gap-2 rounded-lg bg-white/[0.04] px-2.5 py-2">
                      <span className="flex h-6 w-6 items-center justify-center rounded-md bg-gradient-to-br from-emerald-400 to-teal-500 text-[10px] font-bold text-white">
                        A
                      </span>
                      <div>
                        <div className="text-[10px] font-semibold text-slate-200">Acme Trading Co</div>
                        <div className="text-[8.5px] text-slate-500">Enterprise plan</div>
                      </div>
                    </div>
                    {[
                      { icon: 'solar:widget-5-bold-duotone', label: 'Dashboard', active: true },
                      { icon: 'solar:wallet-money-bold-duotone', label: 'Accounting' },
                      { icon: 'solar:box-bold-duotone', label: 'Inventory' },
                      { icon: 'solar:shop-bold-duotone', label: 'Point of Sale' },
                      { icon: 'solar:users-group-two-rounded-bold-duotone', label: 'HR' },
                      { icon: 'solar:cart-large-2-bold-duotone', label: 'E-commerce' },
                      { icon: 'solar:chart-2-bold-duotone', label: 'Reports' },
                    ].map((item) => (
                      <div
                        key={item.label}
                        className={`mb-0.5 flex items-center gap-2 rounded-md px-2.5 py-[7px] text-[10.5px] ${
                          item.active
                            ? 'bg-emerald-500/15 font-medium text-emerald-300'
                            : 'text-slate-500'
                        }`}
                      >
                        <Icon icon={item.icon} className="h-3.5 w-3.5" />
                        {item.label}
                      </div>
                    ))}
                  </div>

                  {/* main */}
                  <div className="p-4 sm:p-5">
                    <div className="mb-4 flex items-center justify-between">
                      <div>
                        <div className="text-[13px] font-semibold text-slate-100">Executive overview</div>
                        <div className="text-[10px] text-slate-500">Fiscal year 2026 - live data</div>
                      </div>
                      <div className="flex items-center gap-1.5 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2.5 py-1 text-[9.5px] font-medium text-emerald-300">
                        <span className="h-1 w-1 rounded-full bg-emerald-400" />
                        All systems synced
                      </div>
                    </div>

                    <div className="mb-4 grid grid-cols-2 gap-2.5 lg:grid-cols-4">
                      {[
                        { label: 'Revenue MTD', value: '$248,920', trend: '+12.4%', up: true, icon: 'solar:graph-up-bold-duotone', color: 'text-emerald-400' },
                        { label: 'Open invoices', value: '$86,140', trend: '-8.2%', up: true, icon: 'solar:bill-list-bold-duotone', color: 'text-emerald-400' },
                        { label: 'Stock value', value: '$1.2M', trend: '+3.1%', up: true, icon: 'solar:box-bold-duotone', color: 'text-emerald-400' },
                        { label: 'POS sales today', value: '$14,380', trend: '+21.7%', up: true, icon: 'solar:shop-bold-duotone', color: 'text-amber-400' },
                      ].map((kpi) => (
                        <div key={kpi.label} className="rounded-lg border border-white/[0.06] bg-white/[0.025] p-3">
                          <div className="flex items-center justify-between">
                            <span className="text-[9px] uppercase tracking-wide text-slate-500">{kpi.label}</span>
                            <Icon icon={kpi.icon} className={`h-3.5 w-3.5 ${kpi.color}`} />
                          </div>
                          <div className="mt-1.5 text-[15px] font-semibold tracking-tight text-white">{kpi.value}</div>
                          <div className="mt-0.5 text-[9px] font-medium text-emerald-400">{kpi.trend} vs last month</div>
                        </div>
                      ))}
                    </div>

                    <div className="grid gap-2.5 lg:grid-cols-[1.6fr_1fr]">
                      {/* chart card */}
                      <div className="rounded-lg border border-white/[0.06] bg-white/[0.025] p-3.5">
                        <div className="mb-3 flex items-center justify-between">
                          <span className="text-[10.5px] font-medium text-slate-300">Cash flow trend</span>
                          <span className="text-[9px] text-slate-500">Last 12 weeks</span>
                        </div>
                        <div className="flex h-[88px] items-end gap-1.5">
                          {[38, 52, 44, 60, 55, 72, 64, 78, 70, 86, 80, 94].map((h, i) => (
                            <div key={i} className="flex-1 rounded-t-sm bg-gradient-to-t from-emerald-500/30 to-emerald-400/80" style={{ height: `${h}%` }} />
                          ))}
                        </div>
                      </div>
                      {/* activity card */}
                      <div className="rounded-lg border border-white/[0.06] bg-white/[0.025] p-3.5">
                        <div className="mb-2.5 text-[10.5px] font-medium text-slate-300">Live activity</div>
                        {[
                          { icon: 'solar:check-circle-bold', text: 'Invoice INV-2041 paid', time: '2m', color: 'text-emerald-400' },
                          { icon: 'solar:box-bold', text: 'PO-118 received at WH-2', time: '9m', color: 'text-emerald-400' },
                          { icon: 'solar:shop-bold', text: 'Register 3 opened shift', time: '14m', color: 'text-amber-400' },
                          { icon: 'solar:document-text-bold', text: 'OCR matched 6 receipts', time: '31m', color: 'text-emerald-400' },
                        ].map((row) => (
                          <div key={row.text} className="flex items-center gap-2 border-b border-white/[0.04] py-[7px] last:border-0">
                            <Icon icon={row.icon} className={`h-3 w-3 shrink-0 ${row.color}`} />
                            <span className="flex-1 truncate text-[9.5px] text-slate-400">{row.text}</span>
                            <span className="text-[8.5px] text-slate-600">{row.time}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* floating chips */}
            <div
              className="absolute -left-4 top-1/4 hidden rounded-xl border border-white/10 bg-[#0b241c]/90 px-4 py-3 shadow-2xl backdrop-blur lg:block"
              style={{ animation: 'landing-float 7s ease-in-out infinite' }}
            >
              <div className="flex items-center gap-2.5">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-400/15">
                  <Icon icon="solar:shield-check-bold-duotone" className="h-5 w-5 text-emerald-400" />
                </span>
                <div>
                  <div className="text-[11px] font-semibold text-white">Close cycle ready</div>
                  <div className="text-[9.5px] text-slate-400">0 blocking findings</div>
                </div>
              </div>
            </div>
            <div
              className="absolute -right-4 top-2/3 hidden rounded-xl border border-white/10 bg-[#0b241c]/90 px-4 py-3 shadow-2xl backdrop-blur lg:block"
              style={{ animation: 'landing-float 8s ease-in-out 1.2s infinite' }}
            >
              <div className="flex items-center gap-2.5">
                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-400/15">
                  <Icon icon="solar:magic-stick-3-bold-duotone" className="h-5 w-5 text-emerald-400" />
                </span>
                <div>
                  <div className="text-[11px] font-semibold text-white">AI assistant</div>
                  <div className="text-[9.5px] text-slate-400">Forecast updated</div>
                </div>
              </div>
            </div>
          </Reveal>

          {/* -------- Stats row -------- */}
          <Reveal delay={120}>
            <div className="mx-auto mt-16 grid max-w-4xl grid-cols-2 gap-px overflow-hidden rounded-2xl border border-white/[0.07] bg-white/[0.06] sm:grid-cols-4">
              {HERO_STATS.map((stat) => (
                <div key={stat.label} className="bg-[#071812] px-6 py-6 text-center">
                  <div className="text-[26px] font-semibold tracking-tight text-white">{stat.value}</div>
                  <div className="mt-1 text-[12px] text-slate-500">{stat.label}</div>
                </div>
              ))}
            </div>
          </Reveal>
        </div>
      </section>

      {/* ============================ MARQUEE ============================ */}
      <section className="border-y border-white/[0.05] bg-white/[0.015] py-5">
        <div className="landing-marquee relative overflow-hidden">
          <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-24 bg-gradient-to-r from-[#04120d] to-transparent" />
          <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-24 bg-gradient-to-l from-[#04120d] to-transparent" />
          <div className="landing-marquee-track flex w-max items-center gap-10 px-5">
            {[0, 1].map((dup) => (
              <div key={dup} className="flex items-center gap-10">
                {[
                  'General Ledger', 'Multi-Currency', 'Bank Reconciliation', 'Offline POS',
                  'Barcode Scanning', 'Purchase Orders', 'Payroll-Ready HR', 'Document OCR',
                  'Financial Close', 'Work Orders', 'IoT Telemetry', 'Executive Dashboards',
                  'Storefront Builder', 'Approval Queues',
                ].map((cap) => (
                  <span key={`${dup}-${cap}`} className="flex items-center gap-2.5 whitespace-nowrap text-[13px] font-medium text-slate-500">
                    <Icon icon="solar:verified-check-bold" className="h-4 w-4 text-slate-600" />
                    {cap}
                  </span>
                ))}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ============================ PLATFORM / BENTO ============================ */}
      <section id="platform" className="relative py-24 sm:py-32">
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            backgroundImage: 'radial-gradient(900px 500px at 85% 20%, rgba(61,163,130,0.12), transparent 60%)',
          }}
        />
        <div className="relative mx-auto w-full max-w-7xl px-5 sm:px-8">
          <Reveal className="mx-auto max-w-2xl text-center">
            <span className="text-[12px] font-semibold uppercase tracking-[0.2em] text-emerald-400">The platform</span>
            <h2 className="mt-4 text-balance text-[34px] font-semibold leading-[1.1] tracking-tight text-white sm:text-[44px]">
              Everything connected. Nothing duplicated.
            </h2>
            <p className="mt-5 text-[15.5px] leading-7 text-slate-400">
              When a sale happens at the register, your stock, ledger, taxes and reports
              update instantly. That is what an operating system for business feels like.
            </p>
          </Reveal>

          <div className="mt-14 grid gap-5 lg:grid-cols-3">
            {/* Large card */}
            <Reveal className="lg:col-span-2">
              <div className="group relative h-full overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.015] p-8 transition duration-300 hover:border-white/[0.16]">
                <div className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-emerald-500/10 blur-3xl transition duration-500 group-hover:bg-emerald-500/20" />
                <span className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-400/20 bg-emerald-400/10">
                  <Icon icon="solar:layers-minimalistic-bold-duotone" className="h-6 w-6 text-emerald-400" />
                </span>
                <h3 className="mt-5 text-[22px] font-semibold tracking-tight text-white">
                  A single source of truth for your whole company
                </h3>
                <p className="mt-3 max-w-xl text-[14.5px] leading-7 text-slate-400">
                  Customers, products, prices, taxes and accounts are defined once and shared
                  by every module. No more reconciling exports between your POS, your
                  accountant's software and a warehouse spreadsheet.
                </p>
                <div className="mt-7 flex flex-wrap gap-2">
                  {['Shared catalog', 'Unified customers', 'One chart of accounts', 'Cross-module reporting'].map((t) => (
                    <span key={t} className="rounded-full border border-white/10 bg-white/[0.04] px-3.5 py-1.5 text-[12px] font-medium text-slate-300">
                      {t}
                    </span>
                  ))}
                </div>
              </div>
            </Reveal>

            {/* Tall card */}
            <Reveal delay={100}>
              <div className="group relative h-full overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.015] p-8 transition duration-300 hover:border-white/[0.16]">
                <div className="pointer-events-none absolute -left-16 -bottom-16 h-56 w-56 rounded-full bg-emerald-500/10 blur-3xl transition duration-500 group-hover:bg-emerald-500/20" />
                <span className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-emerald-400/20 bg-emerald-400/10">
                  <Icon icon="solar:bolt-circle-bold-duotone" className="h-6 w-6 text-emerald-400" />
                </span>
                <h3 className="mt-5 text-[22px] font-semibold tracking-tight text-white">Real-time, even offline</h3>
                <p className="mt-3 text-[14.5px] leading-7 text-slate-400">
                  Registers keep selling when the network drops and sync automatically when
                  it returns. Dashboards reflect every event the moment it lands.
                </p>
                <div className="mt-6 space-y-2.5">
                  {[
                    { icon: 'solar:wi-fi-router-minimalistic-bold-duotone', text: 'Offline-first POS queue' },
                    { icon: 'solar:refresh-circle-bold-duotone', text: 'Automatic conflict-safe sync' },
                    { icon: 'solar:pulse-2-bold-duotone', text: 'Live operational dashboards' },
                  ].map((row) => (
                    <div key={row.text} className="flex items-center gap-3 rounded-lg border border-white/[0.06] bg-white/[0.03] px-3.5 py-2.5 text-[13px] text-slate-300">
                      <Icon icon={row.icon} className="h-5 w-5 shrink-0 text-emerald-400" />
                      {row.text}
                    </div>
                  ))}
                </div>
              </div>
            </Reveal>

            {/* Three small cards */}
            {[
              {
                icon: 'solar:magic-stick-3-bold-duotone',
                accent: 'text-emerald-400',
                border: 'border-emerald-400/20',
                bg: 'bg-emerald-400/10',
                title: 'AI built into the workflow',
                desc: 'OCR document capture, close-cycle findings, demand forecasting and a business-aware assistant.',
              },
              {
                icon: 'solar:globus-bold-duotone',
                accent: 'text-amber-400',
                border: 'border-amber-400/20',
                bg: 'bg-amber-400/10',
                title: 'Multi-entity, multi-currency',
                desc: 'Operate across companies, warehouses, registers and currencies without leaving the workspace.',
              },
              {
                icon: 'solar:code-square-bold-duotone',
                accent: 'text-teal-400',
                border: 'border-teal-400/20',
                bg: 'bg-teal-400/10',
                title: 'Open and extensible',
                desc: 'A clean API surface, feature gates and a website builder for storefronts and customer portals.',
              },
            ].map((card, i) => (
              <Reveal key={card.title} delay={i * 80}>
                <div className="group relative h-full overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.015] p-7 transition duration-300 hover:border-white/[0.16]">
                  <span className={`inline-flex h-10 w-10 items-center justify-center rounded-xl border ${card.border} ${card.bg}`}>
                    <Icon icon={card.icon} className={`h-5 w-5 ${card.accent}`} />
                  </span>
                  <h3 className="mt-4 text-[17px] font-semibold tracking-tight text-white">{card.title}</h3>
                  <p className="mt-2.5 text-[13.5px] leading-6 text-slate-400">{card.desc}</p>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ============================ MODULES ============================ */}
      <section id="modules" className="relative border-t border-white/[0.05] py-24 sm:py-32">
        <div className="mx-auto w-full max-w-7xl px-5 sm:px-8">
          <Reveal className="mx-auto max-w-2xl text-center">
            <span className="text-[12px] font-semibold uppercase tracking-[0.2em] text-emerald-400">Modules</span>
            <h2 className="mt-4 text-balance text-[34px] font-semibold leading-[1.1] tracking-tight text-white sm:text-[44px]">
              Every department. One login.
            </h2>
            <p className="mt-5 text-[15.5px] leading-7 text-slate-400">
              Turn modules on as you grow. Each one is deeply integrated with the rest -
              not bolted on.
            </p>
          </Reveal>

          <div className="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {MODULES.map((mod, i) => (
              <Reveal key={mod.name} delay={(i % 3) * 80}>
                <div className="group relative h-full overflow-hidden rounded-2xl border border-white/[0.07] bg-white/[0.025] p-6 transition duration-300 hover:-translate-y-1 hover:border-white/[0.16] hover:bg-white/[0.045]">
                  <div className={`pointer-events-none absolute -right-12 -top-12 h-36 w-36 rounded-full bg-gradient-to-br ${mod.glow} to-transparent opacity-0 blur-2xl transition duration-500 group-hover:opacity-100`} />
                  <div className="flex items-center gap-3.5">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/[0.08] bg-white/[0.04]">
                      <Icon icon={mod.icon} className={`h-5 w-5 ${mod.accent}`} />
                    </span>
                    <h3 className="text-[15.5px] font-semibold tracking-tight text-white">{mod.name}</h3>
                  </div>
                  <p className="mt-3.5 text-[13px] leading-6 text-slate-400">{mod.desc}</p>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ============================ SECURITY ============================ */}
      <section id="security" className="relative overflow-hidden border-t border-white/[0.05] py-24 sm:py-32">
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            backgroundImage: 'radial-gradient(800px 500px at 15% 50%, rgba(16,185,129,0.08), transparent 60%)',
          }}
        />
        <div className="relative mx-auto w-full max-w-7xl px-5 sm:px-8">
          <div className="grid items-center gap-14 lg:grid-cols-2">
            <Reveal>
              <span className="text-[12px] font-semibold uppercase tracking-[0.2em] text-emerald-400">Security</span>
              <h2 className="mt-4 text-balance text-[34px] font-semibold leading-[1.1] tracking-tight text-white sm:text-[42px]">
                Enterprise-grade trust, from day one.
              </h2>
              <p className="mt-5 max-w-lg text-[15.5px] leading-7 text-slate-400">
                Your financials deserve more than a shared database and good intentions.
                NoovaERP is architected for isolation, control and accountability.
              </p>
              <div className="mt-8 space-y-5">
                {SECURITY_POINTS.map((point) => (
                  <div key={point.title} className="flex gap-4">
                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-emerald-400/15 bg-emerald-400/10">
                      <Icon icon={point.icon} className="h-5 w-5 text-emerald-400" />
                    </span>
                    <div>
                      <h3 className="text-[15px] font-semibold text-white">{point.title}</h3>
                      <p className="mt-1 text-[13.5px] leading-6 text-slate-400">{point.desc}</p>
                    </div>
                  </div>
                ))}
              </div>
            </Reveal>

            <Reveal delay={120}>
              <div className="relative">
                <div className="pointer-events-none absolute -inset-6 rounded-3xl bg-emerald-500/5 blur-2xl" />
                <div className="relative rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.05] to-white/[0.015] p-7">
                  <div className="mb-5 flex items-center justify-between">
                    <span className="text-[13px] font-semibold text-white">Tenant security posture</span>
                    <span className="flex items-center gap-1.5 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2.5 py-1 text-[10.5px] font-medium text-emerald-300">
                      <Icon icon="solar:shield-check-bold" className="h-3 w-3" />
                      Hardened
                    </span>
                  </div>
                  {[
                    { label: 'Schema isolation', value: 'Per-tenant', icon: 'solar:server-square-bold-duotone' },
                    { label: 'Transport encryption', value: 'TLS 1.2+', icon: 'solar:lock-password-bold-duotone' },
                    { label: 'Access model', value: 'RBAC + scoped tokens', icon: 'solar:user-id-bold-duotone' },
                    { label: 'Financial controls', value: 'Lock dates + audit trail', icon: 'solar:clipboard-check-bold-duotone' },
                    { label: 'Session handling', value: 'Sanctum tokens', icon: 'solar:key-minimalistic-square-3-bold-duotone' },
                  ].map((row) => (
                    <div key={row.label} className="flex items-center justify-between border-b border-white/[0.05] py-3.5 last:border-0">
                      <span className="flex items-center gap-3 text-[13px] text-slate-300">
                        <Icon icon={row.icon} className="h-5 w-5 text-slate-500" />
                        {row.label}
                      </span>
                      <span className="rounded-md border border-white/[0.07] bg-white/[0.04] px-2.5 py-1 font-mono text-[11px] text-emerald-300">
                        {row.value}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            </Reveal>
          </div>
        </div>
      </section>

      {/* ============================ WHY ============================ */}
      <section id="why" className="border-t border-white/[0.05] py-24 sm:py-32">
        <div className="mx-auto w-full max-w-7xl px-5 sm:px-8">
          <Reveal className="mx-auto max-w-2xl text-center">
            <span className="text-[12px] font-semibold uppercase tracking-[0.2em] text-emerald-400">Why NoovaERP</span>
            <h2 className="mt-4 text-balance text-[34px] font-semibold leading-[1.1] tracking-tight text-white sm:text-[44px]">
              Built for teams who outgrew their tools.
            </h2>
          </Reveal>

          <div className="mt-14 grid gap-5 sm:grid-cols-2">
            {WHY_POINTS.map((point, i) => (
              <Reveal key={point.title} delay={(i % 2) * 80}>
                <div className="flex h-full gap-5 rounded-2xl border border-white/[0.07] bg-white/[0.025] p-7 transition duration-300 hover:border-white/[0.14]">
                  <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-emerald-400/15 bg-emerald-400/10">
                    <Icon icon={point.icon} className="h-6 w-6 text-emerald-400" />
                  </span>
                  <div>
                    <h3 className="text-[17px] font-semibold tracking-tight text-white">{point.title}</h3>
                    <p className="mt-2 text-[14px] leading-6 text-slate-400">{point.desc}</p>
                  </div>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* ============================ CTA ============================ */}
      <section className="relative overflow-hidden border-t border-white/[0.05] py-24 sm:py-28">
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            backgroundImage:
              'radial-gradient(700px 400px at 50% 0%, rgba(56,189,248,0.14), transparent 65%),' +
              'radial-gradient(600px 350px at 50% 100%, rgba(61,163,130,0.12), transparent 65%)',
          }}
        />
        <div className="relative mx-auto max-w-3xl px-5 text-center sm:px-8">
          <Reveal>
            <h2 className="text-balance text-[36px] font-semibold leading-[1.08] tracking-tight text-white sm:text-[52px]">
              Your operations deserve
              <span className="block bg-gradient-to-r from-emerald-300 via-teal-300 to-emerald-300 bg-clip-text text-transparent">
                a real operating system.
              </span>
            </h2>
            <p className="mx-auto mt-6 max-w-xl text-[15.5px] leading-7 text-slate-400">
              Create your workspace in under two minutes. Full platform access,
              14 days, no credit card.
            </p>
            <div className="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
              <Link
                to="/signup"
                className="group inline-flex h-12 w-full items-center justify-center gap-2 rounded-full bg-white px-8 text-[15px] font-semibold text-slate-950 shadow-[0_0_40px_rgba(125,211,252,0.3)] transition hover:bg-slate-200 sm:w-auto"
              >
                Create your workspace
                <Icon icon="solar:arrow-right-linear" className="h-[18px] w-[18px] transition-transform group-hover:translate-x-1" />
              </Link>
              <Link
                to="/login"
                className="inline-flex h-12 w-full items-center justify-center rounded-full border border-white/15 bg-white/[0.04] px-8 text-[15px] font-medium text-white transition hover:border-white/30 hover:bg-white/[0.08] sm:w-auto"
              >
                I already have an account
              </Link>
            </div>
          </Reveal>
        </div>
      </section>

      {/* ============================ FOOTER ============================ */}
      <footer className="border-t border-white/[0.06] bg-[#030d0a]">
        <div className="mx-auto w-full max-w-7xl px-5 py-14 sm:px-8">
          <div className="grid gap-10 md:grid-cols-[1.4fr_repeat(3,1fr)]">
            <div>
              <div className="flex items-center">
                <span className="flex h-8 items-center rounded-lg bg-white px-1.5">
                  <img src={NOOVAERP_LOGO_URL} alt="NoovaERP" className="h-5 w-auto object-contain" />
                </span>
              </div>
              <p className="mt-4 max-w-xs text-[13px] leading-6 text-slate-500">
                The multi-tenant ERP platform that unifies finance, inventory, retail,
                HR and service into one governed workspace.
              </p>
              <div className="mt-5 flex items-center gap-3 text-[11px] text-slate-500">
                <span className="inline-flex items-center gap-1.5">
                  <Icon icon="solar:shield-check-bold-duotone" className="h-3.5 w-3.5 text-emerald-500" />
                  TLS encrypted
                </span>
                <span className="h-3 w-px bg-white/10" />
                <span className="inline-flex items-center gap-1.5">
                  <Icon icon="solar:server-square-cloud-bold-duotone" className="h-3.5 w-3.5 text-emerald-500" />
                  Tenant-isolated
                </span>
              </div>
            </div>

            {FOOTER_COLS.map((col) => (
              <div key={col.title}>
                <h4 className="text-[12px] font-semibold uppercase tracking-wider text-slate-400">{col.title}</h4>
                <ul className="mt-4 space-y-2.5">
                  {col.links.map((link) => (
                    <li key={link}>
                      <a href="#top" className="text-[13px] text-slate-500 transition hover:text-slate-200">
                        {link}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>

          <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-white/[0.06] pt-7 text-[12px] text-slate-600 sm:flex-row">
            <span>(c) {new Date().getFullYear()} NoovaERP. All rights reserved.</span>
            <div className="flex items-center gap-5">
              <Link to="/login" className="transition hover:text-slate-300">Log in</Link>
              <Link to="/signup" className="transition hover:text-slate-300">Create workspace</Link>
            </div>
          </div>
        </div>
      </footer>
    </div>
  )
}
