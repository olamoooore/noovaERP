import { useState } from 'react';
import { Heart, Eye, ShoppingBag } from 'lucide-react';
import { Product } from '@/storefront/data/products';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { useCart } from '@/storefront/context/CartContext';
import { useWishlist } from '@/storefront/context/WishlistContext';
import { useMembership } from '@/storefront/context/MembershipContext';
import { cn } from '@/lib/utils';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';

interface ProductCardProps {
  product: Product;
  onQuickView?: (product: Product) => void;
}

export const ProductCard = ({ product, onQuickView }: ProductCardProps) => {
  const [isHovered, setIsHovered] = useState(false);
  const { addToCart } = useCart();
  const { isInWishlist, toggleWishlist } = useWishlist();
  const { tier, discount } = useMembership();

  const isLiked = isInWishlist(product.id);
  const memberPrice = tier !== 'free' ? product.price * (1 - discount / 100) : null;
  const isOutOfStock = product.inStock === false || product.stockQuantity === 0;

  const tagVariant = (tag: string) => {
    switch (tag) {
      case 'limited': return 'limited';
      case 'preorder': return 'preorder';
      case 'new': return 'new';
      case 'bestseller': return 'bestseller';
      default: return 'default';
    }
  };

  return (
    <div
      className="group relative rounded-xl overflow-hidden bg-gradient-card card-hover"
      onMouseEnter={() => setIsHovered(true)}
      onMouseLeave={() => setIsHovered(false)}
    >
      {/* Image Container */}
      <div className="relative aspect-square overflow-hidden">
        <img
          src={product.image}
          alt={product.title}
          className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
          loading="lazy"
          onError={(e) => { (e.target as HTMLImageElement).src = PLACEHOLDER_PRODUCT_URL; }}
        />

        {/* Overlay */}
        <div
          className={cn(
            "absolute inset-0 bg-gradient-to-t from-background/90 via-background/20 to-transparent transition-opacity duration-300",
            isHovered ? "opacity-100" : "opacity-0"
          )}
        />

        {/* Tags */}
        <div className="absolute top-3 left-3 flex flex-wrap gap-1">
          {isOutOfStock && (
            <Badge variant="destructive" className="text-xs">
              Out of Stock
            </Badge>
          )}
          {product.tags.map((tag) => (
            <Badge key={tag} variant={tagVariant(tag)} className="text-xs">
              {tag.charAt(0).toUpperCase() + tag.slice(1)}
            </Badge>
          ))}
        </div>

        {/* Wishlist Button */}
        <button
          onClick={() => toggleWishlist(product)}
          className={cn(
            "absolute top-3 right-3 w-9 h-9 rounded-full flex items-center justify-center transition-all duration-300",
            isLiked
              ? "bg-primary text-primary-foreground"
              : "bg-background/80 backdrop-blur-sm text-foreground hover:bg-primary hover:text-primary-foreground"
          )}
          aria-label={isLiked ? "Remove from wishlist" : "Add to wishlist"}
        >
          <Heart className={cn("h-4 w-4", isLiked && "fill-current")} />
        </button>

        {/* Quick Actions */}
        <div
          className={cn(
            "absolute bottom-3 left-3 right-3 flex gap-2 transition-all duration-300",
            isHovered ? "opacity-100 translate-y-0" : "opacity-0 translate-y-4"
          )}
        >
          <Button
            variant="glass"
            size="sm"
            className="flex-1"
            onClick={() => addToCart(product)}
            disabled={isOutOfStock}
          >
            <ShoppingBag className="h-4 w-4 mr-1" />
            {isOutOfStock ? 'Out of Stock' : 'Add to Cart'}
          </Button>
          {onQuickView && (
            <Button
              variant="glass"
              size="icon"
              className="shrink-0"
              onClick={() => onQuickView(product)}
            >
              <Eye className="h-4 w-4" />
            </Button>
          )}
        </div>
      </div>

      {/* Content */}
      <div className="p-4">
        <p className="text-xs text-muted-foreground mb-1">{product.brand}</p>
        <h3 className="font-medium text-sm mb-2 line-clamp-2 group-hover:text-primary transition-colors">
          {product.title}
        </h3>
        <div className="flex items-center gap-2">
          <span className={cn("font-display font-bold", memberPrice && "text-muted-foreground line-through text-sm")}>
            SGD {product.price.toFixed(2)}
          </span>
          {memberPrice && (
            <span className="font-display font-bold text-primary">
              SGD {memberPrice.toFixed(2)}
            </span>
          )}
        </div>
        {memberPrice && (
          <p className="text-xs text-accent mt-1">Member Price ({discount}% off)</p>
        )}
      </div>
    </div>
  );
};
