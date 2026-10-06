import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import TemplateListPage from '@/pages/TemplateBuilder/TemplateListPage'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import {
  SettingsDirtyScope,
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui'
import { useSettingsLeaveGuard } from '@/components/settings/settings-leave-guard'
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
  const ActiveComponent =
    SECTIONS.find((s) => s.key === activeSection)?.component ?? NumberingSettings

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold tracking-tight">Sales Settings</h1>
        <p className="mt-1 text-muted-foreground">
          Configure sales documents, workflow rules, and customer-facing behaviour for this tenant.
        </p>
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

        <TabsContent value="settings" className="mt-0">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
            <SettingsSectionNav
              sections={SECTIONS}
              activeKey={activeSection}
              onSelect={(key) => {
                if (key !== activeSection) leaveGuard.guard(() => setActiveSection(key))
              }}
              dirtyKey={leaveGuard.dirty ? activeSection : undefined}
            />

            {/* Active section content */}
            <div className="min-w-0 flex-1">
              <SettingsDirtyScope reportDirty={leaveGuard.reportDirty}>
                <ActiveComponent />
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
