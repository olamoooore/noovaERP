import { Icon } from '@iconify/react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import TemplateListPage from '@/pages/TemplateBuilder/TemplateListPage'

export function SalesSettingsPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Sales Settings</h1>
        <p className="mt-1 text-muted-foreground">
          Configure sales documents and their printable templates.
        </p>
      </div>

      <Tabs defaultValue="templates" className="space-y-6">
        <TabsList className="w-full max-w-xs">
          <TabsTrigger value="templates">
            <Icon icon="solar:document-text-bold-duotone" className="mr-2 h-4 w-4" />
            Template Builder
          </TabsTrigger>
        </TabsList>

        <TabsContent value="templates">
          <TemplateListPage embedded />
        </TabsContent>

      </Tabs>
    </div>
  )
}
