import { useState, useMemo, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Filter, Grid, LayoutGrid, Loader2, ChevronLeft, ChevronRight, X, Search, Ticket, Package2 } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { QuickViewModal } from '@/storefront/components/products/QuickViewModal';
import { FilterPanel } from '@/storefront/components/shop/FilterPanel';
import { Button, buttonVariants } from '@/storefront/components/ui/button';
import { useCart } from '@/storefront/context/CartContext';
import { Product, Brand, ProductType, Collection, TYPES, BRANDS } from '@/storefront/data/products';
import { useProducts } from '@/storefront/hooks/useProducts';
import { useStorefrontEvents } from '@/storefront/hooks/useEvents';
import { buildEventCommerce, getEventTimelineStatus, type Event } from '@/storefront/data/events';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';

const PRODUCTS_PER_PAGE = 30;

type ShopView = 'all' | 'merchandise' | 'limited' | 'bundles';

const SHOP_VIEWS: Array<{ value: ShopView; label: string; description: string }> = [
  { value: 'all', label: 'All Store', description: 'Everything available in the shop right now.' },
  { value: 'merchandise', label: 'Merchandise', description: 'Fan goods, lightsticks, wearables, and collectibles.' },
  { value: 'limited', label: 'Limited Drops', description: 'Limited edition, pre-order, and new campaign releases.' },
  { value: 'bundles', label: 'Event Bundles', description: 'Ticket-plus-merch packages and campaign sets.' },
];

function isMerchandiseProduct(product: Product): boolean {
  return Boolean(product.isMerchandise);
}

function isLimitedDropProduct(product: Product): boolean {
  return Boolean(
    product.tags?.includes('limited')
      || product.tags?.includes('preorder')
      || product.tags?.includes('new')
      || product.collection === 'Limited Edition'
      || product.collection === 'Pre-Order'
  );
}

function isBundleProduct(product: Product): boolean {
  if (product.isBundle) {
    return true;
  }

  const haystack = [product.title, product.description, product.shortDescription, product.fullDescription]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

  return /(bundle|package|fan pack|starter pack|ticket)/.test(haystack);
}

function matchesShopView(product: Product, view: ShopView): boolean {
  switch (view) {
    case 'merchandise':
      return isMerchandiseProduct(product);
    case 'limited':
      return isLimitedDropProduct(product);
    case 'bundles':
      return isBundleProduct(product);
    default:
      return true;
  }
}

function productMatchesControlFilters(
  product: Product,
  selectedBrands: Brand[],
  selectedTypes: ProductType[],
  selectedCollections: Collection[],
  selectedTags: string[],
  showOutOfStock: boolean,
) {
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

  if (selectedTags.length > 0) {
    const hasMatchingTag = selectedTags.some(tag => {
      if (tag === 'in-stock') return product.inStock !== false && (product.stockQuantity || 0) > 0;
      if (tag === 'out-of-stock') return product.inStock === false || (product.stockQuantity || 0) === 0;
      if (tag === 'preorder') return product.tags?.includes('preorder');
      if (tag === 'new') return product.tags?.includes('new');
      if (tag === 'sale') return product.memberPrice && product.memberPrice < product.price;
      return false;
    });

    if (!hasMatchingTag) {
      return false;
    }
  }

  return true;
}

type BundleOffer = {
  id: string;
  event: Event;
  products: Product[];
  merchUrl: string;
  eventUrl: string;
  startingPrice: number;
};

function buildEventCommerceUrls(event: Event) {
  const commerce = buildEventCommerce(event);
  const merchParams = new URLSearchParams({ view: 'merchandise', event: event.title });

  if (commerce.brand) {
    merchParams.set('brand', commerce.brand);
  }
  if (commerce.merchType) {
    merchParams.set('type', commerce.merchType);
  }
  if (commerce.merchSearch) {
    merchParams.set('search', commerce.merchSearch);
  }

  return {
    merchUrl: `${storefrontRoutes.shop}?${merchParams.toString()}`,
    eventUrl: `${storefrontRoutes.events}?event=${encodeURIComponent(event.title)}`,
  };
}

