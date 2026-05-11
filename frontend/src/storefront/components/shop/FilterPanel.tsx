import { X, Loader2 } from 'lucide-react';
import { Icon } from '@iconify/react';
import { COLLECTIONS, Brand, ProductType, Collection } from '@/storefront/data/products';
import { Button } from '@/storefront/components/ui/button';
import { useCategories } from '@/storefront/hooks/useProducts';
import { cn } from '@/lib/utils';

interface FilterPanelProps {
  selectedBrands: Brand[];
  selectedTypes: ProductType[];
  selectedCollections: Collection[];
  selectedTags: string[];
  onBrandChange: (brands: Brand[]) => void;
  onTypeChange: (types: ProductType[]) => void;
  onCollectionChange: (collections: Collection[]) => void;
  onTagChange: (tags: string[]) => void;
  onClearAll: () => void;
  availableBrands?: string[];
  isMobile?: boolean;
  onClose?: () => void;
}

export const FilterPanel = ({
  selectedBrands,
  selectedTypes,
  selectedCollections,
  selectedTags,
  onBrandChange,
  onTypeChange,
  onCollectionChange,
  onTagChange,
  onClearAll,
  availableBrands,
  isMobile,
  onClose,
}: FilterPanelProps) => {
  const { data: apiCategories, isLoading: categoriesLoading } = useCategories();

  // Icon mapping for categories - fallback to a default icon
  const categoryIconMap: Record<string, string> = {
    'album': 'solar:music-note-linear',
    'albums': 'solar:music-note-linear',
    'dvd': 'solar:videocamera-record-linear',
    'dvds': 'solar:videocamera-record-linear',
    'video': 'solar:videocamera-record-linear',
    "season's greetings": 'solar:gift-linear',
    'seasons greetings': 'solar:gift-linear',
    'lightstick': 'solar:flashlight-linear',
    'lightsticks': 'solar:flashlight-linear',
    'merchandise': 'solar:bag-2-linear',
    'merch': 'solar:bag-2-linear',
    'photocard': 'solar:gallery-linear',
    'photocards': 'solar:gallery-linear',
    'miscellaneous': 'solar:stars-line-duotone',
    'accessories': 'solar:hanger-2-linear',
    'clothing': 'solar:t-shirt-linear',
    'apparel': 'solar:t-shirt-linear',
    'stationery': 'solar:pen-new-square-linear',
    'poster': 'solar:gallery-wide-linear',
    'posters': 'solar:gallery-wide-linear',
    'books': 'solar:book-linear',
    'collectibles': 'solar:star-shine-linear',
    'food': 'solar:cup-hot-linear',
    'beauty': 'solar:palette-round-linear',
    'electronics': 'solar:laptop-linear',
    'toys': 'solar:gameboy-linear',
    'home': 'solar:home-linear',
    'bags': 'solar:bag-3-linear',
    'jewelry': 'solar:diamond-linear',
  };

  const getCategoryIcon = (name: string) => {
    const key = name.toLowerCase().trim();
    return categoryIconMap[key] || 'solar:box-linear';
  };

  // Build category items from DB categories, fallback to hardcoded
  const categoryItems: Array<{ type: ProductType; icon: string; label: string; id?: number }> = apiCategories && apiCategories.length > 0
    ? apiCategories.map(cat => ({
        type: cat.name as ProductType,
        icon: getCategoryIcon(cat.name),
        label: cat.name,
        id: cat.id,
      }))
    : [
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

  const toggleTag = (tag: string) => {
    if (selectedTags.includes(tag)) {
      onTagChange(selectedTags.filter(t => t !== tag));
    } else {
      onTagChange([...selectedTags, tag]);
    }
  };

  const hasFilters = selectedBrands.length > 0 || selectedTypes.length > 0 || selectedCollections.length > 0 || selectedTags.length > 0;
  const activeCount = selectedBrands.length + selectedTypes.length + selectedCollections.length + selectedTags.length;

  return (
    <div className={cn("space-y-6", isMobile && "p-6")}>
      {/* Mobile Header */}
      {isMobile && (
        <div className="flex items-center justify-between pb-4 border-b border-border">
          <div className="flex items-center gap-2">
            <h3 className="font-display font-bold text-lg">Filters</h3>
            {activeCount > 0 && (
              <span className="inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full bg-primary px-2 text-xs font-semibold text-primary-foreground">
                {activeCount}
              </span>
            )}
          </div>
          <Button variant="ghost" size="icon" onClick={onClose}>
            <X className="h-5 w-5" />
          </Button>
        </div>
      )}

      {/* Active filters summary + clear (desktop & mobile) */}
      {hasFilters && (
        <div className="flex items-center justify-between rounded-xl border border-primary/30 bg-primary/5 px-3 py-2">
          <span className="text-xs font-medium text-foreground">
            <span className="text-primary">{activeCount}</span> active filter{activeCount === 1 ? '' : 's'}
          </span>
          <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={onClearAll}>
            Clear all
          </Button>
        </div>
      )}

      {/* Sort by Tag/Status */}
      <div>
        <div className="mb-3 flex items-center gap-2">
          <Icon icon="solar:tag-horizontal-bold-duotone" className="h-4 w-4 text-primary" />
          <h4 className="font-semibold text-sm uppercase tracking-wide">Status</h4>
        </div>
        <div className="grid grid-cols-1 gap-1.5">
          {[
            { value: 'preorder', label: 'Pre-Order', icon: 'solar:clock-circle-bold' },
            { value: 'new', label: 'New Arrival', icon: 'solar:star-bold' },
            { value: 'in-stock', label: 'In-Stock', icon: 'solar:check-circle-bold' },
            { value: 'out-of-stock', label: 'Out of Stock', icon: 'solar:close-circle-bold' },
            { value: 'sale', label: 'Sales', icon: 'solar:tag-price-bold' },
          ].map((tag) => {
            const isActive = selectedTags.includes(tag.value);
            return (
              <button
                key={tag.value}
                onClick={() => toggleTag(tag.value)}
                className={cn(
                  "w-full flex items-center gap-2.5 rounded-lg border px-2.5 py-2 text-left transition-all",
                  isActive
                    ? "border-primary bg-primary/10 text-foreground"
                    : "border-transparent bg-background hover:bg-muted/40 hover:border-border"
                )}
              >
                <Icon
                  className={cn("h-4 w-4 shrink-0", isActive ? "text-primary" : "text-muted-foreground")}
                  icon={tag.icon}
                />
                <span className="text-sm font-medium flex-1">{tag.label}</span>
                {isActive && (
                  <span className="h-1.5 w-1.5 rounded-full bg-primary" />
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Shop by Category */}
      <div>
        <div className="mb-3 flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Icon icon="solar:widget-bold-duotone" className="h-4 w-4 text-primary" />
            <h4 className="font-semibold text-sm uppercase tracking-wide">Category</h4>
          </div>
          <span className="text-[10px] font-medium text-muted-foreground">{categoryItems.length}</span>
        </div>
        <div className="max-h-72 space-y-1.5 overflow-y-auto pr-1">
          {categoriesLoading ? (
            <div className="flex items-center gap-2 rounded-xl px-3 py-4 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              Loading...
            </div>
          ) : categoryItems.map((item) => {
            const isActive = selectedTypes.includes(item.type);
            return (
              <button
                key={item.type}
                onClick={() => toggleType(item.type)}
                className={cn(
                  "w-full flex items-center gap-2.5 rounded-lg border px-2.5 py-2 text-left transition-all",
                  isActive
                    ? "border-primary bg-primary/10 text-foreground"
                    : "border-transparent bg-background hover:bg-muted/40 hover:border-border"
                )}
              >
                <span
                  className={cn(
                    "flex h-8 w-8 items-center justify-center rounded-md shrink-0",
                    isActive ? "bg-primary/15 text-primary" : "bg-muted/60 text-muted-foreground"
                  )}
                  aria-hidden="true"
                >
                  <Icon className="h-4 w-4" icon={item.icon} />
                </span>
                <span className="block truncate text-sm font-medium flex-1">{item.label}</span>
                {isActive && (
                  <span className="h-1.5 w-1.5 rounded-full bg-primary shrink-0" />
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Collections */}
      <div>
        <div className="mb-3 flex items-center gap-2">
          <Icon icon="solar:bookmark-bold-duotone" className="h-4 w-4 text-primary" />
          <h4 className="font-semibold text-sm uppercase tracking-wide">Collection</h4>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {COLLECTIONS.map((collection) => (
            <button
              key={collection}
              onClick={() => toggleCollection(collection)}
              className={cn(
                "px-3 py-1.5 rounded-full text-xs font-medium transition-all border",
                selectedCollections.includes(collection)
                  ? "bg-primary text-primary-foreground border-primary"
                  : "bg-background text-muted-foreground border-border hover:border-primary/40 hover:text-foreground"
              )}
            >
              {collection}
            </button>
          ))}
        </div>
      </div>

      {/* Brands / Artists */}
      {availableBrands && availableBrands.length > 0 && (
      <div>
        <div className="mb-3 flex items-center gap-2">
          <Icon icon="solar:microphone-bold-duotone" className="h-4 w-4 text-primary" />
          <h4 className="font-semibold text-sm uppercase tracking-wide">Artists</h4>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {availableBrands.map((brand) => (
            <button
              key={brand}
              onClick={() => toggleBrand(brand)}
              className={cn(
                "px-3 py-1.5 rounded-full text-xs font-medium transition-all border",
                selectedBrands.includes(brand)
                  ? "bg-primary text-primary-foreground border-primary shadow-sm"
                  : "bg-background text-muted-foreground border-border hover:border-primary/40 hover:text-foreground"
              )}
            >
              {brand}
            </button>
          ))}
        </div>
      </div>
      )}

      {/* Mobile Apply Button */}
      {isMobile && (
        <Button variant="neon" className="w-full mt-6 sticky bottom-0" onClick={onClose}>
          Apply Filters{activeCount > 0 ? ` (${activeCount})` : ''}
        </Button>
      )}
    </div>
  );
};
