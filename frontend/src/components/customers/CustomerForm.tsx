import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import {
  CUSTOMER_GENDERS,
  type CustomerFormValues,
} from '@/lib/customerPayload'

export type { CustomerFormValues } from '@/lib/customerPayload'

interface CustomerFormProps {
  value: CustomerFormValues
  onChange: (value: CustomerFormValues) => void
  /** Name/phone/email only — the POS walk-in capture. */
  compact?: boolean
  idPrefix?: string
  disabled?: boolean
}

const digitsOnly = (value: string) => value.replace(/\D/g, '')

/**
 * #437 (CU9): the one customer create/edit form. The list quick-create, the
 * detail-page edit dialog, the in-document (invoice) dialog and the POS
 * walk-in capture all render this component, so their field handling cannot
 * diverge again.
 */
export function CustomerForm({ value, onChange, compact = false, idPrefix = 'customer', disabled = false }: CustomerFormProps) {
  const set = (patch: Partial<CustomerFormValues>) => onChange({ ...value, ...patch })
  const id = (name: string) => `${idPrefix}-${name}`

  if (compact) {
    return (
      <div className="space-y-4" data-testid="customer-form">
        <div className="space-y-2">
          <Label htmlFor={id('name')}>Name *</Label>
          <Input
            id={id('name')}
            value={value.name}
            onChange={(e) => set({ name: e.target.value })}
            placeholder="Customer name"
            disabled={disabled}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor={id('phone')}>Phone</Label>
          <Input
            id={id('phone')}
            value={value.phone}
            onChange={(e) => set({ phone: digitsOnly(e.target.value) })}
            placeholder="Phone number"
            inputMode="numeric"
            disabled={disabled}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor={id('email')}>Email</Label>
          <Input
            id={id('email')}
            type="email"
            value={value.email}
            onChange={(e) => set({ email: e.target.value })}
            placeholder="Email address"
            disabled={disabled}
          />
        </div>
      </div>
    )
  }

  return (
    <Tabs defaultValue="basic" className="w-full" data-testid="customer-form">
      <TabsList className="w-fit max-w-full">
        <TabsTrigger value="basic">Basic Info</TabsTrigger>
        <TabsTrigger value="addresses">Addresses</TabsTrigger>
        <TabsTrigger value="financial">Financial</TabsTrigger>
      </TabsList>

      <TabsContent value="basic" className="space-y-4 mt-4">
        <div className="space-y-2">
          <Label htmlFor={id('code')}>Customer Code</Label>
          <Input
            id={id('code')}
            value={value.code}
            onChange={(e) => set({ code: e.target.value })}
            placeholder="Auto-generated if left empty"
            className="font-mono"
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">Your own ID format — leave empty to auto-generate</p>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('type')}>Customer Type</Label>
            <Select
              value={value.type}
              onValueChange={(v) => set({ type: v as 'individual' | 'company' })}
              disabled={disabled}
            >
              <SelectTrigger id={id('type')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="individual">Individual</SelectItem>
                <SelectItem value="company">Company</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('name')}>{value.type === 'company' ? 'Contact / Trading Name *' : 'Name *'}</Label>
            <Input
              id={id('name')}
              value={value.name}
              onChange={(e) => set({ name: e.target.value })}
              required
              disabled={disabled}
            />
          </div>
        </div>

        {value.type === 'company' && (
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor={id('company')}>Registered Company Name</Label>
              <Input
                id={id('company')}
                value={value.company}
                onChange={(e) => set({ company: e.target.value })}
                disabled={disabled}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor={id('tax-number')}>Tax Number</Label>
              <Input
                id={id('tax-number')}
                value={value.tax_number}
                onChange={(e) => set({ tax_number: e.target.value })}
                disabled={disabled}
              />
            </div>
          </div>
        )}

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('company-registration')}>Company Registration Number</Label>
            <Input
              id={id('company-registration')}
              value={value.company_registration_number}
              onChange={(e) => set({ company_registration_number: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('website')}>Website</Label>
            <Input
              id={id('website')}
              type="url"
              placeholder="https://"
              value={value.website}
              onChange={(e) => set({ website: e.target.value })}
              disabled={disabled}
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('email')}>Email</Label>
            <Input
              id={id('email')}
              type="email"
              value={value.email}
              onChange={(e) => set({ email: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('phone')}>Phone</Label>
            <Input
              id={id('phone')}
              value={value.phone}
              onChange={(e) => set({ phone: digitsOnly(e.target.value) })}
              inputMode="numeric"
              pattern="[0-9]*"
              disabled={disabled}
            />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('mobile')}>Mobile</Label>
            <Input
              id={id('mobile')}
              value={value.mobile}
              onChange={(e) => set({ mobile: digitsOnly(e.target.value) })}
              inputMode="numeric"
              pattern="[0-9]*"
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('preferred-contact')}>Preferred Contact Method</Label>
            <Select
              value={value.preferred_contact}
              onValueChange={(v) => set({ preferred_contact: v })}
              disabled={disabled}
            >
              <SelectTrigger id={id('preferred-contact')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="email">Email</SelectItem>
                <SelectItem value="phone">Phone</SelectItem>
                <SelectItem value="sms">SMS</SelectItem>
                <SelectItem value="whatsapp">WhatsApp</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('date-of-birth')}>Date of Birth</Label>
            <Input
              id={id('date-of-birth')}
              type="date"
              value={value.date_of_birth}
              onChange={(e) => set({ date_of_birth: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('gender')}>Gender</Label>
            <Select
              value={value.gender || ''}
              onValueChange={(v) => set({ gender: v })}
              disabled={disabled}
            >
              <SelectTrigger id={id('gender')}>
                <SelectValue placeholder="Select gender" />
              </SelectTrigger>
              <SelectContent>
                {CUSTOMER_GENDERS.map((gender) => (
                  <SelectItem key={gender} value={gender}>
                    {gender === 'male' ? 'Male' : gender === 'female' ? 'Female' : 'Other'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </TabsContent>

      <TabsContent value="addresses" className="space-y-4 mt-4">
        <p className="text-sm font-medium">Billing Address</p>
        <div className="space-y-2">
          <Label htmlFor={id('billing-street')}>Street</Label>
          <Input
            id={id('billing-street')}
            value={value.billing_street}
            onChange={(e) => set({ billing_street: e.target.value })}
            disabled={disabled}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor={id('billing-street2')}>Street 2</Label>
          <Input
            id={id('billing-street2')}
            value={value.billing_street2}
            onChange={(e) => set({ billing_street2: e.target.value })}
            disabled={disabled}
          />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('billing-city')}>City</Label>
            <Input
              id={id('billing-city')}
              value={value.billing_city}
              onChange={(e) => set({ billing_city: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('billing-state')}>State / Province</Label>
            <Input
              id={id('billing-state')}
              value={value.billing_state}
              onChange={(e) => set({ billing_state: e.target.value })}
              disabled={disabled}
            />
          </div>
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('billing-zip')}>Postal Code</Label>
            <Input
              id={id('billing-zip')}
              value={value.billing_zip}
              onChange={(e) => set({ billing_zip: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('billing-country')}>Country (ISO code)</Label>
            <Input
              id={id('billing-country')}
              maxLength={2}
              placeholder="e.g. MY"
              value={value.billing_country}
              onChange={(e) => set({ billing_country: e.target.value.toUpperCase() })}
              disabled={disabled}
            />
          </div>
        </div>
        <div className="space-y-2">
          <Label htmlFor={id('shipping-address')}>Shipping Address (full text)</Label>
          <Textarea
            id={id('shipping-address')}
            rows={2}
            value={value.shipping_address}
            onChange={(e) => set({ shipping_address: e.target.value })}
            placeholder="Optional — enter the shipping address manually"
            disabled={disabled}
          />
        </div>
      </TabsContent>

      <TabsContent value="financial" className="space-y-4 mt-4">
        <div className="grid grid-cols-2 gap-4">
          <div className="space-y-2">
            <Label htmlFor={id('credit-limit')}>Credit Limit</Label>
            <Input
              id={id('credit-limit')}
              type="number"
              step="0.01"
              value={value.credit_limit}
              onChange={(e) => set({ credit_limit: e.target.value })}
              disabled={disabled}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={id('payment-terms')}>Payment Terms (days)</Label>
            <Input
              id={id('payment-terms')}
              type="number"
              value={value.payment_terms}
              onChange={(e) => set({ payment_terms: e.target.value })}
              disabled={disabled}
            />
          </div>
        </div>

        <div className="space-y-2">
          <Label htmlFor={id('notes')}>Notes</Label>
          <Textarea
            id={id('notes')}
            rows={3}
            value={value.notes}
            onChange={(e) => set({ notes: e.target.value })}
            disabled={disabled}
          />
        </div>

        <div className="flex items-center space-x-2">
          <input
            type="checkbox"
            id={id('marketing-opt-in')}
            checked={value.marketing_opt_in}
            onChange={(e) => set({ marketing_opt_in: e.target.checked })}
            className="h-4 w-4 rounded border-gray-300"
            disabled={disabled}
          />
          <Label htmlFor={id('marketing-opt-in')} className="font-normal">
            Opt-in for marketing communications
          </Label>
        </div>
      </TabsContent>
    </Tabs>
  )
}

export default CustomerForm
