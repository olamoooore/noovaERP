import { useState, useEffect, useMemo } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Calendar, MapPin, Users, Ticket, Radio, Sparkles, Archive, Package2, Tag } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Event, buildEventCommerce, getEventTimelineStatus, resolveEventEnd, type EventTimelineStatus } from '@/storefront/data/events';
import { Button, buttonVariants } from '@/storefront/components/ui/button';
import { useProducts } from '@/storefront/hooks/useProducts';
import type { Product } from '@/storefront/data/products';
import { Badge } from '@/storefront/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';
import { useStorefrontEvents } from '@/storefront/hooks/useEvents';
import { useCart } from '@/storefront/context/CartContext';
import { mapApiProduct } from '@/storefront/hooks/useProducts';
import { bookPublicEvent, fetchProduct, preparePublicEventCheckout } from '@/storefront/lib/api';
import { storefrontRoutes } from '@/storefront/lib/paths';
import { cn } from '@/lib/utils';

interface CountdownProps {
  targetDate: Date;
  large?: boolean;
}

const Countdown = ({ targetDate, large }: CountdownProps) => {
  const [timeLeft, setTimeLeft] = useState({ days: 0, hours: 0, minutes: 0, seconds: 0 });

  useEffect(() => {
    const calculateTimeLeft = () => {
      const difference = targetDate.getTime() - new Date().getTime();
      if (difference > 0) {
        setTimeLeft({
          days: Math.floor(difference / (1000 * 60 * 60 * 24)),
          hours: Math.floor((difference / (1000 * 60 * 60)) % 24),
          minutes: Math.floor((difference / 1000 / 60) % 60),
          seconds: Math.floor((difference / 1000) % 60),
        });
      }
    };

    calculateTimeLeft();
    const timer = setInterval(calculateTimeLeft, 1000);
    return () => clearInterval(timer);
  }, [targetDate]);

  return (
    <div className="flex gap-2">
      {Object.entries(timeLeft).map(([unit, value]) => (
        <div key={unit} className="text-center flex-1">
          <div
            className={`rounded-lg bg-muted flex items-center justify-center ${
              large ? 'w-16 h-16 md:w-20 md:h-20' : 'h-10'
            }`}
          >
            <span
              className={`font-display font-bold text-primary ${
                large ? 'text-2xl md:text-3xl' : 'text-base'
              }`}
            >
              {value.toString().padStart(2, '0')}
            </span>
          </div>
          <span className="text-[10px] text-muted-foreground mt-1 block capitalize">
            {unit}
          </span>
        </div>
      ))}
    </div>
  );
};

const buildEventCommerceLinks = (event: Event) => {
  const commerce = buildEventCommerce(event);
  const merchParams = new URLSearchParams({ view: 'merchandise', event: event.title });
  const bundleParams = new URLSearchParams({ view: 'bundles', event: event.title });
  const linkedMerchCount = commerce.merchProductIds?.length ?? 0;
  const linkedBundleCount = commerce.bundleProductIds?.length ?? 0;

  if (commerce.brand) {
    merchParams.set('brand', commerce.brand);
  }
  if (commerce.merchType) {
    merchParams.set('type', commerce.merchType);
  }
  if (commerce.merchSearch) {
    merchParams.set('search', commerce.merchSearch);
  }
  if (commerce.bundleSearch) {
    bundleParams.set('search', commerce.bundleSearch);
  }
  if (linkedMerchCount > 0) {
    merchParams.set('event_id', event.id);
  }
  if (linkedBundleCount > 0) {
    bundleParams.set('event_id', event.id);
  }

  return {
    brand: commerce.brand ?? null,
    linkedMerchCount,
    linkedBundleCount,
    merchUrl: `${storefrontRoutes.shop}?${merchParams.toString()}`,
    bundleUrl: `${storefrontRoutes.shop}?${bundleParams.toString()}`,
  };
};

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

const SECTION_META: Record<EventTimelineStatus, {
  title: string;
  emptyMessage: string;
}> = {
  current: {
    title: 'Current Events',
    emptyMessage: 'No events are live right now.',
  },
  upcoming: {
    title: 'Upcoming Events',
    emptyMessage: 'No upcoming events have been published yet.',
  },
  past: {
    title: 'Past Events',
    emptyMessage: 'No past events are available yet.',
  },
};

