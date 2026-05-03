// Receipt Builder Type Definitions

export type PaperWidth = '58mm' | '80mm'

export type SectionType = 
  | 'header'
  | 'company-info'
  | 'customer-info'
  | 'delivery-info'
  | 'line-items'
  | 'summary'
  | 'footer'

export type ContentType = 'text' | 'image' | 'field' | 'separator'

export type Alignment = 'left' | 'center' | 'right'

export type FontSize = 'small' | 'medium' | 'large'

export type FontWeight = 'normal' | 'bold'

export type TextTransform = 'uppercase' | 'lowercase' | 'capitalize' | 'none'

export type DynamicFieldType = 
  | 'company_name'
  | 'company_address'
  | 'biz_reg_no'
  | 'tax_reg_no'
  | 'receipt_number'
  | 'date'
  | 'cashier'
  | 'customer_name'
  | 'customer_phone'
  | 'delivery_address'
  | 'product_name'
  | 'product_price'
  | 'product_qty'
  | 'product_total'
  | 'subtotal'
  | 'tax'
  | 'discount'
  | 'total'
  | 'payment_method'
  | 'amount_paid'
  | 'change'

export interface ReceiptConfig {
  id: string
  name: string
  paperWidth: PaperWidth
  sections: ReceiptSection[]
  metadata: {
    createdAt: string
    updatedAt: string
    version: string
  }
}

export interface ReceiptSection {
  id: string
  type: SectionType
  title?: string
  order: number
  height: number
  columns: ReceiptColumn[]
  properties: SectionProperties
}

export interface ReceiptColumn {
  id: string
  width: number
  alignment: Alignment
  content: ColumnContent[]
}

export interface ColumnContent {
  id: string
  type: ContentType
  value: string | DynamicField
  style: ContentStyle
  // Preserved when a field is overridden with a custom text value
  originalFieldType?: DynamicFieldType
}

export interface DynamicField {
  fieldType: DynamicFieldType
  format?: string
}

export interface ContentStyle {
  fontSize: FontSize
  fontWeight: FontWeight
  textTransform?: TextTransform
  marginTop?: number
  marginBottom?: number
}

export interface SectionProperties {
  backgroundColor?: string
  borderTop?: boolean
  borderBottom?: boolean
  padding?: number
  repeatable?: boolean
}

// Receipt data for preview/printing
export interface ReceiptData {
  company_name: string
  company_address: string
  biz_reg_no: string
  tax_reg_no: string
  receipt_number: string
  date: string
  cashier: string
  customer_name?: string
  customer_phone?: string
  delivery_address?: string
  items: ReceiptItem[]
  subtotal: number
  tax: number
  discount: number
  total: number
  payment_method: string
  amount_paid: number
  change: number
}

export interface ReceiptItem {
  product_name: string
  product_price: number
  product_qty: number
  product_total: number
}

// Template definitions
export interface SectionTemplate {
  type: SectionType
  label: string
  icon: string
  description: string
  defaultSection: Omit<ReceiptSection, 'id' | 'order'>
}

// Field definitions
export interface FieldDefinition {
  fieldType: DynamicFieldType
  label: string
  category: 'company' | 'receipt' | 'customer' | 'product' | 'payment'
  defaultFormat?: string
  availableIn: SectionType[]
}
