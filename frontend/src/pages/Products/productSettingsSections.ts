/**
 * Single source of truth for the product-settings sections: label, icon and
 * description. Consumed by the Product Settings page (section nav) and by each
 * manager component (section card header) so the two can never drift apart.
 */
export interface ProductSettingsSectionMeta {
  title: string
  description: string
  icon: string
}

export const PRODUCT_SETTINGS_SECTIONS: Record<string, ProductSettingsSectionMeta> = {
  categories: {
    title: 'Product Categories',
    description: 'Create clear hierarchies like Men / Shoes / Lifestyle Shoes',
    icon: 'solar:folder-bold-duotone',
  },
  brands: {
    title: 'Brands',
    description: 'Manufacturer and brand names',
    icon: 'solar:star-bold-duotone',
  },
  units: {
    title: 'Units',
    description: 'How products are counted or measured (pcs, kg, m)',
    icon: 'solar:ruler-cross-pen-bold-duotone',
  },
  attributes: {
    title: 'Attributes',
    description: 'Reusable options such as colour and size',
    icon: 'solar:list-check-bold-duotone',
  },
  features: {
    title: 'Features',
    description: 'Feature chips highlighted on product pages',
    icon: 'solar:widget-bold-duotone',
  },
  taxes: {
    title: 'Taxes',
    description: 'Tax rates applied to products and documents',
    icon: 'solar:calculator-bold-duotone',
  },
  meta: {
    title: 'Custom Fields (Meta)',
    description: 'Extra fields captured on every product',
    icon: 'solar:tag-bold-duotone',
  },
}
