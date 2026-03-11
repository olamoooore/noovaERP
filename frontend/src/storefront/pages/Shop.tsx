import { useState, useMemo, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Filter, Grid, LayoutGrid, Loader2, ChevronLeft, ChevronRight, X, Search } from 'lucide-react';
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
  const searchQuery = searchParams.get('search') || '';
  const sortBy = searchParams.get('sort') || 'newest';

  // Local search input state
  const [searchInput, setSearchInput] = useState(searchQuery);

  // Sync searchInput with URL parameter
  useEffect(() => {
    setSearchInput(searchQuery);
  }, [searchQuery]);

  // Fetch real products from backend with server-side pagination
  const { data, isLoading, error } = useProducts({ 
    per_page: PRODUCTS_PER_PAGE, 
    page: currentPage,
    search: searchQuery || undefined,
  });

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
  const [selectedTags, setSelectedTags] = useState<string[]>([]);
  const [showOutOfStock, setShowOutOfStock] = useState(true); // Toggle for out-of-stock products
  const [quickViewProduct, setQuickViewProduct] = useState<Product | null>(null);
  const [showMobileFilters, setShowMobileFilters] = useState(false);
  const [gridSize, setGridSize] = useState<'small' | 'large'>('large');

  // Use real products from API, with client-side filtering and sorting
  const products = data?.products || [];
  const totalProducts = data?.total || 0;
  const lastPage = data?.lastPage || 1;

  // Derive available brands from actual product data
  const availableBrands = useMemo(() => {
    const brands = new Set<string>();
    products.forEach(p => {
      if (p.brand && p.brand !== 'Unknown') brands.add(p.brand);
    });
    return Array.from(brands).sort();
  }, [products]);
  
  const filteredProducts = useMemo(() => {
    let filtered = products.filter((product) => {
      // Hide out-of-stock products if toggle is off
      if (!showOutOfStock && (product.inStock === false || (product.stockQuantity || 0) === 0)) {
        return false;
      }
      
      if (selectedBrands.length > 0 && !selectedBrands.includes(product.brand as Brand)) {
        return false;
      }
      if (selectedTypes.length > 0 && !selectedTypes.includes(product.type)) {
        return false;
      }
      if (selectedCollections.length > 0 && !selectedCollections.includes(product.collection as Collection)) {
        return false;
      }
      
      // Tag filtering
      if (selectedTags.length > 0) {
        const hasMatchingTag = selectedTags.some(tag => {
          if (tag === 'in-stock') return product.inStock !== false && (product.stockQuantity || 0) > 0;
          if (tag === 'out-of-stock') return product.inStock === false || (product.stockQuantity || 0) === 0;
          if (tag === 'preorder') return product.tags?.includes('preorder');
          if (tag === 'new') return product.tags?.includes('new');
          if (tag === 'sale') return product.memberPrice && product.memberPrice < product.price;
          return false;
        });
        if (!hasMatchingTag) return false;
      }
      
      return true;
    });

    // Apply sorting
    const sorted = [...filtered].sort((a, b) => {
      switch (sortBy) {
        case 'name-asc':
          return String(a.title || '').localeCompare(String(b.title || ''));
        case 'name-desc':
          return String(b.title || '').localeCompare(String(a.title || ''));
        case 'price-low':
          return (a.price || 0) - (b.price || 0);
        case 'price-high':
          return (b.price || 0) - (a.price || 0);
        case 'newest':
          return Number(b.id || 0) - Number(a.id || 0);
        default:
          return 0;
      }
    });

    return sorted;
  }, [products, selectedBrands, selectedTypes, selectedCollections, selectedTags, sortBy, showOutOfStock]);

  const clearAllFilters = () => {
    setSelectedBrands([]);
    setSelectedTypes([]);
    setSelectedCollections([]);
    setSelectedTags([]);
    // Also clear search query
    const params = new URLSearchParams(searchParams);
    params.delete('search');
    params.set('page', '1');
    setSearchParams(params);
    setSearchInput('');
  };

  const clearSearch = () => {
    const params = new URLSearchParams(searchParams);
    params.delete('search');
    params.set('page', '1');
    setSearchParams(params);
    setSearchInput('');
  };

  const handleSearch = (value: string) => {
    const params = new URLSearchParams(searchParams);
    if (value.trim()) {
      params.set('search', value.trim());
    } else {
      params.delete('search');
    }
    params.set('page', '1');
    setSearchParams(params);
  };

  const handleSortChange = (value: string) => {
    const params = new URLSearchParams(searchParams);
    params.set('sort', value);
    params.set('page', '1');
    setSearchParams(params);
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
            {searchQuery ? (
              <>
                Search Results for <span className="gradient-text">"{searchQuery}"</span>
              </>
            ) : (
              <>
                Shop <span className="gradient-text">All Products</span>
              </>
            )}
          </h1>
          <p className="text-muted-foreground">
            {totalProducts} products found{lastPage > 1 ? ` - Page ${currentPage} of ${lastPage}` : ''}
            {searchQuery && (
              <Button 
                variant="link" 
                className="px-2 ml-2 h-auto text-primary"
                onClick={clearSearch}
              >
                <X className="h-3 w-3 mr-1" />
                Clear search
              </Button>
            )}
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
                selectedTags={selectedTags}
                onBrandChange={setSelectedBrands}
                onTypeChange={setSelectedTypes}
                onCollectionChange={setSelectedCollections}
                onTagChange={setSelectedTags}
                onClearAll={clearAllFilters}
                availableBrands={availableBrands}
              />
            </div>
          </aside>

          {/* Main Content */}
          <div className="flex-1">
            {/* Search Bar and Sort - Aligned with Filter Title */}
            <div className="flex items-start gap-4 mb-6 pt-6">
              {/* Mobile Filter Button */}
              <Button
                variant="outline"
                className="lg:hidden shrink-0 h-10"
                onClick={() => setShowMobileFilters(true)}
              >
                <Filter className="h-4 w-4 mr-2" />
                Filters
              </Button>

              {/* Search Bar - Middle */}
              <div className="flex-1">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <input
                    type="text"
                    placeholder="Search products..."
                    value={searchInput}
                    onChange={(e) => {
                      setSearchInput(e.target.value);
                      handleSearch(e.target.value);
                    }}
                    className="w-full h-10 pl-10 pr-4 rounded-lg border border-input bg-background text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                  />
                  {searchInput && (
                    <button
                      type="button"
                      onClick={() => {
                        setSearchInput('');
                        clearSearch();
                      }}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                    >
                      <X className="h-4 w-4" />
                    </button>
                  )}
                </div>
              </div>

              {/* Sort Dropdown - Right */}
              <div className="shrink-0">
                <select
                  value={sortBy}
                  onChange={(e) => handleSortChange(e.target.value)}
                  className="h-10 px-4 rounded-lg border border-input bg-background text-sm focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer"
                >
                  <option value="name-asc">Name (A-Z)</option>
                  <option value="name-desc">Name (Z-A)</option>
                  <option value="price-low">Price (Low to High)</option>
                  <option value="price-high">Price (High to Low)</option>
                  <option value="newest">Newest First</option>
                </select>
              </div>
            </div>

            {/* Grid Toggle - Separate Row */}
            <div className="flex items-center justify-between mb-6">
              <div className="text-sm text-muted-foreground">
                {filteredProducts.length} {filteredProducts.length === 1 ? 'product' : 'products'}
              </div>
              
              <div className="flex items-center gap-3">
                {/* Out of Stock Toggle */}
                <label className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={showOutOfStock}
                    onChange={(e) => setShowOutOfStock(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <span className="text-sm text-muted-foreground">Show Out of Stock</span>
                </label>

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
              selectedTags={selectedTags}
              onBrandChange={setSelectedBrands}
              onTypeChange={setSelectedTypes}
              onCollectionChange={setSelectedCollections}
              onTagChange={setSelectedTags}
              onClearAll={clearAllFilters}
              availableBrands={availableBrands}
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
