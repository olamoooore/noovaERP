import { useQuery } from '@tanstack/react-query'
import api from '@/lib/api'
import type { SiteAddress } from '@/lib/customerContacts'

export const companySitesQueryKey = (organisationId?: string | null) => ['customer-contact-sites', organisationId]

export interface CompanySites {
  sites: SiteAddress[]
  /** Contact Master person id → the site they work at, for this company. */
  siteByPerson: Record<string, SiteAddress>
}

/**
 * ADR-003B: a company's Site addresses (Contact Master `contact_addresses`)
 * and which person works at which site (the org_person relationship's
 * `site_address_id`). One query, shared by the Addresses card and the
 * People cards.
 */
export function useCompanySites(organisationId?: string | null) {
  return useQuery<CompanySites>({
    queryKey: companySitesQueryKey(organisationId),
    queryFn: async () => {
      const [addresses, relationships] = await Promise.all([
        api.get(`/contacts/${organisationId}/addresses`),
        api.get(`/contacts/${organisationId}/relationships`),
      ])
      const sites = ((addresses.data?.data ?? []) as SiteAddress[]).filter((address) => address.address_type === 'site')
      const byId = new Map(sites.map((site) => [site.id, site]))
      const siteByPerson: Record<string, SiteAddress> = {}
      for (const row of (relationships.data?.data ?? []) as {
        relationship_type: string
        related_contact_id: string
        site_address_id?: string | null
      }[]) {
        const site = row.relationship_type === 'org_person' && row.site_address_id ? byId.get(row.site_address_id) : undefined
        if (site) siteByPerson[row.related_contact_id] = site
      }

      return { sites, siteByPerson }
    },
    enabled: Boolean(organisationId),
  })
}
