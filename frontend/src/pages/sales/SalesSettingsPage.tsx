import { useState } from 'react'
import { Icon } from '@iconify/react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Card } from '@/components/ui/card'
import { cn } from '@/lib/utils'
import TemplateListPage from '@/pages/TemplateBuilder/TemplateListPage'
import {
  NumberingSettings,
  ApprovalSettings,
  InvoiceDefaultsSettings,
  PaymentPortalSettings,
  TaxPricingSettings,
  DeliverySettings,
} from './SalesSettingsSections'

type SettingsSection = {
  key: string
  title: string
  description: string
  icon: string
  component: React.ComponentType
}

const SECTIONS: SettingsSection[] = [
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
        <TabsList className="h-11 w-full max-w-md justify-start gap-1 rounded-lg bg-muted/60 p-1">
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
            {/* Section navigation */}
            <Card className="w-full shrink-0 overflow-hidden p-2 lg:w-72">
              <nav className="flex flex-col gap-1">
                {SECTIONS.map((section) => {
                  const isActive = activeSection === section.key
                  return (
                    <button
                      key={section.key}
                      type="button"
                      onClick={() => setActiveSection(section.key)}
                      className={cn(
                        'group flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-all',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        isActive
                          ? 'bg-primary/10 text-foreground shadow-sm ring-1 ring-primary/20'
                          : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                      )}
                    >
                      <div
                        className={cn(
                          'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors',
                          isActive
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'bg-muted text-muted-foreground group-hover:bg-background',
                        )}
                      >
                        <Icon icon={section.icon} className="h-4 w-4" />
                      </div>
                      <div className="min-w-0 flex-1 space-y-0.5">
                        <div
                          className={cn(
                            'text-sm font-medium leading-tight',
                            isActive && 'text-primary',
                          )}
                        >
                          {section.title}
                        </div>
                        <div className="text-[11px] leading-tight text-muted-foreground line-clamp-2">
                          {section.description}
                        </div>
                      </div>
                      {isActive && (
                        <Icon
                          icon="solar:alt-arrow-right-bold-duotone"
                          className="mt-1.5 h-3.5 w-3.5 shrink-0 text-primary"
                        />
                      )}
                    </button>
                  )
                })}
              </nav>
            </Card>

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
