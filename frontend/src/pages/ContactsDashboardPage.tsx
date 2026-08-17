import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import api, { accountingApi } from '@/lib/api'

type ContactKind = 'Customer' | 'Vendor' | 'Employee'

interface ContactItem {
  key: string
  id: number
  name: string
  kind: ContactKind
  email?: string | null
  phone?: string | null
  detail?: string | null
  active: boolean
  status: string
  createdAt?: string | null
  href: string
}

const unwrap = (value: any): any[] => value?.data?.data ?? value?.data ?? []

export function ContactsDashboardPage() {
  const [search, setSearch] = useState('')
  const [showMissingOnly, setShowMissingOnly] = useState(false)

  const customersQuery = useQuery({
    queryKey: ['contacts-dashboard', 'customers'],
    queryFn: () => api.get('/customers', { params: { per_page: 1000, sort_by: 'created_at', sort_dir: 'desc' } }).then((response) => response.data),
  })
  const vendorsQuery = useQuery({
    queryKey: ['contacts-dashboard', 'vendors'],
    queryFn: () => accountingApi.get('/accounting/vendors', { params: { per_page: 1000 } }).then((response) => response.data),
    retry: false,
  })
  const employeesQuery = useQuery({
    queryKey: ['contacts-dashboard', 'employees'],
    queryFn: () => api.get('/hrm/employees').then((response) => response.data),
    retry: false,
  })

  const contacts = useMemo<ContactItem[]>(() => {
    const customers = unwrap(customersQuery.data).map((item: any): ContactItem => ({
      key: `customer-${item.id}`,
      id: item.id,
      name: item.name,
      kind: 'Customer',
      email: item.email,
      phone: item.mobile || item.phone,
      detail: item.company || item.type,
      active: item.is_active !== false,
      status: item.is_active === false ? 'Inactive' : 'Active',
      createdAt: item.updated_at || item.created_at || item.customer_since,
      href: `/customers/${item.id}`,
    }))
    const vendors = unwrap(vendorsQuery.data).map((item: any): ContactItem => ({
      key: `vendor-${item.id}`,
      id: item.id,
      name: item.name,
      kind: 'Vendor',
      email: item.email,
      phone: item.phone,
      detail: item.company || item.contact_person,
      active: item.is_active !== false,
      status: item.is_active === false ? 'Inactive' : 'Active',
      createdAt: item.updated_at || item.created_at,
      href: '/accounting/vendors',
    }))
    const employees = unwrap(employeesQuery.data).map((item: any): ContactItem => ({
      key: `employee-${item.id}`,
      id: item.id,
      name: item.name,
      kind: 'Employee',
      email: item.email,
      phone: item.contact_number,
      detail: item.department?.name || item.employee_number,
      active: ['active', 'probation'].includes(item.status),
      status: String(item.status || 'active').replace('_', ' '),
      createdAt: item.updated_at || item.created_at,
      href: '/hrm/employees',
    }))

    return [...customers, ...vendors, ...employees]
  }, [customersQuery.data, vendorsQuery.data, employeesQuery.data])

  const filteredContacts = useMemo(() => {
    const term = search.trim().toLowerCase()
    return contacts.filter((contact) => {
      if (showMissingOnly && contact.email && contact.phone) return false

      return !term || [contact.name, contact.email, contact.phone, contact.detail, contact.kind]
        .some((value) => value?.toLowerCase().includes(term))
    })
  }, [contacts, search, showMissingOnly])

  const activeCount = contacts.filter((contact) => contact.active).length
  const inactiveCount = contacts.length - activeCount
  const missingDetails = contacts.filter((contact) => !contact.email || !contact.phone).length
  const recentContacts = [...contacts]
    .filter((contact) => contact.createdAt)
    .sort((a, b) => new Date(b.createdAt!).getTime() - new Date(a.createdAt!).getTime())
    .slice(0, 6)
  const isLoading = customersQuery.isLoading || vendorsQuery.isLoading || employeesQuery.isLoading

  const summaries = [
    { label: 'Total contacts', value: contacts.length, icon: 'solar:users-group-two-rounded-bold-duotone', style: 'bg-blue-50 text-blue-600 dark:bg-blue-950/40', filter: false },
    { label: 'Active contacts', value: activeCount, icon: 'solar:user-check-bold-duotone', style: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40', filter: false },
    { label: 'Inactive contacts', value: inactiveCount, icon: 'solar:user-block-bold-duotone', style: 'bg-slate-100 text-slate-600 dark:bg-slate-900', filter: false },
    { label: 'Missing details', value: missingDetails, icon: 'solar:danger-triangle-bold-duotone', style: 'bg-amber-50 text-amber-600 dark:bg-amber-950/40', filter: true },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-3xl font-bold">Contacts Dashboard</h1>
          <p className="mt-1 text-muted-foreground">Central hub for active contact status and activity.</p>
        </div>
        <Button asChild><Link to="/customers"><Icon icon="solar:users-group-rounded-bold" className="mr-2 h-4 w-4" />Manage Customers</Link></Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {summaries.map((summary) => (
          <Card
            key={summary.label}
            role={summary.filter ? 'button' : undefined}
            tabIndex={summary.filter ? 0 : undefined}
            aria-pressed={summary.filter ? showMissingOnly : undefined}
            onClick={summary.filter ? () => setShowMissingOnly((current) => !current) : undefined}
            onKeyDown={summary.filter ? (event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                setShowMissingOnly((current) => !current)
              }
            } : undefined}
            className={summary.filter
              ? `cursor-pointer transition-all hover:border-amber-400 hover:shadow-sm ${showMissingOnly ? 'border-amber-500 ring-2 ring-amber-500/20' : ''}`
              : undefined}
          >
            <CardContent className="flex items-center justify-between p-5">
              <div>
                <p className="text-sm text-muted-foreground">{summary.label}</p>
                <p className="mt-1 text-3xl font-bold">{isLoading ? '—' : summary.value}</p>
                {summary.filter && <p className="mt-1 text-xs text-muted-foreground">Click to {showMissingOnly ? 'show all' : 'view contacts'}</p>}
              </div>
              <div className={`rounded-xl p-3 ${summary.style}`}><Icon icon={summary.icon} className="h-6 w-6" /></div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        {([
          ['Customers', contacts.filter((item) => item.kind === 'Customer').length, '/customers', 'solar:users-group-rounded-bold-duotone'],
          ['Vendors', contacts.filter((item) => item.kind === 'Vendor').length, '/accounting/vendors', 'solar:buildings-2-bold-duotone'],
          ['Employees', contacts.filter((item) => item.kind === 'Employee').length, '/hrm/employees', 'solar:user-id-bold-duotone'],
        ] as const).map(([label, count, href, icon]) => (
          <Card key={label}><CardContent className="flex items-center justify-between p-5"><div className="flex items-center gap-3"><Icon icon={icon} className="h-7 w-7 text-primary" /><div><p className="font-medium">{label}</p><p className="text-sm text-muted-foreground">{count} contacts</p></div></div><Button variant="ghost" size="sm" asChild><Link to={href}>View <Icon icon="solar:arrow-right-linear" className="ml-1 h-4 w-4" /></Link></Button></CardContent></Card>
        ))}
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div className="flex items-center gap-2">
                <CardTitle>{showMissingOnly ? 'Contacts missing details' : 'Contact directory'}</CardTitle>
                {showMissingOnly && <Badge variant="secondary">{filteredContacts.length}</Badge>}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">{showMissingOnly ? 'Contacts without an email address or phone number' : 'Unified visibility across customers, vendors, and employees'}</p>
            </div>
            <div className="flex w-full gap-2 sm:w-auto">
              {showMissingOnly && <Button variant="outline" size="sm" onClick={() => setShowMissingOnly(false)}>Clear filter</Button>}
              <div className="relative flex-1 sm:w-72"><Icon icon="solar:magnifer-linear" className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search contacts" className="pl-9" /></div>
            </div>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader><TableRow><TableHead>Contact</TableHead><TableHead>Type</TableHead><TableHead>Contact details</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
              <TableBody>
                {!isLoading && filteredContacts.length === 0 && <TableRow><TableCell colSpan={4} className="h-24 text-center text-muted-foreground">No contacts found.</TableCell></TableRow>}
                {filteredContacts.slice(0, 12).map((contact) => (
                  <TableRow key={contact.key}><TableCell><Link to={contact.href} className="font-medium hover:underline">{contact.name}</Link><p className="text-xs text-muted-foreground">{contact.detail || 'No organization'}</p></TableCell><TableCell><Badge variant="outline">{contact.kind}</Badge></TableCell><TableCell><p className="text-sm">{contact.email || 'No email'}</p><p className="text-xs text-muted-foreground">{contact.phone || 'No phone'}</p></TableCell><TableCell><Badge variant={contact.active ? 'default' : 'secondary'} className="capitalize">{contact.status}</Badge></TableCell></TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Recent activity</CardTitle><p className="text-sm text-muted-foreground">Recently added or updated contacts</p></CardHeader>
          <CardContent className="space-y-3">
            {recentContacts.map((contact) => (
              <Link key={contact.key} to={contact.href} className="flex items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50"><div className="rounded-full bg-primary/10 p-2 text-primary"><Icon icon={contact.kind === 'Customer' ? 'solar:user-bold-duotone' : contact.kind === 'Vendor' ? 'solar:buildings-2-bold-duotone' : 'solar:user-id-bold-duotone'} className="h-4 w-4" /></div><div className="min-w-0"><p className="truncate text-sm font-medium">{contact.name}</p><p className="text-xs text-muted-foreground">{contact.kind} · {new Date(contact.createdAt!).toLocaleDateString()}</p></div></Link>
            ))}
            {!isLoading && recentContacts.length === 0 && <div className="py-10 text-center text-sm text-muted-foreground">No recent contact activity.</div>}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
