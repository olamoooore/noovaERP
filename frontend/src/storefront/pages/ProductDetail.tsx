import { useState, useMemo, useEffect, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import {
  ShoppingBag, Heart, ChevronRight, Share2,
  ShieldCheck, Package, Truck, Star,
  ChevronLeft, Check, ArrowLeft, Loader2,
  MessageSquare, LogIn,
} from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { useProduct } from '@/storefront/hooks/useProduct';
import { useProducts } from '@/storefront/hooks/useProducts';
import { ProductCard } from '@/storefront/components/products/ProductCard';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { useCart } from '@/storefront/context/CartContext';
import { useWishlist } from '@/storefront/context/WishlistContext';
import { useMembership } from '@/storefront/context/MembershipContext';
import { useCustomerAuth } from '@/storefront/context/CustomerAuthContext';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';
import { PLACEHOLDER_PRODUCT_URL } from '@/storefront/lib/assets';
import { storefrontRoutes, productRoute } from '@/storefront/lib/paths';
import type { ProductReview } from '@/storefront/data/products';

/* ── helpers ───────────────────────────────────────────── */

const StarRating = ({ rating, size = 'sm' }: { rating: number; size?: 'sm' | 'md' | 'lg' }) => {
  const cls = size === 'lg' ? 'h-5 w-5' : size === 'md' ? 'h-4 w-4' : 'h-3.5 w-3.5';
  return (
    <div className="flex items-center gap-0.5">
      {[1, 2, 3, 4, 5].map(i => (
        <Star
          key={i}
          className={cn(
            cls,
            i <= Math.floor(rating)
              ? 'fill-amber-400 text-amber-400'
              : i - 0.5 <= rating
                ? 'fill-amber-400/50 text-amber-400'
                : 'text-muted-foreground/30',
          )}
        />
      ))}
    </div>
  );
};

/** Demo reviews – realistic, product-aware comments */
const DEMO_REVIEWS: ProductReview[] = [
  {
    id: 'demo-r1',
    author: 'MelodyACE_SG',
    rating: 5,
    date: '2025-12-20',
    comment:
      'The quality exceeded my expectations! I ordered this as a birthday gift for my best friend and she absolutely loved it. Packaging was really secure too — no damage at all. Will definitely be ordering more from this store.',
    verified: true,
  },
  {
    id: 'demo-r2',
    author: 'kpopcollector_88',
    rating: 5,
    date: '2025-12-08',
    comment:
      "I've been collecting K-pop merch for years and this is easily one of the best purchases I've made. The colours are vivid, material feels premium, and it arrived within 3 days to my doorstep in Tampines. 10/10 would recommend.",
    verified: true,
  },
  {
    id: 'demo-r3',
    author: 'Jiaying W.',
    rating: 4,
    date: '2025-11-22',
    comment:
      'Really nice item overall. Took off one star only because the delivery was slightly delayed due to the 11.11 sale rush, but the product itself is top-notch. Customer service was responsive when I asked about tracking.',
    verified: true,
  },
  {
    id: 'demo-r4',
    author: 'NCTzen_MY',
    rating: 5,
    date: '2025-11-10',
    comment:
      'Ordered from Malaysia and it shipped fast! Everything was legit and authentic. The store even included a small thank-you card which was a cute touch. Already planning my next haul 🫶',
    verified: false,
  },
  {
    id: 'demo-r5',
    author: 'Rachel_K',
    rating: 4,
    date: '2025-10-28',
    comment:
      'Good value for money. I compared prices across a few stores and ACE had the best deal. Product matches the photos on the listing. Would have loved more bubble wrap but it arrived fine.',
    verified: true,
  },
];

/* ── Local review storage (persisted in localStorage) ── */
const REVIEW_STORAGE_KEY = 'ace_storefront_reviews';

interface StoredReviews {
  [productId: string]: ProductReview[];
}

function getStoredReviews(): StoredReviews {
  try {
    const raw = localStorage.getItem(REVIEW_STORAGE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch {
    return {};
  }
}

function saveReview(productId: string, review: ProductReview): void {
  const all = getStoredReviews();
  if (!all[productId]) all[productId] = [];
  all[productId].unshift(review);
  localStorage.setItem(REVIEW_STORAGE_KEY, JSON.stringify(all));
}

function getProductStoredReviews(productId: string): ProductReview[] {
  return getStoredReviews()[productId] ?? [];
}

/* ── Interactive star picker ── */
const StarPicker = ({
  value,
  onChange,
  disabled = false,
}: {
  value: number;
  onChange: (v: number) => void;
  disabled?: boolean;
}) => {
  const [hover, setHover] = useState(0);
  return (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map(i => (
        <button
          key={i}
          type="button"
          disabled={disabled}
          onMouseEnter={() => setHover(i)}
          onMouseLeave={() => setHover(0)}
          onClick={() => onChange(i)}
          className="focus:outline-none disabled:cursor-not-allowed transition-transform hover:scale-110"
        >
          <Star
            className={cn(
              'h-7 w-7 transition-colors',
              i <= (hover || value)
                ? 'fill-amber-400 text-amber-400'
                : 'text-muted-foreground/30',
            )}
          />
        </button>
      ))}
      {value > 0 && (
        <span className="ml-2 text-sm font-medium text-muted-foreground">
          {value === 1 && 'Poor'}
          {value === 2 && 'Fair'}
          {value === 3 && 'Good'}
          {value === 4 && 'Very Good'}
          {value === 5 && 'Excellent'}
        </span>
      )}
    </div>
  );
};

/** Generate a demo full description when none is provided (returns HTML) */
function buildDescriptionHtml(title: string, brand: string, description?: string): string {
  if (description) return description;
  return `<p>${title} is an officially licensed item from ${brand}. It has been crafted with premium materials to ensure a high-quality experience for every fan.</p><p>This item is perfect for collectors and casual fans alike. Whether you want to display it, use it daily, or gift it to a fellow fan, it is sure to impress.</p><p>All products are 100% official and sourced directly from authorised distributors. Every purchase comes securely packaged to arrive in perfect condition.</p>`;
}

/** Strip HTML tags and truncate to plain-text fallback */
function stripHtml(html: string): string {
  return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
}

/* ── page ──────────────────────────────────────────────── */

const ProductDetail = () => {
  const { id } = useParams<{ id: string }>();
  const [activeImageIndex, setActiveImageIndex] = useState(0);
  const [imageTransition, setImageTransition] = useState(false);
  const [quantity, setQuantity] = useState(1);
  const [activeTab, setActiveTab] = useState<'description' | 'specs' | 'reviews'>('description');

  /* Review form state */
  const [userReviews, setUserReviews] = useState<ProductReview[]>([]);
  const [reviewRating, setReviewRating] = useState(0);
  const [reviewComment, setReviewComment] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const { addToCart } = useCart();
  const { isInWishlist, toggleWishlist } = useWishlist();
  const { tier, discount } = useMembership();
  const { customer, isAuthenticated } = useCustomerAuth();

  const { data: product, isLoading, error } = useProduct(id);

  // Fetch related products (same brand / type)
  const { data: relatedData } = useProducts({ per_page: 8, page: 1 });

  const relatedProducts = useMemo(() => {
    if (!product || !relatedData?.products) return [];
    return relatedData.products
      .filter(p => p.id !== product.id && (p.brand === product.brand || p.type === product.type))
      .slice(0, 4);
  }, [product, relatedData]);

  // Load user-submitted reviews from localStorage
  useEffect(() => {
    if (id) {
      setUserReviews(getProductStoredReviews(id));
    }
  }, [id]);

  // Reset state when navigating to a different product
  useEffect(() => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setActiveImageIndex(0);
    setQuantity(1);
    setActiveTab('description');
    setReviewRating(0);
    setReviewComment('');
  }, [id]);

  /* ── derived values ── */
  const isLiked = product ? isInWishlist(product.id) : false;
  const memberPrice = product && tier !== 'free' ? product.price * (1 - discount / 100) : null;
  const allImages = product
    ? product.images && product.images.length > 0
      ? product.images
      : [product.image]
    : [];

  // Combine API reviews + fallback demo reviews + user-submitted reviews
  const baseReviews: ProductReview[] = (product?.reviews && product.reviews.length > 0)
    ? product.reviews
    : DEMO_REVIEWS;
  const allReviews = [...userReviews, ...baseReviews];
  const reviewCount = allReviews.length;
  const rating = allReviews.length > 0
    ? allReviews.reduce((sum, r) => sum + r.rating, 0) / allReviews.length
    : 4.5;

  /* ── handlers ── */
  const handleSubmitReview = useCallback(() => {
    if (!id || !customer || reviewRating === 0 || !reviewComment.trim()) return;
    setIsSubmitting(true);

    // Simulate a tiny network delay for realistic feel
    setTimeout(() => {
      const newReview: ProductReview = {
        id: `user-${Date.now()}`,
        author: customer.name || customer.first_name || 'Customer',
        rating: reviewRating,
        date: new Date().toISOString().split('T')[0],
        comment: reviewComment.trim(),
        verified: true,
      };

      saveReview(id, newReview);
      setUserReviews(prev => [newReview, ...prev]);
      setReviewRating(0);
      setReviewComment('');
      setIsSubmitting(false);
      toast.success('Thank you! Your review has been posted.');
    }, 600);
  }, [id, customer, reviewRating, reviewComment]);

  /* ── handlers ── */
  const switchImage = (index: number) => {
    setImageTransition(true);
    setTimeout(() => {
      setActiveImageIndex(index);
      setImageTransition(false);
    }, 150);
  };

  const handleAddToCart = () => {
    if (!product) return;
    for (let i = 0; i < quantity; i++) addToCart(product);
    toast.success(`${quantity} × ${product.title} added to cart`);
  };

  const handleShare = () => {
    if (navigator.share) {
      navigator.share({ title: product?.title, url: window.location.href }).catch(() => {});
    } else {
      navigator.clipboard.writeText(window.location.href);
      toast.success('Link copied to clipboard');
    }
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

  /* ── loading / error states ── */
  if (isLoading) {
    return (
      <Layout>
        <div className="container py-24 flex items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      </Layout>
    );
  }

  if (error || !product) {
    return (
      <Layout>
        <div className="container py-24 text-center max-w-md mx-auto">
          <h1 className="text-2xl font-bold mb-4">Product Not Found</h1>
          <p className="text-muted-foreground mb-8">
            The product you are looking for does not exist or has been removed.
          </p>
          <Button asChild>
            <Link to={storefrontRoutes.shop}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Back to Shop
            </Link>
          </Button>
        </div>
      </Layout>
    );
  }

  const isOutOfStock = product.inStock === false || product.stockQuantity === 0;
  const fullDescHtml = buildDescriptionHtml(product.title, product.brand, product.fullDescription || product.description);
  // Short description: prefer HTML short_description, fall back to plain text
  const shortDescHtml = product.shortDescription
    ? product.shortDescription
    : null;

  /* ── render ── */
  return (
    <Layout>
      {/* Breadcrumb */}
      <nav className="border-b border-border/50 bg-muted/20 backdrop-blur-sm">
        <div className="container py-3 flex items-center text-sm text-muted-foreground overflow-x-auto whitespace-nowrap gap-1">
          <Link to={storefrontRoutes.home} className="hover:text-foreground transition-colors">Home</Link>
          <ChevronRight className="h-3.5 w-3.5 shrink-0" />
          <Link to={storefrontRoutes.shop} className="hover:text-foreground transition-colors">Shop</Link>
          <ChevronRight className="h-3.5 w-3.5 shrink-0" />
          <Link
            to={`${storefrontRoutes.shop}?brand=${product.brand}`}
            className="hover:text-foreground transition-colors"
          >
            {product.brand}
          </Link>
          <ChevronRight className="h-3.5 w-3.5 shrink-0" />
          <span className="text-foreground font-medium truncate max-w-[200px]">{product.title}</span>
        </div>
      </nav>

      <div className="container py-8 lg:py-14">
        {/* ─── Main product section ─── */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16 mb-16 lg:mb-24">

          {/* LEFT — Image Gallery */}
          <div className="space-y-4">
            {/* Main Image */}
            <div className="relative aspect-square rounded-2xl overflow-hidden bg-muted/10 border border-border/50 group">
              <img
                src={allImages[activeImageIndex] || PLACEHOLDER_PRODUCT_URL}
                alt={product.title}
                className={cn(
                  'w-full h-full object-cover transition-all duration-300',
                  imageTransition ? 'opacity-0 scale-105' : 'opacity-100 scale-100',
                )}
                onError={(e) => { (e.target as HTMLImageElement).src = PLACEHOLDER_PRODUCT_URL; }}
              />

              {/* Tag overlay */}
              <div className="absolute top-4 left-4 flex flex-col gap-2 z-10">
                {isOutOfStock && (
                  <Badge variant="destructive" className="text-xs shadow-lg">Out of Stock</Badge>
                )}
                {product.tags.map(tag => (
                  <Badge key={tag} variant={tagVariant(tag) as any} className="text-xs shadow-lg">
                    {tag.charAt(0).toUpperCase() + tag.slice(1)}
                  </Badge>
                ))}
              </div>

              {/* Arrow nav – only when multiple images */}
              {allImages.length > 1 && (
                <>
                  <button
                    onClick={() => switchImage((activeImageIndex - 1 + allImages.length) % allImages.length)}
                    className="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-background/80 backdrop-blur-sm shadow-lg flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all hover:bg-background"
                  >
                    <ChevronLeft className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => switchImage((activeImageIndex + 1) % allImages.length)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-background/80 backdrop-blur-sm shadow-lg flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all hover:bg-background"
                  >
                    <ChevronRight className="h-5 w-5" />
                  </button>
                  {/* Dot indicators */}
                  <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2">
                    {allImages.map((_, i) => (
                      <button
                        key={i}
                        onClick={() => switchImage(i)}
                        className={cn(
                          'rounded-full transition-all',
                          activeImageIndex === i ? 'w-6 h-2 bg-primary' : 'w-2 h-2 bg-foreground/30 hover:bg-foreground/60',
                        )}
                      />
                    ))}
                  </div>
                </>
              )}
            </div>

            {/* Thumbnail strip */}
            {allImages.length > 1 && (
              <div className="grid grid-cols-4 gap-3">
                {allImages.map((img, index) => (
                  <button
                    key={index}
                    onClick={() => switchImage(index)}
                    className={cn(
                      'aspect-square rounded-xl overflow-hidden border-2 transition-all duration-200 hover:shadow-md',
                      activeImageIndex === index
                        ? 'border-primary ring-2 ring-primary/20 shadow-md'
                        : 'border-border/50 hover:border-primary/50',
                    )}
                  >
                    <img
                      src={img}
                      alt={`View ${index + 1}`}
                      className="w-full h-full object-cover"
                      onError={(e) => { (e.target as HTMLImageElement).src = PLACEHOLDER_PRODUCT_URL; }}
                    />
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* RIGHT — Product Info */}
          <div className="flex flex-col">
            {/* Brand + Actions */}
            <div className="flex items-center justify-between mb-3">
              <Link
              to={`${storefrontRoutes.shop}?brand=${product.brand}`}
                className="text-sm font-semibold text-primary hover:underline uppercase tracking-wider"
              >
                {product.brand}
              </Link>
              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-9 w-9 rounded-full hover:bg-muted"
                  onClick={handleShare}
                >
                  <Share2 className="h-4 w-4" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className={cn('h-9 w-9 rounded-full hover:bg-muted', isLiked && 'text-red-500')}
                  onClick={() => toggleWishlist(product)}
                >
                  <Heart className={cn('h-4 w-4', isLiked && 'fill-current')} />
                </Button>
              </div>
            </div>

            {/* Title */}
            <h1 className="text-2xl lg:text-3xl font-display font-bold mb-3 leading-tight">
              {product.title}
            </h1>

            {/* Star rating */}
            {rating > 0 && (
              <div className="flex items-center gap-3 mb-4">
                <StarRating rating={rating} size="md" />
                <span className="text-sm font-semibold">{rating.toFixed(1)}</span>
                <span className="text-sm text-muted-foreground">({reviewCount} reviews)</span>
              </div>
            )}

            {/* Short description */}
            {shortDescHtml ? (
              <div
                className="text-muted-foreground text-sm leading-relaxed mb-6 rich-content
                  [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mb-2
                  [&_li]:mb-1 [&_strong]:font-semibold [&_em]:italic [&_a]:text-primary [&_a]:underline"
                dangerouslySetInnerHTML={{ __html: shortDescHtml }}
              />
            ) : (
              <p className="text-muted-foreground text-sm leading-relaxed mb-6">
                {product.description ? stripHtml(product.description).slice(0, 200) : 'Official licensed K-pop merchandise.'}
              </p>
            )}

            <hr className="border-border mb-6" />

            {/* Price */}
            <div className="mb-6">
              <div className="flex items-baseline gap-3">
                <span className="text-3xl font-display font-bold text-foreground">
                  SGD {(memberPrice !== null ? memberPrice : product.price).toFixed(2)}
                </span>
                {memberPrice !== null && (
                  <span className="text-lg text-muted-foreground line-through">
                    SGD {product.price.toFixed(2)}
                  </span>
                )}
              </div>
              {memberPrice !== null && (
                <div className="mt-2 inline-flex items-center gap-2 bg-primary/10 text-primary border border-primary/20 rounded-full px-3 py-1">
                  <Check className="h-3.5 w-3.5" />
                  <span className="text-xs font-semibold">Member Price • {discount}% off</span>
                </div>
              )}
            </div>

            {/* SKU + Stock */}
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm mb-6">
              {product.sku && (
                <span className="text-muted-foreground">
                  SKU: <span className="font-medium text-foreground">{product.sku}</span>
                </span>
              )}
              <span className={cn(
                'flex items-center gap-1.5 font-medium',
                !isOutOfStock ? 'text-green-500' : 'text-red-500',
              )}>
                <span className={cn('w-2 h-2 rounded-full', !isOutOfStock ? 'bg-green-500' : 'bg-red-500')} />
                {!isOutOfStock ? 'In Stock' : 'Out of Stock'}
              </span>
            </div>

            <hr className="border-border mb-6" />

            {/* Quantity + Add to Cart */}
            <div className="space-y-4">
              <div>
                <label className="text-sm font-semibold mb-2 block">Quantity</label>
                <div className="flex items-center w-fit">
                  <button
                    onClick={() => setQuantity(Math.max(1, quantity - 1))}
                    disabled={quantity <= 1}
                    className="w-11 h-11 border border-border rounded-l-lg flex items-center justify-center hover:bg-muted transition-colors text-lg font-medium disabled:opacity-40"
                  >
                    −
                  </button>
                  <div className="w-14 h-11 border-t border-b border-border flex items-center justify-center font-semibold tabular-nums">
                    {quantity}
                  </div>
                  <button
                    onClick={() => setQuantity(quantity + 1)}
                    className="w-11 h-11 border border-border rounded-r-lg flex items-center justify-center hover:bg-muted transition-colors text-lg font-medium"
                  >
                    +
                  </button>
                </div>
              </div>

              <div className="flex gap-3 pt-2">
                <Button
                  className="flex-1 h-12 text-base font-semibold rounded-xl"
                  onClick={handleAddToCart}
                  disabled={isOutOfStock}
                >
                  <ShoppingBag className="h-5 w-5 mr-2" />
                  {isOutOfStock ? 'Out of Stock' : 'Add to Cart'}
                </Button>
                <Button
                  variant="outline"
                  size="icon"
                  className={cn('h-12 w-12 rounded-xl shrink-0', isLiked && 'border-red-500/50 text-red-500')}
                  onClick={() => toggleWishlist(product)}
                >
                  <Heart className={cn('h-5 w-5', isLiked && 'fill-current')} />
                </Button>
              </div>
            </div>

            {/* Trust badges */}
            <div className="mt-8 grid grid-cols-3 gap-3">
              {[
                { icon: ShieldCheck, label: '100% Official' },
                { icon: Package, label: 'Secure Packaging' },
                { icon: Truck, label: 'SG & Intl Shipping' },
              ].map(({ icon: Icon, label }) => (
                <div
                  key={label}
                  className="flex flex-col items-center text-center gap-2 p-3 rounded-xl bg-muted/40 border border-border/50"
                >
                  <Icon className="h-5 w-5 text-primary" />
                  <span className="text-[11px] font-medium leading-tight">{label}</span>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* ─── Tabs: Description / Specs / Reviews ─── */}
        <div className="mb-16 lg:mb-24">
          {/* Tab bar */}
          <div className="flex border-b border-border mb-8 gap-0 overflow-x-auto">
            {(['description', 'specs', 'reviews'] as const).map(tab => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={cn(
                  'px-6 py-4 text-sm font-semibold transition-all whitespace-nowrap border-b-2 -mb-px',
                  activeTab === tab
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border',
                )}
              >
                {tab === 'description' && 'Description'}
                {tab === 'specs' && 'Specifications'}
                {tab === 'reviews' && `Reviews (${reviewCount})`}
              </button>
            ))}
          </div>

          {/* Tab content */}
          <div className="max-w-4xl">
            {activeTab === 'description' && (
              <div
                className="rich-content text-sm leading-relaxed
                  [&_h1]:text-2xl [&_h1]:font-bold [&_h1]:mb-4 [&_h1]:mt-2
                  [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:mb-3 [&_h2]:mt-4
                  [&_h3]:text-lg [&_h3]:font-semibold [&_h3]:mb-2 [&_h3]:mt-3
                  [&_p]:mb-3 [&_p]:text-muted-foreground
                  [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:mb-3 [&_ul]:text-muted-foreground
                  [&_ol]:list-decimal [&_ol]:pl-6 [&_ol]:mb-3 [&_ol]:text-muted-foreground
                  [&_li]:mb-1
                  [&_strong]:font-semibold [&_strong]:text-foreground
                  [&_em]:italic
                  [&_a]:text-primary [&_a]:underline hover:[&_a]:text-primary/80
                  [&_blockquote]:border-l-4 [&_blockquote]:border-primary/40 [&_blockquote]:pl-4 [&_blockquote]:italic [&_blockquote]:mb-3 [&_blockquote]:text-muted-foreground
                  [&_code]:bg-muted [&_code]:px-1 [&_code]:rounded [&_code]:font-mono [&_code]:text-xs
                  [&_pre]:bg-muted [&_pre]:rounded [&_pre]:p-4 [&_pre]:mb-3 [&_pre]:overflow-x-auto
                  [&_hr]:border-border [&_hr]:my-6
                  [&_img]:max-w-full [&_img]:rounded-xl [&_img]:my-4
                  [&_iframe]:w-full [&_iframe]:rounded-xl [&_iframe]:my-4"
                dangerouslySetInnerHTML={{ __html: fullDescHtml }}
              />
            )}

            {activeTab === 'specs' && (
              <div>
                {product.specs && product.specs.length > 0 ? (
                  <div className="rounded-xl border border-border overflow-hidden">
                    {product.specs.map((spec, i) => (
                      <div
                        key={i}
                        className={cn(
                          'grid grid-cols-3 gap-4 px-5 py-3.5 text-sm',
                          i % 2 === 0 ? 'bg-muted/30' : '',
                        )}
                      >
                        <span className="font-semibold text-foreground">{spec.label}</span>
                        <span className="col-span-2 text-muted-foreground">{spec.value}</span>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="rounded-xl border border-border overflow-hidden">
                    {[
                      { label: 'Brand', value: product.brand },
                      { label: 'Type', value: product.type },
                      { label: 'Collection', value: product.collection },
                      ...(product.sku ? [{ label: 'SKU', value: product.sku }] : []),
                    ].map((spec, i) => (
                      <div
                        key={i}
                        className={cn(
                          'grid grid-cols-3 gap-4 px-5 py-3.5 text-sm',
                          i % 2 === 0 ? 'bg-muted/30' : '',
                        )}
                      >
                        <span className="font-semibold text-foreground">{spec.label}</span>
                        <span className="col-span-2 text-muted-foreground">{spec.value}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {activeTab === 'reviews' && (
              <div className="space-y-8">
                {/* Rating summary */}
                <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6 p-6 rounded-xl bg-muted/30 border border-border/50">
                  <div className="text-center sm:text-left">
                    <div className="text-5xl font-display font-bold mb-1">{rating.toFixed(1)}</div>
                    <StarRating rating={rating} size="lg" />
                    <p className="text-sm text-muted-foreground mt-1">{reviewCount} reviews</p>
                  </div>
                  <div className="w-px h-20 bg-border hidden sm:block" />
                  <div className="flex-1 space-y-2 w-full">
                    {[5, 4, 3, 2, 1].map(star => {
                      const count = allReviews.filter(r => Math.floor(r.rating) === star).length;
                      const pct = allReviews.length > 0 ? (count / allReviews.length) * 100 : 0;
                      return (
                        <div key={star} className="flex items-center gap-3 text-sm">
                          <span className="w-3 font-medium">{star}</span>
                          <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" />
                          <div className="flex-1 h-2.5 rounded-full bg-muted overflow-hidden">
                            <div
                              className="h-full rounded-full bg-amber-400 transition-all duration-500"
                              style={{ width: `${pct}%` }}
                            />
                          </div>
                          <span className="text-muted-foreground w-8 text-right">{count}</span>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* ── Write a Review ── */}
                <div className="rounded-xl border border-border/50 overflow-hidden">
                  <div className="px-6 py-4 bg-muted/30 border-b border-border/50 flex items-center gap-2">
                    <MessageSquare className="h-4 w-4 text-primary" />
                    <h3 className="font-semibold text-sm">Write a Review</h3>
                  </div>

                  {isAuthenticated && customer ? (
                    <div className="p-6 space-y-4">
                      <div>
                        <label className="text-sm font-medium mb-2 block">Your Rating</label>
                        <StarPicker value={reviewRating} onChange={setReviewRating} disabled={isSubmitting} />
                      </div>
                      <div>
                        <label htmlFor="review-comment" className="text-sm font-medium mb-2 block">
                          Your Review
                        </label>
                        <textarea
                          id="review-comment"
                          rows={4}
                          value={reviewComment}
                          onChange={(e) => setReviewComment(e.target.value)}
                          disabled={isSubmitting}
                          placeholder="Share your experience with this product..."
                          className={cn(
                            'w-full rounded-lg border border-border bg-background px-4 py-3 text-sm',
                            'placeholder:text-muted-foreground/60 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary',
                            'transition-colors resize-none disabled:opacity-50 disabled:cursor-not-allowed',
                          )}
                        />
                      </div>
                      <div className="flex items-center justify-between gap-4">
                        <p className="text-xs text-muted-foreground">
                          Posting as <span className="font-semibold text-foreground">{customer.name || customer.first_name}</span>
                        </p>
                        <Button
                          size="sm"
                          className="px-6"
                          disabled={isSubmitting || reviewRating === 0 || !reviewComment.trim()}
                          onClick={handleSubmitReview}
                        >
                          {isSubmitting ? (
                            <>
                              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                              Posting…
                            </>
                          ) : (
                            'Post Review'
                          )}
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <div className="p-6 text-center">
                      <p className="text-sm text-muted-foreground mb-3">
                        Sign in to share your thoughts on this product.
                      </p>
                      <Button variant="outline" size="sm" asChild>
                        <Link to={storefrontRoutes.login}>
                          <LogIn className="h-4 w-4 mr-2" />
                          Sign In to Review
                        </Link>
                      </Button>
                    </div>
                  )}
                </div>

                {/* Review list */}
                {allReviews.length > 0 ? (
                  <div className="divide-y divide-border">
                    {allReviews.map(review => (
                      <div key={review.id} className="py-6 first:pt-0">
                        <div className="flex items-start justify-between mb-2">
                          <div>
                            <div className="flex items-center gap-2 mb-1">
                              <span className="font-semibold text-sm">{review.author}</span>
                              {review.verified && (
                                <span className="inline-flex items-center gap-1 text-[11px] text-green-600 dark:text-green-400 bg-green-500/10 px-2 py-0.5 rounded-full font-medium">
                                  <Check className="h-3 w-3" /> Verified
                                </span>
                              )}
                            </div>
                            <StarRating rating={review.rating} />
                          </div>
                          <span className="text-xs text-muted-foreground">
                            {new Date(review.date).toLocaleDateString('en-SG', {
                              year: 'numeric', month: 'short', day: 'numeric',
                            })}
                          </span>
                        </div>
                        <p className="text-sm text-muted-foreground leading-relaxed mt-2">
                          {review.comment}
                        </p>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-muted-foreground text-center py-8">
                    No reviews yet. Be the first to review this product!
                  </p>
                )}
              </div>
            )}
          </div>
        </div>

        {/* ─── Related Products ─── */}
        {relatedProducts.length > 0 && (
          <div className="pt-12 border-t border-border">
            <div className="flex items-center justify-between mb-8">
              <h2 className="text-2xl font-display font-bold">You May Also Like</h2>
              <Button variant="ghost" size="sm" asChild className="text-muted-foreground hover:text-foreground">
                <Link to={`${storefrontRoutes.shop}?brand=${product.brand}`}>
                  View All
                  <ChevronRight className="h-4 w-4 ml-1" />
                </Link>
              </Button>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6">
              {relatedProducts.map((p, index) => (
                <div
                  key={p.id}
                  className="animate-fade-in"
                  style={{ animationDelay: `${index * 80}ms` }}
                >
                  <ProductCard product={p} />
                </div>
              ))}
            </div>
          </div>
        )}
      </div>
    </Layout>
  );
};

export default ProductDetail;
