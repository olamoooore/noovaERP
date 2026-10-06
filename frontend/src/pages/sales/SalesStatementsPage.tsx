import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { SingleStatementPanel } from '@/components/reports/SingleStatementPanel'
import { BatchStatementPanel } from '@/components/reports/BatchStatementPanel'

/**
 * Issue #607 (RPT-F1) — the Sales-side "Statements" surface. Gated by the
 * existing customers.view permission (not reports.view, which SALES lacks),
 * so sales users generate statements without going to Accounting.
 */
export function SalesStatementsPage() {
  const [activeTab, setActiveTab] = useState('single')

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Customer Statements</h1>
        <p className="mt-1 text-muted-foreground">
          Generate account statements with aging for one customer or in batch, then print, email or send them.
        </p>
      </div>

      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList className="h-10 w-fit rounded-full">
          <TabsTrigger value="single" className="gap-2 rounded-full px-4">
            <Icon icon="solar:user-id-bold" className="h-4 w-4" />
            Single
          </TabsTrigger>
          <TabsTrigger value="batch" className="gap-2 rounded-full px-4">
            <Icon icon="solar:users-group-rounded-bold" className="h-4 w-4" />
            Batch
          </TabsTrigger>
        </TabsList>

        <TabsContent value="single">
          <SingleStatementPanel />
        </TabsContent>

        <TabsContent value="batch">
          <BatchStatementPanel />
        </TabsContent>
      </Tabs>
    </div>
  )
}

export default SalesStatementsPage
