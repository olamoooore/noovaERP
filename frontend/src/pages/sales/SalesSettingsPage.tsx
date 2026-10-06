import { useState } from 'react'
import { Icon } from '@iconify/react'
import { useMutation } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useToast } from '@/hooks/use-toast'
import api from '@/lib/api'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import TemplateListPage from '@/pages/TemplateBuilder/TemplateListPage'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import {
  SettingsDirtyScope,
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui'
import { SettingsHighlightContext, useSettingsLeaveGuard } from '@/components/settings/settings-leave-guard'
import {
  NumberingSettings,
  ApprovalSettings,
  InvoiceDefaultsSettings,
  SalesDefaultsSettings,
  PaymentPortalSettings,
  TaxPricingSettings,
  CashRoundingSettings,
  DeliverySettings,
} from './SalesSettingsSections'
import { buildSalesSettingsExport, searchSalesSettings, type NumberingFormatsPayload } from './salesSettingsTools'

/** #549: save the export as a JSON file. */
function downloadJson(filename: string, payload: unknown) {
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

const SECTIONS: SettingsSectionDef[] = [
  {
    key: 'numbering',
    title: 'Document Numbering',
    description: 'Invoice, quotation & DO number formats',
    icon: 'solar:hashtag-circle-bold-duotone',
    component: NumberingSettings,
  },
  {
    key: 'approvals',
    title: 'Approval Workflow',
    description: 'Review requirements and expiry rules',
    icon: 'solar:checklist-bold-duotone',
    component: ApprovalSettings,
  },
  {
    key: 'defaults',
    title: 'Invoice Defaults',
    description: 'Terms, notes, and posting behaviour',
    icon: 'solar:document-add-bold-duotone',
    component: InvoiceDefaultsSettings,
  },
  {
    key: 'sales-defaults',
    title: 'Sales Defaults',
    description: 'Default salesperson, team and pricelist display',
    icon: 'solar:user-hand-up-bold-duotone',
    component: SalesDefaultsSettings,
  },
  {
    key: 'payments',
    title: 'Payments & Portal',
    description: 'Customer acceptance, signatures & payment links',
    icon: 'solar:card-bold-duotone',
    component: PaymentPortalSettings,
  },
  {
    key: 'tax',
    title: 'Tax & Pricing',
    description: 'Default rates, treatments and price basis',
    icon: 'solar:sale-bold-duotone',
    component: TaxPricingSettings,
  },
  {
    key: 'rounding',
    title: 'Cash Rounding',
    description: 'Round invoice totals and post the difference',
    icon: 'solar:calculator-minimalistic-bold-duotone',
    component: CashRoundingSettings,
  },
  {
    key: 'delivery',
    title: 'Delivery & Sending',
    description: 'Attachments, CC addresses and document type',
    icon: 'solar:plain-2-bold-duotone',
    component: DeliverySettings,
  },
]

export function SalesSettingsPage() {
  const [activeSection, setActiveSection] = useState<string>('numbering')
  const [activeTab, setActiveTab] = useState<string>('settings')
  // #546: each section keeps unsaved edits in local state, and swapping the
  // section (or tab) unmounts it — so confirm before throwing edits away.
  const leaveGuard = useSettingsLeaveGuard()
  // #549: search across every section; picking a result opens its section
  // (through the leave guard) and outlines the matching setting.
  const [query, setQuery] = useState('')
  const [highlight, setHighlight] = useState<string | null>(null)
  const results = searchSalesSettings(query)
  const { toast } = useToast()

  const exportMutation = useMutation({
    mutationFn: async () => {
      // Fresh reads, so the file reflects what is saved right now.
      const [settings, formats] = await Promise.all([
        api.get('/settings/sales').then((res) => (res.data?.data ?? {}) as Record<string, unknown>),
        api.get('/settings/document-formats').then((res) => res.data as NumberingFormatsPayload),
      ])
      const payload = buildSalesSettingsExport(settings, formats)
      downloadJson(`sales-settings-${payload.exported_at.slice(0, 10)}.json`, payload)
    },
    onError: (error: any) => {
      toast({
        variant: 'destructive',
        title: 'Export failed',
        description: error.response?.data?.message || 'Please try again.',
      })
    },
  })

  const openResult = (section: string, title: string) => {
    const go = () => {
      setActiveSection(section)
      setHighlight(title)
      setQuery('')
    }
    if (section === activeSection) go()
    else leaveGuard.guard(go)
  }
  const ActiveComponent =
    SECTIONS.find((s) => s.key === activeSection)?.component ?? NumberingSettings

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Sales Settings</h1>
          <p className="mt-1 text-muted-foreground">
            Configure sales documents, workflow rules, and customer-facing behaviour for this tenant.
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => exportMutation.mutate()}
          disabled={exportMutation.isPending}
        >
          <Icon icon="solar:export-bold-duotone" className="mr-2 h-4 w-4" />
          Export settings
        </Button>
      </div>

      <Tabs
        value={activeTab}
        onValueChange={(tab) => leaveGuard.guard(() => setActiveTab(tab))}
        className="space-y-6"
      >
        <TabsList className="h-10 w-fit max-w-full justify-start gap-1 bg-muted p-1">
          <TabsTrigger value="settings" className="gap-2 px-5">
            <Icon icon="solar:settings-bold-duotone" className="h-4 w-4" />
            <span>Settings</span>
          </TabsTrigger>
          <TabsTrigger value="templates" className="gap-2 px-5">
            <Icon icon="solar:document-text-bold-duotone" className="h-4 w-4" />
            <span>Template Builder</span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings" className="mt-0 space-y-4">
          <div className="relative max-w-md">
            <Icon
              icon="solar:magnifer-linear"
              className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search settings…"
              aria-label="Search settings"
              className="pl-9"
            />
            {query.trim() !== '' && (
              <div
                role="listbox"
                aria-label="Matching settings"
                className="absolute z-20 mt-1 max-h-80 w-full overflow-y-auto rounded-md border bg-popover p-1 shadow-md"
              >
                {results.length === 0 ? (
                  <p className="px-3 py-2 text-sm text-muted-foreground">No settings match “{query.trim()}”.</p>
                ) : (
                  results.map((r) => (
                    <button
                      key={`${r.section}:${r.title}`}
                      type="button"
                      role="option"
                      aria-selected={false}
                      onClick={() => openResult(r.section, r.title)}
                      className="flex w-full flex-col items-start rounded-sm px-3 py-2 text-left hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                    >
                      <span className="text-sm font-medium">{r.title}</span>
                      <span className="text-xs text-muted-foreground">{r.sectionTitle}</span>
                    </button>
                  ))
                )}
              </div>
            )}
          </div>
          <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
            <SettingsSectionNav
              sections={SECTIONS}
              activeKey={activeSection}
              onSelect={(key) => {
                if (key !== activeSection)
                  leaveGuard.guard(() => {
                    setActiveSection(key)
                    setHighlight(null)
                  })
              }}
              dirtyKey={leaveGuard.dirty ? activeSection : undefined}
            />

            {/* Active section content */}
            <div className="min-w-0 flex-1">
              <SettingsDirtyScope reportDirty={leaveGuard.reportDirty}>
                <SettingsHighlightContext.Provider value={highlight}>
                  <ActiveComponent />
                </SettingsHighlightContext.Provider>
              </SettingsDirtyScope>
            </div>
          </div>
        </TabsContent>

        <TabsContent value="templates" className="mt-0">
          <TemplateListPage embedded />
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={leaveGuard.confirmOpen}
        onOpenChange={(open) => {
          if (!open) leaveGuard.keepEditing()
        }}
        title="Discard unsaved changes?"
        description="This section has changes that are not saved yet. Switching now discards them."
        confirmLabel="Discard and switch"
        cancelLabel="Keep editing"
        tone="warning"
        onConfirm={leaveGuard.confirmDiscard}
      />
    </div>
  )
}
