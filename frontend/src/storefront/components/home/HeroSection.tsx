import { useState, useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from '@iconify/react';
import { Button } from '@/storefront/components/ui/button';
import { storefrontRoutes } from '@/storefront/lib/paths';

export const HeroSection = () => {
  const [mousePosition, setMousePosition] = useState({ x: 0, y: 0 });
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    // Load UnicornStudio script
    const scriptId = 'unicorn-studio-script';
    
    if (!document.getElementById(scriptId)) {
      const script = document.createElement("script");
      script.id = scriptId;
      script.src = "https://cdn.jsdelivr.net/gh/hiunicornstudio/unicornstudio.js@v1.5.2/dist/unicornStudio.umd.js";
      script.onload = () => {
        // @ts-expect-error -- UnicornStudio is injected from a CDN and has no type declaration
        if (window.UnicornStudio) {
          // @ts-expect-error -- UnicornStudio is injected from a CDN and has no type declaration
          window.UnicornStudio.init();
        }
      };
      document.head.appendChild(script);
    } else {
       // @ts-expect-error -- UnicornStudio is injected from a CDN and has no type declaration
       if (window.UnicornStudio) {
         // @ts-expect-error -- UnicornStudio is injected from a CDN and has no type declaration
         window.UnicornStudio.init();
       }
    }

    const handleMouseMove = (e: MouseEvent) => {
      if (!containerRef.current) return;
      const rect = containerRef.current.getBoundingClientRect();
      const x = (e.clientX - rect.left - rect.width / 2) / rect.width;
      const y = (e.clientY - rect.top - rect.height / 2) / rect.height;
      setMousePosition({ x, y });
    };

    window.addEventListener('mousemove', handleMouseMove);
    return () => window.removeEventListener('mousemove', handleMouseMove);
  }, []);

  return (
    <section
      ref={containerRef}
      className="relative min-h-[90vh] flex items-center justify-center overflow-hidden bg-white"
    >
      {/* Background Layers */}
      <div className="absolute inset-0 z-0">
        <div 
          data-us-project="f44xkZNBoxcIaUwvptCv" 
          style={{ width: '100%', height: '100%' }}
        ></div>
      </div>

      {/* Content */}
      <div className="container relative z-10 text-center px-4">
        <div className="max-w-4xl mx-auto">
          {/* Badge - Hidden to keep spacing */}
          <div 
            className="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white border border-border/50 shadow-sm mb-8 invisible select-none pointer-events-none"
            aria-hidden="true"
          >
            <Icon icon="solar:stars-minimalistic-linear" className="h-4 w-4 text-primary" />
            <span className="text-xs font-semibold tracking-wide uppercase text-muted-foreground">New Arrivals Every Week</span>
          </div>

          {/* Headline */}
          <h1
            className="text-5xl md:text-7xl lg:text-8xl font-display font-bold mb-8 animate-fade-in-up"
            style={{ animationDelay: '100ms' }}
          >
            Your Premium{' '}
            <span className="text-aura relative inline-block">
              K-Pop
              <Icon icon="solar:crown-star-bold" className="absolute -top-6 -right-8 h-10 w-10 text-yellow-400 opacity-80 rotate-12" />
            </span>
            <br />
            Destination
          </h1>

          {/* Subheadline */}
          <p
            className="text-lg md:text-xl text-muted-foreground max-w-2xl mx-auto mb-12 leading-relaxed animate-fade-in-up"
            style={{ animationDelay: '200ms' }}
          >
            Discover exclusive albums, official lightsticks, photocards, and premium merch from your favorite artists. Singapore's most trusted K-pop store.
          </p>

          {/* CTAs */}
          <div
            className="flex flex-col sm:flex-row items-center justify-center gap-4 animate-fade-in-up"
            style={{ animationDelay: '300ms' }}
          >
            <Button size="lg" className="h-12 px-8 rounded-full text-base bg-foreground text-background hover:bg-foreground/90 transition-all hover:scale-105" asChild>
              <Link to={storefrontRoutes.shop}>
                Shop Now
                <Icon icon="solar:arrow-right-linear" className="h-5 w-5 ml-2" />
              </Link>
            </Button>
            <Button variant="outline" size="lg" className="h-12 px-8 rounded-full text-base border-border bg-white hover:bg-secondary/5 hover:text-secondary hover:border-secondary/20 transition-all hover:scale-105" asChild>
              <Link to={storefrontRoutes.events}>
                <Icon icon="solar:calendar-linear" className="h-5 w-5 mr-2" />
                Upcoming Events
              </Link>
            </Button>
          </div>

          {/* Stats - Hidden to keep spacing */}
          <div
            className="grid grid-cols-3 gap-8 mt-20 max-w-2xl mx-auto pt-8 invisible select-none pointer-events-none"
            aria-hidden="true"
          >
            <div className="text-center">
              <p className="text-3xl md:text-4xl font-display font-bold text-foreground">5K+</p>
              <p className="text-xs uppercase tracking-wider text-muted-foreground mt-2">Products</p>
            </div>
            <div className="text-center">
              <p className="text-3xl md:text-4xl font-display font-bold text-foreground">50+</p>
              <p className="text-xs uppercase tracking-wider text-muted-foreground mt-2">Artists</p>
            </div>
            <div className="text-center">
              <p className="text-3xl md:text-4xl font-display font-bold text-foreground">10K+</p>
              <p className="text-xs uppercase tracking-wider text-muted-foreground mt-2">Happy Fans</p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};
