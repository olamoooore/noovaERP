import { Link, useLocation } from 'react-router-dom'
import { Icon } from '@iconify/react'
import { cn } from '@/lib/utils'
import { NOOVAERP_LOGO_URL } from '@/lib/assets'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { useState } from 'react'
import { useAuthStore } from '@/store/authStore'

interface NavItem {
  title: string
  href: string
  icon: string
  badge?: number
  featureKey?: string
  requiredPermission?: string
  children?: NavItem[]
}

const baseNavItems: NavItem[] = [
  {
    title: 'Dashboard',
    href: '/',
    icon: 'solar:home-2-bold-duotone',
  },
  {
    title: 'Contacts',
    href: '/master-contacts',
    icon: 'solar:users-group-two-rounded-bold-duotone',
    featureKey: 'master-contacts',
    children: [
       {
        title: 'Dashboard',
        href: '/master-contacts',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Customers',
        href: '/customers',
        icon: 'solar:users-group-rounded-bold-duotone',
      },
      {
        title: 'Vendors',
        href: '/accounting/vendors',
        icon: 'solar:users-group-two-rounded-bold-duotone',
      },
      {
        title: 'Employee',
        href: '/hrm',
        icon: 'solar:users-group-two-rounded-bold-duotone',
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
    ],
  },
  {
    title: 'Products',
    href: '/products',
    icon: 'solar:box-bold-duotone',
    featureKey: 'inventory',
    children: [
       {
        title: 'Dashboard',
        href: '/products/dashboard',
        icon: 'solar:chart-square-bold-duotone',
      },
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
        title: 'Report',
        href: '/products/report',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Product Settings',
        href: '/products/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
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
        title: 'Lots & Serials',
        href: '/inventory/tracking',
        icon: 'solar:qr-code-bold-duotone',
      },
      // {
      //   title: 'Stock Movements',
      //   href: '/inventory/movements',
      //   icon: 'solar:transfer-horizontal-bold-duotone',
      // },
      // {
      //   title: 'Purchase Orders',
      //   href: '/inventory/purchase-orders',
      //   icon: 'solar:cart-large-4-bold-duotone',
      // },
      {
        title: 'Warehouse Transfers',
        href: '/inventory/transfers',
        icon: 'solar:round-transfer-horizontal-bold-duotone',
      },
      // {
      //   title: 'Suppliers',
      //   href: '/inventory/suppliers',
      //   icon: 'solar:users-group-two-rounded-bold-duotone',
      // },
      {
        title: 'Report',
        href: '/reports/inventory',
        icon: 'solar:box-bold-duotone',
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
      // {
      //   title: 'Orders',
      //   href: '/sales/orders',
      //   icon: 'solar:document-text-bold-duotone',
      // },
      {
        title: 'Quotations',
        href: '/quotations',
        icon: 'solar:file-text-bold-duotone',
      },
      {
        title: 'Delivery Orders',
        href: '/delivery-orders',
        icon: 'solar:delivery-bold-duotone',
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
      // {
      //   title: 'Payments',
      //   href: '/payments',
      //   icon: 'solar:wallet-money-bold-duotone',
      // },
      {
        title: 'Medical Cases',
        href: '/sales/medical-cases',
        icon: 'solar:medical-kit-bold-duotone',
      },
      {
        title: 'Bonus Schemes',
        href: '/sales/bonus-schemes',
        icon: 'solar:gift-bold-duotone',
      },
      {
        title: 'Scheme Assignments',
        href: '/sales/customer-bonus-schemes',
        icon: 'solar:users-group-rounded-bold-duotone',
      },
        {
        title: 'Report',
        href: '/reports/sales-analytics',
        icon: 'solar:chart-2-bold-duotone',
      },
      {
        title: 'Settings',
        href: '/sales/settings',
        icon: 'solar:settings-bold-duotone',
      },
    ],
  },
  {
    title: 'CRM',
    href: '/crm',
    icon: 'solar:users-group-two-rounded-bold-duotone',
    featureKey: 'crm',
    children: [
      {
        title: 'Dashboard',
        href: '/crm',
        icon: 'solar:chart-bold-duotone',
      },
      {
        title: 'Leads',
        href: '/crm/leads',
        icon: 'solar:user-plus-bold-duotone',
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
        title: 'Dashboard',
        href: '/accounting/dashboard',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Customers',
        href: '/customers',
        icon: 'solar:users-group-rounded-bold-duotone',
        featureKey: 'customers',
    children: [

      {
        title: 'Customers',
        href: '/customers',
        icon: 'solar:users-group-rounded-bold-duotone',
      },

      {
        title: 'Customer Invoices',
        href: '/accounting/customers/bills',
        icon: 'solar:bill-list-bold-duotone',
      },
      {
        title: 'Credit Notes',
        href: '/accounting/customers/credit-notes',
        icon: 'solar:wallet-money-bold-duotone',
      },
      {
        title: 'Payments',
        href: '/accounting/customers/payments',
        icon: 'solar:wallet-money-bold-duotone',
      },
      // {
      //   title: 'Batch Payments',
      //   href: '/accounting/customers/batch-payments',
      //   icon: 'solar:wallet-money-bold-duotone',
      // },


    ],
      },
      
      {
        title: 'Vendors',
        href: '/accounting/vendors',
        icon: 'solar:users-group-two-rounded-bold-duotone',
         featureKey: 'vendors',
    children: [
       

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
        title: 'Debit Notes',
        href: '/accounting/vendors/debit-notes',
        icon: 'solar:wallet-money-bold-duotone',
      },
      {
        title: 'Payments',
        href: '/accounting/vendors/payments',
        icon: 'solar:wallet-money-bold-duotone',
      },
      // {
      //   title: 'Batch Payments',
      //   href: '/accounting/vendors/batch-payments',
      //   icon: 'solar:wallet-money-bold-duotone',
      // },



    ],
      },

      {
    title: 'Accounting',
    href: '',
    icon: 'solar:chart-2-bold-duotone',
    featureKey: 'accounting-data',
    children: [
         {
    title: 'Transactions',
    href: '',
    icon: 'solar:wallet-2-bold-duotone',
    featureKey: 'transactions',
    children: [
{
        title: 'Journal Entries',
        href: '/accounting/core/journal-entries',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Journals',
        href: '/accounting/core/journals',
        icon: 'solar:notebook-bold-duotone',
      },

      
    ],
      },
            {
    title: 'Assets & Liabilities',
    href: '',
    icon: 'solar:buildings-2-bold-duotone',
    featureKey: 'assets-liabilities',
    children: [
 {
        title: 'Fixed Assets',
        href: '/accounting/core/assets',
        icon: 'solar:buildings-bold-duotone',
      },
      // {
      //   title: 'Loans',
      //   href: '/accounting/core/loans',
      //   icon: 'solar:document-text-bold-duotone',
      // },
      {
        title: 'Bank Accounts',
        href: '/accounting/configuration/bank-accounts',
        icon: 'solar:card-bold-duotone',
      },
      {
        title: 'Findings',
        href: '/accounting/configuration/findings',
        icon: 'solar:flag-bold-duotone',
      },
      {
        title: 'Settlements',
        href: '/accounting/settlements',
        icon: 'solar:wallet-bold-duotone',
      },

      
    ],
      },
                 {
    title: 'Closing',
    href: '',
    icon: 'solar:calendar-mark-bold-duotone',
    featureKey: 'closing',
    children: [
{
        title: 'Reconcile',
        href: '/accounting/core/reconciliation',
        icon: 'solar:document-text-bold-duotone',
      },
      // {
      //   title: 'Tax Returns',
      //   href: '/accounting/core/tax-returns',
      //   icon: 'solar:document-text-bold-duotone',
      // },
      {
        title: 'Lock Dates',
        href: '/accounting/core/lock-dates',
        icon: 'solar:document-text-bold-duotone',
      },

      
    ],
      },
//     {
//     title: 'Review',
//     href: '',
//     icon: 'solar:checklist-minimalistic-bold-duotone',
//     featureKey: 'review',
//     children: [
// {
//         title: 'Journal Items',
//         href: '/accounting/core/journal-entries',
//         icon: 'solar:document-text-bold-duotone',
//       },
//       {
//         title: 'Journal Audit',
//         href: '/accounting/core/journal-entries',
//         icon: 'solar:document-text-bold-duotone',
//       },

      
//     ],
//       },
    {
    title: 'AI Suites',
    href: '',
    icon: 'solar:magic-stick-3-bold-duotone',
    featureKey: 'ai-suites',
    children: [
 {
        title: 'Financial Close',
        href: '/financial-close',
        icon: 'solar:calendar-mark-bold-duotone',
      
      },
      {
        title: 'OCR Bookkeeping',
        href: '/ocr/documents',
        icon: 'solar:document-add-bold-duotone',
        featureKey: 'ocr-bookkeeping',
      },

      
    ],
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
        title: 'Trial Balance',
        href: '/accounting/reporting/trial-balance',
        icon: 'solar:scale-bold-duotone',
      },
      {
        title: 'Balance Sheet',
        href: '/accounting/reporting/balance-sheet',
        icon: 'solar:chart-bold-duotone',
      },
      {
        title: 'Financial Statement',
        href: '/accounting/reporting/financial-statement',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Profit & Loss',
        href: '/accounting/reporting/profit-loss',
        icon: 'solar:chart-square-bold-duotone',
      },
      {
        title: 'Expense Analytics',
        href: '/reports/expense-analytics',
        icon: 'solar:wallet-money-bold-duotone',
      },
      {
        title: 'Customer Account Statement',
        href: '/accounting/reporting/customer-statements',
        icon: 'solar:document-text-bold-duotone',
      },
      {
        title: 'Compliance Reports',
        href: '/accounting/reporting/compliance',
        icon: 'solar:shield-check-bold-duotone',
      },
    ],
  },
     
      
      {
        title: 'MyInvois E-Invoice',
        href: '/accounting/reporting/myinvoice',
        icon: 'solar:document-text-bold-duotone',
        featureKey: 'myinvoice',
      },
     
      {
        title: 'Settings',
        href: '/accounting/configuration/company',
        icon: 'solar:settings-bold-duotone',
       
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
        title: 'Auditor Portal',
        href: '/auditor',
        icon: 'solar:shield-check-bold-duotone',
        featureKey: 'auditor-portal',
        children: [
          {
            title: 'Dashboard',
            href: '/auditor/dashboard',
            icon: 'solar:chart-square-bold-duotone',
          },
          {
            title: 'Manage Auditors',
            href: '/auditor/manage',
            icon: 'solar:users-group-two-rounded-bold-duotone',
          },
          {
            title: 'Queries',
            href: '/auditor/queries',
            icon: 'solar:message-circle-bold-duotone',
          },
          {
            title: 'Exports',
            href: '/auditor/exports',
            icon: 'solar:download-bold-duotone',
          },
          {
            title: 'Activity Log',
            href: '/auditor/activity',
            icon: 'solar:clock-circle-bold-duotone',
          },
        ],
      },
    ]

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
    retry: (failureCount, error: any) => {
      const status = error?.response?.status
      // Retry transient throttles / server errors with backoff; give up on 4xx
      // client errors (except 429) which won't fix themselves.
      if (status === 429 || (status >= 500 && status < 600)) return failureCount < 3
      if (status >= 400 && status < 500) return false
      return failureCount < 3
    },
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 15000),
    staleTime: 1000 * 60 * 5,
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

  const derivePermission = (item: NavItem): string | undefined => {
    if (item.requiredPermission) return item.requiredPermission
    if (!item.featureKey) return undefined

    // Map feature keys to view permissions
    const permissionMap: Record<string, string> = {
      pos: 'pos.view',
      inventory: 'inventory.view',
      sales: 'sales.view',
      accounting: 'accounting.view',
      loyalty: 'loyalty.view',
      'event-management': 'event_management.view',
      'website-builder': 'settings.view',
      ecommerce: 'sales.view',
      iot: 'iot.view',
      ai: 'ai.view',
      'field-service': 'field_service.view',
      hrm: 'hrm.view',
      'financial-reporting': 'reports.view',
    }

    const base = permissionMap[item.featureKey]
    if (!base) return undefined

    // Settings pages within modules require *.settings permission
    if (item.href.endsWith('/settings') || item.title.toLowerCase().includes('settings')) {
      const module = base.replace('.view', '')
      return `${module}.settings`
    }

    return base
  }

  const canAccessNavItem = (item: NavItem): boolean => {
    const permission = derivePermission(item)
    if (!permission) return true
    return hasPermission(permission)
  }

  const filterNavItem = (item: NavItem): NavItem | null => {
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
