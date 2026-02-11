import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Product } from '@/storefront/data/products';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { QuickViewModal } from '@/storefront/components/products/QuickViewModal';
import { Button } from '@/storefront/components/ui/button';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { useProducts } from '@/storefront/hooks/useProducts';

export const FeaturedProducts = () => {
  const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);
  
  // Fetch real products from backend
  const { data, isLoading } = useProducts({ per_page: 20 });
  const products = data?.products || [];
  
  // Get featured products (limited, bestseller, or new) - if no tagged products, show first 8
  const featuredProducts = products.filter(
    p => p.tags.includes('limited') || p.tags.includes('bestseller') || p.tags.includes('new')
  ).slice(0, 8);
  
  // Fallback to first 8 products if no featured ones
  const displayProducts = featuredProducts.length > 0 ? featuredProducts : products.slice(0, 8);

  return (
    <section className="py-24 bg-secondary/5">
      <div className="container">
        {/* Header */}
        <div className="flex items-end justify-between mb-12">
          <div>
            <h2 className="text-4xl font-display font-bold mb-3 tracking-tight">
              Featured <span className="text-aura">Products</span>
            </h2>
            <p className="text-muted-foreground text-lg">
              Discover our hottest drops and fan favorites
            </p>
          </div>
          <Button variant="ghost" asChild className="hidden md:flex hover:bg-white hover:shadow-sm">
            <Link to={storefrontRoutes.shop}>
              View All
              <Icon icon="solar:arrow-right-linear" className="h-5 w-5 ml-2" />
            </Link>
          </Button>
        </div>

        {/* Products Grid */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 lg:gap-8">
          {isLoading ? (
            // Loading skeleton
            [...Array(8)].map((_, i) => (
              <div key={i} className="animate-pulse">
                <div className="aspect-square bg-muted rounded-lg mb-3" />
                <div className="h-4 bg-muted rounded w-3/4 mb-2" />
                <div className="h-4 bg-muted rounded w-1/2" />
              </div>
            ))
          ) : (
            displayProducts.map((product, index) => (
              <div
                key={product.id}
                className="animate-fade-in-up"
                style={{ animationDelay: `${index * 100}ms` }}
              >
                <ProductCard
                  product={product}
                  onQuickView={setQuickViewProduct}
                />
              </div>
            ))
          )}
        </div>

        {/* Mobile View All */}
        <div className="mt-12 text-center md:hidden">
          <Button className="w-full rounded-full bg-foreground text-background" asChild>
            <Link to={storefrontRoutes.shop}>
              View All Products
              <Icon icon="solar:arrow-right-linear" className="h-5 w-5 ml-2" />
            </Link>
          </Button>
        </div>
      </div>

      {/* Quick View Modal */}
      <QuickViewModal
        product={quickViewProduct}
        isOpen={!!quickViewProduct}
        onClose={() => setQuickViewProduct(null)}
      />
    </section>
  );
};
