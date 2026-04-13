import { NavLink } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'

interface NavItem {
  label: string
  path: string
  icon: string
}

const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard',     path: '/',                icon: 'solar:home-angle-bold-duotone' },
  { label: 'Tenants',       path: '/tenants',         icon: 'solar:buildings-bold-duotone' },
  { label: 'Plans',         path: '/plans',           icon: 'solar:tag-price-bold-duotone' },
  { label: 'Modules',       path: '/modules',         icon: 'solar:widget-bold-duotone' },
  { label: 'Catalog',       path: '/catalog',         icon: 'solar:box-bold-duotone' },
  { label: 'Tier Matrix',   path: '/packaging',       icon: 'solar:widget-5-bold-duotone' },
  { label: 'Pricing',       path: '/pricing',         icon: 'solar:calculator-bold-duotone' },
  { label: 'Plan Changes',  path: '/plan-changes',    icon: 'solar:arrow-right-up-bold-duotone' },
  { label: 'Subscriptions', path: '/subscriptions',   icon: 'solar:card-recive-bold-duotone' },
  { label: 'Billing',       path: '/billing',         icon: 'solar:bill-list-bold-duotone' },
  { label: 'Collections',   path: '/collections',     icon: 'solar:hand-money-bold-duotone' },
  { label: 'Operations',    path: '/operations',      icon: 'solar:settings-bold-duotone' },
  { label: 'Analytics',     path: '/analytics',       icon: 'solar:chart-square-bold-duotone' },
  { label: 'Admin Users',   path: '/users',           icon: 'solar:users-group-rounded-bold-duotone' },
  { label: 'Audit Log',     path: '/audit',           icon: 'solar:shield-check-bold-duotone' },
  { label: 'Observability', path: '/observability',   icon: 'solar:graph-bold-duotone' },
]

export function AdminSidebar() {
  return (
    <aside className="flex h-screen w-[var(--sidebar-width)] flex-col border-r border-gray-200 bg-white">
      {/* Logo */}
      <div className="flex h-[var(--topbar-height)] items-center gap-2.5 border-b border-gray-200 px-4">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600">
          <Icon icon="solar:shield-star-bold-duotone" className="text-white" width={18} />
        </div>
        <div className="leading-tight">
          <p className="text-xs font-bold tracking-widest text-brand-700 uppercase">NoovaERP</p>
          <p className="text-[10px] text-gray-400">Control Plane</p>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto px-3 py-4 scrollbar-thin">
        <ul className="space-y-0.5">
          {NAV_ITEMS.map((item) => (
            <li key={item.path}>
              <NavLink
                to={item.path}
                end={item.path === '/'}
                className={({ isActive }) =>
                  cn(
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-brand-50 text-brand-700'
                      : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
                  )
                }
              >
                <Icon icon={item.icon} width={18} className="shrink-0" />
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      {/* Footer */}
      <div className="border-t border-gray-200 px-4 py-3">
        <p className="text-[10px] text-gray-400">Phase 6E — Plan Change Engine</p>
      </div>
    </aside>
  )
}
