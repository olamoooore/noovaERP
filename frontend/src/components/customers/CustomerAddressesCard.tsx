import { Icon } from '@iconify/react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { HeaderActionButton } from '@/components/layout/HeaderActionButton'
import { siteLabel, type SiteAddress } from '@/lib/customerContacts'

/**
 * ADR-003B W5: the customer owns its addresses, shown in one card grouped as
 * Billing, Delivery and Site (plus Other).
 *
 * Until W6 moves everything into the Contact Master, the card reads two
 * stores: billing/delivery/other come from `customer_addresses`, sites from
 * the Contact Master (`contact_addresses`). Older free-text addresses on the
 * customer row are shown read-only below.
 */
export interface CustomerAddressRow {
  id: number
  type: 'billing' | 'shipping' | 'other'
  label?: string | null
  street?: string | null
  street2?: string | null
  city?: string | null
  state?: string | null
  zip?: string | null
  country?: string | null
  is_default?: boolean
}

interface CustomerAddressesCardProps {
  addresses: CustomerAddressRow[]
  sites: SiteAddress[]
  /** Site id → names of the people who work there. */
  peopleBySite: Record<string, string[]>
  /** Older free-text addresses on the customer row, already formatted. */
  legacyBilling?: string | null
  legacyShipping?: string | null
  /** Sites need the customer's Contact Master organisation. */
  canAddSites: boolean
  onAdd: () => void
  onEdit: (address: CustomerAddressRow) => void
  onDelete: (address: CustomerAddressRow) => void
}

const formatRow = (address: CustomerAddressRow) =>
  [address.street, address.street2, address.city, address.state, address.zip, address.country].filter(Boolean).join(', ')

const formatSite = (site: SiteAddress) =>
  [site.line_1, site.line_2, site.city, site.postal_code, site.country].filter(Boolean).join(', ')

function Group({ title, icon, count, children, empty }: {
  title: string
  icon: string
  count: number
  children: React.ReactNode
  empty: string
}) {
  return (
    <section aria-label={title} className="space-y-2">
      <h3 className="flex items-center gap-2 text-sm font-semibold">
        <Icon icon={icon} className="h-4 w-4 text-muted-foreground" />
        {title}
      </h3>
      {count > 0 ? (
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">{children}</div>
      ) : (
        <p className="text-sm text-muted-foreground">{empty}</p>
      )}
    </section>
  )
}

export function CustomerAddressesCard({
  addresses,
  sites,
  peopleBySite,
  legacyBilling,
  legacyShipping,
  canAddSites,
  onAdd,
  onEdit,
  onDelete,
}: CustomerAddressesCardProps) {
  const billing = addresses.filter((address) => address.type === 'billing')
  const delivery = addresses.filter((address) => address.type === 'shipping')
  const other = addresses.filter((address) => address.type === 'other')

  const addressTile = (address: CustomerAddressRow) => (
    <div key={address.id} className="group relative rounded-xl border border-slate-200/80 bg-card p-4 transition-all hover:shadow-md dark:border-slate-800">
      <div className="absolute right-2 top-2 flex gap-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100">
        <Button size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={() => onEdit(address)} title="Edit address" aria-label="Edit address">
          <Icon icon="solar:pen-bold-duotone" className="h-3.5 w-3.5" />
        </Button>
        <Button
          size="sm"
          variant="ghost"
          className="h-7 w-7 p-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
          onClick={() => onDelete(address)}
          title="Delete address"
          aria-label="Delete address"
        >
          <Icon icon="solar:trash-bin-trash-bold-duotone" className="h-3.5 w-3.5" />
        </Button>
      </div>
      <div className="mb-1 flex flex-wrap items-center gap-2 pr-14">
        {address.label && <span className="text-sm font-medium">{address.label}</span>}
        {address.is_default && (
          <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
            Default
          </span>
        )}
      </div>
      <p className="text-sm leading-relaxed text-muted-foreground">{formatRow(address) || 'No address details'}</p>
    </div>
  )

  return (
    <Card className="rounded-xl border-slate-200/80 dark:border-slate-800" data-testid="customer-addresses-card">
      <CardHeader className="flex flex-row items-center justify-between space-y-0">
        <CardTitle className="flex items-center gap-2">
          <Icon icon="solar:map-point-bold" className="h-5 w-5" />
          Addresses
        </CardTitle>
        <HeaderActionButton variant="outline" icon="solar:add-circle-bold" onClick={onAdd}>
          Add Address
        </HeaderActionButton>
      </CardHeader>
      <CardContent className="space-y-6">
        <Group title="Billing" icon="solar:bill-list-bold-duotone" count={billing.length} empty="No billing address yet.">
          {billing.map(addressTile)}
        </Group>

        <Group title="Delivery" icon="solar:delivery-bold-duotone" count={delivery.length} empty="No delivery address yet.">
          {delivery.map(addressTile)}
        </Group>

        <Group
          title="Sites"
          icon="solar:buildings-2-bold-duotone"
          count={sites.length}
          empty={canAddSites ? 'No sites yet. Add one where people of this customer work.' : 'Sites are not available for this customer yet.'}
        >
          {sites.map((site) => (
            <div key={site.id} className="rounded-xl border border-slate-200/80 bg-card p-4 dark:border-slate-800" data-testid="customer-site">
              <p className="mb-1 text-sm font-medium">{siteLabel(site)}</p>
              <p className="text-sm leading-relaxed text-muted-foreground">{formatSite(site) || 'No address details'}</p>
              {(peopleBySite[site.id] ?? []).length > 0 && (
                <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                  <Icon icon="solar:users-group-rounded-linear" className="h-3.5 w-3.5 shrink-0" />
                  {peopleBySite[site.id].join(', ')}
                </p>
              )}
            </div>
          ))}
        </Group>

        {other.length > 0 && (
          <Group title="Other" icon="solar:map-point-linear" count={other.length} empty="">
            {other.map(addressTile)}
          </Group>
        )}

        {(legacyBilling || legacyShipping) && (
          <section aria-label="Older addresses" className="space-y-2 border-t pt-4">
            <h3 className="text-sm font-semibold text-muted-foreground">Older addresses (text, read-only)</h3>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
              {legacyBilling && (
                <div className="rounded-xl border border-dashed p-4">
                  <p className="mb-1 text-xs font-medium text-muted-foreground">Billing (older record)</p>
                  <p className="whitespace-pre-line text-sm">{legacyBilling}</p>
                </div>
              )}
              {legacyShipping && (
                <div className="rounded-xl border border-dashed p-4">
                  <p className="mb-1 text-xs font-medium text-muted-foreground">Delivery (older record)</p>
                  <p className="whitespace-pre-line text-sm">{legacyShipping}</p>
                </div>
              )}
            </div>
          </section>
        )}
      </CardContent>
    </Card>
  )
}