function productMatchesEventBundle(product: Product, event: Event): boolean {
  const commerce = buildEventCommerce(event);
  const haystack = [product.title, product.brand, product.type, product.description, product.shortDescription]
    .filter(Boolean)
    .join(' ')
    .toLowerCase();

  if (commerce.brand && product.brand !== commerce.brand) {
    return false;
  }

  if (commerce.merchType && product.type !== commerce.merchType) {
    return false;
  }

  if (!commerce.merchSearch) {
    return true;
  }

  const tokens = commerce.merchSearch
    .toLowerCase()
    .split(/\s+/)
    .filter((token) => token.length > 2);

  if (tokens.length === 0) {
    return true;
  }

  return tokens.some((token) => haystack.includes(token));
}

const Shop = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const { addToCart } = useCart();
  
  const currentPage = parseInt(searchParams.get('page') || '1', 10);
  const searchQuery = searchParams.get('search') || '';
  const sortBy = searchParams.get('sort') || 'newest';
  const eventCampaign = searchParams.get('event') || '';
  const linkedEventId = searchParams.get('event_id');
  const rawShopView = searchParams.get('view');
  const shopView: ShopView = SHOP_VIEWS.some((view) => view.value === rawShopView)
    ? (rawShopView as ShopView)
    : 'all';
  const hasExactMerchContext = Boolean(linkedEventId && shopView === 'merchandise');
  const hasExactBundleContext = Boolean(linkedEventId && shopView === 'bundles');

  // Local search input state
  const [searchInput, setSearchInput] = useState(searchQuery);
  const { data: storefrontEvents = [] } = useStorefrontEvents();

  // Sync searchInput with URL parameter
  useEffect(() => {
    setSearchInput(searchQuery);
  }, [searchQuery]);

  // Fetch real products from backend with server-side pagination
  const { data, isLoading, error } = useProducts({ 
    per_page: PRODUCTS_PER_PAGE, 
    page: currentPage,
    search: searchQuery || undefined,
    event_id: hasExactMerchContext ? Number(linkedEventId) : undefined,
    event_relation_type: hasExactMerchContext ? 'merchandise' : undefined,
  });
  const { data: bundleData, isLoading: isLoadingBundleProducts } = useProducts({
    per_page: PRODUCTS_PER_PAGE,
    page: currentPage,
    search: searchQuery || undefined,
    is_bundle: true,
    event_id: hasExactBundleContext ? Number(linkedEventId) : undefined,
    event_relation_type: hasExactBundleContext ? 'bundle' : undefined,
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

  useEffect(() => {
    setSelectedBrands(initialBrand ? [initialBrand] : []);
  }, [initialBrand]);

  useEffect(() => {
    setSelectedTypes(initialType ? [initialType] : []);
  }, [initialType]);

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

  const filteredByControls = useMemo(() => {
    return products.filter((product) => productMatchesControlFilters(
      product,
      selectedBrands,
      selectedTypes,
      selectedCollections,
      selectedTags,
      showOutOfStock,
    ));
  }, [products, selectedBrands, selectedTypes, selectedCollections, selectedTags, showOutOfStock]);

  const filteredBundleProducts = useMemo(() => {
    return (bundleData?.products || []).filter((product) => productMatchesControlFilters(
      product,
      selectedBrands,
      selectedTypes,
      selectedCollections,
      selectedTags,
      showOutOfStock,
    ));
  }, [bundleData?.products, selectedBrands, selectedTypes, selectedCollections, selectedTags, showOutOfStock]);

  const bundleOffers = useMemo<BundleOffer[]>(() => {
    const normalizedCampaign = eventCampaign.trim().toLowerCase();

    return storefrontEvents
      .filter((event) => getEventTimelineStatus(event) !== 'past')
      .filter((event) => {
        if (!normalizedCampaign) {
          return true;
        }

        return event.title.toLowerCase().includes(normalizedCampaign);
      })
      .map((event) => {
        const matchingProducts = filteredByControls
          .filter((product) => productMatchesEventBundle(product, event))
          .slice(0, 3);

        if (matchingProducts.length === 0) {
          return null;
        }

        const cheapestProduct = matchingProducts.reduce((lowest, current) => {
          return current.price < lowest.price ? current : lowest;
        }, matchingProducts[0]);

        return {
          id: `bundle-${event.id}`,
          event,
          products: matchingProducts,
          ...buildEventCommerceUrls(event),
          startingPrice: event.price + cheapestProduct.price,
        };
      })
      .filter((offer): offer is BundleOffer => offer !== null);
  }, [eventCampaign, filteredByControls, storefrontEvents]);

  const realBundleProducts = useMemo(() => {
    return filteredBundleProducts.filter((product) => matchesShopView(product, 'bundles'));
  }, [filteredBundleProducts]);

  const displayBundleCount = hasExactBundleContext
    ? realBundleProducts.length
    : (realBundleProducts.length > 0 ? realBundleProducts.length : bundleOffers.length);

  const shopViewCounts = useMemo(() => {
    return SHOP_VIEWS.reduce<Record<ShopView, number>>((acc, view) => {
      if (view.value === 'all') {
        acc[view.value] = totalProducts;
        return acc;
      }

      acc[view.value] = view.value === 'bundles'
        ? displayBundleCount
        : filteredByControls.filter((product) => matchesShopView(product, view.value)).length;
      return acc;
    }, {
      all: 0,
      merchandise: 0,
      limited: 0,
      bundles: 0,
    });
  }, [displayBundleCount, filteredByControls, totalProducts]);

  const filteredProducts = useMemo(() => {
    let filtered = filteredByControls.filter((product) => matchesShopView(product, shopView));

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
  }, [filteredByControls, shopView, sortBy]);

  const currentShopView = SHOP_VIEWS.find((view) => view.value === shopView) || SHOP_VIEWS[0];
  const eventAwareTitle = eventCampaign
    ? `${currentShopView.label} for ${eventCampaign}`
    : currentShopView.label;
  const resultSummary = shopView === 'bundles'
    ? `${displayBundleCount} ${displayBundleCount === 1 ? 'bundle offer' : 'bundle offers'}`
    : `${totalProducts} ${totalProducts === 1 ? 'product' : 'products'}`;
  const visibleSummary = shopView === 'bundles'
    ? `${displayBundleCount} ${displayBundleCount === 1 ? 'bundle offer' : 'bundle offers'} visible`
    : `${filteredProducts.length} ${filteredProducts.length === 1 ? 'product' : 'products'} visible`;
  const headerTitle = searchQuery ? `Search: "${searchQuery}"` : 'Shop';

  const setShopViewParam = (nextView: ShopView) => {
    const params = new URLSearchParams(searchParams);
    if (nextView === 'all') {
      params.delete('view');
    } else {
      params.set('view', nextView);
    }
    params.set('page', '1');
    setSearchParams(params);
  };

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

  const handleAddBundleToCart = (offer: BundleOffer) => {
    const shippableProducts = offer.products.filter(
      (product) => product.inStock !== false && (product.stockQuantity ?? 1) > 0
    );

    if (shippableProducts.length === 0) {
      toast.error('This bundle does not have in-stock merchandise right now.');
      return;
    }

    shippableProducts.forEach((product) => addToCart(product));

    toast.success(`${offer.event.title} merch set added to cart`, {
      description: `${shippableProducts.length} item${shippableProducts.length === 1 ? '' : 's'} added. Complete ticket access from the event page.`,
    });
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
        <div className="mb-6 rounded-[24px] border border-border/60 bg-card/80 p-4 shadow-sm md:p-5">
          <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-3">
                <h1 className="text-2xl font-display font-bold md:text-3xl">
                  {headerTitle}
                </h1>
                <span className="rounded-full border border-border/60 bg-background px-3 py-1 text-sm text-muted-foreground">
                  {resultSummary}
                </span>
              </div>

              {eventCampaign ? (
                <div className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                  <span className="rounded-full border border-primary/15 bg-primary/5 px-3 py-1.5 font-medium text-foreground">
                    Campaign: {eventCampaign}
                  </span>
                  <Link to={storefrontRoutes.events} className="font-medium text-primary hover:underline">
                    Back to Events
                  </Link>
                </div>
              ) : (
                <p className="mt-2 text-sm text-muted-foreground">
                  Merchandise, limited drops, and bundles.
                </p>
              )}
            </div>

            {searchQuery && (
              <Button
                variant="link"
                className="h-auto shrink-0 px-0 text-primary"
                onClick={clearSearch}
              >
                <X className="mr-1 h-3 w-3" />
                Clear search
              </Button>
            )}
          </div>

          <div className="mt-4 flex flex-wrap gap-2">
            {SHOP_VIEWS.map((view) => (
              <button
                key={view.value}
                type="button"
                onClick={() => setShopViewParam(view.value)}
                className={cn(
                  'rounded-full border px-4 py-2 text-sm transition',
                  shopView === view.value
                    ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                    : 'border-border bg-background hover:border-primary/40 hover:text-foreground'
                )}
              >
                {view.label}
                <span className="ml-2 text-xs opacity-80">{shopViewCounts[view.value]}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="flex gap-8">
          {/* Desktop Filters */}
          <aside className="hidden lg:block w-64 shrink-0">
            <div className="sticky top-24 glass max-h-[calc(100vh-7rem)] overflow-y-auto rounded-xl p-6">
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
            <div className="mb-6 rounded-3xl border border-border/60 bg-card/70 p-4 md:p-5">
              <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                <div>
                  <div className="mt-2 flex flex-wrap items-center gap-3">
                    <h2 className="text-xl font-display font-bold text-foreground">{eventAwareTitle}</h2>
                    <span className="rounded-full border border-border/60 bg-background px-3 py-1 text-sm text-muted-foreground">
                      {visibleSummary}
                    </span>
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 text-sm">
                  <Link to={storefrontRoutes.events} className="font-medium text-primary hover:underline">
                    Need tickets? Go to Events
                  </Link>
                </div>
              </div>

              <div className="mt-5 flex flex-col gap-4 xl:flex-row xl:items-center">
                <Button
                  variant="outline"
                  className="h-10 shrink-0 lg:hidden"
                  onClick={() => setShowMobileFilters(true)}
                >
                  <Filter className="mr-2 h-4 w-4" />
                  Filters
                </Button>

                <div className="relative flex-1">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <input
                    type="text"
                    placeholder="Search products..."
                    value={searchInput}
                    onChange={(e) => {
                      setSearchInput(e.target.value);
                      handleSearch(e.target.value);
                    }}
                    className="h-11 w-full rounded-xl border border-input bg-background pl-10 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
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

                <select
                  value={sortBy}
                  onChange={(e) => handleSortChange(e.target.value)}
                  className="h-11 rounded-xl border border-input bg-background px-4 text-sm focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer xl:min-w-[190px]"
                >
                  <option value="name-asc">Name (A-Z)</option>
                  <option value="name-desc">Name (Z-A)</option>
                  <option value="price-low">Price (Low to High)</option>
                  <option value="price-high">Price (High to Low)</option>
                  <option value="newest">Newest First</option>
                </select>

                <label className="flex h-11 shrink-0 items-center gap-2 rounded-xl border border-border/60 bg-background px-4 cursor-pointer">
                  <input
                    type="checkbox"
                    checked={showOutOfStock}
                    onChange={(e) => setShowOutOfStock(e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                  />
                  <span className="text-sm text-muted-foreground">Show out of stock</span>
                </label>

                <div className="hidden items-center gap-1 rounded-xl bg-muted p-1 sm:flex">
                  <button
                    onClick={() => setGridSize('large')}
                    className={cn(
                      'rounded-md p-2 transition-colors',
                      gridSize === 'large' ? 'bg-background shadow' : 'hover:bg-background/50'
                    )}
                    aria-label="Large grid"
                  >
                    <LayoutGrid className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => setGridSize('small')}
                    className={cn(
                      'rounded-md p-2 transition-colors',
                      gridSize === 'small' ? 'bg-background shadow' : 'hover:bg-background/50'
                    )}
                    aria-label="Small grid"
                  >
                    <Grid className="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>

            {/* Products Grid */}
            {isLoading || (shopView === 'bundles' && isLoadingBundleProducts) ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                <span className="ml-3 text-muted-foreground">Loading products...</span>
              </div>
            ) : shopView === 'bundles' ? (
              realBundleProducts.length > 0 ? (
                <div className="space-y-6">
                  <div className="rounded-2xl border border-border/60 bg-card/70 p-4 text-sm text-muted-foreground">
                    Live bundle products are available. These packages can go straight through the normal webshop cart and checkout flow.
                  </div>
                  <div
                    className={cn(
                      "grid gap-4 md:gap-6",
                      gridSize === 'large'
                        ? "grid-cols-2 md:grid-cols-3"
                        : "grid-cols-2 sm:grid-cols-3 md:grid-cols-4"
                    )}
                  >
                    {realBundleProducts.map((product, index) => (
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
                </div>
              ) : !hasExactBundleContext && bundleOffers.length > 0 ? (
                <div className="grid gap-5 lg:grid-cols-2">
                  {bundleOffers.map((offer, index) => (
                    <div
                      key={offer.id}
                      className="rounded-3xl border border-border/60 bg-card/70 p-5 shadow-sm animate-fade-in"
                      style={{ animationDelay: `${index * 60}ms` }}
                    >
                      <div className="flex flex-col gap-5 md:flex-row">
                        <div className="md:w-44 shrink-0 overflow-hidden rounded-2xl bg-muted">
                          <img
                            src={offer.event.image}
                            alt={offer.event.title}
                            className="h-full w-full object-cover"
                          />
                        </div>

                        <div className="flex-1 space-y-4">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-primary">
                              Bundle Prototype
                            </span>
                            <span className="rounded-full bg-background px-3 py-1 text-xs text-muted-foreground border border-border/60">
                              {getEventTimelineStatus(offer.event) === 'current' ? 'Live Event' : 'Upcoming Event'}
                            </span>
                          </div>

                          <div>
                            <h3 className="text-2xl font-display font-bold text-foreground">{offer.event.title}</h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                              Ticket access plus curated merchandise from the same campaign journey.
                            </p>
                          </div>

                          <div className="grid gap-2 text-sm text-muted-foreground">
                            <div className="flex items-center gap-2">
                              <Ticket className="h-4 w-4 text-primary" />
                              <span>Ticket from SGD {offer.event.price.toFixed(2)}</span>
                            </div>
                            <div className="flex items-center gap-2">
                              <Package2 className="h-4 w-4 text-primary" />
                              <span>{offer.products.length} merch item{offer.products.length === 1 ? '' : 's'} matched to this campaign</span>
                            </div>
                          </div>

                          <div className="rounded-2xl border border-border/60 bg-background/70 p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">Included Merch Ideas</p>
                            <div className="mt-3 flex flex-wrap gap-2">
                              {offer.products.map((product) => (
                                <span key={product.id} className="rounded-full border border-border bg-card px-3 py-1 text-sm text-foreground">
                                  {product.title}
                                </span>
                              ))}
                            </div>
                          </div>

                          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                              <p className="text-xs uppercase tracking-[0.18em] text-muted-foreground">Bundle starting from</p>
                              <p className="text-2xl font-display font-bold text-foreground">SGD {offer.startingPrice.toFixed(2)}</p>
                              <p className="mt-1 text-xs text-muted-foreground">Merch goes to cart now. Ticket selection continues on the event page.</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                              <Button variant="outline" className="min-w-[140px]" onClick={() => handleAddBundleToCart(offer)}>
                                Add Merch Set
                              </Button>
                              <Link to={offer.eventUrl} className={cn('min-w-[140px]', 'inline-flex', 'items-center', 'justify-center', 'rounded-lg', 'px-4', 'py-2', buttonVariants({ variant: 'default' }))}>
                                Pick Ticket
                              </Link>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-16">
                  <p className="text-lg text-muted-foreground mb-3">No event bundles are live yet.</p>
                  <p className="text-sm text-muted-foreground mb-5 max-w-xl mx-auto">
                    We looked for active or upcoming events with matching campaign merchandise but did not find a usable bundle combination under the current filters.
                  </p>
                  <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <Button variant="neon" onClick={clearAllFilters}>
                      Clear All Filters
                    </Button>
                    <Link to={storefrontRoutes.events} className="text-sm font-medium text-primary hover:underline">
                      Browse Events Instead
                    </Link>
                  </div>
                </div>
              )
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
                <p className="text-lg text-muted-foreground mb-3">No products found matching your filters.</p>
                <p className="text-sm text-muted-foreground mb-5 max-w-xl mx-auto">
                  Try another tab, adjust your filters, or clear the current search.
                </p>
                <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
                  <Button variant="neon" onClick={clearAllFilters}>
                    Clear All Filters
                  </Button>
                </div>
              </div>
            )}

            {/* Pagination */}
            {shopView !== 'bundles' && lastPage > 1 && !isLoading && (
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
