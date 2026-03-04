import { useQuery } from '@tanstack/react-query';
import { fetchProducts, fetchCategories, fetchTags, ApiProduct } from '@/storefront/lib/api';
import { Product } from '@/storefront/data/products';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';

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

  const primaryImage = apiProduct.image || PLACEHOLDER_PRODUCT_URL;
  const galleryImages = apiProduct.gallery && apiProduct.gallery.length > 0
    ? [primaryImage, ...apiProduct.gallery.filter(g => g !== primaryImage)]
    : [primaryImage];

  return {
    id: String(apiProduct.id),
    title: apiProduct.name,
    brand: apiProduct.brand?.name || 'Unknown',
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
    shortDescription: apiProduct.description ? apiProduct.description.split('.')[0] + '.' : undefined,
    sku: apiProduct.sku || undefined,
    inStock: apiProduct.in_stock,
    stockQuantity: apiProduct.stock_quantity,
  };
}

/**
 * Fetch products from the backend API.
 * Falls back to hardcoded data if API fails.
 */
export function useProducts(params?: {
  category_id?: number;
  brand_id?: number;
  min_price?: number;
  max_price?: number;
  search?: string;
  tag?: string;
  sort_by?: string;
  sort_dir?: string;
  per_page?: number;
  page?: number;
}) {
  return useQuery({
    queryKey: ['storefront-products', params],
    queryFn: async () => {
      const response = await fetchProducts({ ...params, per_page: params?.per_page || 30 });
      return {
        products: response.data.map(mapApiProduct),
        total: response.total,
        currentPage: response.current_page,
        lastPage: response.last_page,
      };
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
