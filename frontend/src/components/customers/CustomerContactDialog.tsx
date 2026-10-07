import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useToast } from '@/hooks/use-toast'
import { getApiErrorMessage } from '@/lib/utils'
import api from '@/lib/api'
import {
  contactFormToPayload,
  emptyContactForm,
  siteLabel,
  type ContactFormState,
  type SiteAddress,
} from '@/lib/customerContacts'

/**
 * ADR-003B W4: add/edit a person listed under a customer.
 *
 * - No address fields: a person under a company owns no address (D2). They
 *   are linked to one of the company's Site addresses instead (D3/D4).
 * - Canonical ADR-003A fields replace the legacy free-text Category/Type:
 *   functional category, classification, persistent roles, department.
 *   The backend projects the legacy columns from these (CT-VEN-021).
 */
export interface CustomerContactRecord {
  id: number
  name: string
  job_title?: string | null
  email?: string | null
  phone?: string | null
  mobile?: string | null
  notes?: string | null
  is_primary?: boolean
  core_contact_uuid?: string | null
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
}

interface TaxonomyEntry {
  taxonomy_type: 'functional_category' | 'classification' | 'persistent_role'
  code: string
  display_label: string
}

interface Relationship {
  id: string
  relationship_type: string
  related_contact_id: string
  functional_category_code?: string | null
  department_name?: string | null
  site_address_id?: string | null
}

const NONE = '__none__'
const NEW_SITE = '__new_site__'

const numericPhoneValue = (value: string) => value.replace(/\D/g, '')

const legacyPersonalAddress = (contact?: CustomerContactRecord | null) =>
  contact
    ? [contact.street, contact.street2, contact.city, contact.state, contact.zip, contact.country].filter(Boolean).join(', ')
    : ''

interface CustomerContactDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  customerId: string | number
  /** The customer's Contact Master organisation (`core_contact_uuid`). */
  organisationId?: string | null
  /** The contact being edited; null/undefined adds a new contact. */
  contact?: CustomerContactRecord | null
  onSaved: () => void
}

