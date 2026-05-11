/**
 * Product Module Types
 * Comprehensive type definitions for the enhanced ERP Product Module
 */

// Product Status Workflow
export type ProductStatus = 'draft' | 'pending_approval' | 'active' | 'discontinued' | 'archived';

// Tracking Methods
export type TrackingMethod = 'none' | 'quantity' | 'batch' | 'serial';

// Product Type
export type ProductType = 'product' | 'service';

// Bundle Types
export type BundleType = 'fixed' | 'dynamic' | 'customizable';

// Discount Types
export type DiscountType = 'percentage' | 'fixed' | 'fixed_price';

// Relationship Types
export type ProductRelationshipType = 'cross_sell' | 'up_sell' | 'substitute' | 'accessory' | 'complementary';

// Document Types
export type ProductDocumentType = 'manual' | 'specification' | 'certificate' | 'safety_sheet' | 'datasheet' | 'image' | 'video' | 'other';

// Batch Status
export type BatchStatus = 'available' | 'quarantine' | 'expired' | 'recalled';

// Serial Status
export type SerialStatus = 'available' | 'reserved' | 'sold' | 'returned' | 'defective' | 'scrapped';

// Tax Mode
export type TaxMode = 'default' | 'none' | 'custom' | 'tax';

// Base Product Interface
export interface Product {
  id: number;
  name: string;
  secondary_name?: string | null;
  slug?: string | null;
  sku: string;
  barcode?: string | null;
  barcodes?: string[] | null;
  description?: string | null;
  category_id?: number | null;
  brand_id?: number | null;
  type: ProductType;

  // Bundle
  is_bundle: boolean;
  bundle_type?: BundleType | null;

  // Pricing
  cost: number;
  price: number;
  wholesale_price?: number | null;
  msrp?: number | null;
  min_price?: number | null;
  max_price?: number | null;

  // Tax
  tax_rate?: number | null;
  tax_mode?: TaxMode | null;
  tax_included: boolean;
  tax_rate_override?: number | null;
  tax_id?: number | null;

  // Accounting
  income_account_id?: number | null;
  expense_account_id?: number | null;

  // Inventory
  track_inventory: boolean;
  tracking_method: TrackingMethod;
  stock_quantity: number;
  reorder_level?: number | null;
  safety_stock?: number | null;
  max_stock?: number | null;
  reorder_quantity?: number | null;
  min_stock_threshold?: number | null;
  unit?: string | null;

  // Physical Attributes
  weight?: number | null;
  length?: number | null;
  width?: number | null;
  height?: number | null;
  dimensional_weight?: number | null;

  // Storage & Handling
  is_hazmat: boolean;
  hazmat_class?: string | null;
  storage_conditions?: string | null;
  is_fragile: boolean;
  shelf_life_days?: number | null;

  // Compliance
  hs_code?: string | null;
  country_of_origin?: string | null;
  warranty_months?: number | null;
  warranty_type?: string | null;
  certifications?: string[] | null;

  // Manufacturer
  manufacturer?: string | null;
  manufacturer_part_number?: string | null;

  // Media
  image?: string | null;
  gallery?: string[] | null;

  // SEO
  meta_title?: string | null;
  meta_description?: string | null;
  meta_keywords?: string | null;

  // Digital Product
  is_digital: boolean;
  is_subscription: boolean;
  download_url?: string | null;
  license_key?: string | null;

  // Localization
  localized_names?: Record<string, string> | null;
  localized_descriptions?: Record<string, string> | null;

  // Attributes
  attributes?: ProductAttributeValue[] | null;

  // Status & Flags
  status: ProductStatus;
  is_active: boolean;
  is_preorder: boolean;
  is_new_arrival: boolean;
  show_on_website: boolean;
  preorder_available_at?: string | null;

  // Approval
  approved_by?: number | null;
  approved_at?: string | null;
  discontinued_by?: number | null;
  discontinued_at?: string | null;

  // Timestamps
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;

  // Computed
  stock?: number;
  margin?: number;
  markup?: number;
  volume?: number | null;

