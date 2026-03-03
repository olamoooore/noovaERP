import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { useStorefrontEvents } from '@/storefront/hooks/useEvents';
import { Button } from '@/storefront/components/ui/button';
import { Badge } from '@/storefront/components/ui/badge';
import { storefrontRoutes, storefrontPath } from '@/storefront/lib/paths';

interface CountdownProps {
  targetDate: Date;
}

const Countdown = ({ targetDate }: CountdownProps) => {
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
        <div key={unit} className="text-center">
          <div className="w-12 h-12 md:w-14 md:h-14 rounded-xl bg-white/90 backdrop-blur-md flex items-center justify-center shadow-lg border border-white/40">
            <span className="text-lg md:text-xl font-display font-bold text-foreground">
              {value.toString().padStart(2, '0')}
            </span>
          </div>
          <span className="text-[10px] bg-black/50 text-white px-2 py-0.5 rounded-full mt-2 block backdrop-blur-sm capitalize font-medium">
            {unit.slice(0, 3)}
          </span>
        </div>
      ))}
    </div>
  );
};

export const EventsSection = () => {
  const { data: events = [], isLoading } = useStorefrontEvents();

  return (
    <section className="py-24 bg-white relative overflow-hidden">
      {/* Background decoration */}
      <div className="absolute top-0 right-0 w-1/3 h-full bg-gradient-to-l from-secondary/5 to-transparent pointer-events-none" />

      <div className="container relative z-10">
        {/* Header */}
        <div className="flex items-end justify-between mb-12">
          <div>
            <h2 className="text-4xl font-display font-bold mb-3 tracking-tight">
              Upcoming <span className="text-aura">Events</span>
            </h2>
            <p className="text-muted-foreground text-lg">
              Don't miss out on exclusive fan experiences
            </p>
          </div>
          <Button variant="ghost" asChild className="hidden md:flex hover:bg-secondary/10 hover:text-secondary hover:shadow-sm">
            <Link to={storefrontRoutes.events}>
              View All
              <Icon icon="solar:arrow-right-linear" className="h-5 w-5 ml-2" />
            </Link>
          </Button>
        </div>

        {/* Events Grid */}
        <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
          {isLoading && [1, 2, 3].map((i) => (
            <div key={i} className="rounded-3xl overflow-hidden bg-muted/30 animate-pulse h-96 border border-border/20" />
          ))}
          {!isLoading && events.map((event, index) => (
            <div
              key={event.id}
              className="group relative rounded-3xl overflow-hidden bg-white border border-border/40 hover:border-border hover:shadow-xl transition-all duration-500 hover:-translate-y-1 animate-fade-in-up"
              style={{ animationDelay: `${index * 100}ms` }}
            >
              {/* Image */}
              <div className="relative aspect-[4/3] overflow-hidden">
                <img
                  src={event.image}
                  alt={event.title}
                  className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105"
                  loading="lazy"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-80" />
                
                {/* Countdown Overlay */}
                <div className="absolute bottom-6 left-1/2 -translate-x-1/2 w-full flex justify-center px-4">
                  <Countdown targetDate={event.date} />
                </div>

                {/* Spots Badge */}
                <div className="absolute top-4 right-4">
                  <Badge className={event.spotsLeft < 50 ? 'bg-red-500 text-white border-0' : 'bg-white/90 text-foreground backdrop-blur-md border-0'}>
                    {event.spotsLeft} spots left
                  </Badge>
                </div>
              </div>

              {/* Content */}
              <div className="p-6">
                <h3 className="text-xl font-display font-bold mb-3 group-hover:text-primary transition-colors">
                  {event.title}
                </h3>
                <p className="text-sm text-muted-foreground mb-6 line-clamp-2 leading-relaxed">
                  {event.description}
                </p>

                <div className="flex flex-col gap-3 text-sm text-muted-foreground mb-6">
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                      <Icon icon="solar:calendar-linear" className="h-4 w-4 text-primary" />
                    </div>
                    <span>
                      {event.date.toLocaleDateString('en-SG', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                      })}
                    </span>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-secondary/10 flex items-center justify-center flex-shrink-0">
                      <Icon icon="solar:map-point-linear" className="h-4 w-4 text-secondary" />
                    </div>
                    <span>{event.venue}</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className="w-8 h-8 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0">
                      <Icon icon="solar:users-group-rounded-linear" className="h-4 w-4 text-orange-500" />
                    </div>
                    <span>{event.spotsLeft} / {event.totalSpots} available</span>
                  </div>
                </div>

                <div className="flex items-center justify-between pt-6 border-t border-dashed border-border">
                  <span className="font-display font-bold text-2xl text-foreground">
                    {event.price === 0 ? 'FREE' : `SGD ${event.price.toFixed(2)}`}
                  </span>
                  <Button className="rounded-full px-6 bg-foreground text-background hover:scale-105 transition-transform" asChild>
                    <Link to={`${storefrontRoutes.events}/${event.id}`}>Register</Link>
                  </Button>
                </div>
              </div>
            </div>
          ))}
        </div>

        {/* Mobile View All */}
        <div className="mt-12 text-center md:hidden">
          <Button variant="outline" className="w-full rounded-full border-2" asChild>
            <Link to={storefrontRoutes.events}>
              View All Events
              <Icon icon="solar:arrow-right-linear" className="h-5 w-5 ml-2" />
            </Link>
          </Button>
        </div>
      </div>
    </section>
  );
};
