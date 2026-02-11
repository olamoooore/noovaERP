import { useState, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Filter, Grid, LayoutGrid, Loader2, ChevronLeft, ChevronRight } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { QuickViewModal } from '@/storefront/components/products/QuickViewModal';
import { FilterPanel } from '@/storefront/components/shop/FilterPanel';
import { Button } from '@/storefront/components/ui/button';
import { Product, Brand, ProductType, Collection, TYPES, BRANDS } from '@/storefront/data/products';
import { useProducts } from '@/storefront/hooks/useProducts';
import { cn } from '@/lib/utils';

const PRODUCTS_PER_PAGE = 30;

const Shop = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  
  const currentPage = parseInt(searchParams.get('page') || '1', 10);

  // Fetch real products from backend with server-side pagination
  const { data, isLoading, error } = useProducts({ per_page: PRODUCTS_PER_PAGE, page: currentPage });

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
  const [priceRange, setPriceRange] = useState<[number, number]>([0, 500]);
  const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);
  const [showMobileFilters, setShowMobileFilters] = useState(false);
  const [gridSize, setGridSize] = useState<'small' | 'large'>('large');

  // Use real products from API, with client-side filtering
  const products = data?.products || [];
  const totalProducts = data?.total || 0;
  const lastPage = data?.lastPage || 1;
  
  const filteredProducts = useMemo(() => {
    return products.filter((product) => {
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
  }, [products, selectedBrands, selectedTypes, selectedCollections, priceRange]);

  const clearAllFilters = () => {
    setSelectedBrands([]);
    setSelectedTypes([]);
    setSelectedCollections([]);
    setPriceRange([0, 500]);
  };

  const goToPage = (page: number) => {
    const params = new URLSearchParams(searchParams);
    params.set('page', String(page));
    setSearchParams(params);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // Generate page numbers to display
  const getPageNumbers = () => {
    const pages: (number | '...')[] = [];
    if (lastPage <= 7) {
      for (let i = 1; i <= lastPage; i++) pages.push(i);
    } else {
      pages.push(1);
      if (currentPage > 3) pages.push('...');
      const start = Math.max(2, currentPage - 1);
      const end = Math.min(lastPage - 1, currentPage + 1);
      for (let i = start; i <= end; i++) pages.push(i);
      if (currentPage < lastPage - 2) pages.push('...');
      pages.push(lastPage);
    }
    return pages;
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
            {totalProducts} products available{lastPage > 1 ? ` • Page ${currentPage} of ${lastPage}` : ''}
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
            {isLoading ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                <span className="ml-3 text-muted-foreground">Loading products...</span>
              </div>
            ) : filteredProducts.length > 0 ? (
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

            {/* Pagination */}
            {lastPage > 1 && !isLoading && (
              <div className="flex items-center justify-center gap-2 mt-10">
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => goToPage(currentPage - 1)}
                  disabled={currentPage <= 1}
                  className="h-9 w-9"
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>

                {getPageNumbers().map((page, i) =>
                  page === '...' ? (
                    <span key={`dots-${i}`} className="px-2 text-muted-foreground">...</span>
                  ) : (
                    <Button
                      key={page}
                      variant={page === currentPage ? 'default' : 'outline'}
                      size="icon"
                      onClick={() => goToPage(page as number)}
                      className={cn(
                        'h-9 w-9',
                        page === currentPage && 'bg-primary text-primary-foreground'
                      )}
                    >
                      {page}
                    </Button>
                  )
                )}

                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => goToPage(currentPage + 1)}
                  disabled={currentPage >= lastPage}
                  className="h-9 w-9"
                >
                  <ChevronRight className="h-4 w-4" />
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