const Events = () => {
  const navigate = useNavigate();
  const { addToCart } = useCart();
  const { data: events = [], isLoading } = useStorefrontEvents();
  const { data: storefrontProductsData } = useProducts({ per_page: 30, page: 1 });
  const { data: storefrontBundleData } = useProducts({ per_page: 30, page: 1, is_bundle: true });
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [showRegistration, setShowRegistration] = useState(false);
  const [formData, setFormData] = useState({ name: '', email: '', phone: '' });
  const [queueNumber, setQueueNumber] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [checkoutEventId, setCheckoutEventId] = useState<string | null>(null);
  const selectedEventCommerce = selectedEvent ? buildEventCommerceLinks(selectedEvent) : null;

  const eventSections = useMemo(() => {
    const now = new Date();
    const buckets: Record<EventTimelineStatus, Event[]> = {
      current: [],
      upcoming: [],
      past: [],
    };

    events.forEach((event) => {
      buckets[getEventTimelineStatus(event, now)].push(event);
    });

    buckets.current.sort((left, right) => left.date.getTime() - right.date.getTime());
    buckets.upcoming.sort((left, right) => left.date.getTime() - right.date.getTime());
    buckets.past.sort((left, right) => resolveEventEnd(right).getTime() - resolveEventEnd(left).getTime());

    return buckets;
  }, [events]);

  const eventShopCounts = useMemo(() => {
    const storefrontProducts = storefrontProductsData?.products || [];

    return {
      merchandise: storefrontProducts.filter(isMerchandiseProduct).length,
      limited: storefrontProducts.filter(isLimitedDropProduct).length,
      bundles: storefrontBundleData?.total ?? storefrontBundleData?.products.length ?? 0,
    };
  }, [storefrontBundleData?.products.length, storefrontBundleData?.total, storefrontProductsData?.products]);

  const eventShopTiles = [
    {
      title: 'Merchandise',
      count: eventShopCounts.merchandise,
      icon: Package2,
      href: `${storefrontRoutes.shop}?view=merchandise`,
      subtitle: 'Fan goods',
      cta: 'Shop now',
    },
    {
      title: 'Limited Drops',
      count: eventShopCounts.limited,
      icon: Sparkles,
      href: `${storefrontRoutes.shop}?view=limited`,
      subtitle: 'Timed releases',
      cta: 'View drops',
    },
    {
      title: 'Event Bundles',
      count: eventShopCounts.bundles,
      icon: Tag,
      href: `${storefrontRoutes.shop}?view=bundles`,
      subtitle: 'Ticket + merch',
      cta: 'Open bundles',
    },
  ];

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedEvent) return;
    if (!selectedEvent.isBookable) {
      toast.error('Booking unavailable', {
        description: 'This event is a storefront preview. Publish a live event in the backend to enable booking and payment.',
      });
      return;
    }

    setIsSubmitting(true);
    try {
      const response = await bookPublicEvent(selectedEvent.id, formData);
      const formatted = response.queue_number
        ? String(response.queue_number).padStart(4, '0')
        : String(Math.floor(Math.random() * 9000) + 1000);
      setQueueNumber(formatted);
      toast.success('Registration successful!', {
        description: `Your queue number is #${formatted}`,
      });
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Registration failed. Please try again.';
      toast.error('Registration failed', { description: msg });
    } finally {
      setIsSubmitting(false);
    }
  };

  const closeModal = () => {
    setShowRegistration(false);
    setSelectedEvent(null);
    setFormData({ name: '', email: '', phone: '' });
    setQueueNumber(null);
  };

  const handleStartCheckout = async (event: Event) => {
    if (!event.isBookable) {
      toast.error('Booking unavailable', {
        description: 'This event is a storefront preview. Publish a live event in the backend to enable ticket checkout.',
      });
      return;
    }

    setCheckoutEventId(event.id);
    try {
      const checkout = await preparePublicEventCheckout(event.id);
      const apiProduct = await fetchProduct(checkout.ticket_product_id);
      addToCart(mapApiProduct(apiProduct), 1);
      toast.success('Ticket added to cart', {
        description: 'Continue through the standard checkout flow to complete payment and generate your invoice.',
      });
      navigate(storefrontRoutes.checkout);
    } catch (err: any) {
      const msg = err?.response?.data?.message || 'Failed to start checkout for this event.';
      toast.error('Checkout unavailable', { description: msg });
    } finally {
      setCheckoutEventId(null);
    }
  };

  const renderEventCard = (event: Event, index: number, timeline: EventTimelineStatus) => {
    const commerce = buildEventCommerceLinks(event);
    const isBookable = event.isBookable === true;
    const timelineLabel = timeline === 'current' ? 'Live Now' : timeline === 'upcoming' ? 'Upcoming' : 'Archive';
    const countdownTarget = timeline === 'current' ? resolveEventEnd(event) : event.date;
    const countdownLabel = timeline === 'current' ? 'Ends in' : 'Starts in';
    const timelineBadgeClass =
      timeline === 'current'
        ? 'bg-emerald-500 text-white border-0'
        : timeline === 'past'
          ? 'bg-stone-900 text-white border-0'
          : 'bg-primary text-primary-foreground border-0';

    return (
      <div
        key={event.id}
        className="group glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in flex flex-col h-full"
        style={{ animationDelay: `${index * 80}ms` }}
      >
        <div className="relative aspect-[16/10] overflow-hidden">
          <img
            src={event.image}
            alt={event.title}
            className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
            loading="lazy"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-background/80 via-background/10 to-transparent" />

          <div className="absolute left-3 top-3 flex flex-wrap gap-2">
            <Badge className={timelineBadgeClass}>{timelineLabel}</Badge>
          </div>
          <div className="absolute right-3 top-3">
            <Badge variant={event.spotsLeft < 50 ? 'limited' : 'default'}>
              {timeline === 'past' ? 'Closed' : `${event.spotsLeft} left`}
            </Badge>
          </div>

          <div className="absolute left-3 right-3 bottom-3">
            <h2 className="text-lg md:text-xl font-display font-bold text-white drop-shadow-md line-clamp-2 group-hover:text-primary transition-colors">
              {event.title}
            </h2>
          </div>
        </div>

        <div className="p-5 flex flex-col flex-1">
          <p className="text-sm text-muted-foreground line-clamp-2 mb-4">
            {event.description}
          </p>

          {timeline !== 'past' && (
            <div className="mb-4 rounded-xl border border-border/60 bg-background/40 px-3 py-2">
              <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground mb-2">
                {countdownLabel}
              </p>
              <Countdown targetDate={countdownTarget} />
            </div>
          )}

          <div className="space-y-2 text-xs text-muted-foreground mb-4">
            <div className="flex items-center gap-2">
              <Calendar className="h-4 w-4 text-primary shrink-0" />
              <span className="truncate">
                {event.date.toLocaleDateString('en-SG', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                })}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <MapPin className="h-4 w-4 text-primary shrink-0" />
              <span className="truncate">{event.venue}</span>
            </div>
            <div className="flex items-center gap-2">
              <Users className="h-4 w-4 text-primary shrink-0" />
              <span className="truncate">
                {timeline === 'past'
                  ? `${event.totalSpots - event.spotsLeft} attendees`
                  : `${event.spotsLeft} / ${event.totalSpots} spots`}
              </span>
            </div>
          </div>

          <div className="mb-4 flex flex-wrap gap-2">
            <Link
              to={commerce.merchUrl}
              className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'flex-1 min-w-[120px]')}
            >
              Shop Merch
            </Link>
            <Link
              to={commerce.bundleUrl}
              className={cn(buttonVariants({ variant: 'glass', size: 'sm' }), 'flex-1 min-w-[120px]')}
            >
              {commerce.linkedBundleCount > 0 ? 'View Bundles' : 'Build Bundle'}
            </Link>
          </div>

          <div className="mt-auto flex items-center justify-between gap-3 pt-3 border-t border-border/60">
            <span className="text-xl font-display font-bold">
              {event.price === 0 ? 'FREE' : `SGD ${event.price.toFixed(2)}`}
            </span>
            {timeline === 'past' ? (
              <Button variant="outline" size="sm" disabled>
                Closed
              </Button>
            ) : (
              <Button
                variant="hero"
                size="sm"
                disabled={!isBookable || checkoutEventId === event.id}
                title={!isBookable ? 'Booking is only available for published backend events.' : undefined}
                onClick={() => {
                  if (!isBookable) {
                    return;
                  }
                  if (event.price > 0) {
                    void handleStartCheckout(event);
                    return;
                  }
                  setSelectedEvent(event);
                  setShowRegistration(true);
                }}
              >
                <Ticket className="h-4 w-4 mr-1.5" />
                {!isBookable ? 'Preview Only' : checkoutEventId === event.id ? 'Opening Checkout...' : event.price > 0 ? 'Buy Ticket' : 'Register'}
              </Button>
            )}
          </div>
        </div>
      </div>
    );
  };

  return (
    <Layout>
      <div className="container py-8 md:py-12">
        {/* Hero header */}
        <div className="relative overflow-hidden rounded-3xl border border-border/60 bg-gradient-to-br from-primary/15 via-accent/10 to-background mb-10">
          <div className="absolute -top-24 -right-24 h-72 w-72 rounded-full bg-primary/20 blur-3xl" aria-hidden />
          <div className="absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-accent/20 blur-3xl" aria-hidden />

          <div className="relative px-6 py-10 md:px-12 md:py-14 text-center">
            <Badge className="mb-4 bg-background/70 text-foreground border border-border/60 backdrop-blur">
              <Sparkles className="h-3.5 w-3.5 mr-1.5 text-primary" />
              Event Hub
            </Badge>
            <h1 className="text-4xl md:text-6xl font-display font-bold mb-4">
              Events & <span className="gradient-text">Drops</span>
            </h1>
            <p className="text-base md:text-lg text-muted-foreground max-w-2xl mx-auto">
              Start with the event flow, then jump into merch, drops, or bundles.
            </p>
          </div>
        </div>

        <section className="mb-12 rounded-3xl border border-border/60 bg-card/70 p-5 shadow-sm md:p-6">
          <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                Event Shop
              </p>
              <h2 className="mt-2 text-2xl font-display font-bold text-foreground md:text-3xl">
                Choose a shopping flow
              </h2>
              <p className="mt-2 text-sm text-muted-foreground">
                Pick one path and continue shopping.
              </p>
            </div>
            <Link
              to={storefrontRoutes.shop}
              className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'w-fit')}
            >
              Full Shop
            </Link>
          </div>

          <div className="mt-6 grid gap-4 lg:grid-cols-3">
            {eventShopTiles.map((tile) => {
              const IconCmp = tile.icon;

              return (
                <Link
                  key={tile.title}
                  to={tile.href}
                  className="group rounded-2xl border border-border/60 bg-background/70 p-5 transition-all hover:border-primary/40 hover:shadow-lg hover:-translate-y-0.5"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                        {tile.subtitle}
                      </p>
                      <h3 className="mt-2 text-xl font-display font-bold text-foreground">{tile.title}</h3>
                    </div>
                    <div className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                      <IconCmp className="h-5 w-5" />
                    </div>
                  </div>

                  <div className="mt-6 flex items-end justify-between gap-3 border-t border-border/60 pt-4">
                    <div>
                      <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">Available</p>
                      <p className="text-3xl font-display font-bold text-foreground">{tile.count}</p>
                    </div>
                    <div className="text-sm font-medium text-primary transition-transform group-hover:translate-x-0.5">
                      {tile.cta}
                    </div>
                  </div>
                </Link>
              );
            })}
          </div>
        </section>

        {/* Stat tiles */}
        <div className="grid gap-3 sm:grid-cols-3 mb-12">
          {(['current', 'upcoming', 'past'] as EventTimelineStatus[]).map((timeline) => {
              const meta = {
                current: { Icon: Radio, accent: 'from-emerald-500/20 to-emerald-500/0', dot: 'bg-emerald-500', text: 'text-emerald-500' },
                upcoming: { Icon: Sparkles, accent: 'from-primary/20 to-primary/0', dot: 'bg-primary', text: 'text-primary' },
                past: { Icon: Archive, accent: 'from-stone-500/20 to-stone-500/0', dot: 'bg-stone-500', text: 'text-stone-400' },
              }[timeline];
              const IconCmp = meta.Icon;
              const count = eventSections[timeline].length;

              return (
                <a
                  key={timeline}
                  href={`#section-${timeline}`}
                  className="group relative overflow-hidden rounded-2xl border border-border/60 bg-card/70 p-4 text-left transition-all hover:border-primary/50 hover:shadow-lg hover:-translate-y-0.5"
                >
                  <div className={`absolute inset-0 bg-gradient-to-br ${meta.accent} opacity-60 group-hover:opacity-100 transition-opacity`} aria-hidden />
                  <div className="relative flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <div className={`inline-flex h-10 w-10 items-center justify-center rounded-xl bg-background/80 ${meta.text} shadow-sm`}>
                        <IconCmp className="h-5 w-5" />
                      </div>
                      <div>
                        <p className="text-sm font-semibold text-foreground">
                          {SECTION_META[timeline].title}
                        </p>
                        <div className="mt-1 flex items-center gap-1.5">
                          <span className={`h-2 w-2 rounded-full ${meta.dot} ${timeline === 'current' ? 'animate-pulse' : ''}`} />
                          <span className="text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                            {timeline === 'current' ? 'Live' : timeline === 'upcoming' ? 'Soon' : 'Archived'}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="text-right shrink-0">
                      <p className="text-3xl font-display font-bold text-foreground leading-none">{count}</p>
                      <p className="mt-1 text-[11px] uppercase tracking-[0.18em] text-muted-foreground">events</p>
                    </div>
                  </div>
                </a>
              );
            })}
        </div>

        <div className="space-y-12">
          {isLoading ? (
            <div className="space-y-8">
              {[1, 2, 3].map((i) => (
                <div key={i} className="glass-strong rounded-2xl overflow-hidden h-64 animate-pulse bg-muted/30" />
              ))}
            </div>
          ) : (
            (['current', 'upcoming', 'past'] as EventTimelineStatus[]).map((timeline) => (
              <section key={timeline} id={`section-${timeline}`} className="space-y-6 scroll-mt-24">
                <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                  <div>
                    <h2 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                      {SECTION_META[timeline].title}
                    </h2>
                  </div>
                  <Badge className="w-fit bg-background text-foreground border border-border/60">
                    {eventSections[timeline].length} event{eventSections[timeline].length === 1 ? '' : 's'}
                  </Badge>
                </div>

                {eventSections[timeline].length > 0 ? (
                  <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {eventSections[timeline].map((event, index) => renderEventCard(event, index, timeline))}
                  </div>
                ) : (
                  <div className="rounded-2xl border border-dashed border-border/60 bg-card/40 p-8 text-center text-muted-foreground">
                    {SECTION_META[timeline].emptyMessage}
                  </div>
                )}
              </section>
            ))
          )}
        </div>
      </div>

      {/* Free Registration Modal */}
      <Dialog open={showRegistration} onOpenChange={closeModal}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-xl font-display">
              {queueNumber ? 'Registration Complete!' : `Register for ${selectedEvent?.title}`}
            </DialogTitle>
          </DialogHeader>

          {queueNumber ? (
            <div className="text-center py-6">
              <div className="w-32 h-32 mx-auto mb-6 rounded-full bg-gradient-to-br from-primary to-accent flex items-center justify-center">
                <Ticket className="h-16 w-16 text-primary-foreground" />
              </div>
              <p className="text-muted-foreground mb-2">Your queue number is</p>
              <p className="text-4xl font-display font-bold gradient-text mb-4">
                #{queueNumber}
              </p>
              <p className="text-sm text-muted-foreground mb-6">
                A confirmation email has been sent to {formData.email}
              </p>
              {selectedEventCommerce && (
                <div className="mb-6 rounded-2xl border border-border/60 bg-background/60 p-4 text-left">
                  <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">Keep Shopping</p>
                  <p className="mt-2 text-sm text-muted-foreground">
                    Continue this campaign by browsing merch or opening the exact bundle flow for this event.
                  </p>
                  <div className="mt-4 flex flex-wrap gap-2">
                    <Link
                      to={selectedEventCommerce.merchUrl}
                      className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'min-w-[140px]')}
                      onClick={closeModal}
                    >
                      Shop Merch
                    </Link>
                    <Link
                      to={selectedEventCommerce.bundleUrl}
                      className={cn(buttonVariants({ variant: 'glass', size: 'sm' }), 'min-w-[140px]')}
                      onClick={closeModal}
                    >
                      {selectedEventCommerce.linkedBundleCount > 0 ? 'View Live Bundles' : 'Build Bundle'}
                    </Link>
                  </div>
                </div>
              )}
              <Button variant="neon" onClick={closeModal}>
                Done
              </Button>
            </div>
          ) : (
            <form onSubmit={handleRegister} className="space-y-4">
              <div>
                <Label htmlFor="name">Full Name</Label>
                <Input
                  id="name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  required
                  placeholder="Enter your full name"
                />
              </div>
              <div>
                <Label htmlFor="email">Email</Label>
                <Input
                  id="email"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  required
                  placeholder="Enter your email"
                />
              </div>
              <div>
                <Label htmlFor="phone">Phone Number</Label>
                <Input
                  id="phone"
                  type="tel"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                  required
                  placeholder="+65 XXXX XXXX"
                />
              </div>
              <div className="flex justify-between items-center pt-4">
                <span className="font-display font-bold text-lg">
                  {selectedEvent?.price === 0 ? 'FREE' : `SGD ${selectedEvent?.price.toFixed(2)}`}
                </span>
                <Button variant="neon" type="submit" disabled={isSubmitting}>
                  {isSubmitting ? 'Creating booking...' : 'Complete Registration'}
                </Button>
              </div>
            </form>
          )}
        </DialogContent>
      </Dialog>
    </Layout>
  );
};

export default Events;