export function CustomerContactDialog({
  open,
  onOpenChange,
  customerId,
  organisationId,
  contact,
  onSaved,
}: CustomerContactDialogProps) {
  const { toast } = useToast()
  const isEdit = Boolean(contact)
  const personId = contact?.core_contact_uuid ?? null
  const [form, setForm] = useState<ContactFormState>(emptyContactForm)
  const [newSite, setNewSite] = useState({ label: '', line_1: '', city: '', postal_code: '', country: '' })
  const [addingSite, setAddingSite] = useState(false)

  const { data: taxonomy = [] } = useQuery<TaxonomyEntry[]>({
    queryKey: ['contact-taxonomy'],
    queryFn: async () => (await api.get('/contacts/taxonomy')).data?.data ?? [],
    enabled: open,
  })

  const { data: sites = [], refetch: refetchSites } = useQuery<SiteAddress[]>({
    queryKey: ['contact-sites', organisationId],
    queryFn: async () => {
      const rows: SiteAddress[] = (await api.get(`/contacts/${organisationId}/addresses`)).data?.data ?? []
      return rows.filter((row) => row.address_type === 'site')
    },
    enabled: open && Boolean(organisationId),
  })

  // Edit: the canonical values live in the Contact Master, not on the
  // customer contact row — load them for this person at this company.
  const { data: canonical, isError: canonicalFailed } = useQuery({
    queryKey: ['customer-contact-canonical', organisationId, personId],
    queryFn: async () => {
      const [relationships, roles, classifications] = await Promise.all([
        api.get(`/contacts/${organisationId}/relationships`),
        api.get(`/contacts/${personId}/roles`),
        api.get(`/contacts/${personId}/classifications`),
      ])
      const relationship = ((relationships.data?.data ?? []) as Relationship[]).find(
        (row) => row.relationship_type === 'org_person' && row.related_contact_id === personId,
      )
      const roleCodes = ((roles.data?.data ?? []) as { role: string; relationship_id?: string | null }[])
        .filter((row) => relationship && row.relationship_id === relationship.id)
        .map((row) => row.role)

      return {
        functional_category_code: relationship?.functional_category_code ?? '',
        department_name: relationship?.department_name ?? '',
        site_address_id: relationship?.site_address_id ?? '',
        persistent_role_codes: roleCodes,
        classification_code: (classifications.data?.data?.classification_codes ?? [])[0] ?? '',
      }
    },
    enabled: open && isEdit && Boolean(organisationId) && Boolean(personId),
  })

  useEffect(() => {
    if (!open) return
    setAddingSite(false)
    setNewSite({ label: '', line_1: '', city: '', postal_code: '', country: '' })
    setForm({
      ...emptyContactForm(),
      ...(contact
        ? {
            name: contact.name ?? '',
            job_title: contact.job_title ?? '',
            email: contact.email ?? '',
            phone: contact.phone ?? '',
            mobile: contact.mobile ?? '',
            notes: contact.notes ?? '',
            is_primary: Boolean(contact.is_primary),
          }
        : {}),
    })
  }, [open, contact])

  useEffect(() => {
    if (open && canonical) {
      setForm((current) => ({ ...current, ...canonical }))
    }
  }, [open, canonical])

  const options = useMemo(() => ({
    categories: taxonomy.filter((entry) => entry.taxonomy_type === 'functional_category'),
    classifications: taxonomy.filter((entry) => entry.taxonomy_type === 'classification'),
    roles: taxonomy.filter((entry) => entry.taxonomy_type === 'persistent_role'),
  }), [taxonomy])

  const createSite = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/contacts/${organisationId}/addresses`, {
        address_type: 'site',
        label: newSite.label,
        line_1: newSite.line_1 || null,
        city: newSite.city || null,
        postal_code: newSite.postal_code || null,
        country: newSite.country ? newSite.country.toUpperCase() : null,
      })
      return response.data?.data as SiteAddress
    },
    onSuccess: async (site) => {
      await refetchSites()
      setForm((current) => ({ ...current, site_address_id: site.id }))
      setAddingSite(false)
    },
    onError: (error: unknown) => {
      toast({ variant: 'destructive', title: 'Failed to add site', description: getApiErrorMessage(error) })
    },
  })

  const save = useMutation({
    mutationFn: async () => {
      const payload = contactFormToPayload(form)
      return contact
        ? api.put(`/customers/${customerId}/contacts/${contact.id}`, payload)
        : api.post(`/customers/${customerId}/contacts`, payload)
    },
    onSuccess: () => {
      toast({ title: isEdit ? 'Contact updated successfully' : 'Contact added successfully' })
      onSaved()
      onOpenChange(false)
    },
    onError: (error: unknown) => {
      toast({
        variant: 'destructive',
        title: isEdit ? 'Failed to update contact' : 'Failed to add contact',
        description: getApiErrorMessage(error),
      })
    },
  })

  const set = (patch: Partial<ContactFormState>) => setForm((current) => ({ ...current, ...patch }))
  const toggleRole = (code: string, checked: boolean) =>
    set({
      persistent_role_codes: checked
        ? [...form.persistent_role_codes, code]
        : form.persistent_role_codes.filter((value) => value !== code),
    })
  const oldAddress = legacyPersonalAddress(contact)
  // Editing replaces the person's roles/category/site; never save before the
  // current values have loaded, or they would be wiped.
  const waitingForCanonical = isEdit && Boolean(organisationId) && Boolean(personId) && !canonical

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] w-[calc(100%-2rem)] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="px-6 pt-6">
          <DialogTitle>{isEdit ? 'Edit Contact' : 'Add Contact'}</DialogTitle>
        </DialogHeader>
        <div className="space-y-4 overflow-y-auto px-6 py-1" data-testid="customer-contact-dialog">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="contact-name">Name *</Label>
              <Input id="contact-name" value={form.name} onChange={(e) => set({ name: e.target.value })} required />
            </div>
            <div className="space-y-2">
              <Label htmlFor="contact-job-title">Job Title</Label>
              <Input id="contact-job-title" value={form.job_title} onChange={(e) => set({ job_title: e.target.value })} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label>Category</Label>
              <Select
                value={form.functional_category_code || NONE}
                onValueChange={(value) => set({ functional_category_code: value === NONE ? '' : value })}
              >
                <SelectTrigger aria-label="Category"><SelectValue placeholder="Select category" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>None</SelectItem>
                  {options.categories.map((entry) => (
                    <SelectItem key={entry.code} value={entry.code}>{entry.display_label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Classification</Label>
              <Select
                value={form.classification_code || NONE}
                onValueChange={(value) => set({ classification_code: value === NONE ? '' : value })}
              >
                <SelectTrigger aria-label="Classification"><SelectValue placeholder="Select classification" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>None</SelectItem>
                  {options.classifications.map((entry) => (
                    <SelectItem key={entry.code} value={entry.code}>{entry.display_label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {options.roles.length > 0 && (
            <fieldset className="space-y-2">
              <legend className="text-sm font-medium">Roles at this company</legend>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {options.roles.map((entry) => (
                  <label key={entry.code} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-gray-300"
                      checked={form.persistent_role_codes.includes(entry.code)}
                      onChange={(e) => toggleRole(entry.code, e.target.checked)}
                    />
                    {entry.display_label}
                  </label>
                ))}
              </div>
            </fieldset>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="contact-department">Department</Label>
              <Input id="contact-department" value={form.department_name} onChange={(e) => set({ department_name: e.target.value })} />
            </div>
            <div className="space-y-2">
              <Label>Site</Label>
              <Select
                value={form.site_address_id || NONE}
                onValueChange={(value) => {
                  if (value === NEW_SITE) {
                    setAddingSite(true)
                    return
                  }
                  set({ site_address_id: value === NONE ? '' : value })
                }}
                disabled={!organisationId}
              >
                <SelectTrigger aria-label="Site"><SelectValue placeholder="Select site" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value={NONE}>No site</SelectItem>
                  {sites.map((site) => (
                    <SelectItem key={site.id} value={site.id}>{siteLabel(site)}</SelectItem>
                  ))}
                  <SelectItem value={NEW_SITE}>+ New site…</SelectItem>
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">Where this person works at the company.</p>
            </div>
          </div>

          {addingSite && (
            <div className="space-y-3 rounded-lg border p-3" data-testid="new-site-form">
              <p className="text-sm font-medium">New company site</p>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <Input aria-label="Site name" placeholder="Site name, e.g. Main Clinic" value={newSite.label} onChange={(e) => setNewSite({ ...newSite, label: e.target.value })} />
                <Input aria-label="Site street" placeholder="Street" value={newSite.line_1} onChange={(e) => setNewSite({ ...newSite, line_1: e.target.value })} />
                <Input aria-label="Site city" placeholder="City" value={newSite.city} onChange={(e) => setNewSite({ ...newSite, city: e.target.value })} />
                <Input aria-label="Site postal code" placeholder="Postal code" value={newSite.postal_code} onChange={(e) => setNewSite({ ...newSite, postal_code: e.target.value })} />
                <Input aria-label="Site country" placeholder="Country (ISO code, e.g. MY)" maxLength={2} value={newSite.country} onChange={(e) => setNewSite({ ...newSite, country: e.target.value })} />
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => setAddingSite(false)}>Cancel</Button>
                <Button type="button" size="sm" onClick={() => createSite.mutate()} disabled={!newSite.label.trim() || createSite.isPending}>
                  {createSite.isPending ? 'Adding…' : 'Add site'}
                </Button>
              </div>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="contact-email">Email</Label>
            <Input id="contact-email" type="email" value={form.email} onChange={(e) => set({ email: e.target.value })} />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="contact-phone">Phone</Label>
              <Input id="contact-phone" value={form.phone} onChange={(e) => set({ phone: numericPhoneValue(e.target.value) })} inputMode="numeric" pattern="[0-9]*" />
            </div>
            <div className="space-y-2">
              <Label htmlFor="contact-mobile">Mobile</Label>
              <Input id="contact-mobile" value={form.mobile} onChange={(e) => set({ mobile: numericPhoneValue(e.target.value) })} inputMode="numeric" pattern="[0-9]*" />
            </div>
          </div>

          {canonicalFailed && (
            <p className="rounded-lg border border-destructive/40 p-3 text-sm text-destructive" role="alert">
              Could not load this contact's roles and site. Close and try again before saving.
            </p>
          )}

          {oldAddress && (
            <div className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground" data-testid="legacy-personal-address">
              <p className="font-medium text-foreground">Address saved on this person (older record)</p>
              <p>{oldAddress}</p>
              <p className="mt-1 text-xs">People no longer hold addresses — pick or add a company Site above. This address is kept until it is moved to the company.</p>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="contact-notes">Notes</Label>
            <Textarea id="contact-notes" value={form.notes} onChange={(e) => set({ notes: e.target.value })} />
          </div>
          <div className="flex items-center space-x-2">
            <input
              type="checkbox"
              id="contact-is-primary"
              checked={form.is_primary}
              onChange={(e) => set({ is_primary: e.target.checked })}
              className="h-4 w-4 rounded border-gray-300"
            />
            <Label htmlFor="contact-is-primary" className="font-normal">Primary contact</Label>
          </div>
        </div>
        <DialogFooter className="border-t bg-background px-6 py-4">
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button
            onClick={() => save.mutate()}
            disabled={!form.name.trim() || save.isPending || waitingForCanonical}
          >
            {isEdit ? (save.isPending ? 'Saving...' : 'Save Changes') : (save.isPending ? 'Adding...' : 'Add Contact')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
