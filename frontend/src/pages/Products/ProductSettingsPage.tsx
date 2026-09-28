import { useState } from 'react'
import { Link } from 'react-router-dom'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import {
  SettingsSectionNav,
  type SettingsSectionDef,
} from '@/components/settings/settings-ui'
import { CategoryManager } from '@/pages/Products/CategoryManager'
import { BrandManager } from '@/pages/Products/BrandManager'
import { UnitManager } from '@/pages/Products/UnitManager'
import { AttributeManager } from '@/pages/Products/AttributeManager'
import { FeatureManager } from '@/pages/Products/FeatureManager'
import { MetaManager } from '@/pages/Products/MetaManager'
import { TaxManager } from '@/pages/Products/TaxManager'
import { PRODUCT_SETTINGS_SECTIONS } from '@/pages/Products/productSettingsSections'

/**
 * Every catalog dimension a product can carry, in one place — the same
 * section-nav layout as Sales / Company / Inventory settings.
 */
const SECTIONS: SettingsSectionDef[] = [
  { key: 'categories', ...PRODUCT_SETTINGS_SECTIONS.categories, component: CategoryManager },
  { key: 'brands', ...PRODUCT_SETTINGS_SECTIONS.brands, component: BrandManager },
  { key: 'units', ...PRODUCT_SETTINGS_SECTIONS.units, component: UnitManager },
  { key: 'attributes', ...PRODUCT_SETTINGS_SECTIONS.attributes, component: AttributeManager },
  { key: 'features', ...PRODUCT_SETTINGS_SECTIONS.features, component: FeatureManager },
  { key: 'taxes', ...PRODUCT_SETTINGS_SECTIONS.taxes, component: TaxManager },
  { key: 'meta', ...PRODUCT_SETTINGS_SECTIONS.meta, component: MetaManager },
]

export function ProductSettingsPage() {
  const [activeSection, setActiveSection] = useState<string>('categories')
  const ActiveComponent =
    SECTIONS.find((section) => section.key === activeSection)?.component ?? CategoryManager

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Product Settings</h1>
          <p className="mt-1 text-muted-foreground">
            Configure product catalog settings — categories, brands, units, attributes, features,
            taxes and custom fields.
          </p>
        </div>
        <HeaderActionButton variant="outline" icon="solar:alt-arrow-left-bold-duotone" asChild>
          <Link to="/products">Back to Products</Link>
        </HeaderActionButton>
      </div>

      <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
        <SettingsSectionNav
          sections={SECTIONS}
          activeKey={activeSection}
          onSelect={setActiveSection}
        />

        <div className="min-w-0 flex-1">
          <ActiveComponent />
        </div>
      </div>
    </div>
  )
}
