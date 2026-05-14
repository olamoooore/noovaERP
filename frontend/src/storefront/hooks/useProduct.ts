import { useQuery } from '@tanstack/react-query';
import { fetchProduct } from '@/storefront/lib/api';
import { mapApiProduct } from '@/storefront/hooks/useProducts';
import { PRODUCTS } from '@/storefront/data/products';

/**
 * Fetches a single product by its storefront ID (string or number).
 * Falls back to the static products list if the API request fails.
 */
export function useProduct(id: string | undefined) {
  return useQuery({
    queryKey: ['storefront-product', id],
    queryFn: async () => {
      if (!id) throw new Error('No product ID provided');

      // Try the API first (backend IDs are numeric)
      const numericId = Number(id);
      if (!isNaN(numericId)) {
        try {
          const apiProduct = await fetchProduct(numericId, { include_all_products: true });
          return mapApiProduct(apiProduct);
        } catch {
          // fall through to static lookup below
        }
      }

      // Fallback: look up in the static product list (covers demo/slug IDs like "p001")
      const staticProduct = PRODUCTS.find(p => p.id === id);
      if (staticProduct) return staticProduct;

      throw new Error(`Product ${id} not found`);
    },
    enabled: !!id,
    staleTime: 1000 * 60 * 5, // 5 minutes
    retry: 1,
  });
}
