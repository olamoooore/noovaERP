import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import { useAuthStore } from '@/store/authStore'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { RegisterPaymentDialog } from '@/components/payment/RegisterPaymentDialog'
import { isInvoicePayable, REGISTER_PAYMENT_PERMISSION } from '@/lib/registerPayment'
import { isCommandBarClaimed, isCommandBarKey, isTypingTarget } from '@/lib/keyboardShortcuts'

// #347 (D10): global Ctrl/⌘+K command bar — keyboard-only entry points for
// sales documents. Every command carries the same permission the matching
// button / API route checks, so the bar never offers what the server refuses.

type Mode = 'open' | 'pay' | 'customer'

interface ParsedInput {
  mode: Mode | null
  term: string
}

/** Deterministic grammar: `open <no>`, `pay <invoice no>`, `customer <name>`, or a bare document number. */
function parseCommandInput(raw: string): ParsedInput {
  const text = raw.trim()
  const prefixed = text.match(/^(open|pay|payment|customer|cust)\s+(.+)$/i)
  if (prefixed) {
    const keyword = prefixed[1].toLowerCase()
    const mode: Mode = keyword === 'open' ? 'open' : keyword.startsWith('pay') ? 'pay' : 'customer'
    return { mode, term: prefixed[2].trim() }
  }
  // Document numbers carry digits (INV-2026-0009, SO-0005): look them up directly.
  if (/\d/.test(text) && text.length >= 3 && !/\s/.test(text)) return { mode: 'open', term: text }
  return { mode: null, term: text }
}

interface StaticCommand {
  id: string
  label: string
  keywords: string
  /** Same permission string as the matching button / API route ('a|b' = any of). */
  permission: string
  run: () => void
}

interface DocumentHit {
  key: string
  number: string
  subtitle: string
  url: string
}

// List rows are loosely typed API payloads (invoice / quotation / order / customer).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Row = Record<string, any>

/** List endpoints answer a paginator (`{ data: [...] }`) or a wrapped one (`{ data: { data } }`). */
const rowsOf = (body: unknown): Row[] => {
  const outer = body as { data?: unknown } | null
  const inner = outer?.data as { data?: unknown } | undefined
  if (Array.isArray(inner?.data)) return inner.data as Row[]
  if (Array.isArray(outer?.data)) return outer.data as Row[]
  return Array.isArray(body) ? (body as Row[]) : []
}

const searchList = async (url: string, term: string): Promise<Row[]> => {
  const res = await api.get(url, { params: { search: term, per_page: 8 } })
  return rowsOf(res.data)
}

function useDebounced(value: string, delayMs = 200) {
  const [debounced, setDebounced] = useState(value)
  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(timer)
  }, [value, delayMs])
  return debounced
}

const customerName = (row: Row): string => String(row.customer?.name ?? row.customer_name ?? '')