  // Relationships (when loaded)
  category?: ProductCategory | null;
  brand?: Brand | null;
  tax?: Tax | null;
  income_account?: ChartOfAccount | null;
  expense_account?: ChartOfAccount | null;
  variants?: ProductVariant[];
  uoms?: ProductUom[];
  product_suppliers?: ProductSupplierPivot[];
  bundle_components?: ProductBundleComponent[];
  price_tiers?: ProductPriceTier[];
  documents?: ProductDocument[];
}

// Product Category
export interface ProductCategory {
  id: number;
  name: string;
  slug?: string;
  parent_id?: number | null;
  description?: string | null;
  image?: string | null;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

// Brand
export interface Brand {
  id: number;
  name: string;
  slug?: string;
  logo?: string | null;
  is_active: boolean;
}

// Tax
export interface Tax {
  id: number;
  name: string;
  rate: number;
  is_active: boolean;
}

// Chart of Account
export interface ChartOfAccount {
  id: number;
  code: string;
  name: string;
  type: string;
}

// Product Variant
export interface ProductVariant {
  id: number;
  product_id: number;
  name: string;
  sku: string;
  barcode?: string | null;
  barcodes?: string[] | null;
  price: number;
  cost?: number | null;
  stock_quantity: number;
  weight?: number | null;
  image?: string | null;
  attributes?: Record<string, string>;
  is_default: boolean;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

// Product UOM
export interface ProductUom {
  id: number;
  product_id: number;
  name: string;
  abbreviation: string;
  conversion_factor: number;
  is_base_unit: boolean;
  is_purchase_unit: boolean;
  is_sales_unit: boolean;
  barcode?: string | null;
  price_multiplier: number;
  sort_order: number;
  is_active: boolean;
}

// Supplier
export interface Supplier {
  id: number;
  name: string;
  code: string;
  contact_name?: string | null;
  email?: string | null;
  phone?: string | null;
  website?: string | null;
  address_line_1?: string | null;
  address_line_2?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code?: string | null;
  country?: string | null;
  tax_id?: string | null;
  payment_terms_days: number;
  currency: string;
  default_lead_time_days: number;
  minimum_order_value?: number | null;
  quality_rating?: number | null;
  delivery_rating?: number | null;
  notes?: string | null;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

// Product-Supplier Pivot
export interface ProductSupplierPivot {
  id: number;
  product_id: number;
  supplier_id: number;
  supplier_sku?: string | null;
  supplier_barcode?: string | null;
  cost: number;
  last_cost?: number | null;
  lead_time_days: number;
  moq: number;
  order_multiple: number;
  priority: number;
  is_preferred: boolean;
  last_purchase_date?: string | null;
  notes?: string | null;
  is_active: boolean;
  supplier?: Supplier;
}

// Product Bundle Component
export interface ProductBundleComponent {
  id: number;
  bundle_product_id: number;
  component_product_id: number;
  component_variant_id?: number | null;
  quantity: number;
  discount_type?: 'percentage' | 'fixed' | null;
  discount_value: number;
  is_optional: boolean;
  sort_order: number;
  component_product?: Product;
  component_variant?: ProductVariant;
  discounted_price?: number;
  total_cost?: number;
}

// Product Relationship
export interface ProductRelationship {
  id: number;
  product_id: number;
  related_product_id: number;
  type: ProductRelationshipType;
  priority: number;
  is_active: boolean;
  related_product?: Product;
}

// Product Price Tier
export interface ProductPriceTier {
  id: number;
  product_id: number;
  customer_id?: number | null;
  customer_group?: string | null;
  min_quantity: number;
  max_quantity?: number | null;
  discount_type: DiscountType;
  discount_value: number;
  valid_from?: string | null;
  valid_until?: string | null;
  is_active: boolean;
}

// Product Batch
export interface ProductBatch {
  id: number;
  product_id: number;
  warehouse_id?: number | null;
  batch_number: string;
  lot_number?: string | null;
  quantity: number;
  quantity_reserved: number;
  manufacturing_date?: string | null;
  expiry_date?: string | null;
  received_date?: string | null;
  supplier_batch_number?: string | null;
  supplier_id?: number | null;
  cost?: number | null;
  status: BatchStatus;
  notes?: string | null;
  created_at?: string;
  product?: Product;
  warehouse?: Warehouse;
  available_quantity?: number;
  is_expired?: boolean;
  is_expiring_soon?: boolean;
}

// Product Serial
export interface ProductSerial {
  id: number;
  product_id: number;
  product_variant_id?: number | null;
  batch_id?: number | null;
  warehouse_id?: number | null;
  serial_number: string;
  mac_address?: string | null;
  imei?: string | null;
  manufacturing_date?: string | null;
  warranty_start_date?: string | null;
  warranty_end_date?: string | null;
  supplier_id?: number | null;
  cost?: number | null;
  status: SerialStatus;
  customer_id?: number | null;
  sold_at?: string | null;
  sale_reference?: string | null;
  notes?: string | null;
  created_at?: string;
  product?: Product;
  warehouse?: Warehouse;
  customer?: Customer;
  is_under_warranty?: boolean;
}

// Product Audit Log
export interface ProductAuditLog {
  id: number;
  product_id: number;
  user_id?: number | null;
  action: string;
  field?: string | null;
  old_value?: string | null;
  new_value?: string | null;
  metadata?: Record<string, unknown> | null;
  ip_address?: string | null;
  user_agent?: string | null;
  created_at: string;
  user?: User;
}

// Product Document
export interface ProductDocument {
  id: number;
  product_id: number;
  type: ProductDocumentType;
  name: string;
  file_path: string;
  file_name: string;
  mime_type?: string | null;
  file_size?: number | null;
  language: string;
  valid_until?: string | null;
  is_public: boolean;
  sort_order: number;
  uploaded_by?: number | null;
  created_at?: string;
  human_file_size?: string;
  is_expired?: boolean;
}

// Product Attribute
export interface ProductAttribute {
  id: number;
  name: string;
  slug: string;
  type: string;
  is_active: boolean;
  values?: ProductAttributeOption[];
}

// Product Attribute Option
export interface ProductAttributeOption {
  id: number;
  product_attribute_id: number;
  value: string;
  color_code?: string | null;
}

// Product Attribute Value (for product)
export interface ProductAttributeValue {
  attribute_id?: number;
  attribute_name?: string;
  values: string[];
}

// Product Feature
export interface ProductFeature {
  id: number;
  name: string;
  slug: string;
  type: 'text' | 'select' | 'toggle';
  is_active: boolean;
  values?: ProductFeatureOption[];
}

// Product Feature Option
export interface ProductFeatureOption {
  id: number;
  product_feature_id: number;
  value: string;
}

// Product Feature Value
export interface ProductFeatureValue {
  id: number;
  product_id: number;
  product_feature_id: number;
  value: string;
  feature?: ProductFeature;
}

// Product Custom Field
export interface ProductCustomField {
  id: number;
  name: string;
  slug: string;
  type: 'text' | 'number' | 'date' | 'boolean';
  is_required: boolean;
  default_value?: string | null;
  is_active: boolean;
}

// Product Custom Field Value
export interface ProductCustomFieldValue {
  id: number;
  product_id: number;
  product_custom_field_id: number;
  value: string;
  custom_field?: ProductCustomField;
}

// Warehouse
export interface Warehouse {
  id: number;
  name: string;
  code?: string;
  is_active: boolean;
}

// Customer (minimal)
export interface Customer {
  id: number;
  name: string;
  email?: string | null;
}

// User (minimal)
export interface User {
  id: number;
  name: string;
  email: string;
}

// Product Unit
export interface ProductUnit {
  id: number;
  name: string;
  short_name?: string | null;
  allow_decimal: boolean;
  is_active: boolean;
}

// API Response Types
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

export interface ProductStatistics {
  total: number;
  active: number;
  draft: number;
  pending_approval: number;
  discontinued: number;
  archived: number;
  out_of_stock: number;
  low_stock: number;
  bundles: number;
  digital: number;
  by_category: Array<{ category_id: number; count: number; category?: ProductCategory }>;
  by_tracking_method: Array<{ tracking_method: TrackingMethod; count: number }>;
}

export interface SupplierStatistics {
  total: number;
  active: number;
  inactive: number;
  average_quality_rating: number;
  average_delivery_rating: number;
  by_country: Array<{ country: string; count: number }>;
}

export interface BundleAvailability {
  bundle_id: number;
  bundle_name: string;
  requested_quantity: number;
  can_fulfill: boolean;
  max_fulfillable: number;
  components: Array<{
    component_id: number;
    product_id: number;
    product_name: string;
    product_sku: string;
    quantity_needed: number;
    quantity_available: number;
    is_optional: boolean;
    can_fulfill: boolean;
    shortage: number;
  }>;
}

export interface PriceCalculation {
  product_id: number;
  quantity: number;
  unit_price: number;
  total_price: number;
  regular_price: number;
  discount_amount: number;
}

// Form Data Types
export interface ProductFormData {
  name: string;
  secondary_name?: string;
  sku: string;
  barcode?: string;
  description?: string;
  category_id?: number | string;
  brand_id?: number | string;
  type: ProductType;
  
