import { nanoid } from 'nanoid'
import type { SectionTemplate, SectionType, FieldDefinition } from '@/types/receipt-builder'

// Section Templates
export const SECTION_TEMPLATES: SectionTemplate[] = [
  {
    type: 'header',
    label: 'Header',
    icon: 'solar:document-text-bold-duotone',
    description: 'Store logo and name',
    defaultSection: {
      type: 'header',
      height: 80,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'center',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'company_name' },
              style: { fontSize: 'large', fontWeight: 'bold' }
            }
          ]
        }
      ],
      properties: { borderBottom: true, padding: 8 }
    }
  },
  {
    type: 'company-info',
    label: 'Company Info',
    icon: 'solar:buildings-2-bold-duotone',
    description: 'Company address and registration',
    defaultSection: {
      type: 'company-info',
      height: 100,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'center',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'company_address' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'biz_reg_no', format: 'Reg: {value}' },
              style: { fontSize: 'small', fontWeight: 'normal', marginTop: 4 }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'tax_reg_no', format: 'Tax: {value}' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            }
          ]
        }
      ],
      properties: { borderBottom: true, padding: 8 }
    }
  },
  {
    type: 'customer-info',
    label: 'Customer Info',
    icon: 'solar:user-bold-duotone',
    description: 'Customer name and contact',
    defaultSection: {
      type: 'customer-info',
      height: 60,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'left',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'customer_name', format: 'Customer: {value}' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'customer_phone', format: 'Phone: {value}' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            }
          ]
        }
      ],
      properties: { padding: 8 }
    }
  },
  {
    type: 'delivery-info',
    label: 'Delivery Info',
    icon: 'solar:delivery-bold-duotone',
    description: 'Delivery address',
    defaultSection: {
      type: 'delivery-info',
      height: 60,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'left',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'delivery_address', format: 'Deliver to: {value}' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            }
          ]
        }
      ],
      properties: { padding: 8 }
    }
  },
  {
    type: 'line-items',
    label: 'Line Items',
    icon: 'solar:list-bold-duotone',
    description: 'Product list (repeatable)',
    defaultSection: {
      type: 'line-items',
      height: 60,
      columns: [
        {
          id: nanoid(),
          width: 60,
          alignment: 'left',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'product_name' },
              style: { fontSize: 'medium', fontWeight: 'normal' }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'product_qty', format: '{value} x' },
              style: { fontSize: 'small', fontWeight: 'normal' }
            }
          ]
        },
        {
          id: nanoid(),
          width: 40,
          alignment: 'right',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'product_total', format: 'RM {value}' },
              style: { fontSize: 'medium', fontWeight: 'normal' }
            }
          ]
        }
      ],
      properties: { repeatable: true, padding: 4, borderBottom: true }
    }
  },
  {
    type: 'summary',
    label: 'Summary',
    icon: 'solar:calculator-bold-duotone',
    description: 'Subtotal, tax, and total',
    defaultSection: {
      type: 'summary',
      height: 120,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'left',
          content: [
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'subtotal', format: 'Subtotal: RM {value}' },
              style: { fontSize: 'medium', fontWeight: 'normal' }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'tax', format: 'Tax: RM {value}' },
              style: { fontSize: 'medium', fontWeight: 'normal', marginTop: 4 }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'discount', format: 'Discount: -RM {value}' },
              style: { fontSize: 'medium', fontWeight: 'normal', marginTop: 4 }
            },
            {
              id: nanoid(),
              type: 'field',
              value: { fieldType: 'total', format: 'TOTAL: RM {value}' },
              style: { fontSize: 'large', fontWeight: 'bold', marginTop: 8 }
            }
          ]
        }
      ],
      properties: { borderTop: true, borderBottom: true, padding: 8 }
    }
  },
  {
    type: 'footer',
    label: 'Footer',
    icon: 'solar:text-bold-duotone',
    description: 'Thank you message',
    defaultSection: {
      type: 'footer',
      height: 60,
      columns: [
        {
          id: nanoid(),
          width: 100,
          alignment: 'center',
          content: [
            {
              id: nanoid(),
              type: 'text',
              value: 'Thank you for shopping with us!',
              style: { fontSize: 'medium', fontWeight: 'normal' }
            }
          ]
        }
      ],
      properties: { padding: 8 }
    }
  }
]

