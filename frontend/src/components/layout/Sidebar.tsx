import { Link, useLocation } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { useState } from 'react'
import { useAuthStore } from '@/store/authStore'
import { baseNavItems, deriveNavPermission, type NavItem } from '@/lib/navigation'

export function Sidebar() {
  const location = useLocation()
  const [expandedItems, setExpandedItems] = useState<string[]>([])
  const [isMinimized, setIsMinimized] = useState(() => localStorage.getItem('sidebar_minimized') === 'true')
  const [hoveredItem, setHoveredItem] = useState<string | null>(null)
  const tenantId = localStorage.getItem('tenant_id') || 'default'
  const isAcmeTenant = tenantId === '00000000-0000-0000-0000-000000000001'

  const toggleMinimize = () => {
    setIsMinimized(prev => {
      const next = !prev
      localStorage.setItem('sidebar_minimized', String(next))
      return next
    })
  }

  // Issue: sidebar blanked (only Master Dashboard / Website / Settings showed)
  // whenever GET /features returned 429 (platform rate limit) or any error.
  // Root causes fixed here:
  //   1. Removed the `X-Feature-Gate-Fresh: 1` header — it forced a server-side
  //      cache bypass (full DB re-resolve) on EVERY sidebar render, feeding the
  //      request storm that tripped the rate limit in the first place.
  //   2. `retry` now retries transient failures (429/5xx) with backoff so a
  //      momentary throttle self-recovers instead of permanently hiding modules.
  //   3. `placeholderData: keepPreviousData` keeps the last-known-good feature
  //      set on screen during background refetch — the nav never blanks.
  //   4. `isError` is consumed below so a FAILED fetch fails OPEN (modules shown)
  //      rather than closed. The sidebar is UX only; entitlements are still
  //      enforced server-side by EnsureEntitlementAccess on every API route, so
  //      failing open here cannot bypass any real authorization.
  const { data: features, isError: featuresError } = useQuery({
    queryKey: ['features', tenantId],
    queryFn: async () => {
      const response = await api.get('/features')
      const data = response.data
      return Array.isArray(data) ? data : (Array.isArray(data?.data) ? data.data : [])
    },
    // Don't retry 429s — the Retry-After header says exactly when the
    // bucket resets; piling on more requests just makes the throttling
    // worse and the user wait longer. Server-side failures (5xx) still
    // retry with backoff.
    retry: (failureCount, error: any) => {
      const status = error?.response?.status
      if (status === 429) return false
      if (status >= 500 && status < 600) return failureCount < 3
      if (status >= 400 && status < 500) return false
      return failureCount < 3
    },
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 15000),
    staleTime: 1000 * 60 * 10,
    placeholderData: (prev: any) => prev,
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
    '00000000-0000-0000-0000-000000000001': ['ecommerce', 'crm', 'hrm', 'pos', 'auditor-portal', 'myinvoice'],
    '00000000-0000-0000-0000-000000000002': ['ecommerce', 'crm', 'hrm', 'pos', 'auditor-portal', 'myinvoice'],
  }

  const isFeatureEnabled = (featureKey?: string) => {
    if (!featureKey) return true

    // Fail-closed only while the FIRST fetch is genuinely in flight (no data
    // yet and no error). Once the fetch has ERRORED (e.g. a 429 rate-limit or
    // network blip), fail OPEN: show the module rather than blanking the whole
    // sidebar. This is safe because the sidebar is UX only — every API route
    // independently enforces entitlements via EnsureEntitlementAccess, so a
    // visible-but-unentitled module still cannot read or write data.
    if (!features) {
      return featuresError ? true : false
    }

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

  // RBAC: derive required permission from featureKey or explicit override
  const { hasPermission } = useAuthStore()

  const canAccessNavItem = (item: NavItem): boolean => {
    const permission = deriveNavPermission(item)
    if (!permission) return true
    return hasPermission(permission)
  }

  const filterNavItem = (item: NavItem): NavItem | null => {
    // Search-only entries are exposed to the omnibox, never the sidebar.
    if (item.searchOnly) return null
    if (isAcmeTenant && item.title === 'Orders' && item.href === '/sales/orders') return null
    if (!isFeatureEnabled(item.featureKey)) return null
    if (!canAccessNavItem(item)) return null

    if (!item.children) return item

    const children = item.children
      .map(filterNavItem)
      .filter((child): child is NavItem => child !== null)

    if (!item.featureKey && features && item.children.length > 0 && children.length === 0) {
      return null
    }

    return { ...item, children }
  }

  const navItems = baseNavItems
    .map(filterNavItem)
    .filter((item): item is NavItem => item !== null)

  const toggleExpand = (title: string) => {
    setExpandedItems(prev =>
      prev.includes(title)
        ? prev.filter(t => t !== title)
        : [...prev, title]
    )
  }

  const hasMoreSpecificRouteMatch = (items: NavItem[], href: string): boolean =>
    items.some(item => {
      const itemMatches = Boolean(
        item.href &&
        item.href.length > href.length &&
        (location.pathname === item.href || location.pathname.startsWith(item.href + '/'))
      )

      return itemMatches || Boolean(item.children && hasMoreSpecificRouteMatch(item.children, href))
    })

  const isItemActive = (item: NavItem): boolean => {
    if (item.href && location.pathname === item.href) return true
    if (item.children) {
      if (item.children.some(child => isItemActive(child))) return true
      // Keep a module parent active for nested routes not represented by a child item.
      return Boolean(
        item.href && item.href !== '/' && location.pathname.startsWith(item.href + '/')
      )
    }

    // A list route remains active on detail pages, unless a more specific menu
    // item owns that path (for example /products/report over /products).
    if (
      item.href &&
      item.href !== '/' &&
      location.pathname.startsWith(item.href + '/') &&
      !hasMoreSpecificRouteMatch(navItems, item.href)
    ) return true

    return false
  }

  // Auto-expand parent items (at any depth) when a descendant route is active
  const collectActiveParents = (items: NavItem[]): string[] => {
    const result: string[] = []
    for (const item of items) {
      if (item.children && item.children.length > 0 && isItemActive(item)) {
        result.push(item.title, ...collectActiveParents(item.children))
      }
    }
    return result
  }

  const activeParents = collectActiveParents(navItems)

  const effectiveExpanded = [...new Set([...expandedItems, ...activeParents])]

  const sidebarWidth = isMinimized ? 'w-16' : 'w-64'

  const renderNavItem = (item: NavItem, depth = 0) => {
    const isActive = isItemActive(item)
    const isExpanded = effectiveExpanded.includes(item.title)
    const hasChildren = item.children && item.children.length > 0

    if (hasChildren) {
      return (
        <div key={item.title} className="relative">
          <button
            onClick={() => {
              if (isMinimized) return
              toggleExpand(item.title)
            }}
            onMouseEnter={() => isMinimized && setHoveredItem(item.title)}
            onMouseLeave={() => isMinimized && setHoveredItem(null)}
            className={cn(
              'flex w-full items-center gap-3 rounded-lg text-sm font-medium transition-colors',
              isMinimized ? 'justify-center p-2' : 'px-3 py-2',
              isActive
                ? 'bg-primary/10 text-primary'
                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
            )}
          >
            <Icon icon={item.icon} className={depth === 0 ? 'h-5 w-5 shrink-0' : 'h-4 w-4 shrink-0'} />
            {!isMinimized && (
              <>
                <span className="flex-1 text-left">{item.title}</span>
                <Icon
                  icon={isExpanded ? "solar:alt-arrow-up-linear" : "solar:alt-arrow-down-linear"}
                  className="h-4 w-4"
                />
              </>
            )}
          </button>
          {/* Minimized tooltip */}
          {isMinimized && hoveredItem === item.title && (
            <div className="absolute left-full top-0 z-50 ml-2 rounded-lg border bg-popover px-3 py-2 text-sm font-medium text-popover-foreground shadow-md whitespace-nowrap">
              {item.title}
            </div>
          )}
          {/* Expanded children */}
          {!isMinimized && isExpanded && (
            <div className="ml-4 mt-1 space-y-1 border-l pl-4">
              {item.children!.map((child) => renderNavItem(child, depth + 1))}
            </div>
          )}
        </div>
      )
    }

    return (
      <Link
        key={item.title}
        to={item.href}
        onMouseEnter={() => isMinimized && setHoveredItem(item.title)}
        onMouseLeave={() => isMinimized && setHoveredItem(null)}
        className={cn(
          'flex items-center gap-3 rounded-lg text-sm transition-colors relative',
          depth === 0 ? 'font-medium' : '',
          isMinimized ? 'justify-center p-2' : 'px-3 py-2',
          isActive
            ? 'bg-primary text-primary-foreground'
            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
        )}
      >
        <Icon icon={item.icon} className={depth === 0 ? 'h-5 w-5 shrink-0' : 'h-4 w-4 shrink-0'} />
        {!isMinimized && <span>{item.title}</span>}
        {!isMinimized && item.badge && (
          <span className="ml-auto flex h-5 w-5 items-center justify-center rounded-full bg-destructive text-xs text-destructive-foreground">
            {item.badge}
          </span>
        )}
        {/* Minimized tooltip */}
        {isMinimized && hoveredItem === item.title && (
          <div className="absolute left-full top-0 z-50 ml-2 rounded-lg border bg-popover px-3 py-2 text-sm font-medium text-popover-foreground shadow-md whitespace-nowrap">
            {item.title}
          </div>
        )}
      </Link>
    )
  }

  return (
    <div className={cn('flex h-full flex-col border-r bg-card transition-all duration-300', sidebarWidth)}>
      {/* Logo + Minimize Toggle */}
      <div className={cn('flex h-16 items-center border-b', isMinimized ? 'justify-center px-2' : 'justify-between px-6')}>
        {!isMinimized && (
          <img
            src={NOOVAERP_LOGO_URL}
            alt="NoovaERP"
            className="h-9 w-auto max-w-full object-contain"
          />
        )}
        <button
          type="button"
          onClick={toggleMinimize}
          className={cn(
            'flex h-9 w-9 shrink-0 items-center justify-center',
            'rounded-md border border-border/60',
            'bg-background text-foreground',
            'shadow-sm transition-all hover:bg-accent hover:text-accent-foreground',
            'hover:shadow-md focus-visible:outline-2 focus-visible:outline-ring',
            'active:scale-95'
          )}
          aria-label={isMinimized ? 'Expand sidebar' : 'Minimize sidebar'}
          title={isMinimized ? 'Expand sidebar' : 'Minimize sidebar'}
        >
          <Icon
            icon={isMinimized ? 'solar:double-alt-arrow-right-bold-duotone' : 'solar:double-alt-arrow-left-bold-duotone'}
            className="h-[18px] w-[18px]"
          />
        </button>
      </div>

      {/* Navigation */}
      <nav className={cn('flex-1 space-y-1 overflow-y-auto', isMinimized ? 'p-2' : 'p-4')}>
        {navItems.map((item) => renderNavItem(item, 0))}
      </nav>

      {/* General settings — OWNER/ADMIN/SUPERADMIN only (settings.view). */}
      {hasPermission('settings.view') && (
      <div className={cn('border-t', isMinimized ? 'p-2' : 'p-3')}>
        <Link
          to="/settings"
          title="General Settings"
          className={cn(
            'group flex items-center gap-3 rounded-lg py-2 transition-colors',
            isMinimized ? 'justify-center px-0' : 'px-2',
            'hover:bg-accent hover:text-accent-foreground',
            location.pathname.startsWith('/settings') && 'bg-primary/10 text-primary',
          )}
        >
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 transition-colors group-hover:bg-primary group-hover:text-primary-foreground">
            <Icon icon="solar:settings-bold-duotone" className="h-5 w-5 text-primary transition-colors group-hover:text-primary-foreground" />
          </div>
          {!isMinimized && (
            <>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium leading-tight">General Settings</p>
                <p className="truncate text-xs leading-tight text-muted-foreground group-hover:text-accent-foreground">
                  NoovaERP Management
                </p>
              </div>
              <Icon
                icon="solar:alt-arrow-right-bold-duotone"
                className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
              />
            </>
          )}
        </Link>
      </div>
      )}
    </div>
  )
}
