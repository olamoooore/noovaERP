import React, { useState, useMemo } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Heading1, BodyText } from '@/components/ui/responsive-text';
import { Calendar, MapPin, Search, Filter, Ticket, Users, Loader2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { fetchPublicEvents } from '@/storefront/lib/api';

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
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="mb-8">
        <Heading1 className="text-2xl md:text-3xl mb-2">Browse Events</Heading1>
        <BodyText variant="muted">Discover and book tickets for upcoming events</BodyText>
      </div>

      {/* Search and Filters */}
      <div className="flex flex-col md:flex-row gap-4 mb-8">
        <div className="flex-1 relative">
          <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-muted-foreground h-4 w-4" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search events..."
            className="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary"
          />
        </div>
        <Button variant="outline" className="flex items-center gap-2">
          <Filter className="h-4 w-4" />
          Filters
        </Button>
      </div>

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
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
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
              <Card key={event.id} className="overflow-hidden hover:shadow-lg transition-shadow">
                <div
                  className="h-48 bg-cover bg-center"
                  style={{ backgroundImage: `url(${imageUrl})` }}
                />
                <CardContent className="p-4">
                  <h3 className="font-semibold text-lg mb-2">{event.title}</h3>

                  <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
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
                    <div className="flex items-center gap-2 text-muted-foreground text-sm mb-1">
                      <MapPin className="h-4 w-4 flex-shrink-0" />
                      <span>{event.venue}</span>
                    </div>
                  )}

                  <div className="flex items-center gap-2 text-muted-foreground text-sm mb-3">
                    <Users className="h-4 w-4 flex-shrink-0" />
                    <span>
                      {event.available_capacity ?? event.spots_left ?? 0} spots left
                    </span>
                  </div>

                  <div className="flex items-center justify-between">
                    <span className="font-bold text-lg">
                      {event.price === 0 ? 'FREE' : `SGD ${Number(event.price).toFixed(2)}`}
                    </span>
                    <Link to={`/store/events`}>
                      <Button size="sm" className="flex items-center gap-2">
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
            <div className="col-span-3 text-center py-16 text-muted-foreground">
              <Calendar className="h-16 w-16 mx-auto mb-4 opacity-40" />
              <p className="font-medium mb-1">No events found</p>
              <p className="text-sm">Try a different search term.</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default BrowseEventsPage;