  // Pricing
  cost: number | string;
  price: number | string;
  wholesale_price?: number | string;
  msrp?: number | string;
  
  // Tax
  tax_mode?: TaxMode;
  tax_included: boolean;
  tax_rate_override?: number | string;
  tax_id?: number | string;
  
  // Inventory
  track_inventory: boolean;
  tracking_method?: TrackingMethod;
  stock_quantity?: number | string;
  reorder_level?: number | string;
  safety_stock?: number | string;
  unit?: string;
  
  // Physical
  weight?: number | string;
  length?: number | string;
  width?: number | string;
  height?: number | string;
  
  // Flags
  is_active: boolean;
  is_preorder: boolean;
  is_new_arrival: boolean;
  is_merchandise: boolean;
  show_on_website: boolean;
  is_hazmat?: boolean;
  is_fragile?: boolean;
  is_digital?: boolean;
  is_bundle?: boolean;
  
  // Media
  image?: string;
  gallery?: string[];
  
  // Compliance
  warranty_months?: number | string;
  country_of_origin?: string;
  
  // SEO
  meta_title?: string;
  meta_description?: string;
  
  // Dates
  preorder_available_at?: string;
  
  // Attributes & Features
  attributes?: ProductAttributeValue[];
  feature_values?: Array<{ product_feature_id: number; value: string }>;
  custom_field_values?: Array<{ product_custom_field_id: number; value: string }>;
  
