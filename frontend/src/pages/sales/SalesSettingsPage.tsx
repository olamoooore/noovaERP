import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import TemplateListPage from '@/pages/TemplateBuilder/TemplateListPage'
import { SettingsSectionNav, type SettingsSectionDef } from '@/components/settings/settings-ui'
import {
  NumberingSettings,
  ApprovalSettings,
  InvoiceDefaultsSettings,
  PaymentPortalSettings,
  TaxPricingSettings,
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
    key: 'delivery',
    title: 'Delivery & Sending',
    description: 'Attachments, CC addresses and document type',
    icon: 'solar:plain-2-bold-duotone',
    component: DeliverySettings,
  },
]

export function SalesSettingsPage() {
  const [activeSection, setActiveSection] = useState<string>('numbering')
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

      <Tabs defaultValue="settings" className="space-y-6">
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
              onSelect={setActiveSection}
            />

            {/* Active section content */}
            <div className="min-w-0 flex-1">
              <ActiveComponent />
            </div>
          </div>
        </TabsContent>

        <TabsContent value="templates" className="mt-0">
          <TemplateListPage embedded />
        </TabsContent>
      </Tabs>
    </div>
  )
}
