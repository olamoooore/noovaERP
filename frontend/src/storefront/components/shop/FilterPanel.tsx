import { X } from 'lucide-react';
import { Icon } from '@iconify/react';
import { BRANDS, COLLECTIONS, Brand, ProductType, Collection } from '@/storefront/data/products';
import { Button } from '@/storefront/components/ui/button';
import { Slider } from '@/components/ui/slider';
import { cn } from '@/lib/utils';

interface FilterPanelProps {
  selectedBrands: Brand[];
  selectedTypes: ProductType[];
  selectedCollections: Collection[];
  priceRange: [number, number];
  onBrandChange: (brands: Brand[]) => void;
  onTypeChange: (types: ProductType[]) => void;
  onCollectionChange: (collections: Collection[]) => void;
  onPriceChange: (range: [number, number]) => void;
  onClearAll: () => void;
  isMobile?: boolean;
  onClose?: () => void;
}

export const FilterPanel = ({
  selectedBrands,
  selectedTypes,
  selectedCollections,
  priceRange,
  onBrandChange,
  onTypeChange,
  onCollectionChange,
  onPriceChange,
  onClearAll,
  isMobile,
  onClose,
}: FilterPanelProps) => {
  const categoryItems: Array<{ type: ProductType; icon: string; label: string }> = [
    { type: 'Album', icon: 'solar:music-note-linear', label: 'Album' },
    { type: 'DVD', icon: 'solar:videocamera-record-linear', label: 'DVD' },
    { type: "Season's Greetings", icon: 'solar:gift-linear', label: "Season's Greetings" },
    { type: 'Lightstick', icon: 'solar:flashlight-linear', label: 'Lightstick' },
    { type: 'Merchandise', icon: 'solar:bag-2-linear', label: 'Merchandise' },
    { type: 'Photocard', icon: 'solar:gallery-linear', label: 'Photocard' },
    { type: 'Miscellaneous', icon: 'solar:stars-line-duotone', label: 'Miscellaneous' },
  ];

  const toggleBrand = (brand: Brand) => {
    if (selectedBrands.includes(brand)) {
      onBrandChange(selectedBrands.filter(b => b !== brand));
    } else {
      onBrandChange([...selectedBrands, brand]);
    }
  };

  const toggleType = (type: ProductType) => {
    if (selectedTypes.includes(type)) {
      onTypeChange(selectedTypes.filter(t => t !== type));
    } else {
      onTypeChange([...selectedTypes, type]);
    }
  };

  const toggleCollection = (collection: Collection) => {
    if (selectedCollections.includes(collection)) {
      onCollectionChange(selectedCollections.filter(c => c !== collection));
    } else {
      onCollectionChange([...selectedCollections, collection]);
    }
  };

  const hasFilters = selectedBrands.length > 0 || selectedTypes.length > 0 || selectedCollections.length > 0 || priceRange[0] > 0 || priceRange[1] < 150;

  return (
    <div className={cn("space-y-6", isMobile && "p-6")}>
      {/* Mobile Header */}
      {isMobile && (
        <div className="flex items-center justify-between pb-4 border-b border-border">
          <h3 className="font-display font-bold text-lg">Filters</h3>
          <Button variant="ghost" size="icon" onClick={onClose}>
            <X className="h-5 w-5" />
          </Button>
        </div>
      )}

      {/* Clear All */}
      {hasFilters && (
        <div className="flex justify-end">
          <Button variant="ghost" size="sm" onClick={onClearAll}>
            Clear All
          </Button>
        </div>
      )}

      {/* Price Range */}
      <div>
        <h4 className="text-sm font-medium mb-3">
          Price Range: SGD {priceRange[0]} - SGD {priceRange[1]}
        </h4>
        <Slider
          value={priceRange}
          onValueChange={(value) => onPriceChange(value as [number, number])}
          min={0}
          max={150}
          step={5}
          className="mt-4"
        />
      </div>

      {/* Shop by Category */}
      <div>
        <h4 className="font-medium mb-3">Shop by Category</h4>
        <div className="grid grid-cols-1 gap-2">
          {categoryItems.map((item) => {
            const isActive = selectedTypes.includes(item.type);
            return (
              <button
                key={item.type}
                onClick={() => toggleType(item.type)}
                className={cn(
                  "w-full flex items-center gap-3 rounded-xl border p-3 text-left transition-all",
                  isActive
                    ? "border-primary bg-primary/5 text-foreground"
                    : "border-border bg-background hover:bg-muted/40"
                )}
              >
                <span
                  className={cn(
                    "flex h-10 w-10 items-center justify-center rounded-lg border bg-muted/30",
                    isActive ? "border-primary/30" : "border-border"
                  )}
                  aria-hidden="true"
                >
                  <Icon
                    className={cn("h-5 w-5", isActive ? "text-primary" : "text-muted-foreground")}
                    icon={item.icon}
                  />
                </span>
                <span className="flex-1">
                  <span className="block text-sm font-medium">{item.label}</span>
                  <span className="block text-xs text-muted-foreground">
                    Browse {item.label.toLowerCase()}
                  </span>
                </span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Collections */}
      <div>
        <h4 className="font-medium mb-3">Collection</h4>
        <div className="flex flex-wrap gap-2">
          {COLLECTIONS.map((collection) => (
            <button
              key={collection}
              onClick={() => toggleCollection(collection)}
              className={cn(
                "px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200",
                selectedCollections.includes(collection)
                  ? "bg-secondary text-secondary-foreground"
                  : "bg-muted text-muted-foreground hover:bg-muted/80 hover:text-foreground"
              )}
            >
              {collection}
            </button>
          ))}
        </div>
      </div>

      {/* Brands */}
      <div>
        <h4 className="font-medium mb-3">Brands</h4>
        <div className="flex flex-wrap gap-2">
          {BRANDS.map((brand) => (
            <button
              key={brand}
              onClick={() => toggleBrand(brand)}
              className={cn(
                "px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200",
                selectedBrands.includes(brand)
                  ? "bg-primary text-primary-foreground shadow-neon-magenta"
                  : "bg-muted text-muted-foreground hover:bg-muted/80 hover:text-foreground"
              )}
            >
              {brand}
            </button>
          ))}
        </div>
      </div>

      {/* Mobile Apply Button */}
      {isMobile && (
        <Button variant="neon" className="w-full mt-6" onClick={onClose}>
          Apply Filters
        </Button>
      )}
    </div>
  );
};
