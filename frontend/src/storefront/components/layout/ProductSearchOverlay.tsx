import { useState, useEffect, useRef, useCallback, ChangeEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Button } from '@/storefront/components/ui/button';
import { cn } from '@/lib/utils';
import { storefrontRoutes } from '@/storefront/lib/paths';
import api from '@/lib/api';

interface Product {
  id: number;
  name: string;
  secondary_name?: string;
  sku: string;
  price: number;
  image?: string;
  stock_quantity?: number;
  category?: {
    id: number;
    name: string;
  };
}

interface ProductSearchOverlayProps {
  isOpen: boolean;
  onClose: () => void;
}

export function ProductSearchOverlay({ isOpen, onClose }: ProductSearchOverlayProps) {
  const [query, setQuery] = useState('');
  const [products, setProducts] = useState<Product[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState(-1);
  const inputRef = useRef<HTMLInputElement>(null);
  const navigate = useNavigate();

  // Focus input when overlay opens
  useEffect(() => {
    if (isOpen) {
      setTimeout(() => {
        inputRef.current?.focus();
      }, 100);
      setQuery('');
      setProducts([]);
      setSelectedIndex(-1);
    }
  }, [isOpen]);

  // Search products with debounce
  const searchProducts = useCallback(async (searchQuery: string) => {
    if (!searchQuery.trim()) {
      setProducts([]);
      return;
    }

    setIsLoading(true);
    try {
      const response = await api.get('/products', {
        params: {
          search: searchQuery,
          per_page: 10,
          is_active: true,
        },
      });
      setProducts(response.data.data || []);
    } catch (error) {
      console.error('Search error:', error);
      setProducts([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      searchProducts(query);
    }, 300);

    return () => clearTimeout(timer);
  }, [query, searchProducts]);

  // Handle keyboard navigation
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Escape') {
      onClose();
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev < products.length - 1 ? prev + 1 : prev));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev > 0 ? prev - 1 : -1));
    } else if (e.key === 'Enter' && selectedIndex >= 0) {
      e.preventDefault();
      navigate(`/store/shop?product=${products[selectedIndex].id}`);
      onClose();
    }
  };

  // Handle click outside
  const handleBackdropClick = (e: React.MouseEvent) => {
    if (e.target === e.currentTarget) {
      onClose();
    }
  };

  if (!isOpen) return null;

  return (
    <div
      className="fixed inset-0 z-[9998] bg-black/60 backdrop-blur-sm animate-in fade-in duration-200"
      onClick={handleBackdropClick}
    >
      <div className="max-w-3xl mx-auto mt-20 px-4">
        {/* Search Input */}
        <div className="bg-white rounded-2xl shadow-2xl overflow-hidden animate-in slide-in-from-top-4 duration-200">
          <div className="flex items-center gap-3 p-4 border-b">
            <Icon icon="solar:magnifer-linear" className="h-6 w-6 text-muted-foreground" />
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setQuery(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder="Search products..."
              className="flex-1 border-0 shadow-none focus:outline-none focus:ring-0 text-lg bg-transparent"
            />
            <Button variant="ghost" size="icon" onClick={onClose}>
              <Icon icon="solar:close-circle-linear" className="h-6 w-6" />
            </Button>
          </div>

          {/* Results */}
          <div className="max-h-[60vh] overflow-y-auto">
            {isLoading && (
              <div className="flex items-center justify-center py-12">
                <Icon icon="mdi:loading" className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            )}

            {!isLoading && query && products.length === 0 && (
              <div className="text-center py-12">
                <Icon icon="solar:box-minimalistic-linear" className="h-12 w-12 mx-auto text-muted-foreground mb-3" />
                <p className="text-muted-foreground">No products found for "{query}"</p>
                <Link
                  to={`${storefrontRoutes.shop}?search=${encodeURIComponent(query)}`}
                  onClick={onClose}
                  className="text-primary hover:underline mt-2 inline-block"
                >
                  View all products
                </Link>
              </div>
            )}

            {!isLoading && products.length > 0 && (
              <div className="py-2">
                {products.map((product, index) => (
                  <Link
                    key={product.id}
                    to={`${storefrontRoutes.shop}?product=${product.id}`}
                    onClick={onClose}
                    className={cn(
                      "flex items-center gap-4 px-4 py-3 hover:bg-secondary/10 transition-colors",
                      selectedIndex === index && "bg-secondary/10"
                    )}
                  >
                    {/* Product Image */}
                    <div className="w-16 h-16 rounded-lg bg-secondary/10 flex items-center justify-center overflow-hidden flex-shrink-0">
                      {product.image ? (
                        <img
                          src={product.image}
                          alt={product.name}
                          className="w-full h-full object-cover"
                        />
                      ) : (
                        <Icon icon="solar:box-minimalistic-linear" className="h-8 w-8 text-muted-foreground" />
                      )}
                    </div>

                    {/* Product Info */}
                    <div className="flex-1 min-w-0">
                      <p className="font-medium text-foreground truncate">
                        {product.name}
                      </p>
                      {product.secondary_name && (
                        <p className="text-sm text-muted-foreground truncate">
                          {product.secondary_name}
                        </p>
                      )}
                      <div className="flex items-center gap-2 mt-1">
                        {product.category && (
                          <span className="text-xs text-muted-foreground bg-secondary/20 px-2 py-0.5 rounded-full">
                            {product.category.name}
                          </span>
                        )}
                        <span className="text-xs text-muted-foreground">
                          SKU: {product.sku}
                        </span>
                      </div>
                    </div>

                    {/* Price */}
                    <div className="text-right flex-shrink-0">
                      <p className="font-semibold text-foreground">
                        ${Number(product.price).toFixed(2)}
                      </p>
                      {product.stock_quantity !== undefined && product.stock_quantity <= 0 && (
                        <span className="text-xs text-destructive">Out of stock</span>
                      )}
                    </div>
                  </Link>
                ))}

                {/* View All Results */}
                <div className="border-t mt-2 pt-2">
                  <Link
                    to={`${storefrontRoutes.shop}?search=${encodeURIComponent(query)}`}
                    onClick={onClose}
                    className="flex items-center justify-center gap-2 px-4 py-3 text-primary hover:bg-secondary/10 transition-colors"
                  >
                    <Icon icon="solar:arrow-right-linear" className="h-5 w-5" />
                    <span>View all results for "{query}"</span>
                  </Link>
                </div>
              </div>
            )}

            {/* Initial State - Show suggestions */}
            {!query && (
              <div className="py-8 px-4 text-center">
                <Icon icon="solar:magnifer-linear" className="h-12 w-12 mx-auto text-muted-foreground mb-3" />
                <p className="text-muted-foreground mb-4">Start typing to search products</p>
                <div className="flex flex-wrap justify-center gap-2">
                  {['Albums', 'Lightsticks', 'Photocards', 'Merch'].map((term) => (
                    <button
                      key={term}
                      onClick={() => setQuery(term)}
                      className="px-3 py-1.5 text-sm bg-secondary/20 hover:bg-secondary/30 rounded-full transition-colors"
                    >
                      {term}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Keyboard shortcuts hint */}
        <div className="flex justify-center gap-4 mt-4 text-xs text-white/60">
          <span className="flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 bg-white/10 rounded">ESC</kbd> to close
          </span>
          <span className="flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 bg-white/10 rounded">Up/Down</kbd> to navigate
          </span>
          <span className="flex items-center gap-1">
            <kbd className="px-1.5 py-0.5 bg-white/10 rounded">Enter</kbd> to select
          </span>
        </div>
      </div>
    </div>
  );
}
