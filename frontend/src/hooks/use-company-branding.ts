import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface CompanyBranding {
  /** Tenant company name (company_settings.company_name). */
  companyName?: string;
  /** Tenant logo URL, when one is configured. */
  companyLogo?: string;
}

interface BrandingPayload {
  company_name?: string | null;
  company_logo_url?: string | null;
}

const fetchBranding = async (): Promise<BrandingPayload> => {
  const response = await api.get('/accounting/settings/branding');
  return (response.data?.data ?? {}) as BrandingPayload;
};

/** Shared React Query options — also used to ensure data on click. */
export const companyBrandingQueryOptions = {
  queryKey: ['company-branding'] as const,
  queryFn: fetchBranding,
  staleTime: 10 * 60 * 1000,
};

export const toCompanyBranding = (data?: BrandingPayload | null): CompanyBranding => {
  const name = typeof data?.company_name === 'string' ? data.company_name.trim() : '';
  const rawLogo =
    typeof data?.company_logo_url === 'string' && data.company_logo_url.trim()
      ? data.company_logo_url.trim()
      : undefined;

  return {
    companyName: name || undefined,
    // Logos are stored as absolute URLs to the API host; use the /storage path
    // when present so PDF canvases stay same-origin (no CORS taint) in dev,
    // staging and production alike.
    companyLogo: rawLogo?.includes('/storage/')
      ? rawLogo.slice(rawLogo.indexOf('/storage/'))
      : rawLogo,
  };
};

/**
 * Tenant branding for documents generated outside the Template Builder
 * (report PDF/CSV/print exports). These must never fall back to the platform
 * brand — the fallback chain is tenant logo → tenant company name.
 */
export function useCompanyBranding(): CompanyBranding {
  const { data } = useQuery(companyBrandingQueryOptions);

  return toCompanyBranding(data);
}
