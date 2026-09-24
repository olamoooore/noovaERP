import { Link } from 'react-router-dom'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { CategoryManager } from '@/pages/Products/CategoryManager'
import { UnitManager } from '@/pages/Products/UnitManager'
import { AttributeManager } from '@/pages/Products/AttributeManager'
import { FeatureManager } from '@/pages/Products/FeatureManager'
import { MetaManager } from '@/pages/Products/MetaManager'
import { BrandManager } from '@/pages/Products/BrandManager'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'

export function ProductSettingsPage() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Product Settings</h1>
          <div className="text-sm text-muted-foreground">
            Configure product catalog settings.
          </div>
        </div>

        <HeaderActionButton icon="solar:arrow-left-bold" variant="secondary" asChild>
          <Link to="/products">
            Back to Products
          </Link>
        </HeaderActionButton>
      </div>

      <Tabs defaultValue="categories" className="w-full">
        <TabsList className="grid w-full grid-cols-2 md:grid-cols-6">
          <TabsTrigger value="categories">Categories</TabsTrigger>
          <TabsTrigger value="brands">Brands</TabsTrigger>
          <TabsTrigger value="units">Units</TabsTrigger>
          <TabsTrigger value="attributes">Attributes</TabsTrigger>
          <TabsTrigger value="features">Features</TabsTrigger>
          <TabsTrigger value="meta">Meta</TabsTrigger>
        </TabsList>

        <TabsContent value="categories" className="mt-4">
          <CategoryManager />
        </TabsContent>

        <TabsContent value="brands" className="mt-4">
          <BrandManager />
        </TabsContent>

        <TabsContent value="units" className="mt-4">
          <UnitManager />
        </TabsContent>

        <TabsContent value="attributes" className="mt-4">
          <AttributeManager />
        </TabsContent>

        <TabsContent value="features" className="mt-4">
          <FeatureManager />
        </TabsContent>

        <TabsContent value="meta" className="mt-4">
          <MetaManager />
        </TabsContent>
      </Tabs>
    </div>
  )
}