  // Variants
  variants?: ProductVariant[];
}

export interface SupplierFormData {
  name: string;
  code?: string;
  contact_name?: string;
  email?: string;
  phone?: string;
  website?: string;
  address_line_1?: string;
  address_line_2?: string;
  city?: string;
  state?: string;
  postal_code?: string;
  country?: string;
  tax_id?: string;
  payment_terms_days?: number;
  currency?: string;
  default_lead_time_days?: number;
  minimum_order_value?: number;
  quality_rating?: number;
  delivery_rating?: number;
  notes?: string;
  is_active: boolean;
}

export interface ProductSupplierFormData {
  product_id: number;
  supplier_sku?: string;
  supplier_barcode?: string;
  cost: number;
  lead_time_days?: number;
  moq?: number;
  order_multiple?: number;
  priority?: number;
  is_preferred?: boolean;
  notes?: string;
}

export interface BatchFormData {
  batch_number: string;
  lot_number?: string;
  warehouse_id?: number;
  quantity: number;
  manufacturing_date?: string;
  expiry_date?: string;
  received_date?: string;
  supplier_batch_number?: string;
  supplier_id?: number;
  cost?: number;
  notes?: string;
}

export interface SerialFormData {
  serial_number: string;
  warehouse_id?: number;
  batch_id?: number;
  mac_address?: string;
  imei?: string;
  manufacturing_date?: string;
  warranty_start_date?: string;
  warranty_end_date?: string;
  supplier_id?: number;
  cost?: number;
  notes?: string;
}

export interface BundleComponentFormData {
  component_product_id: number;
  quantity: number;
  discount_type?: 'percentage' | 'fixed';
  discount_value?: number;
  is_optional?: boolean;
  sort_order?: number;
}

export interface PriceTierFormData {
  customer_id?: number;
  customer_group?: string;
  min_quantity: number;
  max_quantity?: number;
  discount_type: DiscountType;
  discount_value: number;
  valid_from?: string;
  valid_until?: string;
  is_active: boolean;
}
