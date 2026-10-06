import { useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { format } from 'date-fns'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { StatCard } from '@/components/ui/stat-card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Badge } from '@/components/ui/badge'
import { Textarea } from '@/components/ui/textarea'
import { Separator } from '@/components/ui/separator'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Progress } from '@/components/ui/progress'
import { useToast } from '@/hooks/use-toast'
import { formatCurrency, cn, getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import { PageHeader } from '@/components/layout/PageHeader'
import { TagEditor } from '@/components/tags/TagEditor'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { AuditTab } from '@/components/audit/AuditTab'
import { CustomerForm } from '@/components/customers/CustomerForm'
import { CustomerContactDialog } from '@/components/customers/CustomerContactDialog'
import { siteLabel, type SiteAddress } from '@/lib/customerContacts'
import { customerFormToUpdatePayload, customerToFormValues, emptyCustomerFormValues, type CustomerFormValues } from '@/lib/customerPayload'
import { downloadReportDocx, downloadReportXlsx } from '@/lib/reportFiles'


const formatLegacyAddress = (address: string) => {
  if (!address) return null
  try {
    // If it looks like JSON starts with {
    if (address.trim().startsWith('{')) {
      const parsed = JSON.parse(address)
      const parts = []
      
      // Add name if present
      if (parsed.first_name || parsed.last_name) {
        parts.push(`${parsed.first_name || ''} ${parsed.last_name || ''}`.trim())
      }
      
      // Add address lines
      if (parsed.address || parsed.street) parts.push(parsed.address || parsed.street)
      if (parsed.street2) parts.push(parsed.street2)
      if (parsed.city) parts.push(parsed.city)
      if (parsed.state) parts.push(parsed.state)
      if (parsed.postal_code || parsed.zip) parts.push(parsed.postal_code || parsed.zip)
      if (parsed.country) parts.push(parsed.country)
      
      if (parts.length > 0) return parts.join('\n')
      
      // Fallback: join values
      return Object.values(parsed).filter(v => typeof v === 'string').join('\n')
    }
  } catch (e) {
    // Ignore error, treat as string
  }
  return address
}

/**
 * Flatten a customer-statement payload into the report-table grid used by the
 * Excel/Word exporters: header row, ledger lines, totals, then the aging block.
 */
const statementExportRows = (payload: any): string[][] => {
  const rows: string[][] = []
  const txRows = payload?.transactions ?? []

  rows.push(['Date', 'Type', 'Document', 'Description', 'Reference', 'Due Date', 'Amount', 'Debit', 'Credit', 'Outstanding', 'Balance'])
  txRows.forEach((tx: any) => {
    rows.push([
      tx.date ?? '',
      tx.type ?? '',
      tx.document_number ?? '',
      tx.description ?? '',
      tx.reference ?? '',
      tx.due_date ?? '',
      tx.inv_amount ? formatCurrency(tx.inv_amount) : '',
      tx.debit ? formatCurrency(tx.debit) : '',
      tx.credit ? formatCurrency(tx.credit) : '',
      tx.outstanding !== null && tx.outstanding !== undefined ? formatCurrency(tx.outstanding) : '',
      tx.balance !== null && tx.balance !== undefined ? formatCurrency(tx.balance) : '',
    ])
  })
  rows.push([
    '', '', '', '', '', '',
    '',
    formatCurrency(payload?.total_debits || 0),
    formatCurrency(payload?.total_credits || 0),
    'Closing Balance',
    formatCurrency(payload?.closing_balance || 0),
  ])

  const aging = payload?.aging ?? {}
  rows.push([])
  rows.push(['Aging', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total'])
  rows.push([
    'Outstanding',
    formatCurrency(aging.current || 0),
    formatCurrency(aging.days_1_30 || 0),
    formatCurrency(aging.days_31_60 || 0),
    formatCurrency(aging.days_61_90 || 0),
    formatCurrency(aging.days_90_plus || 0),
    formatCurrency(aging.total || 0),
  ])
  return rows
}

interface Customer {
  id: number
  core_contact_uuid?: string | null
  code: string
  type: 'individual' | 'company'
  name: string
  display_name: string
  email: string
  phone: string
  mobile: string
  company: string
  tax_number: string
  website: string
  billing_address: string
  shipping_address: string
  credit_limit: number
  payment_terms: number
  total_outstanding: number
  total_purchases: number
  total_orders: number
  average_order_value: number
  loyalty_points: number
  is_active: boolean
  marketing_opt_in: boolean
  preferred_contact: string
  customer_since: string
  last_interaction_at: string
  notes: string
  date_of_birth: string
  gender: string
  categories: { id: number; name: string; color: string }[]
  salesperson?: { id: number; name: string }
  price_list?: { id: number; name: string }
  addresses: CustomerAddress[]
  customer_contacts: CustomerContact[]
  loyalty_balances: LoyaltyBalance[]
  invoices: Invoice[]
  pos_orders: PosOrder[]
  web_orders: WebOrder[]
  webOrders?: WebOrder[]
}

interface CustomerAddress {  id: number
  type: 'billing' | 'shipping' | 'other'
  label: string
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
  is_default: boolean
}

interface CustomerContact {
  id: number
  core_contact_uuid?: string | null
  name: string
  job_title: string
  contact_category: string
  contact_type: string
  email: string
  phone: string
  mobile: string
  street: string
  street2: string
  city: string
  state: string
  zip: string
  country: string
  is_primary: boolean
  notes: string
}

interface LoyaltyBalance {
  id: number
  points_balance: number
  points_earned_total: number
  points_redeemed_total: number
  program: { id: number; name: string }
}

interface Invoice {
  id: number
  invoice_number: string
  status: string
  total: number
  balance: number
  invoice_date: string
  due_date: string
}

interface PosOrderItem {
  id: number
  name: string
  product_name?: string
  product_sku?: string
  sku: string
  quantity: number
  unit_price: number
  tax_rate: number
  tax_amount: number
  line_total: number
  product?: { id: number; name: string }
  variant?: { id: number; name: string }
}

interface PosOrder {
  id: number
  order_number: string
  status: string
  subtotal: number
  discount_amount: number
  tax_amount: number
  total: number
  amount_paid: number
  payment_method: string
  notes: string
  paid_at: string
  created_at: string
  items?: PosOrderItem[]
}

interface WebOrderItem {
    id: number
    product_name: string
    product_sku: string
    quantity: number
    unit_price: number
    line_total: number
}

interface WebOrder {
  id: number
  order_number: string
  status: string
  total: number
  created_at: string
  payment_status: string
  items?: WebOrderItem[]
}

interface LoyaltyTransaction {
  id: number
  type: 'earn' | 'redeem' | 'expire' | 'adjust'
  points: number
  balance_after: number
  reference_type: string
  notes: string
  created_at: string
}

export function CustomerDetailsPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  
  const [activeTab, setActiveTab] = useState('overview')
  const [addressDialogOpen, setAddressDialogOpen] = useState(false)
  const [editingAddressId, setEditingAddressId] = useState<number | null>(null)
  const [contactDialogOpen, setContactDialogOpen] = useState(false)
  const [editingContact, setEditingContact] = useState<CustomerContact | null>(null)
  const [loyaltyDialogOpen, setLoyaltyDialogOpen] = useState(false)
  const [noteDialogOpen, setNoteDialogOpen] = useState(false)
  const [editDialogOpen, setEditDialogOpen] = useState(false)
  const [emailDialogOpen, setEmailDialogOpen] = useState(false)
  const [orderDialogOpen, setOrderDialogOpen] = useState(false)
  const [selectedOrder, setSelectedOrder] = useState<PosOrder | null>(null)
  
  // Form states
  const [addressForm, setAddressForm] = useState<Partial<CustomerAddress>>({})
  const [loyaltyAdjustment, setLoyaltyAdjustment] = useState({ points: 0, notes: '' })
  const [customerNote, setCustomerNote] = useState('')
  const [editForm, setEditForm] = useState<CustomerFormValues>(emptyCustomerFormValues())
  const [emailForm, setEmailForm] = useState({ subject: '', body: '' })
  const [statementAsOf, setStatementAsOf] = useState(format(new Date(), 'yyyy-MM-dd'))
  const [statementDialogOpen, setStatementDialogOpen] = useState(false)
  const [statementBusy, setStatementBusy] = useState<'pdf' | 'xlsx' | 'docx' | null>(null)

  // Fetch customer details
  const { data: customer, isLoading } = useQuery<Customer>({
    queryKey: ['customer', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch loyalty history
  const { data: loyaltyData } = useQuery({
    queryKey: ['customer-loyalty', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/loyalty`)
      return response.data
    },
    enabled: !!id && activeTab === 'loyalty',
  })

  // Fetch activity timeline
  const { data: activityData } = useQuery({
    queryKey: ['customer-activity', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/activity?limit=50`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch customer statistics (live POS + invoice aggregates)
  const { data: statsData } = useQuery({
    queryKey: ['customer-statistics', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/statistics`)
      return response.data
    },
    enabled: !!id,
  })

  // Fetch AR aging data for this customer (live receivables)
  const { data: agingData } = useQuery({
    queryKey: ['customer-aging', id],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/aging`)
      return response.data
    },
    enabled: !!id,
  })

  // Full invoice list for the Invoices tab (paginated, not the 10-row preview)
  const [invoicePage, setInvoicePage] = useState(1)
  const { data: invoicesData, isLoading: invoicesLoading } = useQuery({
    queryKey: ['customer-invoices', id, invoicePage],
    queryFn: async () => {
      const response = await api.get(`/customers/${id}/invoices?per_page=25&page=${invoicePage}`)
      return response.data
    },
    enabled: !!id && activeTab === 'invoices',
  })

  // Update customer mutation
  const updateMutation = useMutation({
    mutationFn: async (data: CustomerFormValues | { is_active: boolean }) => {
      const payload = 'type' in data ? customerFormToUpdatePayload(data) : data
      const response = await api.put(`/customers/${id}`, payload)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      toast({ title: 'Customer updated successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update customer',
        description: getApiErrorMessage(error),
      })
    },
  })

  // Add address mutation
  const addAddressMutation = useMutation({
    mutationFn: async (data: Partial<CustomerAddress>) => {
      const response = await api.post(`/customers/${id}/addresses`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setAddressDialogOpen(false)
      setAddressForm({})
      toast({ title: 'Address added successfully' })
    },
  })

  // Delete address mutation
  const deleteAddressMutation = useMutation({
    mutationFn: async (addressId: number) => {
      await api.delete(`/customers/${id}/addresses/${addressId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      toast({ title: 'Address deleted successfully' })
    },
  })

  const updateAddressMutation = useMutation({
    mutationFn: async ({ addressId, data }: { addressId: number; data: Partial<CustomerAddress> }) => {
      const response = await api.put(`/customers/${id}/addresses/${addressId}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setAddressDialogOpen(false)
      setEditingAddressId(null)
      setAddressForm({})
      toast({ title: 'Address updated successfully' })
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Failed to update address',
        description: getApiErrorMessage(error),
      })
    },
  })

  // ADR-003B: each person's company Site, for the contact cards.
  const organisationId = customer?.core_contact_uuid
  const { data: personSites = {} } = useQuery<Record<string, string>>({
    queryKey: ['customer-contact-sites', organisationId],
    queryFn: async () => {
      const [relationships, addresses] = await Promise.all([
        api.get(`/contacts/${organisationId}/relationships`),
        api.get(`/contacts/${organisationId}/addresses`),
      ])
      const sites = new Map<string, SiteAddress>(
        ((addresses.data?.data ?? []) as SiteAddress[])
          .filter((address) => address.address_type === 'site')
          .map((site) => [site.id, site]),
      )
      const byPerson: Record<string, string> = {}
      for (const row of (relationships.data?.data ?? []) as { relationship_type: string; related_contact_id: string; site_address_id?: string | null }[]) {
        const site = row.relationship_type === 'org_person' && row.site_address_id ? sites.get(row.site_address_id) : undefined
        if (site) byPerson[row.related_contact_id] = siteLabel(site)
      }
      return byPerson
    },
    enabled: Boolean(organisationId),
  })

  // Delete contact mutation
  const deleteContactMutation = useMutation({
    mutationFn: async (contactId: number) => {
      await api.delete(`/customers/${id}/contacts/${contactId}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      toast({ title: 'Contact deleted successfully' })
    },
  })

  // Recalculate stats mutation
  const recalculateStatsMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/customers/${id}/recalculate-stats`)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      queryClient.invalidateQueries({ queryKey: ['customer-statistics', id] })
      toast({ title: 'Customer statistics recalculated' })
    },
    onError: () => {
      toast({ variant: 'destructive', title: 'Failed to recalculate stats' })
    },
  })

  // Toggle customer active status
  const handleToggleActive = () => {
    updateMutation.mutate({ is_active: !customer?.is_active })
  }

  // Adjust loyalty mutation
  const adjustLoyaltyMutation = useMutation({
    mutationFn: async (data: { points: number; notes: string }) => {
      const response = await api.post(`/customers/${id}/loyalty/adjust`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      queryClient.invalidateQueries({ queryKey: ['customer-loyalty', id] })
      setLoyaltyDialogOpen(false)
      setLoyaltyAdjustment({ points: 0, notes: '' })
      toast({ title: 'Loyalty points adjusted successfully' })
    },
  })

  // Update notes mutation
  const updateNotesMutation = useMutation({
    mutationFn: async (notes: string) => {
      const response = await api.put(`/customers/${id}`, { notes })
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customer', id] })
      setNoteDialogOpen(false)
      toast({ title: 'Notes updated successfully' })
    },
  })

  // Send email mutation
  const sendEmailMutation = useMutation({
    mutationFn: async (data: { subject: string; body: string }) => {
      const response = await api.post(`/customers/${id}/send-email`, data)
      return response.data
    },
    onSuccess: () => {
      setEmailDialogOpen(false)
      setEmailForm({ subject: '', body: '' })
      toast({ title: 'Email sent successfully' })
    },
    onError: (error: any) => {
      toast({ 
        variant: 'destructive', 
        title: 'Failed to send email',
        description: error.response?.data?.message || 'Please try again later'
      })
    },
  })

  // Open edit dialog with current values
  const openEditDialog = () => {
    if (customer) {
      setEditForm(customerToFormValues(customer))
      setEditDialogOpen(true)
    }
  }

  // Handle edit form submit
  const handleEditSubmit = () => {
    updateMutation.mutate(editForm, {
      onSuccess: () => {
        setEditDialogOpen(false)
      }
    })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary"></div>
      </div>
    )
  }

  if (!customer) {
    return (
      <div className="flex flex-col items-center justify-center h-screen gap-4">
        <Icon icon="solar:user-cross-bold" className="h-16 w-16 text-muted-foreground" />
        <h2 className="text-xl font-semibold">Customer not found</h2>
        <Button onClick={() => navigate('/customers')}>Back to Customers</Button>
      </div>
    )
  }

  const getInitials = (name: string) => {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
  }

  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      draft: 'bg-gray-500',
      pending: 'bg-yellow-500',
      paid: 'bg-green-500',
      sent: 'bg-blue-500',
      overdue: 'bg-red-500',
      cancelled: 'bg-gray-400',
    }
    return colors[status] || 'bg-gray-500'
  }

  // Live figures: prefer the freshly aggregated endpoints over the stored
  // customer columns, which only move when a POS payment or manual recalc
  // touches them. Outstanding comes from the live AR aging endpoint so it
  // always matches the Receivables report.
  const asNumber = (value: unknown) => Number(value) || 0
  const statsPos = statsData?.pos_orders
  const statsInv = statsData?.invoices
  const liveTotalOrders = statsData
    ? asNumber(statsPos?.total_orders) + asNumber(statsInv?.total_invoices)
    : customer.total_orders || 0
  const liveTotalPurchases = statsData
    ? asNumber(statsPos?.total_revenue) + asNumber(statsInv?.total_invoiced)
    : customer.total_purchases || 0
  const liveAvgOrderValue = liveTotalOrders > 0
    ? liveTotalPurchases / liveTotalOrders
    : customer.average_order_value || 0
  const liveOutstanding = agingData?.total_outstanding ?? customer.total_outstanding ?? 0
  const lastActivityAt = statsData?.last_interaction || customer.last_interaction_at

  const creditUsedPercent = customer.credit_limit > 0
    ? (liveOutstanding / customer.credit_limit) * 100
    : 0

  const downloadStatement = async (exportFormat: 'pdf' | 'xlsx' | 'docx') => {
    setStatementBusy(exportFormat)
    try {
      const customerCode = customer?.code || 'UNKNOWN'
      const dateStr = statementAsOf.replace(/-/g, '')

      if (exportFormat === 'pdf') {
        const response = await api.get(`/accounting/customers/${id}/statement`, {
          params: { as_of: statementAsOf },
          responseType: 'blob',
        })
        const blob = new Blob([response.data], { type: 'application/pdf' })
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `SOA_${customerCode}_${dateStr}.pdf`
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        window.URL.revokeObjectURL(url)
      } else {
        const response = await api.get(`/accounting/customers/${id}/statement`, {
          params: { as_of: statementAsOf, format: 'json' },
        })
        const payload = response.data?.payload
        if (!payload) throw new Error('Statement data unavailable')
        const rows = statementExportRows(payload)
        const options = {
          filename: `SOA_${customerCode}_${dateStr}`,
          title: 'Statement of Account',
          subtitle: `${payload.customer?.name || customer?.name || ''} · As of ${format(new Date(statementAsOf), 'MMM d, yyyy')}`,
          companyName: payload.company?.name,
        }
        if (exportFormat === 'xlsx') {
          await downloadReportXlsx(rows, options)
        } else {
          await downloadReportDocx(rows, options)
        }
      }

      setStatementDialogOpen(false)
      toast({ title: `Statement downloaded (${exportFormat.toUpperCase()})` })
    } catch (error) {
      toast({
        variant: 'destructive',
        title: 'Failed to generate statement',
        description: getApiErrorMessage(error),
      })
    } finally {
      setStatementBusy(null)
    }
  }

  return (
    <div className="space-y-6 pb-10">
      {/* Header */}
      <PageHeader
        title={customer.display_name || customer.name}
        onBack={() => navigate('/customers')}
        actions={<>
          <HeaderActionButton
            variant="outline"
            icon="solar:refresh-bold-duotone"
            title="Recalculate statistics"
            loading={recalculateStatsMutation.isPending}
            onClick={() => recalculateStatsMutation.mutate()}
            disabled={recalculateStatsMutation.isPending}
          >
            Recalculate
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon={customer.is_active ? 'solar:pause-circle-bold-duotone' : 'solar:play-circle-bold-duotone'}
            loading={updateMutation.isPending}
            onClick={handleToggleActive}
            disabled={updateMutation.isPending}
          >
            {customer.is_active ? 'Deactivate' : 'Activate'}
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon="solar:notes-bold-duotone"
            onClick={() => setNoteDialogOpen(true)}
          >
            Notes
          </HeaderActionButton>
          <HeaderActionButton
            variant="outline"
            icon="solar:letter-bold-duotone"
            onClick={() => setEmailDialogOpen(true)}
            disabled={!customer.email}
            title={!customer.email ? 'No email address' : 'Send email'}
          >
            Email
          </HeaderActionButton>
          <HeaderActionButton icon="solar:pen-bold-duotone" onClick={openEditDialog}>
            Edit
          </HeaderActionButton>
        </>}
      >
        <div className="mt-3 flex items-start gap-4">
          <Avatar className="h-14 w-14 shrink-0">
            <AvatarFallback className="bg-primary text-lg text-primary-foreground">
              {getInitials(customer.display_name || customer.name)}
            </AvatarFallback>
          </Avatar>
          <div className="min-w-0 flex-1 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className="gap-1 font-mono text-[11px]">
                <Icon icon="solar:tag-horizontal-bold-duotone" className="h-3 w-3" />
                {customer.code}
              </Badge>
              <Badge variant="outline" className="gap-1 text-[11px]">
                <Icon
                  icon={customer.type === 'company' ? 'solar:buildings-2-bold-duotone' : 'solar:user-bold-duotone'}
                  className="h-3 w-3"
                />
                {customer.type === 'company' ? 'Company' : 'Individual'}
              </Badge>
              {customer.is_active ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-300">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                  Active
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
                  <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/60" />
                  Inactive
                </span>
              )}
              {customer.customer_since && (
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Icon icon="solar:calendar-bold-duotone" className="h-3.5 w-3.5" />
                  Customer since {format(new Date(customer.customer_since), 'MMM yyyy')}
                </span>
              )}
            </div>

            {customer.categories?.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5">
                {customer.categories.map((cat) => (
                  <span
                    key={cat.id}
                    className="rounded-full border px-2 py-0.5 text-[10px] font-medium"
                    style={{ borderColor: cat.color, color: cat.color }}
                  >
                    {cat.name}
                  </span>
                ))}
              </div>
            )}
            <TagEditor subjectType="customer" subjectId={id!} />
          </div>
        </div>
      </PageHeader>

      {/* Quick Stats */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <StatCard
          label="Total Purchases"
          value={formatCurrency(liveTotalPurchases)}
          icon="solar:wallet-money-bold-duotone"
          tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
        />
        <StatCard
          label="Total Orders"
          value={liveTotalOrders}
          icon="solar:cart-large-2-bold-duotone"
          tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
        />
        <StatCard
          label="Avg. Order Value"
          value={formatCurrency(liveAvgOrderValue)}
          icon="solar:chart-2-bold-duotone"
          tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
        />
        <StatCard
          label="Outstanding"
          value={<span className="text-orange-600 dark:text-orange-400">{formatCurrency(liveOutstanding)}</span>}
          icon="solar:bill-list-bold-duotone"
          tone="bg-orange-100 text-orange-600 dark:bg-orange-950/60 dark:text-orange-400"
        />
        <StatCard
          label="Loyalty Points"
          value={customer.loyalty_points || 0}
          icon="solar:star-bold-duotone"
          tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
        />
      </div>

      {/* Main Content with Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="overview" className="gap-2 rounded-full px-4">
            <Icon icon="solar:home-2-bold-duotone" className="h-4 w-4" />
            Overview
          </TabsTrigger>
          <TabsTrigger value="activity" className="gap-2 rounded-full px-4">
            <Icon icon="solar:history-bold-duotone" className="h-4 w-4" />
            Activity
          </TabsTrigger>
          <TabsTrigger value="invoices" className="gap-2 rounded-full px-4">
            <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4" />
            Invoices
          </TabsTrigger>
          <TabsTrigger value="contacts" className="gap-2 rounded-full px-4">
            <Icon icon="solar:users-group-rounded-bold-duotone" className="h-4 w-4" />
            Contacts
          </TabsTrigger>
          <TabsTrigger value="financial" className="gap-2 rounded-full px-4">
            <Icon icon="solar:wallet-bold-duotone" className="h-4 w-4" />
            Financial
          </TabsTrigger>
          <TabsTrigger value="audit" className="gap-2 rounded-full px-4">
            <Icon icon="solar:shield-check-bold-duotone" className="h-4 w-4" />
            Audit
          </TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="space-y-6 mt-6">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {/* Contact Information */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800 lg:col-span-2">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:user-id-bold" className="h-5 w-5" />
                  Contact Information
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Email</Label>
                    <p className="font-medium">{customer.email || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Phone</Label>
                    <p className="font-medium">{customer.phone || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Mobile</Label>
                    <p className="font-medium">{customer.mobile || '-'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Website</Label>
                    <p className="font-medium">
                      {customer.website ? (
                        <a href={customer.website} target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
                          {customer.website}
                        </a>
                      ) : '-'}
                    </p>
                  </div>
                  {customer.type === 'company' && (
                    <>
                      <div>
                        <Label className="text-muted-foreground">Company</Label>
                        <p className="font-medium">{customer.company || '-'}</p>
                      </div>
                      <div>
                        <Label className="text-muted-foreground">Tax Number</Label>
                        <p className="font-medium">{customer.tax_number || '-'}</p>
                      </div>
                    </>
                  )}
                  <div>
                    <Label className="text-muted-foreground">Preferred Contact</Label>
                    <p className="font-medium capitalize">{customer.preferred_contact || 'Email'}</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Marketing Opt-in</Label>
                    <p className="font-medium">{customer.marketing_opt_in ? 'Yes' : 'No'}</p>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Credit & Payment */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:wallet-bold" className="h-5 w-5" />
                  Credit Status
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <div className="mb-2 flex justify-between text-sm">
                    <span className="text-muted-foreground">Credit Used</span>
                    <span className="font-medium tabular-nums">
                      {formatCurrency(customer.total_outstanding)}
                      {customer.credit_limit > 0 && (
                        <span className="text-muted-foreground"> / {formatCurrency(customer.credit_limit)}</span>
                      )}
                    </span>
                  </div>
                  {customer.credit_limit > 0 ? (
                    <>
                      <Progress
                        value={Math.min(creditUsedPercent, 100)}
                        className={cn(
                          'h-2.5 rounded-full',
                          creditUsedPercent > 90 ? '[&>div]:bg-red-500' :
                          creditUsedPercent > 70 ? '[&>div]:bg-amber-500' :
                          '[&>div]:bg-emerald-500'
                        )}
                      />
                      {creditUsedPercent > 90 && (
                        <p className="mt-1 text-xs text-red-500">Credit limit almost reached</p>
                      )}
                    </>
                  ) : (
                    <p className="text-xs text-muted-foreground">No credit limit set for this customer</p>
                  )}
                </div>
                <Separator />
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label className="text-muted-foreground">Payment Terms</Label>
                    <p className="font-medium">{customer.payment_terms} days</p>
                  </div>
                  <div>
                    <Label className="text-muted-foreground">Price List</Label>
                    <p className="font-medium">{customer.price_list?.name || 'Default'}</p>
                  </div>
                </div>
                {customer.salesperson && (
                  <div>
                    <Label className="text-muted-foreground">Assigned Salesperson</Label>
                    <p className="font-medium">{customer.salesperson.name}</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Addresses */}
          <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:map-point-bold" className="h-5 w-5" />
                Addresses
              </CardTitle>
              <HeaderActionButton
                variant="outline"
                icon="solar:add-circle-bold"
                onClick={() => {
                  setEditingAddressId(null)
                  setAddressForm({})
                  setAddressDialogOpen(true)
                }}
              >
                Add Address
              </HeaderActionButton>
            </CardHeader>
            <CardContent>
              {customer.addresses?.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {customer.addresses.map(address => (
                    <div key={address.id} className="group relative rounded-xl border border-slate-200/80 bg-card p-4 transition-all hover:shadow-md dark:border-slate-800">
                      <div className="absolute right-2 top-2 flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <Button
                          size="sm"
                          variant="ghost"
                          className="h-7 w-7 p-0"
                          onClick={() => {
                            setEditingAddressId(address.id)
                            setAddressForm({ ...address })
                            setAddressDialogOpen(true)
                          }}
                          title="Edit address"
                          aria-label="Edit address"
                        >
                          <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                          onClick={() => deleteAddressMutation.mutate(address.id)}
                          title="Delete address"
                          aria-label="Delete address"
                        >
                          <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                      <div className="mb-2 flex flex-wrap items-center gap-2">
                        <span
                          className={cn(
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize',
                            address.type === 'billing'
                              ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/60 dark:text-blue-300'
                              : 'bg-violet-100 text-violet-700 dark:bg-violet-950/60 dark:text-violet-300',
                          )}
                        >
                          <Icon
                            icon={address.type === 'billing' ? 'solar:bill-list-bold-duotone' : 'solar:delivery-bold-duotone'}
                            className="h-3 w-3"
                          />
                          {address.type}
                        </span>
                        {address.is_default && (
                          <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                            Default
                          </span>
                        )}
                        {address.label && <span className="text-sm text-muted-foreground">{address.label}</span>}
                      </div>
                      <p className="text-sm leading-relaxed text-muted-foreground">
                        {[address.street, address.street2, address.city, address.state, address.zip, address.country]
                          .filter(Boolean)
                          .join(', ')}
                      </p>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-300 py-10 text-center text-muted-foreground dark:border-slate-700">
                  <Icon icon="solar:map-point-wave-bold" className="mx-auto mb-2 h-10 w-10 opacity-40" />
                  <p className="text-sm">No addresses added yet</p>
                </div>
              )}
              
              {/* Legacy addresses */}
              {(customer.billing_address || customer.shipping_address) && (
                <>
                  <Separator className="my-4" />
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {customer.billing_address && (
                      <div className="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
                        <Badge variant="outline" className="mb-2">Billing (Legacy)</Badge>
                        <p className="text-sm whitespace-pre-line">{formatLegacyAddress(customer.billing_address)}</p>
                      </div>
                    )}
                    {customer.shipping_address && (
                      <div className="rounded-xl border border-slate-200/80 p-4 dark:border-slate-800">
                        <Badge variant="outline" className="mb-2">Shipping (Legacy)</Badge>
                        <p className="text-sm whitespace-pre-line">{formatLegacyAddress(customer.shipping_address)}</p>
                      </div>
                    )}
                  </div>
                </>
              )}
            </CardContent>
          </Card>

          {/* Notes */}
          {customer.notes && (
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Icon icon="solar:notes-bold" className="h-5 w-5" />
                  Notes
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="whitespace-pre-line">{customer.notes}</p>
              </CardContent>
            </Card>
          )}

          {/* Recent Activity */}
          <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:history-bold" className="h-5 w-5" />
                Recent Activity
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-3">
                {customer.pos_orders?.slice(0, 3).map(order => (
                  <div
                    key={order.id}
                    className="flex items-center justify-between gap-4 rounded-xl border border-slate-200/80 p-3.5 transition-colors hover:bg-muted/40 dark:border-slate-800"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                        <Icon icon="solar:cart-check-bold-duotone" className="h-4 w-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="truncate font-medium">POS Order #{order.order_number}</p>
                        <p className="text-sm text-muted-foreground">
                          {order.paid_at ? format(new Date(order.paid_at), 'MMM d, yyyy h:mm a') : format(new Date(order.created_at), 'MMM d, yyyy h:mm a')}
                        </p>
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="font-medium tabular-nums">{formatCurrency(order.total)}</p>
                      <Badge className={getStatusColor(order.status)}>{order.status}</Badge>
                    </div>
                  </div>
                ))}
                {customer.invoices?.slice(0, 3).map(invoice => (
                  <Link
                    key={invoice.id}
                    to={`/invoices/${invoice.id}`}
                    state={{ from: `/customers/${customer.id}` }}
                    className="flex items-center justify-between gap-4 rounded-xl border border-slate-200/80 p-3.5 transition-colors hover:bg-muted/40 dark:border-slate-800"
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400">
                        <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="truncate font-medium text-primary">Invoice #{invoice.invoice_number}</p>
                        <p className="text-sm text-muted-foreground">
                          {format(new Date(invoice.invoice_date), 'MMM d, yyyy')}
                        </p>
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="font-medium tabular-nums">{formatCurrency(invoice.total)}</p>
                      <Badge className={getStatusColor(invoice.status)}>{invoice.status}</Badge>
                    </div>
                  </Link>
                ))}
                {(!customer.pos_orders?.length && !customer.invoices?.length) && (
                  <div className="rounded-xl border border-dashed border-slate-300 py-10 text-center text-muted-foreground dark:border-slate-700">
                    <Icon icon="solar:history-bold" className="mx-auto mb-2 h-10 w-10 opacity-40" />
                    <p className="text-sm">No recent activity</p>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Activity Tab */}
        <TabsContent value="activity" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Icon icon="solar:history-bold" className="h-5 w-5" />
                Activity Timeline
              </CardTitle>
              <CardDescription>Complete history of customer interactions</CardDescription>
            </CardHeader>
            <CardContent>
              {activityData?.activities?.length > 0 ? (
                <div className="relative">
                  {/* Timeline line */}
                  <div className="absolute left-6 top-0 bottom-0 w-0.5 bg-border" />
                  
                  <div className="space-y-6">
                    {activityData.activities.map((activity: any, index: number) => {
                      const iconMap: Record<string, string> = {
                        cart: 'solar:cart-check-bold',
                        document: 'solar:document-text-bold',
                        money: 'solar:wallet-money-bold',
                        star: 'solar:star-bold',
                      }
                      const colorMap: Record<string, string> = {
                        pos_order: 'bg-green-100 text-green-600',
                        invoice: 'bg-blue-100 text-blue-600',
                        payment: 'bg-purple-100 text-purple-600',
                        loyalty: 'bg-yellow-100 text-yellow-600',
                      }
                      
                      return (
                        <div key={`${activity.type}-${activity.id}-${index}`} className="relative flex gap-4">
                          <div className={cn(
                            "relative z-10 flex h-12 w-12 items-center justify-center rounded-full",
                            colorMap[activity.type] || 'bg-gray-100 text-gray-600'
                          )}>
                            <Icon icon={iconMap[activity.icon] || 'solar:info-circle-bold'} className="h-6 w-6" />
                          </div>
                          <div className="flex-1 pt-1">
                            <div className="flex items-center justify-between">
                              <div>
                                <p className="font-medium">{activity.title}</p>
                                <p className="text-sm text-muted-foreground">{activity.description}</p>
                              </div>
                              <div className="text-right">
                                {activity.amount && (
                                  <p className="font-semibold">{formatCurrency(activity.amount)}</p>
                                )}
                                {activity.points && (
                                  <p className={activity.points > 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                    {activity.points > 0 ? '+' : ''}{activity.points} pts
                                  </p>
                                )}
                                <p className="text-xs text-muted-foreground">
                                  {format(new Date(activity.date), 'MMM d, yyyy h:mm a')}
                                </p>
                              </div>
                            </div>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                </div>
              ) : (
                <div className="text-center py-12 text-muted-foreground">
                  <Icon icon="solar:history-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                  <p>No activity recorded yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Orders Tab */}
        <TabsContent value="orders" className="mt-6">
          <Card>
            <CardHeader>
              <CardTitle>Orders</CardTitle>
              <CardDescription>All orders for this customer (POS & Web)</CardDescription>
            </CardHeader>
            <CardContent>
              {(() => {
                const webOrdersList = customer.web_orders || customer.webOrders || [];
                const allOrders = [
                    ...(customer.pos_orders || []).map(o => ({ ...o, type: 'POS', payment_method: o.payment_method || '-' })),
                    ...(webOrdersList).map(o => ({ 
                      ...o, 
                      type: 'Web', 
                      payment_method: o.payment_status,
                      paid_at: o.created_at // Fallback for sort/display
                    }))
                ].sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
                
                if (allOrders.length === 0) {
                     return (
                        <div className="text-center py-12 text-muted-foreground">
                          <Icon icon="solar:cart-large-2-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                          <p>No orders yet</p>
                        </div>
                     )
                }

                return (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Order #</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Date</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Payment</TableHead>
                      <TableHead className="text-right">Total</TableHead>
                      <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {allOrders.map((order: any, idx) => (
                      <TableRow key={`${order.type}-${order.id}-${idx}`}>
                        <TableCell className="font-medium">{order.order_number}</TableCell>
                        <TableCell>
                           <Badge variant="outline">{order.type}</Badge>
                        </TableCell>
                        <TableCell>
                          {order.paid_at ? format(new Date(order.paid_at), 'MMM d, yyyy h:mm a') : format(new Date(order.created_at), 'MMM d, yyyy')}
                        </TableCell>
                        <TableCell>
                          <Badge className={getStatusColor(order.status)}>{order.status}</Badge>
                        </TableCell>
                        <TableCell>
                          <span className="text-sm capitalize">{order.payment_method || '-'}</span>
                        </TableCell>
                        <TableCell className="text-right font-medium">{formatCurrency(order.total)}</TableCell>
                        <TableCell className="text-right">
                          <Button
                            variant="ghost"
                            size="icon"
                            onClick={() => {
                              setSelectedOrder(order)
                              setOrderDialogOpen(true)
                            }}
                            title="View Order Details"
                          >
                            <Icon icon="solar:eye-bold" className="h-4 w-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
                )
              })()}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Invoices Tab */}
        <TabsContent value="invoices" className="mt-6">
          <div className="space-y-6">
            {/* AR Aging Summary — live receivables by bucket */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader className="space-y-1">
                <CardTitle className="text-lg">AR Aging Summary</CardTitle>
                <CardDescription>Outstanding receivables by aging period (as of today)</CardDescription>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                  {[
                    { key: 'not_due', label: 'Not Due', tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' },
                    { key: '1_30', label: '1–30 Days', tone: 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300' },
                    { key: '31_60', label: '31–60 Days', tone: 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' },
                    { key: '61_90', label: '61–90 Days', tone: 'bg-orange-50 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300' },
                    { key: '91_180', label: '91–180 Days', tone: 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' },
                    { key: '180_plus', label: '180+ Days', tone: 'bg-red-100 text-red-800 dark:bg-red-950/70 dark:text-red-200' },
                  ].map((bucket) => {
                    const bucketInvoices = (agingData?.invoices ?? []).filter((inv: any) => inv.aging_bucket === bucket.key)
                    return (
                      <div key={bucket.key} className={cn('rounded-xl p-3', bucket.tone)}>
                        <p className="text-[11px] font-semibold uppercase tracking-wide opacity-80">{bucket.label}</p>
                        <p className="mt-1 text-lg font-bold tabular-nums">
                          {formatCurrency(agingData?.buckets?.[bucket.key]?.amount || 0)}
                        </p>
                        <p className="text-[11px] opacity-70">
                          {bucketInvoices.length} invoice{bucketInvoices.length === 1 ? '' : 's'}
                        </p>
                      </div>
                    )
                  })}
                </div>
                <div className="mt-4 flex items-center justify-between rounded-xl bg-muted/60 px-4 py-3">
                  <span className="text-sm font-medium text-muted-foreground">Total Outstanding</span>
                  <span className="text-2xl font-bold tabular-nums">
                    {formatCurrency(agingData?.total_outstanding || 0)}
                  </span>
                </div>
              </CardContent>
            </Card>

            {/* Invoices Table — full (paginated) list, not just the 10-row preview */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <div>
                  <CardTitle>Invoices</CardTitle>
                  <CardDescription>All invoices for this customer</CardDescription>
                </div>
                <div className="flex gap-2">
                  <HeaderActionButton
                    variant="outline"
                    icon="solar:document-text-bold"
                    onClick={() => setStatementDialogOpen(true)}
                  >
                    Generate Statement
                  </HeaderActionButton>
                  <HeaderActionButton
                    icon="solar:add-circle-bold"
                    onClick={() => navigate('/invoices/new?customer=' + customer.id)}
                  >
                    Create Invoice
                  </HeaderActionButton>
                </div>
              </CardHeader>
              <CardContent>
                {invoicesLoading ? (
                  <div className="flex items-center justify-center py-12">
                    <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-primary" />
                  </div>
                ) : (invoicesData?.data ?? customer.invoices ?? []).length > 0 ? (
                  <>
                    <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                      <Table>
                        <TableHeader>
                          <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead>Invoice #</TableHead>
                            <TableHead>Issue Date</TableHead>
                            <TableHead>Due Date</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead className="text-right">Balance</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {(invoicesData?.data ?? customer.invoices ?? []).map((invoice: Invoice) => {
                            const isOverdue = Number(invoice.balance) > 0 && invoice.due_date && new Date(invoice.due_date) < new Date()
                            return (
                              <TableRow
                                key={invoice.id}
                                className="cursor-pointer"
                                onClick={() => navigate(`/invoices/${invoice.id}`, { state: { from: `/customers/${customer.id}` } })}
                              >
                                <TableCell className="font-medium text-primary">{invoice.invoice_number}</TableCell>
                                <TableCell>{format(new Date(invoice.invoice_date), 'MMM d, yyyy')}</TableCell>
                                <TableCell className={cn(isOverdue && 'font-medium text-red-600 dark:text-red-400')}>
                                  {format(new Date(invoice.due_date), 'MMM d, yyyy')}
                                </TableCell>
                                <TableCell>
                                  <Badge className={getStatusColor(invoice.status)}>{invoice.status}</Badge>
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">{formatCurrency(invoice.total)}</TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                  {Number(invoice.balance) > 0 ? formatCurrency(invoice.balance) : '—'}
                                </TableCell>
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                    {invoicesData && invoicesData.last_page > 1 && (
                      <div className="flex flex-wrap items-center justify-between gap-3 pt-4">
                        <p className="text-xs text-muted-foreground">
                          Page {invoicesData.current_page} of {invoicesData.last_page} · {invoicesData.total} invoices
                        </p>
                        <div className="flex gap-2">
                          <HeaderActionButton
                            variant="outline"
                            icon="solar:alt-arrow-left-bold"
                            disabled={invoicePage <= 1}
                            onClick={() => setInvoicePage((page) => Math.max(1, page - 1))}
                          >
                            Previous
                          </HeaderActionButton>
                          <HeaderActionButton
                            variant="outline"
                            disabled={invoicePage >= invoicesData.last_page}
                            onClick={() => setInvoicePage((page) => page + 1)}
                          >
                            Next
                            <Icon icon="solar:alt-arrow-right-bold" className="ml-1.5 h-3.5 w-3.5" />
                          </HeaderActionButton>
                        </div>
                      </div>
                    )}
                  </>
                ) : (
                  <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center text-muted-foreground dark:border-slate-700">
                    <Icon icon="solar:document-text-bold" className="mx-auto mb-3 h-12 w-12 opacity-40" />
                    <p className="text-sm">No invoices yet</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Contacts Tab */}
        <TabsContent value="contacts" className="mt-6">
          <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
            <CardHeader className="flex flex-row items-center justify-between space-y-0">
              <div>
                <CardTitle>Contacts</CardTitle>
                <CardDescription>People associated with this customer</CardDescription>
              </div>
              <HeaderActionButton
                icon="solar:add-circle-bold"
                onClick={() => {
                  setEditingContact(null)
                  setContactDialogOpen(true)
                }}
              >
                Add Contact
              </HeaderActionButton>
            </CardHeader>
            <CardContent>
              {customer.customer_contacts?.length > 0 ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                  {customer.customer_contacts.map(contact => {
                    // Legacy contacts store the same value in both category and
                    // type ("billing"/"doctor") — show each chip once.
                    const typeChips = Array.from(new Set([contact.contact_category, contact.contact_type].filter(Boolean)))
                    const hasAddress = [contact.street, contact.street2, contact.city, contact.state, contact.zip, contact.country].some(Boolean)

                    return (
                      <div
                        key={contact.id}
                        className="group relative rounded-xl border border-slate-200/80 bg-card p-4 transition-all hover:shadow-md dark:border-slate-800"
                      >
                        <div className="absolute right-2 top-2 flex gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 w-7 p-0"
                            onClick={() => {
                              setEditingContact(contact)
                              setContactDialogOpen(true)
                            }}
                            title="Edit contact"
                            aria-label="Edit contact"
                          >
                            <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                            onClick={() => deleteContactMutation.mutate(contact.id)}
                            title="Delete contact"
                            aria-label="Delete contact"
                          >
                            <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
                          </Button>
                        </div>

                        <div className="flex items-start gap-3">
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {getInitials(contact.name)}
                          </div>
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2 pr-14">
                              <p className="truncate font-medium">{contact.name}</p>
                              {contact.is_primary && (
                                <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                  Primary
                                </span>
                              )}
                            </div>
                            {contact.job_title && (
                              <p className="text-sm text-muted-foreground">{contact.job_title}</p>
                            )}
                            {typeChips.length > 0 && (
                              <div className="mt-2 flex flex-wrap gap-1.5">
                                {typeChips.map((chip) => (
                                  <span
                                    key={chip}
                                    className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium capitalize text-muted-foreground"
                                  >
                                    {chip}
                                  </span>
                                ))}
                              </div>
                            )}
                            <div className="mt-3 space-y-1.5 text-sm">
                              {contact.core_contact_uuid && personSites[contact.core_contact_uuid] && (
                                <div className="flex items-center gap-2 text-muted-foreground" data-testid="contact-site">
                                  <Icon icon="solar:buildings-2-bold" className="h-3.5 w-3.5 shrink-0" />
                                  <span className="truncate">Site: {personSites[contact.core_contact_uuid]}</span>
                                </div>
                              )}
                              {contact.email && (
                                <a href={`mailto:${contact.email}`} className="flex items-center gap-2 text-muted-foreground transition-colors hover:text-foreground">
                                  <Icon icon="solar:letter-bold" className="h-3.5 w-3.5 shrink-0" />
                                  <span className="truncate">{contact.email}</span>
                                </a>
                              )}
                              {contact.phone && (
                                <a href={`tel:${contact.phone}`} className="flex items-center gap-2 text-muted-foreground transition-colors hover:text-foreground">
                                  <Icon icon="solar:phone-bold" className="h-3.5 w-3.5 shrink-0" />
                                  <span className="truncate">{contact.phone}</span>
                                </a>
                              )}
                              {contact.mobile && (
                                <a href={`tel:${contact.mobile}`} className="flex items-center gap-2 text-muted-foreground transition-colors hover:text-foreground">
                                  <Icon icon="solar:smartphone-bold" className="h-3.5 w-3.5 shrink-0" />
                                  <span className="truncate">{contact.mobile}</span>
                                </a>
                              )}
                              {hasAddress && (
                                // ADR-003B: people no longer hold addresses; an
                                // older record's address is shown read-only until
                                // it is moved to a company site.
                                <div className="flex items-start gap-2 pt-0.5 text-xs text-muted-foreground/80" title="Older record — people no longer hold addresses">
                                  <Icon icon="solar:map-point-linear" className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                  <p className="leading-relaxed">
                                    Older personal address:{' '}
                                    {[contact.street, contact.street2, contact.city, contact.state, contact.zip, contact.country]
                                      .filter(Boolean)
                                      .join(', ')}
                                  </p>
                                </div>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                    )
                  })}
                </div>
              ) : (
                <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center text-muted-foreground dark:border-slate-700">
                  <Icon icon="solar:users-group-rounded-bold" className="mx-auto mb-3 h-12 w-12 opacity-40" />
                  <p className="text-sm">No contacts added yet</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Loyalty Tab */}
        <TabsContent value="loyalty" className="mt-6">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <Card className="lg:col-span-1">
              <CardHeader>
                <CardTitle>Loyalty Balance</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="text-center py-6">
                  <div className="inline-flex items-center justify-center w-20 h-20 rounded-full bg-yellow-100 mb-4">
                    <Icon icon="solar:star-bold" className="h-10 w-10 text-yellow-600" />
                  </div>
                  <p className="text-4xl font-bold">{customer.loyalty_points || 0}</p>
                  <p className="text-muted-foreground">Available Points</p>
                </div>
                {customer.loyalty_balances?.[0] && (
                  <div className="grid grid-cols-2 gap-4 text-center">
                    <div>
                      <p className="text-2xl font-semibold text-green-600">
                        {customer.loyalty_balances[0].points_earned_total || 0}
                      </p>
                      <p className="text-sm text-muted-foreground">Total Earned</p>
                    </div>
                    <div>
                      <p className="text-2xl font-semibold text-blue-600">
                        {customer.loyalty_balances[0].points_redeemed_total || 0}
                      </p>
                      <p className="text-sm text-muted-foreground">Total Redeemed</p>
                    </div>
                  </div>
                )}
                <Button className="w-full" onClick={() => setLoyaltyDialogOpen(true)}>
                  <Icon icon="solar:add-circle-bold" className="h-4 w-4 mr-2" />
                  Adjust Points
                </Button>
              </CardContent>
            </Card>

            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>Transaction History</CardTitle>
              </CardHeader>
              <CardContent>
                {loyaltyData?.transactions?.data?.length > 0 ? (
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Date</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Points</TableHead>
                        <TableHead>Balance After</TableHead>
                        <TableHead>Notes</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {loyaltyData.transactions.data.map((tx: LoyaltyTransaction) => (
                        <TableRow key={tx.id}>
                          <TableCell>{format(new Date(tx.created_at), 'MMM d, yyyy h:mm a')}</TableCell>
                          <TableCell>
                            <Badge variant={tx.type === 'earn' ? 'default' : 'secondary'} className="capitalize">
                              {tx.type}
                            </Badge>
                          </TableCell>
                          <TableCell className={tx.points > 0 ? 'text-green-600' : 'text-red-600'}>
                            {tx.points > 0 ? '+' : ''}{tx.points}
                          </TableCell>
                          <TableCell>{tx.balance_after}</TableCell>
                          <TableCell className="text-muted-foreground">{tx.notes || '-'}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                ) : (
                  <div className="text-center py-12 text-muted-foreground">
                    <Icon icon="solar:history-bold" className="h-16 w-16 mx-auto mb-4 opacity-50" />
                    <p>No loyalty transactions yet</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* Financial Tab */}
        <TabsContent value="financial" className="mt-6">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Credit Information */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader>
                <CardTitle>Credit Information</CardTitle>
                <CardDescription>Based on live receivables and POS activity</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="rounded-xl bg-muted/50 p-4">
                    <p className="text-xs text-muted-foreground">Credit Limit</p>
                    <p className="mt-1 text-xl font-bold tabular-nums">
                      {customer.credit_limit > 0 ? formatCurrency(customer.credit_limit) : 'No limit'}
                    </p>
                  </div>
                  <div className="rounded-xl bg-muted/50 p-4">
                    <p className="text-xs text-muted-foreground">Outstanding Balance</p>
                    <p className="mt-1 text-xl font-bold tabular-nums text-orange-600 dark:text-orange-400">
                      {formatCurrency(liveOutstanding)}
                    </p>
                  </div>
                </div>
                {customer.credit_limit > 0 ? (
                  <>
                    <div className="grid grid-cols-2 gap-4">
                      <div className="rounded-xl bg-muted/50 p-4">
                        <p className="text-xs text-muted-foreground">Available Credit</p>
                        <p className="mt-1 text-xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                          {formatCurrency(Math.max(0, customer.credit_limit - liveOutstanding))}
                        </p>
                      </div>
                      <div className="rounded-xl bg-muted/50 p-4">
                        <p className="text-xs text-muted-foreground">Credit Utilization</p>
                        <p className="mt-1 text-xl font-bold tabular-nums">{creditUsedPercent.toFixed(1)}%</p>
                      </div>
                    </div>
                    <div>
                      <Progress
                        value={Math.min(creditUsedPercent, 100)}
                        className={cn(
                          'h-2.5 rounded-full',
                          creditUsedPercent > 90 ? '[&>div]:bg-red-500' :
                          creditUsedPercent > 70 ? '[&>div]:bg-amber-500' :
                          '[&>div]:bg-emerald-500'
                        )}
                      />
                      {creditUsedPercent > 90 && (
                        <p className="mt-1 text-xs text-red-500">Credit limit almost reached</p>
                      )}
                    </div>
                  </>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    No credit limit set for this customer — the outstanding balance is not capped.
                  </p>
                )}
              </CardContent>
            </Card>

            {/* Payment Terms */}
            <Card className="rounded-xl border-slate-200/80 dark:border-slate-800">
              <CardHeader>
                <CardTitle>Payment Terms</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="rounded-xl bg-muted/50 p-4">
                    <p className="text-xs text-muted-foreground">Payment Terms</p>
                    <p className="mt-1 text-xl font-bold">{customer.payment_terms || 30} days</p>
                  </div>
                  <div className="rounded-xl bg-muted/50 p-4">
                    <p className="text-xs text-muted-foreground">Price List</p>
                    <p className="mt-1 text-xl font-bold">{customer.price_list?.name || 'Default'}</p>
                  </div>
                </div>
                {customer.salesperson && (
                  <div className="rounded-xl bg-muted/50 p-4">
                    <p className="text-xs text-muted-foreground">Assigned Salesperson</p>
                    <p className="mt-1 font-medium">{customer.salesperson.name}</p>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Purchase Summary — live aggregates (POS + non-draft invoices) */}
            <div className="grid grid-cols-2 gap-4 lg:col-span-2 xl:grid-cols-4">
              <StatCard
                label="Total Orders"
                value={liveTotalOrders}
                icon="solar:cart-large-2-bold-duotone"
                tone="bg-blue-100 text-blue-600 dark:bg-blue-950/60 dark:text-blue-400"
              />
              <StatCard
                label="Lifetime Value"
                value={formatCurrency(liveTotalPurchases)}
                icon="solar:wallet-money-bold-duotone"
                tone="bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
              />
              <StatCard
                label="Avg. Order Value"
                value={formatCurrency(liveAvgOrderValue)}
                icon="solar:chart-2-bold-duotone"
                tone="bg-violet-100 text-violet-600 dark:bg-violet-950/60 dark:text-violet-400"
              />
              <StatCard
                label="Last Activity"
                value={lastActivityAt ? format(new Date(lastActivityAt), 'MMM d, yyyy') : '—'}
                icon="solar:clock-circle-bold-duotone"
                tone="bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
              />
            </div>

            {/* Frequently Purchased Products */}
            {statsData?.favorite_products?.length > 0 && (
              <Card className="rounded-xl border-slate-200/80 dark:border-slate-800 lg:col-span-2">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Icon icon="solar:heart-bold" className="h-5 w-5" />
                    Frequently Purchased Products
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="overflow-hidden rounded-xl border border-slate-200/80 dark:border-slate-800">
                    <Table>
                      <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                          <TableHead>Product</TableHead>
                          <TableHead className="text-right">Quantity Purchased</TableHead>
                          <TableHead className="text-right">Total Spent</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {statsData.favorite_products.map((product: any) => (
                          <TableRow key={product.id}>
                            <TableCell className="font-medium">{product.name}</TableCell>
                            <TableCell className="text-right tabular-nums">{product.total_qty}</TableCell>
                            <TableCell className="text-right tabular-nums">{formatCurrency(product.total_spent)}</TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        </TabsContent>

        {/* Audit Tab — read-only #895 trail (#905 / PCU1) */}
        <TabsContent value="audit" className="mt-6">
          <AuditTab endpoint={`/customers/${id}/audit`} permission="customers.view" />
        </TabsContent>
      </Tabs>

      {/* Add/Edit Address Dialog */}
      <Dialog
        open={addressDialogOpen}
        onOpenChange={(open) => {
          setAddressDialogOpen(open)
          if (!open) {
            setEditingAddressId(null)
            setAddressForm({})
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingAddressId ? 'Edit Address' : 'Add Address'}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Type</Label>
                <Select 
                  value={addressForm.type} 
                  onValueChange={(v) => setAddressForm({ ...addressForm, type: v as 'billing' | 'shipping' | 'other' })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="billing">Billing</SelectItem>
                    <SelectItem value="shipping">Shipping</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label>Label</Label>
                <Input 
                  placeholder="e.g., Main Office" 
                  value={addressForm.label || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, label: e.target.value })}
                />
              </div>
            </div>
            <div className="space-y-2">
              <Label>Street</Label>
              <Input
                value={addressForm.street || ''}
                onChange={(e) => setAddressForm({ ...addressForm, street: e.target.value })}
              />
            </div>
            <div className="space-y-2">
              <Label>Street 2</Label>
              <Input
                value={addressForm.street2 || ''}
                onChange={(e) => setAddressForm({ ...addressForm, street2: e.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>City</Label>
                <Input
                  value={addressForm.city || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, city: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>State/Province</Label>
                <Input
                  value={addressForm.state || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, state: e.target.value })}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>ZIP/Postal Code</Label>
                <Input
                  value={addressForm.zip || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, zip: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label>Country</Label>
                <Input
                  value={addressForm.country || ''}
                  onChange={(e) => setAddressForm({ ...addressForm, country: e.target.value })}
                />
              </div>
            </div>
            <div className="flex items-center space-x-2">
              <input
                type="checkbox"
                id="is_default"
                checked={addressForm.is_default || false}
                onChange={(e) => setAddressForm({ ...addressForm, is_default: e.target.checked })}
                className="h-4 w-4 rounded border-gray-300"
              />
              <Label htmlFor="is_default" className="font-normal">Set as default address</Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAddressDialogOpen(false)}>Cancel</Button>
            <Button
              onClick={() => {
                if (editingAddressId) {
                  updateAddressMutation.mutate({ addressId: editingAddressId, data: addressForm })
                } else {
                  addAddressMutation.mutate(addressForm)
                }
              }}
              disabled={!addressForm.type || addAddressMutation.isPending || updateAddressMutation.isPending}
            >
              {editingAddressId
                ? (updateAddressMutation.isPending ? 'Saving...' : 'Save Changes')
                : (addAddressMutation.isPending ? 'Adding...' : 'Add Address')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Add/Edit Contact Dialog (ADR-003B W4) */}
      <CustomerContactDialog
        open={contactDialogOpen}
        onOpenChange={(open) => {
          setContactDialogOpen(open)
          if (!open) setEditingContact(null)
        }}
        customerId={id!}
        organisationId={customer.core_contact_uuid}
        contact={editingContact}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['customer', id] })
          queryClient.invalidateQueries({ queryKey: ['customer-contact-sites', customer.core_contact_uuid] })
        }}
      />

      {/* Adjust Loyalty Dialog */}
      <Dialog open={loyaltyDialogOpen} onOpenChange={setLoyaltyDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Adjust Loyalty Points</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <div className="text-center py-4">
              <p className="text-sm text-muted-foreground">Current Balance</p>
              <p className="text-3xl font-bold">{customer.loyalty_points || 0} pts</p>
            </div>
            <div className="space-y-2">
              <Label>Points Adjustment</Label>
              <Input 
                type="number"
                value={loyaltyAdjustment.points}
                onChange={(e) => setLoyaltyAdjustment({ ...loyaltyAdjustment, points: parseInt(e.target.value) || 0 })}
                placeholder="Enter positive to add, negative to deduct"
              />
              <p className="text-xs text-muted-foreground">
                Enter a positive number to add points, negative to deduct
              </p>
            </div>
            <div className="space-y-2">
              <Label>Reason/Notes</Label>
              <Textarea 
                value={loyaltyAdjustment.notes}
                onChange={(e) => setLoyaltyAdjustment({ ...loyaltyAdjustment, notes: e.target.value })}
                placeholder="Reason for adjustment..."
              />
            </div>
            {loyaltyAdjustment.points !== 0 && (
              <div className="p-3 bg-muted rounded-lg text-center">
                <p className="text-sm text-muted-foreground">New Balance After Adjustment</p>
                <p className="text-2xl font-bold">
                  {(customer.loyalty_points || 0) + loyaltyAdjustment.points} pts
                </p>
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setLoyaltyDialogOpen(false)}>Cancel</Button>
            <Button 
              onClick={() => adjustLoyaltyMutation.mutate(loyaltyAdjustment)}
              disabled={loyaltyAdjustment.points === 0 || adjustLoyaltyMutation.isPending}
            >
              Adjust Points
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Notes Dialog */}
      <Dialog open={noteDialogOpen} onOpenChange={(open) => {
        setNoteDialogOpen(open)
        if (open) setCustomerNote(customer.notes || '')
      }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Customer Notes</DialogTitle>
          </DialogHeader>
          <div className="space-y-4">
            <Textarea 
              rows={6}
              value={customerNote}
              onChange={(e) => setCustomerNote(e.target.value)}
              placeholder="Add notes about this customer..."
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setNoteDialogOpen(false)}>Cancel</Button>
            <Button 
              onClick={() => updateNotesMutation.mutate(customerNote)}
              disabled={updateNotesMutation.isPending}
            >
              Save Notes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Customer Dialog */}
      <Dialog open={editDialogOpen} onOpenChange={setEditDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Customer</DialogTitle>
          </DialogHeader>
          <CustomerForm
            value={editForm}
            onChange={setEditForm}
            idPrefix="detail-edit"
            disabled={updateMutation.isPending}
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleEditSubmit}
              disabled={!editForm.name || updateMutation.isPending}
            >
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Email Customer Dialog */}
      <Dialog open={emailDialogOpen} onOpenChange={(open) => {
        setEmailDialogOpen(open)
        if (!open) setEmailForm({ subject: '', body: '' })
      }}>
        <DialogContent className="max-w-xl">
          <DialogHeader>
            <DialogTitle>Send Email to {customer.name}</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label>To</Label>
              <Input value={customer.email} disabled className="bg-muted" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="email-subject">Subject *</Label>
              <Input
                id="email-subject"
                value={emailForm.subject}
                onChange={(e) => setEmailForm({ ...emailForm, subject: e.target.value })}
                placeholder="Email subject"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="email-body">Message *</Label>
              <Textarea
                id="email-body"
                value={emailForm.body}
                onChange={(e) => setEmailForm({ ...emailForm, body: e.target.value })}
                placeholder="Write your message here..."
                rows={8}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEmailDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={() => sendEmailMutation.mutate(emailForm)}
              disabled={!emailForm.subject || !emailForm.body || sendEmailMutation.isPending}
            >
              <Icon icon="solar:letter-bold" className="h-4 w-4 mr-2" />
              {sendEmailMutation.isPending ? 'Sending...' : 'Send Email'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Order Details Dialog */}
      <Dialog open={orderDialogOpen} onOpenChange={(open) => {
        setOrderDialogOpen(open)
        if (!open) setSelectedOrder(null)
      }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Icon icon="solar:cart-large-2-bold" className="h-5 w-5" />
              Order {selectedOrder?.order_number}
            </DialogTitle>
          </DialogHeader>
          {selectedOrder && (
            <div className="space-y-6">
              {/* Order Header Info */}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <p className="text-sm text-muted-foreground">Status</p>
                  <Badge className={getStatusColor(selectedOrder.status)}>{selectedOrder.status}</Badge>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Date</p>
                  <p className="font-medium">
                    {selectedOrder.paid_at 
                      ? format(new Date(selectedOrder.paid_at), 'MMM d, yyyy h:mm a')
                      : format(new Date(selectedOrder.created_at), 'MMM d, yyyy h:mm a')}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Payment Method</p>
                  <p className="font-medium capitalize">{selectedOrder.payment_method || '-'}</p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Amount Paid</p>
                  <p className="font-medium">{formatCurrency(selectedOrder.amount_paid || selectedOrder.total)}</p>
                </div>
              </div>

              <Separator />

              {/* Order Items */}
              {selectedOrder.items && selectedOrder.items.length > 0 ? (
                <div>
                  <h4 className="font-medium mb-3">Order Items</h4>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Product</TableHead>
                        <TableHead className="text-center">Qty</TableHead>
                        <TableHead className="text-right">Unit Price</TableHead>
                        <TableHead className="text-right">Tax</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectedOrder.items.map(item => (
                        <TableRow key={item.id}>
                          <TableCell>
                            <div>
                              <p className="font-medium">
                                {item.product_name || item.name || item.product?.name}
                              </p>
                              {item.variant && (
                                <p className="text-sm text-muted-foreground">{item.variant.name}</p>
                              )}
                              {(item.sku || item.product_sku) && (
                                <p className="text-xs text-muted-foreground">SKU: {item.sku || item.product_sku}</p>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="text-center">{item.quantity}</TableCell>
                          <TableCell className="text-right">{formatCurrency(item.unit_price)}</TableCell>
                          <TableCell className="text-right">
                            {item.tax_amount > 0 ? formatCurrency(item.tax_amount) : '-'}
                          </TableCell>
                          <TableCell className="text-right font-medium">{formatCurrency(item.line_total)}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              ) : (
                <div className="text-center py-6 text-muted-foreground">
                  <p>No item details available</p>
                </div>
              )}

              <Separator />

              {/* Order Totals */}
              <div className="space-y-2">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{formatCurrency(selectedOrder.subtotal || selectedOrder.total)}</span>
                </div>
                {selectedOrder.discount_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Discount</span>
                    <span className="text-red-600">-{formatCurrency(selectedOrder.discount_amount)}</span>
                  </div>
                )}
                {selectedOrder.tax_amount > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Tax</span>
                    <span>{formatCurrency(selectedOrder.tax_amount)}</span>
                  </div>
                )}
                <Separator />
                <div className="flex justify-between font-medium text-lg">
                  <span>Total</span>
                  <span>{formatCurrency(selectedOrder.total)}</span>
                </div>
              </div>

              {/* Notes */}
              {selectedOrder.notes && (
                <>
                  <Separator />
                  <div>
                    <h4 className="font-medium mb-2">Notes</h4>
                    <p className="text-sm text-muted-foreground">{selectedOrder.notes}</p>
                  </div>
                </>
              )}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setOrderDialogOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Statement of Account Dialog */}
      <Dialog open={statementDialogOpen} onOpenChange={setStatementDialogOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Generate Statement of Account</DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <p className="text-sm text-muted-foreground">
              Download the Statement of Account (SOA) for <strong>{customer?.name}</strong> as
              PDF, Excel, or Word. Includes all outstanding invoices as of the selected date.
            </p>
            <div className="space-y-2">
              <Label htmlFor="as-of-date">As of Date</Label>
              <Input
                id="as-of-date"
                type="date"
                value={statementAsOf}
                onChange={(e) => setStatementAsOf(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter className="flex-wrap gap-2">
            <HeaderActionButton
              variant="outline"
              icon="solar:file-text-bold"
              loading={statementBusy === 'pdf'}
              disabled={statementBusy !== null}
              onClick={() => downloadStatement('pdf')}
            >
              PDF
            </HeaderActionButton>
            <HeaderActionButton
              variant="outline"
              icon="solar:document-add-bold"
              loading={statementBusy === 'xlsx'}
              disabled={statementBusy !== null}
              onClick={() => downloadStatement('xlsx')}
            >
              Excel
            </HeaderActionButton>
            <HeaderActionButton
              variant="outline"
              icon="solar:document-text-bold"
              loading={statementBusy === 'docx'}
              disabled={statementBusy !== null}
              onClick={() => downloadStatement('docx')}
            >
              Word
            </HeaderActionButton>
            <HeaderActionButton
              variant="outline"
              className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
              disabled={statementBusy !== null}
              onClick={() => setStatementDialogOpen(false)}
            >
              Cancel
            </HeaderActionButton>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
