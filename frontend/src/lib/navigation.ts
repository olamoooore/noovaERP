export interface NavItem {
  title: string
  href: string
  icon: string
  badge?: number
  featureKey?: string
  requiredPermission?: string
  keywords?: string[]
  /** Searchable in the global omnibox but not rendered in the sidebar (e.g. tab pages). */
  searchOnly?: boolean
  children?: NavItem[]
}

export const baseNavItems: NavItem[] = [
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
        requiredPermission: 'reports.view',
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
      {
        title: 'Traceability',
        href: '/reports/traceability',
        icon: 'solar:routing-2-bold-duotone',
        requiredPermission: 'reports.view',
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
        requiredPermission: 'reports.view',
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
        title: 'Price Lists',
        href: '/sales/price-lists',
        icon: 'solar:tag-price-bold-duotone',
        requiredPermission: 'pricelists.view',
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
        requiredPermission: 'reports.view',
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
      {
        // Statement of account belongs with the customer documents it covers,
        // not under Reports.
        title: 'Account Statement',
        href: '/accounting/reporting/customer-statements',
        icon: 'solar:document-text-bold-duotone',
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
        keywords: ['journal entry', 'je', 'gl entry'],
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
    title: 'Tax',
    href: '',
    icon: 'solar:calculator-bold-duotone',
    children: [
      {
        title: 'Tax Engine',
        href: '/accounting/tax-engine',
        icon: 'solar:shield-check-bold-duotone',
        keywords: ['tax engine', 'gst', 'sst', 'vat', 'tax rules', 'tax rate', 'lhdn', 'iras'],
      },
      {
        title: 'Tax Returns',
        href: '/accounting/tax-returns',
        icon: 'solar:document-text-bold-duotone',
        keywords: ['gst f5', 'f7', 'sst-02', 'statutory return', 'file tax'],
      },
      {
        title: 'Tax Settings (legacy)',
        href: '/accounting/configuration/taxes',
        icon: 'solar:settings-bold-duotone',
      },
      {
        title: 'Tax Report',
        href: '/accounting/tax-report',
        icon: 'solar:chart-square-bold-duotone',
        keywords: ['tax journal', 'reconciliation', 'filing tracker', 'gl variance'],
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
      {
        title: 'AI Tax Advisory',
        href: '/ai/tax-advisory',
        icon: 'solar:magic-stick-3-bold-duotone',
        keywords: ['tax ai', 'gst advice', 'sst advice', 'tax advisory'],
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
        keywords: ['tb'],
      },
      {
        title: 'Balance Sheet',
        href: '/accounting/reporting/balance-sheet',
        icon: 'solar:chart-bold-duotone',
        keywords: ['bs'],
      },
      {
        title: 'Profit & Loss',
        href: '/accounting/reporting/profit-loss',
        icon: 'solar:chart-square-bold-duotone',
        keywords: ['p&l', 'pnl', 'income statement'],
      },
      {
        title: 'FX Report',
        href: '/accounting/reporting/fx',
        icon: 'solar:refresh-circle-bold-duotone',
        requiredPermission: 'accounting.view',
        keywords: ['fx', 'exchange rate', 'currency', 'gain', 'loss', 'exposure'],
      },
      {
        title: 'Expense Analytics',
        href: '/reports/expense-analytics',
        icon: 'solar:wallet-money-bold-duotone',
        requiredPermission: 'reports.view',
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
        keywords: ['company settings', 'fiscal year'],
      },
      // Chart of Accounts lives as a tab of Company Settings; expose it to the
      // omnibox as a search-only entry so it deep-links to the tab directly.
      {
        title: 'Chart of Accounts',
        href: '/accounting/configuration/company?tab=chart-of-accounts',
        icon: 'solar:list-check-bold-duotone',
        keywords: ['coa', 'gl', 'general ledger', 'accounts'],
        requiredPermission: 'accounting.view',
        searchOnly: true,
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
      // Storefront (generic commerce, MRD ecommerce-Enterprice) lives UNDER
      // the Website module — the SHOP itself is published Website Builder
      // pages composed from the Store blocks; these are the merchant-facing
      // admin surfaces.
      {
        title: 'Storefront',
        href: '/storefront/orders',
        icon: 'solar:bag-4-bold-duotone',
        featureKey: 'storefront',
        children: [
          {
            title: 'Orders',
            href: '/storefront/orders',
            icon: 'solar:receipt-bold-duotone',
          },
          {
            title: 'Returns',
            href: '/storefront/returns',
            icon: 'solar:box-bold-duotone',
          },
          {
            title: 'Promotions',
            href: '/storefront/promotions',
            icon: 'solar:ticket-sale-bold-duotone',
          },
          {
            title: 'Reports',
            href: '/storefront/reports',
            icon: 'solar:chart-2-bold-duotone',
          },
          {
            title: 'Settings',
            href: '/storefront/settings',
            icon: 'solar:settings-bold-duotone',
          },
        ],
      },
      // FR-ARC-02 (legacy freeze): the 'Online Shop' (/store) entry was
      // removed from navigation — the legacy ecommerce stack is archived and
      // must not be discoverable. Do not re-add.
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

export function deriveNavPermission(item: NavItem): string | undefined {
  if (item.requiredPermission) return item.requiredPermission
  if (!item.featureKey) return undefined

  // Map feature keys to view permissions
  const permissionMap: Record<string, string> = {
    pos: 'pos.view',
    inventory: 'inventory.view',
    sales: 'sales.view',
    accounting: 'accounting.view',
    loyalty: 'loyalty.view',
    storefront: 'storefront.view',
    'event-management': 'event_management.view',
    'website-builder': 'settings.view',
    // 'ecommerce' intentionally REMOVED (FR-ARC-02): legacy commerce nav is
    // archived; no nav item may carry its featureKey. Do not re-add.
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

export interface FlatNavItem extends NavItem {
  parentTitle?: string
  parentTitles: string[]
}

export function flattenNav(items: NavItem[]): FlatNavItem[] {
  const flat: FlatNavItem[] = []
  const seen = new Set<string>()

  const walk = (list: NavItem[], ancestors: string[], inheritedPermission?: string) => {
    for (const item of list) {
      const key = item.href || `${ancestors.join('>')}>${item.title}`
      const permission = deriveNavPermission(item) ?? inheritedPermission

      if (!seen.has(key)) {
        seen.add(key)
        flat.push({
          ...item,
          parentTitle: ancestors[ancestors.length - 1],
          parentTitles: [...ancestors],
          ...(item.requiredPermission || !permission ? {} : { requiredPermission: permission }),
        })
      }

      if (item.children?.length) {
        walk(item.children, [...ancestors, item.title], permission)
      }
    }
  }

  walk(items, [])

  return flat
}
