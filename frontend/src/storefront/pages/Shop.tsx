import { useState, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Filter, Grid, LayoutGrid } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { QuickViewModal } from '@/storefront/components/products/QuickViewModal';
import { FilterPanel } from '@/storefront/components/shop/FilterPanel';
import { Button } from '@/storefront/components/ui/button';
import { PRODUCTS, Product, Brand, ProductType, Collection, TYPES, BRANDS } from '@/storefront/data/products';
import { cn } from '@/lib/utils';

const Shop = () => {
  const [searchParams] = useSearchParams();

  const normalizeParam = <T extends readonly string[]>(value: string | null, allowed: T): T[number] | null => {
    if (!value) return null;
    const match = allowed.find((v) => v.toLowerCase() === value.toLowerCase());
    return (match ?? null) as T[number] | null;
  };

  const initialBrand = normalizeParam(searchParams.get('brand'), BRANDS) as Brand | null;
  const initialType = normalizeParam(searchParams.get('type'), TYPES) as ProductType | null;

  const [selectedBrands, setSelectedBrands] = useState<Brand[]>(
    initialBrand ? [initialBrand] : []
  );
  const [selectedTypes, setSelectedTypes] = useState<ProductType[]>(
    initialType ? [initialType] : []
  );
  const [selectedCollections, setSelectedCollections] = useState<Collection[]>([]);
  const [priceRange, setPriceRange] = useState<[number, number]>([0, 150]);
  const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);
  const [showMobileFilters, setShowMobileFilters] = useState(false);
  const [gridSize, setGridSize] = useState<'small' | 'large'>('large');

  const filteredProducts = useMemo(() => {
    return PRODUCTS.filter((product) => {
      if (selectedBrands.length > 0 && !selectedBrands.includes(product.brand as Brand)) {
        return false;
      }
      if (selectedTypes.length > 0 && !selectedTypes.includes(product.type)) {
        return false;
      }
      if (selectedCollections.length > 0 && !selectedCollections.includes(product.collection as Collection)) {
        return false;
      }
      if (product.price < priceRange[0] || product.price > priceRange[1]) {
        return false;
      }
      return true;
    });
  }, [selectedBrands, selectedTypes, selectedCollections, priceRange]);

  const clearAllFilters = () => {
    setSelectedBrands([]);
    setSelectedTypes([]);
    setSelectedCollections([]);
    setPriceRange([0, 150]);
  };

  return (
    <Layout>
      <div className="container py-8 md:py-12">
        {/* Header */}
        <div className="mb-8">
          <h1 className="text-3xl md:text-4xl font-display font-bold mb-2">
            Shop <span className="gradient-text">All Products</span>
          </h1>
          <p className="text-muted-foreground">
            {filteredProducts.length} products available
          </p>
        </div>

        <div className="flex gap-8">
          {/* Desktop Filters */}
          <aside className="hidden lg:block w-64 shrink-0">
            <div className="sticky top-24 glass rounded-xl p-6">
              <FilterPanel
                selectedBrands={selectedBrands}
                selectedTypes={selectedTypes}
                selectedCollections={selectedCollections}
                priceRange={priceRange}
                onBrandChange={setSelectedBrands}
                onTypeChange={setSelectedTypes}
                onCollectionChange={setSelectedCollections}
                onPriceChange={setPriceRange}
                onClearAll={clearAllFilters}
              />
            </div>
          </aside>

          {/* Main Content */}
          <div className="flex-1">
            {/* Toolbar */}
            <div className="flex items-center justify-between mb-6 gap-4">
              {/* Mobile Filter Button */}
              <Button
                variant="outline"
                className="lg:hidden"
                onClick={() => setShowMobileFilters(true)}
              >
                <Filter className="h-4 w-4 mr-2" />
                Filters
              </Button>

              {/* Active Filters (Mobile) */}
              <div className="flex-1 lg:hidden">
                {(selectedBrands.length > 0 || selectedTypes.length > 0) && (
                  <p className="text-sm text-muted-foreground">
                    {selectedBrands.length + selectedTypes.length} filters active
                  </p>
                )}
              </div>

              {/* Grid Toggle */}
              <div className="hidden sm:flex items-center gap-1 bg-muted rounded-lg p-1">
                <button
                  onClick={() => setGridSize('large')}
                  className={cn(
                    "p-2 rounded-md transition-colors",
                    gridSize === 'large' ? "bg-background shadow" : "hover:bg-background/50"
                  )}
                  aria-label="Large grid"
                >
                  <LayoutGrid className="h-4 w-4" />
                </button>
                <button
                  onClick={() => setGridSize('small')}
                  className={cn(
                    "p-2 rounded-md transition-colors",
                    gridSize === 'small' ? "bg-background shadow" : "hover:bg-background/50"
                  )}
                  aria-label="Small grid"
                >
                  <Grid className="h-4 w-4" />
                </button>
              </div>
            </div>

            {/* Products Grid */}
            {filteredProducts.length > 0 ? (
              <div
                className={cn(
                  "grid gap-4 md:gap-6",
                  gridSize === 'large'
                    ? "grid-cols-2 md:grid-cols-3"
                    : "grid-cols-2 sm:grid-cols-3 md:grid-cols-4"
                )}
              >
                {filteredProducts.map((product, index) => (
                  <div
                    key={product.id}
                    className="animate-fade-in"
                    style={{ animationDelay: `${index * 50}ms` }}
                  >
                    <ProductCard
                      product={product}
                      onQuickView={setQuickViewProduct}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center py-16">
                <p className="text-lg text-muted-foreground mb-4">
                  No products found matching your filters
                </p>
                <Button variant="neon" onClick={clearAllFilters}>
                  Clear All Filters
                </Button>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Mobile Filters Drawer */}
      {showMobileFilters && (
        <>
          <div
            className="fixed inset-0 bg-background/80 backdrop-blur-sm z-50 lg:hidden"
            onClick={() => setShowMobileFilters(false)}
          />
          <div className="fixed inset-y-0 left-0 w-full max-w-sm bg-card border-r border-border z-50 lg:hidden overflow-y-auto animate-slide-in-right">
            <FilterPanel
              selectedBrands={selectedBrands}
              selectedTypes={selectedTypes}
              selectedCollections={selectedCollections}
              priceRange={priceRange}
              onBrandChange={setSelectedBrands}
              onTypeChange={setSelectedTypes}
              onCollectionChange={setSelectedCollections}
              onPriceChange={setPriceRange}
              onClearAll={clearAllFilters}
              isMobile
              onClose={() => setShowMobileFilters(false)}
            />
          </div>
        </>
      )}

      {/* Quick View Modal */}
      <QuickViewModal
        product={quickViewProduct}
        isOpen={!!quickViewProduct}
        onClose={() => setQuickViewProduct(null)}
      />
    </Layout>
  );
};

export default Shop;
