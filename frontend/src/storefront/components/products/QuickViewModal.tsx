import { useState } from 'react';
import { X, Minus, Plus, ShoppingBag, Heart } from 'lucide-react';
import { Product } from '@/storefront/data/products';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { useCart } from '@/storefront/context/CartContext';
import { useMembership } from '@/storefront/context/MembershipContext';
import { useWishlist } from '@/storefront/context/WishlistContext';
import { cn } from '@/lib/utils';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';

interface QuickViewModalProps {
  product: Product | null;
  isOpen: boolean;
  onClose: () => void;
}

export const QuickViewModal = ({ product, isOpen, onClose }: QuickViewModalProps) => {
  const [quantity, setQuantity] = useState(1);
  const { addToCart } = useCart();
  const { tier, discount } = useMembership();
  const { isInWishlist, toggleWishlist } = useWishlist();

  if (!product) return null;

  const isLiked = isInWishlist(product.id);

  const memberPrice = tier !== 'free' ? product.price * (1 - discount / 100) : null;

  const handleAddToCart = () => {
    addToCart(product, quantity);
    setQuantity(1);
    onClose();
  };

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
    <>
      {/* Backdrop */}
      <div
        className={cn(
          "fixed inset-0 bg-background/80 backdrop-blur-sm z-50 transition-opacity duration-300",
          isOpen ? "opacity-100" : "opacity-0 pointer-events-none"
        )}
        onClick={onClose}
        role="presentation"
      />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="quick-view-title"
        className={cn(
          "fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[95%] max-w-4xl max-h-[90vh] overflow-y-auto z-50 rounded-2xl bg-card border border-border shadow-2xl transition-all duration-300",
          isOpen ? "opacity-100 scale-100" : "opacity-0 scale-95 pointer-events-none"
        )}
      >
        <Button
          variant="ghost"
          size="icon"
          className="absolute top-4 right-4 z-10"
          onClick={onClose}
          aria-label="Close modal"
        >
          <X className="h-5 w-5" />
        </Button>

        <div className="grid md:grid-cols-2 gap-6 p-6">
          {/* Image */}
          <div className="relative aspect-square rounded-xl overflow-hidden">
            <img
              src={product.image}
              alt={product.title}
              className="w-full h-full object-cover"
              onError={(e) => { (e.target as HTMLImageElement).src = PLACEHOLDER_PRODUCT_URL; }}
            />
            <div className="absolute top-3 left-3 flex flex-wrap gap-1">
              {product.tags.map((tag) => (
                <Badge key={tag} variant={tagVariant(tag)}>
                  {tag.charAt(0).toUpperCase() + tag.slice(1)}
                </Badge>
              ))}
            </div>
          </div>

          {/* Content */}
          <div className="flex flex-col">
            <p className="text-sm text-muted-foreground">{product.brand}</p>
            <h2 id="quick-view-title" className="text-2xl font-display font-bold mt-1">
              {product.title}
            </h2>

            <div className="flex items-baseline gap-3 mt-4">
              <span className={cn(
                "text-2xl font-display font-bold",
                memberPrice && "text-muted-foreground line-through text-lg"
              )}>
                SGD {product.price.toFixed(2)}
              </span>
              {memberPrice && (
                <span className="text-2xl font-display font-bold text-primary">
                  SGD {memberPrice.toFixed(2)}
                </span>
              )}
            </div>
            {memberPrice && (
              <p className="text-sm text-accent mt-1">Member Price ({discount}% off)</p>
            )}

            <p className="text-muted-foreground mt-4">
              {product.description || 'Premium K-pop merchandise for true fans.'}
            </p>

            <div className="mt-6 space-y-4">
              <div>
                <label className="text-sm font-medium mb-2 block">Type</label>
                <Badge variant="outline">{product.type}</Badge>
              </div>
              <div>
                <label className="text-sm font-medium mb-2 block">Collection</label>
                <Badge variant="outline">{product.collection}</Badge>
              </div>
            </div>

            {/* Quantity */}
            <div className="mt-6">
              <label className="text-sm font-medium mb-2 block">Quantity</label>
              <div className="flex items-center gap-3">
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => setQuantity(Math.max(1, quantity - 1))}
                  disabled={quantity <= 1}
                >
                  <Minus className="h-4 w-4" />
                </Button>
                <span className="w-12 text-center font-medium">{quantity}</span>
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => setQuantity(quantity + 1)}
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
            </div>

            {/* Actions */}
            <div className="flex gap-3 mt-auto pt-6">
              <Button
                variant="neon"
                className="flex-1"
                onClick={handleAddToCart}
                disabled={product.inStock === false || product.stockQuantity === 0}
              >
                <ShoppingBag className="h-4 w-4 mr-2" />
                {product.inStock === false || product.stockQuantity === 0 ? 'Out of Stock' : 'Add to Cart'}
              </Button>
              <Button
                variant="outline"
                size="icon"
                onClick={() => toggleWishlist(product)}
                aria-label={isLiked ? 'Remove from wishlist' : 'Add to wishlist'}
              >
                <Heart className={cn('h-4 w-4 transition-colors', isLiked && 'fill-red-500 text-red-500')} />
              </Button>
            </div>
          </div>
        </div>
      </div>
    </>
  );
};
