import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { prettyLabel } from './format'

const today = () => new Date().toISOString().slice(0, 10)
const daysAgo = (n: number) => new Date(Date.now() - n * 864e5).toISOString().slice(0, 10)

function Kpi({ label, value, icon, tone }: { label: string; value: string | number; icon: string; tone?: string }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${tone ?? 'bg-muted'}`}><Icon icon={icon} className="h-5 w-5" /></span>
        <div><div className="text-2xl font-bold">{value}</div><div className="text-xs text-muted-foreground">{label}</div></div>
      </CardContent>
    </Card>
  )
}

export function StorefrontReportsPage() {
  const { toast } = useToast()
  const [tab, setTab] = useState('overview')
  const [from, setFrom] = useState(daysAgo(29))
  const [to, setTo] = useState(today())

  const range = { from, to }
  const useReport = (kind: string, withRange = true) => useQuery({
    queryKey: ['storefront-report', kind, withRange ? from : '', withRange ? to : ''],
    queryFn: async () => (await api.get(`/admin/storefront/reports/${kind}`, { params: withRange ? range : {} })).data.data,
  })

  const overview = useReport('overview', false)
  const sales = useReport('sales')
  const tax = useReport('tax')
  const funnel = useReport('funnel')
  const recon = useReport('reconciliation')

  const downloadCsv = async (kind: string) => {
    try {
      const resp = await api.get(`/admin/storefront/reports/${kind}`, { params: { ...range, format: 'csv' }, responseType: 'blob' })
      const url = URL.createObjectURL(resp.data as Blob)
      const a = document.createElement('a')
      a.href = url; a.download = `storefront-${kind}-${to.replace(/-/g, '')}.csv`; a.click()
      URL.revokeObjectURL(url)
    } catch { toast({ title: 'Export failed', variant: 'destructive' }) }
  }

  const ov = overview.data
  const s = sales.data
  const t = tax.data
  const f = funnel.data
  const r = recon.data

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h1 className="text-3xl font-bold">Reports</h1>
          <p className="text-muted-foreground">Sales, tax, funnel and settlement reconciliation — all derived from live order/transaction state.</p></div>
        <div className="flex items-end gap-2">
          <div><Label className="text-xs text-muted-foreground">From</Label><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
          <div><Label className="text-xs text-muted-foreground">To</Label><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
        </div>
      </div>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="sales">Sales</TabsTrigger>
          <TabsTrigger value="tax">Tax</TabsTrigger>
          <TabsTrigger value="funnel">Funnel</TabsTrigger>
          <TabsTrigger value="reconciliation">Reconciliation</TabsTrigger>
        </TabsList>

        {/* Overview */}
        <TabsContent value="overview" className="mt-4">
          {ov ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Kpi label="Awaiting payment" value={ov.awaiting_payment_orders} icon="solar:wallet-money-bold-duotone" tone="bg-amber-100 text-amber-700" />
              <Kpi label="Offline proofs pending" value={ov.offline_proofs_pending} icon="solar:file-check-bold-duotone" tone="bg-blue-100 text-blue-700" />
              <Kpi label="Unfulfilled orders" value={ov.unfulfilled_orders} icon="solar:box-bold-duotone" tone="bg-indigo-100 text-indigo-700" />
              <Kpi label="Unsettled gateway funds" value={`${ov.unsettled_gateway_funds?.count ?? 0}`} icon="solar:card-bold-duotone" tone="bg-purple-100 text-purple-700" />
              <Kpi label="Failed payments (7d)" value={ov.failed_payments_7d} icon="solar:danger-triangle-bold-duotone" tone="bg-rose-100 text-rose-700" />
              <Kpi label="Low-stock products" value={ov.low_stock_products} icon="solar:box-minimalistic-bold-duotone" tone="bg-orange-100 text-orange-700" />
              <Kpi label="Failed messages (24h)" value={ov.failed_messages_24h} icon="solar:letter-bold-duotone" tone="bg-neutral-200 text-neutral-700" />
              <Kpi label="Revenue today" value={(ov.revenue_today ?? []).map((x: { currency: string; gross: string }) => `${x.currency} ${x.gross}`).join(' · ') || '—'} icon="solar:dollar-bold-duotone" tone="bg-green-100 text-green-700" />
            </div>
          ) : <Loading />}
        </TabsContent>

        {/* Sales */}
        <TabsContent value="sales" className="mt-4 space-y-4">
          <ExportBar onExport={() => downloadCsv('sales')} />
          {s ? (<>
            <Card><CardHeader className="pb-2"><CardTitle className="text-base">Paid by currency</CardTitle></CardHeader>
              <CardContent className="p-0"><Table>
                <TableHeader><TableRow><TableHead>Currency</TableHead><TableHead className="text-right">Orders</TableHead><TableHead className="text-right">Subtotal</TableHead><TableHead className="text-right">Discount</TableHead><TableHead className="text-right">Tax</TableHead><TableHead className="text-right">Gross</TableHead></TableRow></TableHeader>
                <TableBody>{(s.paid_by_currency ?? []).map((x: Record<string, string>, i: number) => (
                  <TableRow key={i}><TableCell>{x.currency}</TableCell><TableCell className="text-right">{x.orders}</TableCell><TableCell className="text-right">{x.subtotal}</TableCell><TableCell className="text-right">{x.discount}</TableCell><TableCell className="text-right">{x.tax}</TableCell><TableCell className="text-right font-medium">{x.gross}</TableCell></TableRow>
                ))}{(s.paid_by_currency ?? []).length === 0 && <TableRow><TableCell colSpan={6} className="text-center text-muted-foreground">No paid orders in range.</TableCell></TableRow>}</TableBody>
              </Table></CardContent></Card>
            <Card><CardHeader className="pb-2"><CardTitle className="text-base">Channel capture</CardTitle></CardHeader>
              <CardContent className="p-0"><Table>
                <TableHeader><TableRow><TableHead>Channel</TableHead><TableHead className="text-right">Transactions</TableHead><TableHead className="text-right">Captured</TableHead></TableRow></TableHeader>
                <TableBody>{(s.channel_capture ?? []).map((x: Record<string, string>, i: number) => (
                  <TableRow key={i}><TableCell>{prettyLabel(x.channel)}</TableCell><TableCell className="text-right">{x.transactions}</TableCell><TableCell className="text-right">{x.captured}</TableCell></TableRow>
                ))}</TableBody>
              </Table></CardContent></Card>
          </>) : <Loading />}
        </TabsContent>

        {/* Tax */}
        <TabsContent value="tax" className="mt-4 space-y-4">
          <ExportBar onExport={() => downloadCsv('tax')} />
          {t ? (
            <Card><CardHeader className="pb-2"><CardTitle className="text-base">Tax by rate <span className="text-xs font-normal text-muted-foreground">({t.basis})</span></CardTitle></CardHeader>
              <CardContent className="p-0"><Table>
                <TableHeader><TableRow><TableHead>Rate</TableHead><TableHead>Currency</TableHead><TableHead className="text-right">Base</TableHead><TableHead className="text-right">Tax</TableHead></TableRow></TableHeader>
                <TableBody>{(t.by_rate ?? []).map((x: Record<string, string | number>, i: number) => (
                  <TableRow key={i}><TableCell>{x.tax_rate}%</TableCell><TableCell>{x.currency}</TableCell><TableCell className="text-right">{x.base}</TableCell><TableCell className="text-right">{x.tax}</TableCell></TableRow>
                ))}{(t.by_rate ?? []).length === 0 && <TableRow><TableCell colSpan={4} className="text-center text-muted-foreground">No tax in range.</TableCell></TableRow>}</TableBody>
              </Table></CardContent></Card>
          ) : <Loading />}
        </TabsContent>

        {/* Funnel */}
        <TabsContent value="funnel" className="mt-4 space-y-4">
          <ExportBar onExport={() => downloadCsv('funnel')} />
          {f ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Kpi label="Carts created" value={f.carts?.created ?? 0} icon="solar:cart-large-bold-duotone" />
              <Kpi label="Converted to orders" value={f.carts?.converted_to_orders ?? 0} icon="solar:cart-check-bold-duotone" tone="bg-green-100 text-green-700" />
              <Kpi label="Abandoned" value={f.carts?.abandoned ?? 0} icon="solar:cart-cross-bold-duotone" tone="bg-rose-100 text-rose-700" />
              <Kpi label="Orders placed" value={f.orders?.placed ?? 0} icon="solar:bag-bold-duotone" />
              <Kpi label="Orders paid" value={f.orders?.paid ?? 0} icon="solar:bag-check-bold-duotone" tone="bg-green-100 text-green-700" />
              <Kpi label="Placement → payment" value={f.orders?.placement_to_payment_pct != null ? `${f.orders.placement_to_payment_pct}%` : '—'} icon="solar:chart-bold-duotone" tone="bg-blue-100 text-blue-700" />
            </div>
          ) : <Loading />}
        </TabsContent>

        {/* Reconciliation */}
        <TabsContent value="reconciliation" className="mt-4 space-y-4">
          <ExportBar onExport={() => downloadCsv('reconciliation')} />
          {r ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Kpi label="Captured txns" value={r.captured?.transactions ?? 0} icon="solar:card-transfer-bold-duotone" />
              <Kpi label="Captured amount" value={r.captured?.amount ?? '0'} icon="solar:dollar-bold-duotone" tone="bg-green-100 text-green-700" />
              <Kpi label="Refunded" value={r.captured?.refunded ?? '0'} icon="solar:rewind-back-bold-duotone" tone="bg-rose-100 text-rose-700" />
              <Kpi label="Settlement net" value={r.settlements?.net ?? '0'} icon="solar:bill-check-bold-duotone" tone="bg-blue-100 text-blue-700" />
              <Kpi label="Unsettled" value={`${r.unsettled?.count ?? 0} · ${r.unsettled?.amount ?? '0'}`} icon="solar:hourglass-bold-duotone" tone="bg-amber-100 text-amber-700" />
              <Kpi label="Ledger balanced" value={r.ledger_tie?.balanced ? 'Yes' : 'No'} icon="solar:scale-bold-duotone" tone={r.ledger_tie?.balanced ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700'} />
            </div>
          ) : <Loading />}
        </TabsContent>
      </Tabs>
    </div>
  )
}

function Loading() { return <div className="flex h-40 items-center justify-center text-muted-foreground"><Icon icon="solar:loading-bold-duotone" className="h-6 w-6 animate-spin" /></div> }
function ExportBar({ onExport }: { onExport: () => void }) {
  return <div className="flex justify-end"><Button variant="outline" size="sm" onClick={onExport}><Icon icon="solar:download-minimalistic-bold-duotone" className="mr-1 h-4 w-4" /> Export CSV</Button></div>
}

export default StorefrontReportsPage
