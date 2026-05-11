import { useState, useEffect, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { Calendar, MapPin, Users, Ticket } from 'lucide-react';
import { Layout } from '@/storefront/components/layout/Layout';
import { Event, buildEventCommerce, getEventTimelineStatus, resolveEventEnd, type EventTimelineStatus } from '@/storefront/data/events';
import { Button, buttonVariants } from '@/storefront/components/ui/button';
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
import { bookPublicEvent } from '@/storefront/lib/api';
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
    <div className="flex gap-3">
      {Object.entries(timeLeft).map(([unit, value]) => (
        <div key={unit} className="text-center">
          <div
            className={`rounded-xl bg-muted flex items-center justify-center ${
              large ? 'w-16 h-16 md:w-20 md:h-20' : 'w-14 h-14'
            }`}
          >
            <span
              className={`font-display font-bold text-primary ${
                large ? 'text-2xl md:text-3xl' : 'text-xl'
              }`}
            >
              {value.toString().padStart(2, '0')}
            </span>
          </div>
          <span className="text-xs text-muted-foreground mt-2 block capitalize">
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

const SECTION_META: Record<EventTimelineStatus, {
  title: string;
  description: string;
  emptyMessage: string;
}> = {
  current: {
    title: 'Current Events',
    description: 'Live campaigns, active sales, and in-progress fan experiences.',
    emptyMessage: 'No events are live right now.',
  },
  upcoming: {
    title: 'Upcoming Events',
    description: 'Future launches, ticket drops, and campaigns you can still plan for.',
    emptyMessage: 'No upcoming events have been published yet.',
  },
  past: {
    title: 'Past Events',
    description: 'Campaign archive, previous fan activities, and reference events for future promotions.',
    emptyMessage: 'No past events are available yet.',
  },
};

const Events = () => {
  const { data: events = [], isLoading } = useStorefrontEvents();
  const [selectedEvent, setSelectedEvent] = useState<Event | null>(null);
  const [showRegistration, setShowRegistration] = useState(false);
  const [formData, setFormData] = useState({ name: '', email: '', phone: '' });
  const [queueNumber, setQueueNumber] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
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

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedEvent) return;

    setIsSubmitting(true);
    try {
      const response = await bookPublicEvent(parseInt(selectedEvent.id), formData);
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

  const renderEventCard = (event: Event, index: number, timeline: EventTimelineStatus) => {
    const commerce = buildEventCommerceLinks(event);
    const timelineLabel = timeline === 'current' ? 'Live Now' : timeline === 'upcoming' ? 'Upcoming' : 'Archive';
    const countdownTarget = timeline === 'current' ? resolveEventEnd(event) : event.date;
    const countdownLabel = timeline === 'current' ? 'Event ends in:' : 'Event starts in:';

    return (
      <div
        key={event.id}
        className="group glass-strong rounded-2xl overflow-hidden card-hover animate-fade-in"
        style={{ animationDelay: `${index * 100}ms` }}
      >
        <div className="grid md:grid-cols-2 gap-6">
          <div className="relative aspect-video md:aspect-auto overflow-hidden">
            <img
              src={event.image}
              alt={event.title}
              className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
              loading="lazy"
            />
            <div className="absolute inset-0 bg-gradient-to-r from-background/60 to-transparent md:hidden" />

            <div className="absolute left-4 top-4 flex flex-wrap gap-2">
              <Badge className={timeline === 'current' ? 'bg-emerald-500 text-white border-0' : timeline === 'past' ? 'bg-stone-900 text-white border-0' : 'bg-primary text-primary-foreground border-0'}>
                {timelineLabel}
              </Badge>
            </div>

            <div className="absolute top-4 right-4">
              <Badge variant={event.spotsLeft < 50 ? 'limited' : 'default'}>
                {timeline === 'past' ? 'Campaign Closed' : `${event.spotsLeft} spots left`}
              </Badge>
            </div>
          </div>

          <div className="p-6 md:p-8 flex flex-col">
            <h2 className="text-2xl md:text-3xl font-display font-bold mb-3 group-hover:text-primary transition-colors">
              {event.title}
            </h2>
            <p className="text-muted-foreground mb-6">
              {event.description}
            </p>

            {timeline !== 'past' && (
              <div className="mb-6">
                <p className="text-sm text-muted-foreground mb-3">{countdownLabel}</p>
                <Countdown targetDate={countdownTarget} />
              </div>
            )}

            <div className="space-y-3 text-sm text-muted-foreground mb-6">
              <div className="flex items-center gap-3">
                <Calendar className="h-5 w-5 text-primary" />
                <span>
                  {event.date.toLocaleDateString('en-SG', {
                    weekday: 'long',
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                  })}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <MapPin className="h-5 w-5 text-primary" />
                <span>{event.venue}</span>
              </div>
              <div className="flex items-center gap-3">
                <Users className="h-5 w-5 text-primary" />
                <span>
                  {timeline === 'past'
                    ? `${event.totalSpots - event.spotsLeft} attendees recorded`
                    : `${event.spotsLeft} / ${event.totalSpots} spots available`}
                </span>
              </div>
            </div>

            <div className="mb-6 rounded-2xl border border-border/60 bg-background/60 p-4">
              <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">Event Commerce</p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {commerce.linkedBundleCount > 0
                      ? `${commerce.linkedBundleCount} linked bundle package${commerce.linkedBundleCount === 1 ? '' : 's'} are already published for this event.`
                      : commerce.brand
                        ? `Shop ${commerce.brand} merchandise or open the bundle builder for this event campaign.`
                        : 'Jump into the merch shop or bundle builder to continue this event journey beyond ticket registration.'}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Link
                    to={commerce.merchUrl}
                    className={cn(buttonVariants({ variant: 'outline', size: 'sm' }), 'min-w-[140px]')}
                  >
                    Shop Merch
                  </Link>
                  <Link
                    to={commerce.bundleUrl}
                    className={cn(buttonVariants({ variant: 'glass', size: 'sm' }), 'min-w-[140px]')}
                  >
                    {commerce.linkedBundleCount > 0 ? 'View Live Bundles' : 'Build Bundle'}
                  </Link>
                </div>
              </div>
            </div>

            <div className="mt-auto flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
              <div>
                <span className="text-3xl font-display font-bold">
                  {event.price === 0 ? 'FREE' : `SGD ${event.price.toFixed(2)}`}
                </span>
              </div>
              {timeline === 'past' ? (
                <Button variant="outline" disabled>
                  Registration Closed
                </Button>
              ) : (
                <Button
                  variant="hero"
                  onClick={() => {
                    setSelectedEvent(event);
                    setShowRegistration(true);
                  }}
                >
                  <Ticket className="h-5 w-5 mr-2" />
                  Register Now
                </Button>
              )}
            </div>
          </div>
        </div>
      </div>
    );
  };

  return (
    <Layout>
      <div className="container py-8 md:py-12">
        <div className="text-center mb-12">
          <h1 className="text-4xl md:text-5xl font-display font-bold mb-4">
            Event <span className="gradient-text">Campaigns</span>
          </h1>
          <p className="text-lg text-muted-foreground max-w-3xl mx-auto">
            Browse live campaigns, plan for upcoming launches, and revisit past event archives without mixing every event into a single bucket.
          </p>

          <div className="mt-8 grid gap-4 sm:grid-cols-3 text-left">
            {(['current', 'upcoming', 'past'] as EventTimelineStatus[]).map((timeline) => (
              <div key={timeline} className="rounded-2xl border border-border/60 bg-card/70 p-4">
                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
                  {SECTION_META[timeline].title}
                </p>
                <p className="mt-2 text-3xl font-display font-bold text-foreground">
                  {eventSections[timeline].length}
                </p>
                <p className="mt-2 text-sm text-muted-foreground">{SECTION_META[timeline].description}</p>
              </div>
            ))}
          </div>
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
              <section key={timeline} className="space-y-6">
                <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                  <div>
                    <h2 className="text-2xl md:text-3xl font-display font-bold text-foreground">
                      {SECTION_META[timeline].title}
                    </h2>
                    <p className="text-muted-foreground">
                      {SECTION_META[timeline].description}
                    </p>
                  </div>
                  <Badge className="w-fit bg-background text-foreground border border-border/60">
                    {eventSections[timeline].length} event{eventSections[timeline].length === 1 ? '' : 's'}
                  </Badge>
                </div>

                {eventSections[timeline].length > 0 ? (
                  <div className="space-y-8">
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

      {/* Registration Modal */}
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
                  {isSubmitting ? 'Registering...' : 'Complete Registration'}
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
