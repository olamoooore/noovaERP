import { Link, useLocation } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
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
    ],
  },
  {
    title: 'Products',
    href: '/products',
    icon: 'solar:box-bold-duotone',
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
    title: 'Customers',
    href: '/customers',
    icon: 'solar:users-group-rounded-bold-duotone',
  },
  {
    title: 'Invoices',
    href: '/invoices',
    icon: 'solar:document-text-bold-duotone',
    featureKey: 'accounting',
  },
  {
    title: 'Payments',
    href: '/payments',
    icon: 'solar:wallet-money-bold-duotone',
    featureKey: 'accounting',
  },
  {
    title: 'Accounting',
    href: '/accounting',
    icon: 'solar:chart-2-bold-duotone',
    featureKey: 'accounting',
  },
  {
    title: 'OCR Documents',
    href: '/ocr/documents',
    icon: 'solar:document-add-bold-duotone',
    featureKey: 'accounting',
  },
  {
    title: 'Dashboards',
    href: '/dashboards/executive',
    icon: 'solar:chart-square-bold-duotone',
    children: [
      {
        title: 'Executive',
        href: '/dashboards/executive',
        icon: 'solar:graph-new-bold-duotone',
      },
      {
        title: 'Sales',
        href: '/dashboards/sales',
        icon: 'solar:cart-large-2-bold-duotone',
      },
      {
        title: 'Inventory',
        href: '/dashboards/inventory',
        icon: 'solar:box-bold-duotone',
      },
    ],
  },
  {
    title: 'Reports',
    href: '/reports/financial',
    icon: 'solar:document-text-bold-duotone',
    children: [
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

  const { data: features } = useQuery({
    queryKey: ['features'],
    queryFn: async () => {
      const response = await api.get('/features')
      return response.data
    },
    staleTime: 1000 * 60 * 5, // 5 minutes
  })

  const enabledFeatures = new Set(
    features?.filter((f: any) => f.enabled).map((f: any) => f.key)
  )
  
  // Force enable POS for testing if features list is empty or fails
  if (!features || features.length === 0) {
    enabledFeatures.add('pos')
  }

  const navItems = baseNavItems.filter(item => {
    if (!item.featureKey) return true
    return enabledFeatures.has(item.featureKey)
  })

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
      return item.children.some(child => location.pathname === child.href)
    }
    return false
  }

  return (
    <div className="flex h-full w-64 flex-col border-r bg-card">
      {/* Logo */}
      <div className="flex h-16 items-center border-b px-6">
        <Icon icon="solar:buildings-2-bold-duotone" className="h-8 w-8 text-primary" />
        <span className="ml-2 text-xl font-bold">NoovaERP</span>
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 p-4 overflow-y-auto">
        {navItems.map((item) => {
          const isActive = isItemActive(item)
          const isExpanded = expandedItems.includes(item.title)
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
