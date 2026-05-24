import { Link, useLocation } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { useState } from 'react'

interface NavItem {
  title: string
  href: string
  icon: string
  badge?: number
  featureKey?: string
  children?: NavItem[]
}

const baseNavItems: NavItem[] = [
  {
    title: 'Dashboard',
    href: '/',
    icon: 'solar:home-2-bold-duotone',
  },
  {
    title: 'POS',
    href: '/pos',
    icon: 'solar:cash-out-bold-duotone',
    featureKey: 'pos',
    children: [
      {
        title: 'Open POS',
        href: '/pos',
        icon: 'solar:shop-bold-duotone',
      },
      {
        title: 'POS Orders',
        href: '/pos/orders',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Barcodes',
        href: '/pos/barcodes',
        icon: 'solar:qr-code-bold-duotone',
      },
      {
        title: 'Reports',
        href: '/pos/reports',
        icon: 'solar:chart-2-bold-duotone',
      },
      {
        title: 'POS Settings',
        href: '/pos/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
  },
  {
    title: 'Products',
    href: '/products',
    icon: 'solar:box-bold-duotone',
    featureKey: 'inventory',
    children: [
      {
        title: 'All Products',
        href: '/products',
        icon: 'solar:box-bold-duotone',
      },
      {
        title: 'Add Product',
        href: '/products/new',
        icon: 'solar:add-circle-bold-duotone',
      },
      {
        title: 'Product Settings',
        href: '/products/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
  },
  {
    title: 'Inventory',
    href: '/inventory',
    icon: 'solar:box-minimalistic-bold-duotone',
    featureKey: 'inventory',
    children: [
      {
        title: 'Dashboard',
        href: '/inventory',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Stock Management',
        href: '/inventory/stock',
        icon: 'solar:box-bold-duotone',
      },
      {
        title: 'Stock Movements',
        href: '/inventory/movements',
        icon: 'solar:transfer-horizontal-bold-duotone',
      },
      {
        title: 'Stock Operations',
        href: '/inventory/operations',
        icon: 'solar:clipboard-list-bold-duotone',
      },
      {
        title: 'Purchase Orders',
        href: '/inventory/purchase-orders',
        icon: 'solar:cart-large-4-bold-duotone',
      },
      {
        title: 'Warehouse Transfers',
        href: '/inventory/transfers',
        icon: 'solar:round-transfer-horizontal-bold-duotone',
      },
      {
        title: 'Warehouses',
        href: '/inventory/warehouses',
        icon: 'solar:buildings-3-bold-duotone',
      },
      {
        title: 'Suppliers',
        href: '/inventory/suppliers',
        icon: 'solar:users-group-two-rounded-bold-duotone',
      },
      {
        title: 'Settings',
        href: '/inventory/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
  },
  {
    title: 'Sales',
    href: '/sales',
    icon: 'solar:cart-large-2-bold-duotone',
    featureKey: 'sales',
    children: [
      {
        title: 'Dashboard',
        href: '/sales',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Orders',
        href: '/sales/orders',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Quotes',
        href: '/sales/quotes',
        icon: 'solar:file-text-bold-duotone',
      },
      {
        title: 'Customers',
        href: '/customers',
        icon: 'solar:users-group-rounded-bold-duotone',
      },
      {
        title: 'Invoices',
        href: '/invoices',
        icon: 'solar:bill-list-bold-duotone',
      },
      {
        title: 'Payments',
        href: '/payments',
        icon: 'solar:wallet-money-bold-duotone',
      },
    ],
  },
  {
    title: 'Accounting',
    href: '/accounting/dashboard',
    icon: 'solar:chart-2-bold-duotone',
    featureKey: 'accounting',
    children: [
      {
        title: 'Onboarding',
        href: '/accounting/core/onboarding',
        icon: 'solar:clipboard-check-bold-duotone',
      },
      {
        title: 'Dashboard',
        href: '/accounting/dashboard',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Chart of Accounts',
        href: '/accounting/core/chart-of-accounts',
        icon: 'solar:notebook-bold-duotone',
      },
      {
        title: 'Journal Entries',
        href: '/accounting/core/journal-entries',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Customer Invoices',
        href: '/accounting/customers/bills',
        icon: 'solar:bill-list-bold-duotone',
      },
      {
        title: 'Vendors',
        href: '/accounting/vendors',
        icon: 'solar:users-group-two-rounded-bold-duotone',
      },
      {
        title: 'Vendor Bills',
        href: '/accounting/vendors/bills',
        icon: 'solar:clipboard-list-bold-duotone',
      },
      {
        title: 'Bank Accounts',
        href: '/accounting/configuration/bank-accounts',
        icon: 'solar:card-bold-duotone',
      },
      {
        title: 'Bank Reconciliation',
        href: '/accounting/core/reconciliation',
        icon: 'solar:safe-2-bold-duotone',
      },
      {
        title: 'Entities',
        href: '/accounting/core/entities',
        icon: 'solar:buildings-2-bold-duotone',
      },
      {
        title: 'Fixed Assets',
        href: '/accounting/core/assets',
        icon: 'solar:buildings-bold-duotone',
      },
      {
        title: 'Multi-Currency',
        href: '/accounting/core/multi-currency',
        icon: 'solar:dollar-bold-duotone',
      },
      {
        title: 'Reports',
        href: '/accounting/reporting/trial-balance',
        icon: 'solar:file-text-bold-duotone',
      },
      {
        title: 'Configuration',
        href: '/accounting/configuration/company',
        icon: 'solar:settings-bold-duotone',
      },
      {
        title: 'Financial Close',
        href: '/financial-close',
        icon: 'solar:calendar-mark-bold-duotone',
        featureKey: 'ai',
        children: [
          {
            title: 'Dashboard',
            href: '/financial-close',
            icon: 'solar:home-2-bold-duotone',
          },
          {
            title: 'Close Cycles',
            href: '/financial-close/cycles',
            icon: 'solar:calendar-bold-duotone',
          },
          {
            title: 'My Tasks',
            href: '/financial-close/tasks',
            icon: 'solar:list-check-bold-duotone',
          },
          {
            title: 'Review Queue',
            href: '/financial-close/review-queue',
            icon: 'solar:inbox-bold-duotone',
          },
          {
            title: 'AI Findings',
            href: '/financial-close/findings',
            icon: 'solar:magic-stick-3-bold-duotone',
          },
          {
            title: 'Readiness Cockpit',
            href: '/financial-close/readiness',
            icon: 'solar:shield-check-bold-duotone',
          },
          {
            title: 'Forecast',
            href: '/financial-close/forecast',
            icon: 'solar:chart-2-bold-duotone',
          },
          {
            title: 'Executive Summary',
            href: '/financial-close/executive',
            icon: 'solar:chart-bold-duotone',
          },
        ],
      },
      {
        title: 'OCR Bookkeeping',
        href: '/ocr/documents',
        icon: 'solar:document-add-bold-duotone',
        featureKey: 'ocr-bookkeeping',
      },
    ],
  },
  {
    title: 'Loyalty',
    href: '/loyalty',
    icon: 'solar:gift-bold-duotone',
    featureKey: 'loyalty',
    children: [
      {
        title: 'Dashboard',
        href: '/loyalty',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Customers',
        href: '/loyalty/customers',
        icon: 'solar:users-group-rounded-bold-duotone',
      },
      {
        title: 'Rewards',
        href: '/loyalty/rewards',
        icon: 'solar:gift-bold-duotone',
      },
      {
        title: 'Redemptions',
        href: '/loyalty/redemptions',
        icon: 'solar:ticket-bold-duotone',
      },
      {
        title: 'Settings',
        href: '/loyalty/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
  },
  {
    title: 'Event Management',
    href: '/event-management',
    icon: 'solar:calendar-bold-duotone',
    featureKey: 'event_management',
    children: [
      {
        title: 'Events',
        href: '/event-management/dashboard',
        icon: 'solar:calendar-mark-bold-duotone',
      },
      {
        title: 'Browse Events',
        href: '/event-management/browse',
        icon: 'solar:ticket-sale-bold-duotone',
      },
      {
        title: 'Staff Operations',
        href: '/event-management/staff',
        icon: 'solar:users-group-rounded-bold-duotone',
      },
      {
        title: 'Live Operations',
        href: '/event-management/operations',
        icon: 'solar:monitor-bold-duotone',
      },
      {
        title: 'Bookings',
        href: '/event-management/bookings',
        icon: 'solar:bookmark-bold-duotone',
      },
      {
        title: 'Tickets',
        href: '/event-management/tickets',
        icon: 'solar:ticket-bold-duotone',
      },
      {
        title: 'Feedback',
        href: '/event-management/feedback',
        icon: 'solar:chat-round-bold-duotone',
      },
    ],
  },
  {
    title: 'Website',
    href: '/website-builder',
    icon: 'solar:global-bold-duotone',
    children: [
      {
        title: 'My Websites',
        href: '/website-builder',
        icon: 'solar:buildings-bold-duotone',
        featureKey: 'website-builder',
      },
      {
        title: 'Online Shop',
        href: '/store',
        icon: 'solar:bag-4-bold-duotone',
        featureKey: 'ecommerce',
      },
    ],
  },
  {
    title: 'IoT Hub',
    href: '/iot-hub',
    icon: 'solar:cpu-bolt-bold-duotone',
    featureKey: 'iot',
  },
  {
    title: 'AI Chat',
    href: '/ai/chat',
    icon: 'solar:chat-round-dots-bold-duotone',
    featureKey: 'ai',
  },
  {
    title: 'Field Service',
    href: '/field-service',
    icon: 'solar:clipboard-list-bold-duotone',
    featureKey: 'field-service',
    children: [
      {
        title: 'Dashboard',
        href: '/field-service',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Work Orders',
        href: '/field-service/work-orders',
        icon: 'solar:clipboard-list-bold-duotone',
      },
      {
        title: 'Schedule',
        href: '/field-service/schedule',
        icon: 'solar:calendar-bold-duotone',
      },
      {
        title: 'Technicians',
        href: '/field-service/technicians',
        icon: 'solar:users-group-two-rounded-bold-duotone',
      },
      {
        title: 'Teams',
        href: '/field-service/teams',
        icon: 'solar:people-nearby-bold-duotone',
      },
      {
        title: 'Gantt',
        href: '/field-service/gantt',
        icon: 'solar:chart-bold-duotone',
      },
      {
        title: 'Analytics',
        href: '/field-service/analytics',
        icon: 'solar:graph-bold-duotone',
      },
      {
        title: 'Worksheets',
        href: '/field-service/worksheet-templates',
        icon: 'solar:document-text-bold-duotone',
      },
    ],
  },
  {
    title: 'HRM',
    href: '/hrm',
    icon: 'solar:users-group-rounded-bold-duotone',
    featureKey: 'hrm',
    children: [
      {
        title: 'Dashboard',
        href: '/hrm',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Employees',
        href: '/hrm/employees',
        icon: 'solar:user-id-bold-duotone',
      },
      {
        title: 'Contracts',
        href: '/hrm/contracts',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Timeshifts',
        href: '/hrm/timeshifts',
        icon: 'solar:clock-circle-bold-duotone',
      },
      {
        title: 'Attendance',
        href: '/hrm/attendance',
        icon: 'solar:clipboard-list-bold-duotone',
      },
      {
        title: 'Leave Requests',
        href: '/hrm/leave-requests',
        icon: 'solar:calendar-bold-duotone',
      },
      {
        title: 'Payroll',
        href: '/hrm/payroll',
        icon: 'solar:wallet-money-bold-duotone',
      },
    ],
  },
  {
    title: 'Reports',
    href: '/reports/financial',
    icon: 'solar:document-text-bold-duotone',
    featureKey: 'financial-reporting',
    children: [
      {
        title: 'Executive Dashboard',
        href: '/dashboards/executive',
        icon: 'solar:graph-new-bold-duotone',
      },
      {
        title: 'Financial Reports',
        href: '/reports/financial',
        icon: 'solar:dollar-bold-duotone',
      },
      {
        title: 'Inventory Reports',
        href: '/reports/inventory',
        icon: 'solar:box-bold-duotone',
      },
      {
        title: 'Sales Analytics',
        href: '/reports/sales-analytics',
        icon: 'solar:chart-2-bold-duotone',
      },
      {
        title: 'Expense Analytics',
        href: '/reports/expense-analytics',
        icon: 'solar:wallet-money-bold-duotone',
      },
    ],
  },
  {
    title: 'Modules',
    href: '/modules',
    icon: 'solar:widget-bold-duotone',
  },
  {
    title: 'Settings',
    href: '/settings',
    icon: 'solar:settings-bold-duotone',
  },
]

export function Sidebar() {
  const location = useLocation()
  const [expandedItems, setExpandedItems] = useState<string[]>([])
  const tenantId = localStorage.getItem('tenant_id') || 'default'

  const { data: features } = useQuery({
    queryKey: ['features', tenantId],
    queryFn: async () => {
      const response = await api.get('/features', {
        headers: { 'X-Feature-Gate-Fresh': '1' },
      })
      const data = response.data
      return Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
    },
    retry: false,
    staleTime: 1000 * 30,
  })

  const normalizeFeatureKey = (key: string) => key.toLowerCase().replace(/[\s_]+/g, '-')

  const featureStatusByKey = new Map(
    (features || []).map((f: any) => [normalizeFeatureKey(String(f.key || '')), Boolean(f.enabled)])
  )

  const featureKeyAliases: Record<string, string[]> = {
    hrm: ['human-resources', 'human_resources'],
    'field-service': ['field_service'],
    'event-management': ['event_management'],
    'financial-reporting': ['financial_reporting'],
  }

  // Tenant-specific hard blocks for modules that must never appear in navigation.
  const tenantForceDisabledFeatures: Record<string, string[]> = {
    'c194ebd3-a0af-4aca-a9b5-1f3275318228': ['field-service'],
  }

  const isFeatureEnabled = (featureKey?: string) => {
    if (!featureKey) return true
    if (!features) return false

    const normalizedKey = normalizeFeatureKey(featureKey)
    const forceDisabledForTenant = (tenantForceDisabledFeatures[tenantId] || []).map((key) =>
      normalizeFeatureKey(key)
    )

    if (forceDisabledForTenant.includes(normalizedKey)) {
      return false
    }

    const keysToCheck = [
      normalizedKey,
      ...(featureKeyAliases[normalizedKey] || []).map((key) => normalizeFeatureKey(key)),
    ]
    const matchedKey = keysToCheck.find((key) => featureStatusByKey.has(key))

    if (!matchedKey) {
      return true
    }

    return featureStatusByKey.get(matchedKey) === true
  }

  const navItems = baseNavItems.reduce<NavItem[]>((items, item) => {
    if (!isFeatureEnabled(item.featureKey)) {
      return items
    }

    const children = item.children?.filter((child) => isFeatureEnabled(child.featureKey))

    if (item.children && !item.featureKey && features && (!children || children.length === 0)) {
      return items
    }

    items.push(children ? { ...item, children } : item)

    return items
  }, [])

  const toggleExpand = (title: string) => {
    setExpandedItems(prev => 
      prev.includes(title) 
        ? prev.filter(t => t !== title)
        : [...prev, title]
    )
  }

  const isItemActive = (item: NavItem): boolean => {
    if (location.pathname === item.href) return true
    if (item.children) {
      return item.children.some(child => 
        location.pathname === child.href || location.pathname.startsWith(child.href + '/')
      )
    }
    return false
  }

  // Auto-expand parent items when a child route is active
  const activeParents = navItems
    .filter(item => item.children && isItemActive(item))
    .map(item => item.title)

  const effectiveExpanded = [...new Set([...expandedItems, ...activeParents])]

  return (
    <div className="flex h-full w-64 flex-col border-r bg-card">
      {/* Logo */}
      <div className="flex h-16 items-center border-b px-6">
        <img
          src={NOOVAERP_LOGO_URL}
          alt="NoovaERP"
          className="h-9 w-auto max-w-full object-contain"
        />
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 p-4 overflow-y-auto">
        {navItems.map((item) => {
          const isActive = isItemActive(item)
          const isExpanded = effectiveExpanded.includes(item.title)
          const hasChildren = item.children && item.children.length > 0

          return (
            <div key={item.href}>
              {hasChildren ? (
                <>
                  <button
                    onClick={() => toggleExpand(item.title)}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                      isActive
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                    )}
                  >
                    <Icon icon={item.icon} className="h-5 w-5" />
                    <span className="flex-1 text-left">{item.title}</span>
                    <Icon 
                      icon={isExpanded ? "solar:alt-arrow-up-linear" : "solar:alt-arrow-down-linear"} 
                      className="h-4 w-4" 
                    />
                  </button>
                  {isExpanded && (
                    <div className="ml-4 mt-1 space-y-1 border-l pl-4">
                      {item.children?.map((child) => {
                        const childActive = location.pathname === child.href
                        return (
                          <Link
                            key={child.href}
                            to={child.href}
                            className={cn(
                              'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                              childActive
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                            )}
                          >
                            <Icon icon={child.icon} className="h-4 w-4" />
                            <span>{child.title}</span>
                          </Link>
                        )
                      })}
                    </div>
                  )}
                </>
              ) : (
                <Link
                  to={item.href}
                  className={cn(
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                  )}
                >
                  <Icon icon={item.icon} className="h-5 w-5" />
                  <span>{item.title}</span>
                  {item.badge && (
                    <span className="ml-auto flex h-5 w-5 items-center justify-center rounded-full bg-destructive text-xs text-destructive-foreground">
                      {item.badge}
                    </span>
                  )}
                </Link>
              )}
            </div>
          )
        })}
      </nav>

      {/* User info */}
      <div className="border-t p-4">
        <div className="flex items-center gap-3 text-sm">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-primary-foreground">
            <Icon icon="solar:user-bold" className="h-5 w-5" />
          </div>
          <div className="flex-1">
            <p className="font-medium">Tenant Name</p>
            <p className="text-xs text-muted-foreground">Free Trial</p>
          </div>
        </div>
      </div>
    </div>
  )
}
