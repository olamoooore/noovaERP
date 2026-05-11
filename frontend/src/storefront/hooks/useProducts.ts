import { useQuery } from '@tanstack/react-query';
import { fetchProducts, fetchCategories, fetchTags, ApiProduct, storefrontApi } from '@/storefront/lib/api';
import { Product } from '@/storefront/data/products';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';

/**
 * Check if a URL points directly to an image file.
 */
function isDirectImageUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    const ext = parsed.pathname.split('.').pop()?.toLowerCase() || '';
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'].includes(ext);
  } catch {
    return false;
  }
}

/**
 * Map backend API product to the storefront Product interface.
 * Keeps the original UI design working with real data.
 */
export function mapApiProduct(apiProduct: ApiProduct): Product {
  // Map tags from backend (meta_keywords) to storefront tag types
  const tagMapping: Record<string, 'limited' | 'preorder' | 'new' | 'bestseller'> = {
    limited: 'limited',
    'limited edition': 'limited',
    preorder: 'preorder',
    'pre-order': 'preorder',
    new: 'new',
    'new arrival': 'new',
    bestseller: 'bestseller',
    'best seller': 'bestseller',
    popular: 'bestseller',
  };

  const mappedTags: ('limited' | 'preorder' | 'new' | 'bestseller')[] = [];
  for (const tag of apiProduct.tags) {
    const mapped = tagMapping[tag.toLowerCase()];
    if (mapped && !mappedTags.includes(mapped)) {
      mappedTags.push(mapped);
    }
  }

  // Add auto-tags based on backend flags
  if (apiProduct.is_new_arrival && !mappedTags.includes('new')) {
    mappedTags.push('new');
  }
  if (apiProduct.is_preorder && !mappedTags.includes('preorder')) {
    mappedTags.push('preorder');
  }

  const primaryImage = apiProduct.image && (
    !apiProduct.image.startsWith('http') || isDirectImageUrl(apiProduct.image)
  ) ? apiProduct.image : PLACEHOLDER_PRODUCT_URL;
  const galleryImages = apiProduct.gallery && apiProduct.gallery.length > 0
    ? [primaryImage, ...apiProduct.gallery.filter(g => g !== primaryImage)]
    : [primaryImage];

  return {
    id: String(apiProduct.id),
    title: apiProduct.name,
    brand: apiProduct.secondary_name || apiProduct.manufacturer || apiProduct.brand?.name || 'Unknown',
    type: (apiProduct.category?.name as Product['type']) || 'Merchandise',
    collection: apiProduct.tags.find(t =>
      ['comeback 2025', 'limited edition', 'pre-order', 'regular'].includes(t.toLowerCase())
    ) || 'Regular',
    price: apiProduct.price,
    memberPrice: Math.round(apiProduct.price * 0.9 * 100) / 100, // 10% member discount
    image: primaryImage,
    images: galleryImages,
    tags: mappedTags,
    description: apiProduct.description || undefined,
    shortDescription: apiProduct.short_description || (apiProduct.description ? apiProduct.description.replace(/<[^>]*>/g, '').split('.')[0] + '.' : undefined),
    sku: apiProduct.sku || undefined,
    inStock: apiProduct.in_stock,
    stockQuantity: apiProduct.stock_quantity,
    isMerchandise: apiProduct.is_merchandise,
    isBundle: apiProduct.is_bundle,
    bundleType: apiProduct.bundle_type,
    bundleComponents: apiProduct.bundle_components.map((component) => ({
      id: String(component.id),
      quantity: component.quantity,
      componentProduct: component.component_product
        ? {
            id: String(component.component_product.id),
            title: component.component_product.name,
            price: component.component_product.price,
            image: component.component_product.image,
          }
        : null,
    })),
  };
}

/**
 * Fetch products from the backend API.
 * Falls back to hardcoded data if API fails.
 */
/**
 * Always force Infiniti Kpop tenant for storefront product queries.
 * This ensures /shop always shows Infiniti's products, not admin's.
 */
const INFINITI_TENANT_ID = '147b57e9-be37-4478-a55e-a382816cf7ec'; // Infiniti Kpop tenant UUID

export function useProducts(params?: {
  category_id?: number;
  brand_id?: number;
  min_price?: number;
  max_price?: number;
  search?: string;
  tag?: string;
  sort_by?: string;
  sort_dir?: string;
  event_id?: number;
  event_relation_type?: 'merchandise' | 'bundle';
  is_bundle?: boolean;
  per_page?: number;
  page?: number;
}) {
  return useQuery({
    queryKey: ['storefront-products', { ...params, tenant_id: INFINITI_TENANT_ID }],
    queryFn: async () => {
      // Always send X-Tenant-ID header for Infiniti
      const prevTenantId = localStorage.getItem('tenant_id');
      localStorage.setItem('tenant_id', INFINITI_TENANT_ID);
      try {
        const response = await storefrontApi.get('/website-public/products', {
          params: { ...params, per_page: params?.per_page || 30 },
          headers: { 'X-Tenant-ID': INFINITI_TENANT_ID },
        });
        return {
          products: response.data.data.map(mapApiProduct),
          total: response.data.total,
          currentPage: response.data.current_page,
          lastPage: response.data.last_page,
        };
      } finally {
        if (prevTenantId) {
          localStorage.setItem('tenant_id', prevTenantId);
        } else {
          localStorage.removeItem('tenant_id');
        }
      }
    },
    staleTime: 1000 * 60 * 5, // 5 minutes
  });
}

/**
 * Fetch categories from the backend.
 */
export function useCategories() {
  return useQuery({
    queryKey: ['storefront-categories'],
    queryFn: fetchCategories,
    staleTime: 1000 * 60 * 10, // 10 minutes
  });
}

/**
 * Fetch tags from the backend.
 */
export function useTags() {
  return useQuery({
    queryKey: ['storefront-tags'],
    queryFn: fetchTags,
    staleTime: 1000 * 60 * 10,
  });
}
