import { resolvedCustomerAddress, type CustomerWithResolvedAddresses } from '@/lib/customerAddresses'

export interface InvoiceCustomerAddress {
  id?: number | string
  type?: 'billing' | 'shipping' | 'other'
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
  label?: string | null
  is_default?: boolean
}

export type InvoiceAddressForm = Record<'label' | 'street' | 'street2' | 'city' | 'state' | 'zip' | 'country', string>

export const emptyInvoiceAddressForm: InvoiceAddressForm = {
  label: '', street: '', street2: '', city: '', state: '', zip: '', country: '',
}

export const invoiceAddressToForm = (address?: InvoiceCustomerAddress | null): InvoiceAddressForm => ({
  label: address?.label ?? '',
  street: address?.street ?? '',
  street2: address?.street2 ?? '',
  city: address?.city ?? '',
  state: address?.state ?? '',
  zip: address?.zip ?? '',
  country: address?.country ?? '',
})

export const invoiceAddressFormToSnapshot = (address: InvoiceAddressForm): string =>
  [address.label, address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join('\n')

export const formatInvoiceAddress = (address?: InvoiceCustomerAddress | null): string => {
  if (!address) return ''

  return [address.label, address.street, address.street2, address.city, address.state, address.zip, address.country]
    .filter(Boolean)
    .join('\n')
}

export const formatLegacyInvoiceAddress = (address: string | null | undefined): string => {
  if (!address) return ''
  try {
    const parsed = JSON.parse(address)
    return [
      parsed.first_name || parsed.name || '',
      parsed.last_name || '',
      parsed.address || parsed.street || '',
      parsed.street2 || '',
      parsed.city || '',
      parsed.state || '',
      parsed.postal_code || parsed.zip || '',
      parsed.country || '',
    ].filter(Boolean).join(', ')
  } catch {
    return address
  }
}

export interface InvoiceCustomerWithAddresses extends CustomerWithResolvedAddresses {
  addresses?: InvoiceCustomerAddress[]
  billing_address?: string | null
  shipping_address?: string | null
}

export function getInvoiceCustomerAddress(
  customer?: InvoiceCustomerWithAddresses | null,
  type?: 'billing' | 'shipping',
): string {
  if (!customer) return ''

  const resolved = resolvedCustomerAddress(customer, type ?? 'billing')
  if (type && resolved !== undefined) return formatInvoiceAddress(resolved)

  const addresses = customer.addresses ?? []
  const address = addresses.find((item) => item.type === type && item.is_default)
    ?? addresses.find((item) => item.type === type)
  if (address) return formatInvoiceAddress(address)

  if (type === 'billing') return formatLegacyInvoiceAddress(customer.billing_address)
  if (type === 'shipping') return formatLegacyInvoiceAddress(customer.shipping_address)
  return ''
}