// Field Definitions
export const FIELD_DEFINITIONS: FieldDefinition[] = [
  // Company fields
  { fieldType: 'company_name', label: 'Company Name', category: 'company', availableIn: ['header', 'company-info'] },
  { fieldType: 'company_address', label: 'Company Address', category: 'company', availableIn: ['company-info'] },
  { fieldType: 'biz_reg_no', label: 'Business Registration No', category: 'company', defaultFormat: 'Reg: {value}', availableIn: ['company-info'] },
  { fieldType: 'tax_reg_no', label: 'Tax Registration No', category: 'company', defaultFormat: 'Tax: {value}', availableIn: ['company-info'] },
  
  // Receipt fields
  { fieldType: 'receipt_number', label: 'Receipt Number', category: 'receipt', defaultFormat: 'Receipt: {value}', availableIn: ['header', 'company-info'] },
  { fieldType: 'date', label: 'Date', category: 'receipt', defaultFormat: 'Date: {value}', availableIn: ['header', 'company-info'] },
  { fieldType: 'cashier', label: 'Cashier', category: 'receipt', defaultFormat: 'Cashier: {value}', availableIn: ['header', 'company-info'] },
  
  // Customer fields
  { fieldType: 'customer_name', label: 'Customer Name', category: 'customer', defaultFormat: 'Customer: {value}', availableIn: ['customer-info'] },
  { fieldType: 'customer_phone', label: 'Customer Phone', category: 'customer', defaultFormat: 'Phone: {value}', availableIn: ['customer-info'] },
  { fieldType: 'delivery_address', label: 'Delivery Address', category: 'customer', defaultFormat: 'Deliver to: {value}', availableIn: ['delivery-info'] },
  
  // Product fields
  { fieldType: 'product_name', label: 'Product Name', category: 'product', availableIn: ['line-items'] },
  { fieldType: 'product_price', label: 'Product Price', category: 'product', defaultFormat: 'RM {value}', availableIn: ['line-items'] },
  { fieldType: 'product_qty', label: 'Product Quantity', category: 'product', defaultFormat: '{value} x', availableIn: ['line-items'] },
  { fieldType: 'product_total', label: 'Product Total', category: 'product', defaultFormat: 'RM {value}', availableIn: ['line-items'] },
  
  // Payment fields
  { fieldType: 'subtotal', label: 'Subtotal', category: 'payment', defaultFormat: 'Subtotal: RM {value}', availableIn: ['summary'] },
  { fieldType: 'tax', label: 'Tax', category: 'payment', defaultFormat: 'Tax: RM {value}', availableIn: ['summary'] },
  { fieldType: 'discount', label: 'Discount', category: 'payment', defaultFormat: 'Discount: -RM {value}', availableIn: ['summary'] },
  { fieldType: 'total', label: 'Total', category: 'payment', defaultFormat: 'TOTAL: RM {value}', availableIn: ['summary'] },
  { fieldType: 'payment_method', label: 'Payment Method', category: 'payment', defaultFormat: 'Payment: {value}', availableIn: ['summary'] },
  { fieldType: 'amount_paid', label: 'Amount Paid', category: 'payment', defaultFormat: 'Paid: RM {value}', availableIn: ['summary'] },
  { fieldType: 'change', label: 'Change', category: 'payment', defaultFormat: 'Change: RM {value}', availableIn: ['summary'] }
]

// Helper functions
export function getSectionTemplate(type: SectionType): SectionTemplate | undefined {
  return SECTION_TEMPLATES.find(t => t.type === type)
}

export function getFieldDefinition(fieldType: string): FieldDefinition | undefined {
  return FIELD_DEFINITIONS.find(f => f.fieldType === fieldType)
}

export function getFieldsByCategory(category: string): FieldDefinition[] {
  return FIELD_DEFINITIONS.filter(f => f.category === category)
}

export function getFieldsForSection(sectionType: SectionType): FieldDefinition[] {
  return FIELD_DEFINITIONS.filter(f => f.availableIn.includes(sectionType))
}

// Default heights
export const DEFAULT_HEIGHTS: Record<SectionType, number> = {
  'header': 80,
  'company-info': 100,
  'customer-info': 60,
  'delivery-info': 60,
  'line-items': 60,
  'summary': 120,
  'footer': 60
}

// Paper width configurations
export const PAPER_WIDTHS = {
  '58mm': {
    pixels: 220,
    characters: 32,
    maxColumns: 2
  },
  '80mm': {
    pixels: 300,
    characters: 48,
    maxColumns: 3
  }
} as const