export function CommandBar() {
  const navigate = useNavigate()
  // Subscribing to permissions/role re-renders on login or tenant switch.
  useAuthStore((state) => state.permissions)
  useAuthStore((state) => state.role)
  const hasPermission = useAuthStore((state) => state.hasPermission)

  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const [paymentInvoiceId, setPaymentInvoiceId] = useState<number | null>(null)
  const [standalonePayment, setStandalonePayment] = useState(false)

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (!isCommandBarKey(event)) return
      // A page with its own document command bar (invoice form) owns Ctrl+K.
      if (isCommandBarClaimed() || event.defaultPrevented) return
      // Never while typing in a field.
      if (isTypingTarget(event.target)) return
      event.preventDefault()
      setOpen((current) => !current)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  const close = () => {
    setOpen(false)
    setText('')
  }

  const go = (url: string) => {
    close()
    navigate(url)
  }

  const canViewInvoices = hasPermission('invoices.view')
  const canViewQuotations = hasPermission('quotations.view')
  const canViewOrders = hasPermission('sales.view')
  const canViewCustomers = hasPermission('customers.view')
  const canPay = hasPermission(REGISTER_PAYMENT_PERMISSION)

  const staticCommands: StaticCommand[] = [
    { id: 'new-quotation', label: 'New quotation', keywords: 'create quote quotation new', permission: 'quotations.write', run: () => go('/quotations/create') },
    { id: 'new-order', label: 'New sales order', keywords: 'create order so sales new', permission: 'sales.write', run: () => go('/sales/orders/create') },
    { id: 'new-invoice', label: 'New invoice', keywords: 'create invoice bill new', permission: 'invoices.write', run: () => go('/invoices/create') },
    {
      id: 'new-payment',
      label: 'New payment',
      keywords: 'create payment receipt receive record new',
      permission: REGISTER_PAYMENT_PERMISSION,
      run: () => {
        close()
        setStandalonePayment(true)
      },
    },
    {
      id: 'pay-invoice',
      label: 'Register payment for an invoice… (pay <invoice no>)',
      keywords: 'pay payment receive invoice',
      permission: REGISTER_PAYMENT_PERMISSION,
      run: () => setText('pay '),
    },
    { id: 'customer', label: 'Go to customer… (customer <name>)', keywords: 'customer client go', permission: 'customers.view', run: () => setText('customer ') },
    {
      id: 'open',
      label: 'Open document by number… (open <no>)',
      keywords: 'open find document number quotation order invoice',
      permission: 'invoices.view|quotations.view|sales.view',
      run: () => setText('open '),
    },
  ].filter((command) => hasPermission(command.permission) && (command.id !== 'pay-invoice' || canViewInvoices))

  const parsed = parseCommandInput(text)
  const term = useDebounced(parsed.term).trim()
  const searchable = open && term.length >= 2

  const invoicesQuery = useQuery({
    queryKey: ['command-bar', 'invoices', term],
    queryFn: () => searchList('/invoices', term),
    enabled: searchable && canViewInvoices && (parsed.mode === 'open' || (parsed.mode === 'pay' && canPay)),
    staleTime: 10_000,
  })
  const quotationsQuery = useQuery({
    queryKey: ['command-bar', 'quotations', term],
    queryFn: () => searchList('/quotations', term),
    enabled: searchable && parsed.mode === 'open' && canViewQuotations,
    staleTime: 10_000,
  })
  const ordersQuery = useQuery({
    queryKey: ['command-bar', 'orders', term],
    queryFn: () => searchList('/sales/orders', term),
    enabled: searchable && parsed.mode === 'open' && canViewOrders,
    staleTime: 10_000,
  })
  const customersQuery = useQuery({
    queryKey: ['command-bar', 'customers', term],
    queryFn: () => searchList('/customers', term),
    enabled: searchable && parsed.mode === 'customer' && canViewCustomers,
    staleTime: 10_000,
  })

  const tokens = text.trim().toLowerCase().split(/\s+/).filter(Boolean)
  const visibleStatic = parsed.mode
    ? []
    : staticCommands.filter((command) => {
        const haystack = `${command.label} ${command.keywords}`.toLowerCase()
        return tokens.every((token) => haystack.includes(token))
      })

  const documentGroups: Array<{ heading: string; hits: DocumentHit[] }> = []
  if (parsed.mode === 'open') {
    if (canViewQuotations) {
      documentGroups.push({
        heading: 'Quotations',
        hits: (quotationsQuery.data ?? []).map((row) => ({
          key: `q-${row.id}`, number: String(row.quotation_number ?? row.id), subtitle: customerName(row), url: `/quotations/${row.id}`,
        })),
      })
    }
    if (canViewOrders) {
      documentGroups.push({
        heading: 'Sales orders',
        hits: (ordersQuery.data ?? []).map((row) => ({
          key: `o-${row.id}`, number: String(row.sales_order_number ?? row.order_number ?? row.id), subtitle: customerName(row), url: `/sales/orders/${row.id}`,
        })),
      })
    }
    if (canViewInvoices) {
      documentGroups.push({
        heading: 'Invoices',
        hits: (invoicesQuery.data ?? []).map((row) => ({
          key: `i-${row.id}`, number: String(row.invoice_number ?? row.id), subtitle: customerName(row), url: `/invoices/${row.id}`,
        })),
      })
    }
  }

  // #508 rule: only posted/sent/partial/overdue invoices with an open balance.
  const payableInvoices = parsed.mode === 'pay' && canPay && canViewInvoices
    ? (invoicesQuery.data ?? []).filter((row) => isInvoicePayable(row))
    : []
  const customers = parsed.mode === 'customer' && canViewCustomers ? (customersQuery.data ?? []) : []

  return (
    <>
      <Dialog open={open} onOpenChange={(next) => (next ? setOpen(true) : close())}>
        <DialogContent className="max-w-xl gap-3 p-4">
          <DialogHeader>
            <DialogTitle>Command bar</DialogTitle>
            <DialogDescription>
              Type a command and press Enter: <span className="font-mono">open INV-0001</span> ·{' '}
              <span className="font-mono">pay INV-0001</span> · <span className="font-mono">customer acme</span>
            </DialogDescription>
          </DialogHeader>
          <Command shouldFilter={false} className="rounded-md border">
            <CommandInput
              autoFocus
              value={text}
              onValueChange={setText}
              placeholder="Type a command or a document number…"
            />
            <CommandList>
              <CommandEmpty>No matching command.</CommandEmpty>
              {visibleStatic.length > 0 && (
                <CommandGroup heading="Commands">
                  {visibleStatic.map((command) => (
                    <CommandItem key={command.id} value={command.id} onSelect={command.run}>
                      {command.label}
                    </CommandItem>
                  ))}
                </CommandGroup>
              )}
              {documentGroups.filter((group) => group.hits.length > 0).map((group) => (
                <CommandGroup key={group.heading} heading={group.heading}>
                  {group.hits.map((hit) => (
                    <CommandItem key={hit.key} value={hit.key} onSelect={() => go(hit.url)}>
                      <span className="font-medium">{hit.number}</span>
                      {hit.subtitle && <span className="ml-2 truncate text-xs text-muted-foreground">{hit.subtitle}</span>}
                    </CommandItem>
                  ))}
                </CommandGroup>
              ))}
              {payableInvoices.length > 0 && (
                <CommandGroup heading="Register payment for">
                  {payableInvoices.map((row) => (
                    <CommandItem
                      key={`pay-${row.id}`}
                      value={`pay-${row.id}`}
                      onSelect={() => {
                        close()
                        setPaymentInvoiceId(Number(row.id))
                      }}
                    >
                      <span className="font-medium">{String(row.invoice_number ?? row.id)}</span>
                      <span className="ml-2 truncate text-xs text-muted-foreground">
                        {customerName(row)} · balance {Number(row.balance ?? 0).toFixed(2)}
                      </span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              )}
              {customers.length > 0 && (
                <CommandGroup heading="Customers">
                  {customers.map((row) => (
                    <CommandItem key={`c-${row.id}`} value={`c-${row.id}`} onSelect={() => go(`/customers/${row.id}`)}>
                      <span className="font-medium">{String(row.name ?? '')}</span>
                      {row.code && <span className="ml-2 text-xs text-muted-foreground">{String(row.code)}</span>}
                    </CommandItem>
                  ))}
                </CommandGroup>
              )}
            </CommandList>
          </Command>
        </DialogContent>
      </Dialog>

      {/* #508: the same in-place payment dialog the invoice row's "P" opens. */}
      <RegisterPaymentDialog
        invoiceId={paymentInvoiceId}
        open={standalonePayment || paymentInvoiceId != null}
        onOpenChange={(next) => {
          if (!next) {
            setPaymentInvoiceId(null)
            setStandalonePayment(false)
          }
        }}
      />
    </>
  )
}
