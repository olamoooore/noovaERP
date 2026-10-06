import React, { useState, useMemo } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Calendar, MapPin, Search, Ticket, Users, Loader2, Settings, Sparkles, ArrowRight } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { fetchPublicEvents } from '@/storefront/lib/api';
import { formatCurrency } from '@/lib/utils';
import { storefrontRoutes } from '@/storefront/lib/paths';

/**
 * BrowseEventsPage
 *
 * Displays live events fetched from the public events API.
 */
const BrowseEventsPage: React.FC = () => {
  const [search, setSearch] = useState('');

  const { data: events = [], isLoading, isError } = useQuery({
    queryKey: ['browse-events'],
    queryFn: fetchPublicEvents,
    staleTime: 60 * 1000,
  });

  const filtered = useMemo(() => {
    if (!search.trim()) return events;
    const q = search.toLowerCase();
    return events.filter(
      (e) =>
        e.title.toLowerCase().includes(q) ||
        (e.venue ?? '').toLowerCase().includes(q)
    );
  }, [events, search]);

  return (
    <div className="min-h-screen bg-stone-50">
      <div className="container mx-auto px-4 py-8 space-y-8">
        <section className="rounded-3xl border border-stone-200 bg-[radial-gradient(circle_at_top_left,_rgba(253,230,138,0.35),_transparent_30%),linear-gradient(135deg,_#fffdf8,_#ffffff_55%,_#ecfeff)] p-6 md:p-8 shadow-sm">
          <div className="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-3xl space-y-4">
              <div className="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-white/80 px-3 py-1 text-sm font-medium text-stone-700">
                <Sparkles className="h-4 w-4 text-amber-500" />
                Live Event Catalog
              </div>
              <div>
                <Heading1 className="text-3xl md:text-4xl mb-2 text-stone-900">Browse Events</Heading1>
                <BodyText className="max-w-2xl text-stone-600">
                  Discover upcoming events, review capacity at a glance, and jump straight into your management workspace when you need to edit or publish.
                </BodyText>
              </div>
            </div>

            <Link to="/event-management/dashboard">
              <Button variant="outline" className="flex items-center gap-2 w-full md:w-auto rounded-xl border-stone-300 bg-white px-4 py-2.5">
                <Settings className="h-4 w-4" />
                Open Event Workspace
                <ArrowRight className="h-4 w-4" />
              </Button>
            </Link>
          </div>

          <div className="mt-8 grid grid-cols-1 md:grid-cols-[minmax(0,1fr)_240px] gap-4">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400 h-4 w-4" />
              <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search events, venues, or themes"
                className="h-12 w-full rounded-2xl border border-stone-200 bg-white pl-10 pr-4 text-sm outline-none transition focus:border-stone-400"
              />
            </div>
            <div className="rounded-2xl border border-stone-200 bg-white px-4 py-3 text-sm text-stone-600 flex items-center justify-between">
              <span>Visible events</span>
              <span className="text-lg font-semibold text-stone-900">{filtered.length}</span>
            </div>
          </div>
        </section>

      {/* Loading */}
      {isLoading && (
        <div className="flex items-center justify-center py-24 text-muted-foreground">
          <Loader2 className="h-8 w-8 animate-spin mr-3" />
          Loading events…
        </div>
      )}

      {/* Error */}
      {isError && (
        <div className="text-center py-16 text-muted-foreground">
          <Calendar className="h-16 w-16 mx-auto mb-4 opacity-40" />
          <p className="font-medium mb-1">Couldn't load events</p>
          <p className="text-sm">Please try again later.</p>
        </div>
      )}

      {/* Events Grid */}
      {!isLoading && !isError && (
        <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
          {filtered.map((event) => {
            const imageKeywords = event.title.toLowerCase();
            const fallback = imageKeywords.includes('kpop') || imageKeywords.includes('concert') || imageKeywords.includes('music')
              ? 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=400'
              : imageKeywords.includes('festival')
              ? 'https://images.unsplash.com/photo-1459749411175-04bf5292ceea?w=400'
              : imageKeywords.includes('training') || imageKeywords.includes('workshop')
              ? 'https://images.unsplash.com/photo-1501281668745-f7f57925c3b4?w=400'
              : 'https://images.unsplash.com/photo-1540039155733-5bb30b53aa14?w=400';

            const imageUrl = event.image_url || fallback;

            return (
              <Card key={event.id} className="overflow-hidden border-stone-200 shadow-none hover:shadow-md transition-shadow bg-white">
                <div
                  className="h-56 bg-cover bg-center"
                  style={{ backgroundImage: `url(${imageUrl})` }}
                />
                <CardContent className="p-5">
                  <div className="flex items-start justify-between gap-3 mb-3">
                    <h3 className="font-semibold text-xl leading-tight text-stone-900">{event.title}</h3>
                    <span className="rounded-full bg-stone-900 px-2.5 py-1 text-xs font-medium text-white whitespace-nowrap">
                      {event.price === 0 ? 'Free' : 'Ticketed'}
                    </span>
                  </div>

                  {event.description && (
                    <p className="text-sm text-stone-600 mb-4 line-clamp-2">{event.description}</p>
                  )}

                  <div className="flex items-center gap-2 text-stone-500 text-sm mb-2">
                    <Calendar className="h-4 w-4 flex-shrink-0" />
                    <span>
                      {event.start_date
                        ? new Date(event.start_date).toLocaleDateString('en-SG', {
                            day: 'numeric', month: 'short', year: 'numeric',
                          })
                        : 'TBA'}
                    </span>
                  </div>

                  {event.venue && (
                    <div className="flex items-center gap-2 text-stone-500 text-sm mb-2">
                      <MapPin className="h-4 w-4 flex-shrink-0" />
                      <span>{event.venue}</span>
                    </div>
                  )}

                  <div className="flex items-center gap-2 text-stone-500 text-sm mb-4">
                    <Users className="h-4 w-4 flex-shrink-0" />
                    <span>
                      {event.available_capacity ?? event.spots_left ?? 0} spots left
                    </span>
                  </div>

                  <div className="h-2 rounded-full bg-stone-100 overflow-hidden mb-4">
                    <div
                      className="h-full rounded-full bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500"
                      style={{
                        width: `${Math.max(8, Math.min(100, (((event.total_capacity ?? event.total_spots ?? 0) - (event.available_capacity ?? event.spots_left ?? 0)) / Math.max(1, event.total_capacity ?? event.total_spots ?? 1)) * 100))}%`,
                      }}
                    />
                  </div>

                  <div className="flex items-center justify-between gap-4">
                    <span className="font-bold text-xl text-stone-900">
                      {event.price === 0 ? 'FREE' : formatCurrency(Number(event.price) || 0)}
                    </span>
                    <Link to={storefrontRoutes.events}>
                      <Button size="sm" className="flex items-center gap-2 rounded-xl">
                        <Ticket className="h-4 w-4" />
                        Book Now
                      </Button>
                    </Link>
                  </div>
                </CardContent>
              </Card>
            );
          })}

          {filtered.length === 0 && (
            <div className="col-span-3 text-center py-16 text-muted-foreground rounded-3xl border border-dashed border-stone-300 bg-white">
              <Calendar className="h-16 w-16 mx-auto mb-4 opacity-40" />
              <p className="font-medium mb-1">No events found</p>
              <p className="text-sm">Try a different search term.</p>
            </div>
          )}
        </div>
      )}
      </div>
    </div>
  );
};

export default BrowseEventsPage;
